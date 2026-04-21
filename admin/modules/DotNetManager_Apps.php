<?php
require_once '/usr/local/cwpsrv/var/services/user_files/modules/DotNetManagerDB.php';
require_once '/usr/local/cwpsrv/var/services/user_files/modules/DotNetManagerNginx.php';

function execute_shell_command($command) {
    $output = shell_exec($command . ' 2>&1');
    return $output;
}

function generate_csrf_token() {
    if (empty($_SESSION['dnm_csrf_token'])) {
        if (function_exists('random_bytes')) {
            $_SESSION['dnm_csrf_token'] = bin2hex(random_bytes(32));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $_SESSION['dnm_csrf_token'] = bin2hex(openssl_random_pseudo_bytes(32));
        } else {
            $_SESSION['dnm_csrf_token'] = bin2hex(mt_rand() . microtime() . uniqid('', true));
        }
    }
    return $_SESSION['dnm_csrf_token'];
}

function validate_csrf_token() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;
    if (empty($_POST['csrf_token']) || empty($_SESSION['dnm_csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['dnm_csrf_token'], $_POST['csrf_token']);
}

function get_service_status($serviceName) {
    $output = execute_shell_command("systemctl show $serviceName --no-page 2>/dev/null");
    if (empty($output)) return ['active' => 'unknown', 'sub' => 'unknown', 'pid' => 0];
    preg_match('/ActiveState=(\w+)/', $output, $m);
    preg_match('/SubState=(\w+)/', $output, $sm);
    preg_match('/MainPID=(\d+)/', $output, $pid);
    return [
        'active' => isset($m[1]) ? $m[1] : 'unknown',
        'sub' => isset($sm[1]) ? $sm[1] : 'unknown',
        'pid' => isset($pid[1]) ? $pid[1] : 0
    ];
}

$db = new DotNetManagerDB();
$outputMessages = [];
$actionUser = isset($_SESSION['username']) ? $_SESSION['username'] : 'admin';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validate_csrf_token()) {
        $outputMessages[] = ['style' => 'danger', 'message' => 'Invalid or missing CSRF token.'];
    } elseif (isset($_POST['start_service']) && isset($_POST['service_name'])) {
        $rawName = $_POST['service_name'];
        $name = escapeshellarg($rawName);
        execute_shell_command("systemctl start $name");
        usleep(300000);
        $st = get_service_status($name);
        if ($st['active'] === 'active') {
            $outputMessages[] = ['style' => 'success', 'message' => "Service $rawName started successfully."];
            $db->logAction(str_replace('.service','',$rawName), 'START', 'Service started via admin panel', $actionUser);
        } else {
            $outputMessages[] = ['style' => 'danger', 'message' => "Failed to start service $rawName. Check logs."];
        }
    } elseif (isset($_POST['stop_service']) && isset($_POST['service_name'])) {
        $rawName = $_POST['service_name'];
        $name = escapeshellarg($rawName);
        execute_shell_command("systemctl stop $name");
        usleep(300000);
        $st = get_service_status($name);
        if ($st['active'] === 'inactive') {
            $outputMessages[] = ['style' => 'success', 'message' => "Service $rawName stopped successfully."];
            $db->logAction(str_replace('.service','',$rawName), 'STOP', 'Service stopped via admin panel', $actionUser);
        } else {
            $outputMessages[] = ['style' => 'danger', 'message' => "Failed to stop service $rawName."];
        }
    } elseif (isset($_POST['restart_service']) && isset($_POST['service_name'])) {
        $rawName = $_POST['service_name'];
        $name = escapeshellarg($rawName);
        execute_shell_command("systemctl restart $name");
        usleep(500000);
        $st = get_service_status($name);
        if ($st['active'] === 'active') {
            $outputMessages[] = ['style' => 'success', 'message' => "Service $rawName restarted successfully."];
            $db->logAction(str_replace('.service','',$rawName), 'RESTART', 'Service restarted via admin panel', $actionUser);
        } else {
            $outputMessages[] = ['style' => 'danger', 'message' => "Failed to restart service $rawName."];
        }
    } elseif (isset($_POST['delete_service']) && isset($_POST['service_name'])) {
        $rawName = $_POST['service_name'];
        $name = escapeshellarg($rawName);
        $baseName = str_replace('.service', '', $rawName);
        $baseName = preg_replace('/[^a-z0-9]/', '', strtolower($baseName));
        $app = $db->getAppByName($baseName);
        execute_shell_command("systemctl stop $name");
        execute_shell_command("systemctl disable $name");
        execute_shell_command("rm -f -- /etc/systemd/system/DotNetManager/" . escapeshellarg(basename($rawName)));
        execute_shell_command("systemctl daemon-reload && systemctl reset-failed");
        $nginx = new DotNetManagerNginx();
        if ($app && !empty($app['domain'])) {
            $nginx->removeNginxConfig($app['domain']);
            $nginx->reloadNginx();
        }
        $db->deleteApp($baseName);
        $db->logAction($baseName, 'DELETE', 'Service and nginx vhost deleted', $actionUser);
        $outputMessages[] = ['style' => 'success', 'message' => "Service $rawName and nginx vhost deleted successfully."];
    }
}

