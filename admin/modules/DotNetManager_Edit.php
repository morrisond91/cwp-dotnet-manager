<?php
require_once '/usr/local/cwpsrv/var/services/user_files/modules/DotNetManagerDB.php';
require_once '/usr/local/cwpsrv/var/services/user_files/modules/DotNetManagerNginx.php';

function execute_shell_command($command) {
    $output = shell_exec($command . ' 2>&1');
    return $output;
}

function getCWPAccounts() {
    $output = execute_shell_command("mysql -u root -e \"USE root_cwp; SELECT username, domain FROM user ORDER BY username;\" 2>/dev/null");
    $accounts = [];
    $lines = explode("\n", trim($output));
    foreach ($lines as $i => $line) {
        if ($i === 0) continue; // skip header
        $parts = preg_split('/\s+/', trim($line), 2);
        if (count($parts) === 2) {
            $accounts[] = ['username' => $parts[0], 'domain' => $parts[1]];
        }
    }
    return $accounts;
}

function getDomainsForAccount($username) {
    $output = execute_shell_command("mysql -u root -e \"USE root_cwp; SELECT domain, path FROM domains WHERE user='" . escapeshellcmd($username) . "' ORDER BY domain;\" 2>/dev/null");
    $domains = [];
    $lines = explode("\n", trim($output));
    foreach ($lines as $i => $line) {
        if ($i === 0) continue;
        $parts = preg_split('/\s+/', trim($line), 2);
        if (count($parts) === 2) {
            $domains[] = ['domain' => $parts[0], 'path' => $parts[1]];
        }
    }
    // Also add primary domain from user table
    $primary = execute_shell_command("mysql -u root -e \"USE root_cwp; SELECT domain FROM user WHERE username='" . escapeshellcmd($username) . "' LIMIT 1;\" 2>/dev/null | tail -n1");
    $primary = trim($primary);
    if ($primary) {
        $found = false;
        foreach ($domains as $d) {
            if ($d['domain'] === $primary) { $found = true; break; }
        }
        if (!$found) {
            $home = '/home/' . $username . '/public_html';
            array_unshift($domains, ['domain' => $primary, 'path' => $home]);
        }
    }
    return $domains;
}

function getUserHome($username) {
    $info = posix_getpwnam($username);
    if ($info) return $info['dir'];
    return '/home/' . $username;
}

function parsePortFromUrls($urls) {
    if (preg_match('/:(\d+)/', $urls, $m)) {
        return (int)$m[1];
    }
    return 5000;
}

$db = new DotNetManagerDB();
$nginx = new DotNetManagerNginx();
$outputMessage = '';
$outputStyle = 'success';
$isEdit = false;
$selectedAccount = isset($_GET['account']) ? $_GET['account'] : '';

$formData = [
    'name' => '', 'domain' => '', 'username' => '',
    'working_directory' => '', 'dll_path' => 'app.dll',
    'urls' => 'http://localhost:5000', 'environment' => 'Production', 'port' => 5000
];

$accounts = getCWPAccounts();
$domains = [];

// Load existing app for editing
if (isset($_GET['name']) && !empty($_GET['name'])) {
    $svcName = basename($_GET['name']);
    $baseName = str_replace('.service', '', $svcName);
    $app = $db->getAppByName($baseName);
    if ($app) {
        $isEdit = true;
        $formData = $app;
        $selectedAccount = $app['username'];
    } else {
        $svcFile = "/etc/systemd/system/DotNetManager/$svcName";
        if (file_exists($svcFile)) {
            $isEdit = true;
            $content = file_get_contents($svcFile);
            preg_match('/Description=(.+)/', $content, $m);
            preg_match('/User=(\w+)/', $content, $u);
            preg_match('/WorkingDirectory=(.+)/', $content, $w);
            preg_match('/ExecStart=\/usr\/bin\/dotnet\s+(\S+)\s+--urls=(\S+)/', $content, $e);
            preg_match('/Environment=DOTNET_ENVIRONMENT=(\w+)/', $content, $en);
            $formData['name'] = $baseName;
            $formData['domain'] = '';
            $formData['username'] = isset($u[1]) ? $u[1] : 'root';
            $formData['working_directory'] = isset($w[1]) ? trim($w[1]) : '';
            $formData['dll_path'] = isset($e[1]) ? $e[1] : '';
            $formData['urls'] = isset($e[2]) ? $e[2] : '';
            $formData['environment'] = isset($en[1]) ? $en[1] : 'Production';
            $formData['port'] = parsePortFromUrls($formData['urls']);
            $selectedAccount = $formData['username'];
        }
    }
}

