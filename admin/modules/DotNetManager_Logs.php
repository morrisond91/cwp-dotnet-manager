<?php
require_once '/usr/local/cwpsrv/var/services/user_files/modules/DotNetManagerDB.php';

function execute_shell_command($command) {
    $output = shell_exec($command . ' 2>&1');
    return $output;
}

function sanitize_app_name($name) {
    return preg_replace('/[^a-zA-Z0-9_-]/', '', $name);
}

$db = new DotNetManagerDB();
$appName = isset($_GET['name']) ? sanitize_app_name($_GET['name']) : '';
$app = $appName ? $db->getAppByName($appName) : null;

$journalLines = 100;
if (isset($_GET['lines']) && is_numeric($_GET['lines'])) {
    $journalLines = min(500, max(10, (int)$_GET['lines']));
}

$journalOutput = '';
$serviceStatus = '';
if ($appName) {
    $svcName = escapeshellarg($appName . '.service');
    $journalOutput = execute_shell_command("journalctl -u $svcName --no-pager -n " . (int)$journalLines);
    $serviceStatus = execute_shell_command("systemctl status $svcName --no-pager");
}

// Plugin DB logs
$dbLogs = $db->getLogs($appName, 50);
?>

<style>
    .dnb-log-box { background: #1e1e1e; color: #d4d4d4; font-family: 'Courier New', Courier, monospace; padding: 15px; border-radius: 4px; max-height: 500px; overflow-y: auto; white-space: pre-wrap; font-size: 12px; }
    .dnb-log-box .timestamp { color: #6cc; }
    .dnb-status-pre { background: #f8f8f8; border: 1px solid #ddd; padding: 15px; border-radius: 4px; max-height: 400px; overflow-y: auto; }
</style>

<div class="container mt-4">
    <div class="row">
        <div class="col-md-8">
            <h2 class="mb-3">
                <i class="fa fa-file-text-o"></i> 
                <?php echo $appName ? 'Logs: ' . htmlspecialchars($appName) : 'Application Logs'; ?>
            </h2>
        </div>
        <div class="col-md-4 text-right">
            <a href="index.php?module=DotNetManager_Apps" class="btn btn-default"><i class="fa fa-arrow-left"></i> Back to Apps</a>
        </div>
    </div>

    <?php if (!$appName): ?>
        <div class="alert alert-info">Please select an application from the <a href="index.php?module=DotNetManager_Apps">Manage Applications</a> page to view its logs.</div>
    <?php else: ?>
        <div class="row">
            <div class="col-md-12">
                <ul class="nav nav-tabs" role="tablist">
                    <li role="presentation" class="active"><a href="#journal" aria-controls="journal" role="tab" data-toggle="tab">Journal Logs</a></li>
                    <li role="presentation"><a href="#status" aria-controls="status" role="tab" data-toggle="tab">Service Status</a></li>
                    <li role="presentation"><a href="#history" aria-controls="history" role="tab" data-toggle="tab">Action History</a></li>
                </ul>

                <div class="tab-content" style="padding-top: 20px;">
                    <div role="tabpanel" class="tab-pane active" id="journal">
                        <div class="row">
                            <div class="col-md-6">
                                <form method="get" class="form-inline">
                                    <input type="hidden" name="module" value="DotNetManager_Logs">
                                    <input type="hidden" name="name" value="<?php echo htmlspecialchars($appName); ?>">
                                    <div class="form-group">
                                        <label>Lines: </label>
                                        <select name="lines" class="form-control input-sm" onchange="this.form.submit()">
                                            <option value="50" <?php echo $journalLines==50?'selected':''; ?>>50</option>
                                            <option value="100" <?php echo $journalLines==100?'selected':''; ?>>100</option>
                                            <option value="200" <?php echo $journalLines==200?'selected':''; ?>>200</option>
                                            <option value="500" <?php echo $journalLines==500?'selected':''; ?>>500</option>
                                        </select>
                                    </div>
                                    <a href="index.php?module=DotNetManager_Logs&name=<?php echo urlencode($appName); ?>&lines=<?php echo $journalLines; ?>" class="btn btn-default btn-sm"><i class="fa fa-refresh"></i> Refresh</a>
                                </form>
                            </div>
                            <div class="col-md-6 text-right">
                                <a href="index.php?module=DotNetManager_Edit&name=<?php echo urlencode($appName.'.service'); ?>" class="btn btn-primary btn-sm"><i class="fa fa-pencil"></i> Edit App</a>
                            </div>
                        </div>
                        <div class="dnb-log-box mt-3"><?php echo htmlspecialchars($journalOutput); ?></div>
                    </div>

                    <div role="tabpanel" class="tab-pane" id="status">
                        <pre class="dnb-status-pre"><?php echo htmlspecialchars($serviceStatus); ?></pre>
                    </div>

                    <div role="tabpanel" class="tab-pane" id="history">
                        <table class="table table-striped table-bordered">
                            <thead>
                                <tr><th>Time</th><th>Action</th><th>Details</th><th>Performed By</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($dbLogs as $log): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                                        <td><span class="label label-info"><?php echo htmlspecialchars($log['action']); ?></span></td>
                                        <td><?php echo htmlspecialchars($log['details']); ?></td>
                                        <td><?php echo htmlspecialchars($log['performed_by']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($dbLogs)): ?>
                                    <tr><td colspan="4" class="text-muted text-center">No recorded actions.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="text-muted text-right" style="padding: 10px 0; font-size: 11px; border-top: 1px solid #eee; margin-top: 20px;">
        <i class="fa fa-code"></i> DotNetManager v<?php echo DOTNETMANAGER_VERSION; ?>
    </div>
</div>
