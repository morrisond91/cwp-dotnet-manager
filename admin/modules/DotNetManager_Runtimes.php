<?php
require_once '/usr/local/cwpsrv/var/services/user_files/modules/DotNetManagerDB.php';

function getDotNetRuntimes() {
    $runtimes = [];
    $output = shell_exec('dotnet --list-runtimes 2>&1');
    if ($output) {
        foreach (explode("\n", trim($output)) as $line) {
            if (preg_match('/^(\S+)\s+([\d.]+)\s+\[(.+?)\]$/', trim($line), $m)) {
                $runtimes[] = [
                    'name' => $m[1],
                    'version' => $m[2],
                    'path' => $m[3]
                ];
            }
        }
    }
    return $runtimes;
}

function getDotNetSdks() {
    $sdks = [];
    $output = shell_exec('dotnet --list-sdks 2>&1');
    if ($output) {
        foreach (explode("\n", trim($output)) as $line) {
            if (preg_match('/^([\d.]+)\s+\[(.+?)\]$/', trim($line), $m)) {
                $sdks[] = [
                    'version' => $m[1],
                    'path' => $m[2]
                ];
            }
        }
    }
    return $sdks;
}

function getActiveSdk() {
    $output = shell_exec('dotnet --version 2>&1');
    return trim($output) ?: 'Not detected';
}

$sdks = getDotNetSdks();
$runtimes = getDotNetRuntimes();
$activeSdk = getActiveSdk();
?>

<div class="container mt-4">
    <div class="row">
        <div class="col-md-8"><h2>.NET Runtimes</h2></div>
        <div class="col-md-4 text-right">
            <a href="index.php?module=DotNetManager_Dashboard" class="btn btn-default"><i class="fa fa-arrow-left"></i> Back to Dashboard</a>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title"><i class="fa fa-info-circle"></i> Active SDK</h3>
        </div>
        <div class="panel-body">
            <p><strong>Default SDK Version:</strong> <span class="label label-primary"><?php echo htmlspecialchars($activeSdk); ?></span></p>
            <p class="text-muted">This is the version used when running <code>dotnet</code> commands without specifying a version.</p>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title"><i class="fa fa-code"></i> Installed SDKs</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($sdks)): ?>
                <div class="alert alert-warning">No .NET SDKs detected. Install at least one SDK to build and run applications.</div>
            <?php else: ?>
                <table class="table table-striped table-bordered">
                    <thead>
                        <tr><th>Version</th><th>Path</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sdks as $sdk): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($sdk['version']); ?></td>
                                <td><code><?php echo htmlspecialchars($sdk['path']); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title"><i class="fa fa-cubes"></i> Installed Runtimes</h3>
        </div>
        <div class="panel-body">
            <?php if (empty($runtimes)): ?>
                <div class="alert alert-warning">No .NET runtimes detected.</div>
            <?php else: ?>
                <table class="table table-striped table-bordered">
                    <thead>
                        <tr><th>Runtime</th><th>Version</th><th>Path</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($runtimes as $rt): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($rt['name']); ?></td>
                                <td><?php echo htmlspecialchars($rt['version']); ?></td>
                                <td><code><?php echo htmlspecialchars($rt['path']); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel panel-default">
        <div class="panel-heading">
            <h3 class="panel-title"><i class="fa fa-download"></i> Install Additional Runtimes</h3>
        </div>
        <div class="panel-body">
            <p>To install a new .NET runtime or SDK, use your package manager. Examples:</p>
            <pre>dnf install dotnet-sdk-9.0
dnf install dotnet-runtime-9.0
dnf install aspnetcore-runtime-9.0</pre>
            <p class="text-muted">See <a href="https://learn.microsoft.com/en-us/dotnet/core/install/linux" target="_blank">Microsoft .NET Linux install guide</a> for distro-specific instructions.</p>
        </div>
    </div>

    <div class="text-muted text-right" style="padding: 10px 0; font-size: 11px; border-top: 1px solid #eee; margin-top: 20px;">
        <i class="fa fa-code"></i> DotNetManager v<?php echo DOTNETMANAGER_VERSION; ?>
    </div>
</div>