if ($selectedAccount) {
    $domains = getDomainsForAccount($selectedAccount);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $formData = [
        'name' => strtolower(preg_replace('/[^a-z0-9]/', '', $_POST['name'])),
        'domain' => $_POST['domain'],
        'username' => $_POST['username'],
        'working_directory' => $_POST['working_directory'],
        'dll_path' => $_POST['dll_path'],
        'urls' => $_POST['urls'],
        'environment' => $_POST['environment'],
        'port' => isset($_POST['port']) ? (int)$_POST['port'] : parsePortFromUrls($_POST['urls'])
    ];
    $selectedAccount = $formData['username'];
    $domains = getDomainsForAccount($selectedAccount);

    if (empty($formData['name'])) {
        $outputMessage = 'Application name is required and must contain only alphanumeric characters.';
        $outputStyle = 'danger';
    } elseif (empty($formData['domain'])) {
        $outputMessage = 'Please select a domain.';
        $outputStyle = 'danger';
    } else {
        $svcName = $formData['name'] . '.service';
        $svcDir = '/etc/systemd/system/DotNetManager';
        if (!is_dir($svcDir)) mkdir($svcDir, 0755, true);

        if (!$isEdit && file_exists("$svcDir/$svcName")) {
            $outputMessage = 'An application with this name already exists.';
            $outputStyle = 'danger';
        } else {
            // Find docroot for selected domain
            $docRoot = $formData['working_directory'];
            foreach ($domains as $d) {
                if ($d['domain'] === $formData['domain']) {
                    $docRoot = rtrim($d['path'], '/');
                    if (empty($docRoot)) $docRoot = '/home/' . $formData['username'] . '/public_html';
                    break;
                }
            }

            // Build service file
            $name = $formData['name'];
            $appNameDisp = htmlspecialchars($name);
            $workDir = $formData['working_directory'];
            $dll = $formData['dll_path'];
            $urls = $formData['urls'];
            $env = $formData['environment'];
            $user = $formData['username'];

            $serviceContent = "[Unit]
Description=$appNameDisp
After=network.target

[Service]
Type=simple
User=$user
WorkingDirectory=$workDir
ExecStart=/usr/bin/dotnet $dll --urls=$urls
Restart=on-failure
RestartSec=10
SyslogIdentifier=$name
Environment=DOTNET_ENVIRONMENT=$env
Environment=DOTNET_ROOT=/usr/lib64/dotnet
Environment=HOME=$workDir

[Install]
WantedBy=multi-user.target
";

            file_put_contents("$svcDir/$svcName", $serviceContent);
            execute_shell_command("systemctl daemon-reload");
            execute_shell_command("systemctl enable $svcDir/$svcName");

            // Generate nginx config
            $nginxPaths = $nginx->generateNginxConfig($formData['domain'], $formData['port'], $docRoot);
            $nginx->reloadNginx();

            $formData['nginx_config_path'] = $nginxPaths['http'];

            // Save to DB
            $db->saveApp($formData);

            $actionUser = isset($_SESSION['username']) ? $_SESSION['username'] : 'admin';
            $db->logAction($formData['name'], $isEdit ? 'UPDATE' : 'CREATE', 'App and nginx vhost saved', $actionUser);

            $outputMessage = $isEdit ? 'Application updated successfully. Nginx config regenerated.' : 'Application created successfully. Nginx vhost configured.';
            $isEdit = true;
        }
    }
}
?>