$apps = $db->getAllApps();
?>

<style>
    .dnb-status-dot { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 6px; }
    .dnb-status-dot.running { background-color: #5cb85c; box-shadow: 0 0 6px #5cb85c; }
    .dnb-status-dot.stopped { background-color: #d9534f; }
    .dnb-status-dot.failed { background-color: #f0ad4e; }
    .dnb-status-dot.unknown { background-color: #999; }
    .dnb-table th { background: #f5f5f5; }
    .dnb-actions form { display: inline; }
    .dnb-actions .btn { margin-right: 3px; }
</style>

<div class="container mt-4">
    <?php foreach ($outputMessages as $msg): ?>
        <div class="alert alert-<?php echo $msg['style']; ?> alert-dismissible" role="alert">
            <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            <?php echo htmlspecialchars($msg['message']); ?>
        </div>
    <?php endforeach; ?>

    <div class="row">
        <div class="col-md-8"><h2 class="mb-3">Manage .NET Applications</h2></div>
        <div class="col-md-4 text-right">
            <a href="index.php?module=DotNetManager_Dashboard" class="btn btn-default"><i class="fa fa-dashboard"></i> Dashboard</a>
            <a href="index.php?module=DotNetManager_Edit" class="btn btn-success"><i class="fa fa-plus"></i> New App</a>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-hover dnb-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Domain</th>
                    <th>Owner</th>
                    <th>Status</th>
                    <th>PID</th>
                    <th>Domain</th>
                    <th>Working Dir</th>
                    <th>Created</th>
                    <th style="min-width:160px">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($apps as $app):
                    $svcName = $app['name'] . '.service';
                    $status = get_service_status($svcName);
                    $statusClass = ($status['active'] === 'active') ? 'running' : (($status['active'] === 'failed') ? 'failed' : 'stopped');
                    $statusText = ucfirst($status['active']);
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($app['name']); ?></strong></td>
                    <td><?php echo htmlspecialchars($app['domain']); ?></td>
                    <td><span class="label label-default"><?php echo htmlspecialchars($app['username']); ?></span></td>
                    <td><span class="dnb-status-dot <?php echo $statusClass; ?>"></span><?php echo $statusText; ?></td>
                    <td><?php echo (int)$status['pid']; ?></td>
                    <td><a href="https://<?php echo htmlspecialchars($app['domain']); ?>" target="_blank"><?php echo htmlspecialchars($app['domain']); ?> <i class="fa fa-external-link" style="font-size:10px"></i></a></td>
                    <td><small><?php echo htmlspecialchars($app['working_directory']); ?></small></td>
                    <td><small><?php echo htmlspecialchars($app['created_at']); ?></small></td>
                    <td class="dnb-actions">
                        <form method="post" onsubmit="return confirm('Are you sure?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(generate_csrf_token()); ?>">
                            <input type="hidden" name="service_name" value="<?php echo htmlspecialchars($svcName); ?>">
                            <?php if ($status['active'] === 'active'): ?>
                                <button type="submit" name="stop_service" class="btn btn-danger btn-sm" title="Stop"><i class="fa fa-stop"></i></button>
                                <button type="submit" name="restart_service" class="btn btn-warning btn-sm" title="Restart"><i class="fa fa-refresh"></i></button>
                            <?php else: ?>
                                <button type="submit" name="start_service" class="btn btn-success btn-sm" title="Start"><i class="fa fa-play"></i></button>
                            <?php endif; ?>
                            <a href="index.php?module=DotNetManager_Edit&name=<?php echo urlencode($svcName); ?>" class="btn btn-primary btn-sm" title="Edit"><i class="fa fa-pencil"></i></a>
                            <a href="index.php?module=DotNetManager_Logs&name=<?php echo urlencode($app['name']); ?>" class="btn btn-info btn-sm" title="Logs"><i class="fa fa-file-text"></i></a>
                            <button type="submit" name="delete_service" class="btn btn-default btn-sm" title="Delete" onclick="return confirm('Permanently delete this application?');"><i class="fa fa-trash text-danger"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($apps)): ?>
                <tr>
                    <td colspan="9" class="text-center text-muted">No applications found. <a href="index.php?module=DotNetManager_Edit">Create one now</a>.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="text-muted text-right" style="padding: 10px 0; font-size: 11px; border-top: 1px solid #eee; margin-top: 20px;">
        <i class="fa fa-code"></i> DotNetManager v<?php echo DOTNETMANAGER_VERSION; ?>
    </div>
</div>
