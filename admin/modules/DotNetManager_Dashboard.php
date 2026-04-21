<?php
require_once '/usr/local/cwpsrv/var/services/user_files/modules/DotNetManagerDB.php';

function execute_shell_command($command) {
    $output = shell_exec($command . ' 2>&1');
    return $output;
}

function get_service_status($serviceName) {
    $output = execute_shell_command("systemctl show " . escapeshellarg($serviceName) . " --no-page 2>/dev/null");
    if (empty($output)) return null;
    preg_match('/ActiveState=(\w+)/', $output, $m);
    preg_match('/SubState=(\w+)/', $output, $sm);
    preg_match('/MainPID=(\d+)/', $output, $pid);
    return [
        'active' => isset($m[1]) ? $m[1] : 'unknown',
        'sub' => isset($sm[1]) ? $sm[1] : 'unknown',
        'pid' => isset($pid[1]) ? $pid[1] : 0
    ];
}

function get_system_metrics() {
    $cpu = execute_shell_command("top -bn1 | grep 'Cpu(s)' | awk '{print $2}' | cut -d'%' -f1");
    $mem = execute_shell_command("free -m | awk 'NR==2{printf \"%.2f\", $3*100/$2 }'");
    $disk = execute_shell_command("df -h / | awk 'NR==2{print $5}'");
    $dotnetVersion = execute_shell_command("dotnet --version 2>/dev/null | head -n1 | tr -d '\\n'");
    return [
        'cpu' => trim($cpu) ?: '0',
        'memory' => trim($mem) ?: '0',
        'disk' => trim($disk) ?: '0%',
        'dotnet_version' => trim($dotnetVersion) ?: 'Not installed'
    ];
}

$db = new DotNetManagerDB();
$apps = $db->getAllApps();
$stats = $db->getStats();
$metrics = get_system_metrics();

$running = 0;
$stopped = 0;
$failed = 0;
$userAppCounts = [];

foreach ($apps as $app) {
    $svcName = $app['name'] . '.service';
    $status = get_service_status($svcName);
    if ($status) {
        if ($status['active'] === 'active') $running++;
        elseif ($status['active'] === 'failed') $failed++;
        else $stopped++;
    } else {
        $stopped++;
    }
    $userAppCounts[$app['username']] = (isset($userAppCounts[$app['username']]) ? $userAppCounts[$app['username']] : 0) + 1;
}

// Recent logs
$recentLogs = $db->getLogs(null, 10);
?>

<style>
    .dnb-card { border-radius: 4px; padding: 20px; color: #fff; margin-bottom: 20px; }
    .dnb-card h3 { margin: 0 0 10px; font-size: 28px; font-weight: 700; }
    .dnb-card p { margin: 0; font-size: 14px; opacity: 0.9; }
    .dnb-card.bg-primary { background: #337ab7; }
    .dnb-card.bg-success { background: #5cb85c; }
    .dnb-card.bg-danger { background: #d9534f; }
    .dnb-card.bg-warning { background: #f0ad4e; }
    .dnb-card.bg-info { background: #5bc0de; }
    .dnb-progress { height: 20px; margin-bottom: 10px; }
    .dnb-table th { background: #f5f5f5; }
</style>

<div class="container mt-4">
    <h2 class="mb-3">.NET Web Applications Dashboard</h2>

    <div class="row">
        <div class="col-md-3">
            <div class="dnb-card bg-primary">
                <h3><?php echo (int)$stats['total']; ?></h3>
                <p><i class="fa fa-cubes"></i> Total Applications</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dnb-card bg-success">
                <h3><?php echo $running; ?></h3>
                <p><i class="fa fa-play-circle"></i> Running</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dnb-card bg-danger">
                <h3><?php echo $stopped; ?></h3>
                <p><i class="fa fa-stop-circle"></i> Stopped</p>
            </div>
        </div>
        <div class="col-md-3">
            <div class="dnb-card bg-warning">
                <h3><?php echo $failed; ?></h3>
                <p><i class="fa fa-exclamation-triangle"></i> Failed</p>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-6">
            <div class="panel panel-default">
                <div class="panel-heading"><strong><i class="fa fa-server"></i> System Metrics</strong></div>
                <div class="panel-body">
                    <div class="mb-2">
                        <label>CPU Usage</label>
                        <div class="progress dnb-progress">
                            <div class="progress-bar progress-bar-striped active" style="width: <?php echo (float)$metrics['cpu']; ?>%">
                                <?php echo (float)$metrics['cpu']; ?>%
                            </div>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label>Memory Usage</label>
                        <div class="progress dnb-progress">
                            <div class="progress-bar progress-bar-info progress-bar-striped active" style="width: <?php echo (float)$metrics['memory']; ?>%">
                                <?php echo (float)$metrics['memory']; ?>%
                            </div>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label>Disk Usage (/)</label>
                        <div class="progress dnb-progress">
                            <div class="progress-bar progress-bar-warning progress-bar-striped active" style="width: <?php echo str_replace('%','',$metrics['disk']); ?>%">
                                <?php echo htmlspecialchars($metrics['disk']); ?>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <span class="label label-default">.NET SDK: <?php echo htmlspecialchars($metrics['dotnet_version']); ?></span>
                        <span class="label label-default">Unique Users: <?php echo (int)$stats['users']; ?></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="panel panel-default">
                <div class="panel-heading"><strong><i class="fa fa-users"></i> Apps Per User</strong></div>
                <div class="panel-body">
                    <?php if (empty($userAppCounts)): ?>
                        <p class="text-muted">No applications found.</p>
                    <?php else: ?>
                        <table class="table table-condensed dnb-table">
                            <thead><tr><th>User</th><th>App Count</th></tr></thead>
                            <tbody>
                            <?php foreach ($userAppCounts as $user => $count): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user); ?></td>
                                    <td><span class="badge"><?php echo $count; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-md-12">
            <div class="panel panel-default">
                <div class="panel-heading"><strong><i class="fa fa-history"></i> Recent Activity</strong></div>
                <div class="panel-body">
                    <?php if (empty($recentLogs)): ?>
                        <p class="text-muted">No recent activity.</p>
                    <?php else: ?>
                        <table class="table table-condensed table-striped">
                            <thead>
                                <tr><th>Time</th><th>App</th><th>Action</th><th>Details</th><th>By</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentLogs as $log): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                                    <td><?php echo htmlspecialchars($log['app_name']); ?></td>
                                    <td><span class="label label-primary"><?php echo htmlspecialchars($log['action']); ?></span></td>
                                    <td><?php echo htmlspecialchars($log['details']); ?></td>
                                    <td><?php echo htmlspecialchars($log['performed_by']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="pull-right mb-4">
        <a href="index.php?module=DotNetManager_Apps" class="btn btn-primary"><i class="fa fa-cubes"></i> Manage Applications</a>
        <a href="index.php?module=DotNetManager_Edit" class="btn btn-success"><i class="fa fa-plus"></i> Create New Application</a>
    </div>

    <div class="clearfix"></div>
    <div class="text-muted text-right" style="padding: 10px 0; font-size: 11px; border-top: 1px solid #eee; margin-top: 20px;">
        <i class="fa fa-code"></i> DotNetManager v<?php echo DOTNETMANAGER_VERSION; ?>
    </div>
</div>