<div class="container mt-4">
    <?php if ($outputMessage): ?>
        <div class="alert alert-<?php echo $outputStyle; ?> alert-dismissible" role="alert">
            <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <?php echo htmlspecialchars($outputMessage); ?>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-8"><h2><?php echo $isEdit ? 'Edit' : 'Create New'; ?> .NET Application</h2></div>
        <div class="col-md-4 text-right">
            <a href="index.php?module=DotNetManager_Apps" class="btn btn-default"><i class="fa fa-arrow-left"></i> Back to Apps</a>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-body">
            <form method="post" class="form-horizontal" id="appForm">

                <!-- Step 1: Select Account -->
                <div class="form-group">
                    <label class="col-sm-3 control-label">CWP Account *</label>
                    <div class="col-sm-6">
                        <select class="form-control" id="accountSelect" name="username" required onchange="window.location='index.php?module=DotNetManager_Edit<?php echo $isEdit ? '&name='.urlencode($_GET['name']) : ''; ?>&account='+encodeURIComponent(this.value)">
                            <option value="">-- Select Account --</option>
                            <?php foreach ($accounts as $acc): ?>
                                <option value="<?php echo htmlspecialchars($acc['username']); ?>" <?php echo $selectedAccount === $acc['username'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($acc['username']); ?> (<?php echo htmlspecialchars($acc['domain']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Choose the CWP user that owns this application.</small>
                    </div>
                </div>

                <!-- Step 2: Select Domain -->
                <div class="form-group">
                    <label class="col-sm-3 control-label">Domain *</label>
                    <div class="col-sm-6">
                        <select class="form-control" name="domain" id="domainSelect" required <?php echo empty($selectedAccount) ? 'disabled' : ''; ?>>
                            <option value="">-- Select Domain --</option>
                            <?php foreach ($domains as $d): ?>
                                <option value="<?php echo htmlspecialchars($d['domain']); ?>" data-path="<?php echo htmlspecialchars(rtrim($d['path'],'/')); ?>" <?php echo $formData['domain'] === $d['domain'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($d['domain']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Domains registered to the selected account.</small>
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-sm-3 control-label">Application Name *</label>
                    <div class="col-sm-6">
                        <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($formData['name']); ?>" <?php echo $isEdit ? 'readonly' : 'required'; ?>>
                        <?php if (!$isEdit): ?><small class="text-muted">Lowercase alphanumeric only. Becomes the systemd service name.</small><?php endif; ?>
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-sm-3 control-label">Working Directory *</label>
                    <div class="col-sm-6">
                        <input type="text" class="form-control" name="working_directory" id="workDir" value="<?php echo htmlspecialchars($formData['working_directory']); ?>" required placeholder="/home/user/public_html/app">
                        <small class="text-muted">Absolute path to the published .NET app folder.</small>
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-sm-3 control-label">DLL Path *</label>
                    <div class="col-sm-6">
                        <input type="text" class="form-control" name="dll_path" value="<?php echo htmlspecialchars($formData['dll_path']); ?>" required placeholder="MyApp.dll">
                        <small class="text-muted">Relative to working directory or absolute path.</small>
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-sm-3 control-label">Local URL / Port *</label>
                    <div class="col-sm-6">
                        <input type="text" class="form-control" name="urls" id="urlsInput" value="<?php echo htmlspecialchars($formData['urls']); ?>" required placeholder="http://localhost:5000">
                        <small class="text-muted">Kestrel binding. The port will be used for the nginx proxy_pass.</small>
                    </div>
                </div>

                <div class="form-group">
                    <label class="col-sm-3 control-label">Environment</label>
                    <div class="col-sm-6">
                        <select class="form-control" name="environment">
                            <option value="Production" <?php echo $formData['environment']=='Production'?'selected':''; ?>>Production</option>
                            <option value="Staging" <?php echo $formData['environment']=='Staging'?'selected':''; ?>>Staging</option>
                            <option value="Development" <?php echo $formData['environment']=='Development'?'selected':''; ?>>Development</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <div class="col-sm-offset-3 col-sm-6">
                        <button type="submit" class="btn btn-primary"><?php echo $isEdit ? 'Update Application' : 'Create Application'; ?></button>
                        <a href="index.php?module=DotNetManager_Apps" class="btn btn-default">Cancel</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('domainSelect').addEventListener('change', function() {
    var opt = this.options[this.selectedIndex];
    var path = opt.getAttribute('data-path');
    if (path) {
        document.getElementById('workDir').value = path;
    }
});
</script>
