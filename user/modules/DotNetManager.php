<?php
/**
 * DotNetManager - User Panel Module
 * Accessible on port 2031 for customers to manage their own .NET apps
 *
 * Privileged operations (start/stop/restart/logs) are sent to the
 * DotNetManager socket daemon which validates ownership via SO_PEERCRED.
 */
require_once '/usr/local/cwpsrv/var/services/user_files/modules/DotNetManagerDB.php';

$currentUser = isset($_SESSION['username']) ? $_SESSION['username'] : '';
$tokenUser = isset($_SESSION['tokenuser']) ? $_SESSION['tokenuser'] : '';

function execute_shell_command($command) {
    $output = shell_exec($command . ' 2>&1');
    return $output;
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

/**
 * Send a JSON command to the DotNetManager socket daemon.
 * Returns decoded array on success, null on failure.
 */
function dnm_socket_request($data) {
    $socketPath = '/usr/local/cwpsrv/var/services/user_files/modules/dotnetmanager/control.sock';
    if (!file_exists($socketPath)) {
        return null;
    }
    if (!function_exists('socket_create')) {
        return null;
    }
    $sock = @socket_create(AF_UNIX, SOCK_STREAM, 0);
    if (!$sock) return null;
    if (!@socket_connect($sock, $socketPath)) {
        @socket_close($sock);
        return null;
    }
    $payload = json_encode($data) . "\n";
    socket_write($sock, $payload, strlen($payload));
    socket_shutdown($sock, 1);
    $resp = '';
    while ($buf = @socket_read($sock, 4096)) {
        if ($buf === '') break;
        $resp .= $buf;
    }
    socket_close($sock);
    $decoded = json_decode($resp, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * Fallback to control.sh via sudo if socket is unavailable.
 */
function dnm_control_request($action, $app, $lines = 50) {
    $controlScript = '/usr/local/cwpsrv/var/services/user_files/modules/dotnetmanager/control.sh';
    if (!file_exists($controlScript)) {
        return null;
    }
    $svc = escapeshellarg($app . '.service');
    $cmd = 'sudo ' . escapeshellarg($controlScript) . ' ' . escapeshellarg($action) . ' ' . $svc;
    if ($action === 'logs') {
        $cmd .= ' ' . escapeshellarg((int)$lines);
    }
    $cmd .= ' 2>&1';
    $output = shell_exec($cmd);
    if ($action === 'logs') {
        return ['success' => true, 'logs' => $output];
    }
    // For start/stop/restart, attempt to parse status
    $st = get_service_status($app . '.service');
    return [
        'success' => ($st['active'] === 'active'),
        'status'  => $st['active'],
        'pid'     => $st['pid']
    ];
}

function dnm_action($action, $app) {
    // Try socket daemon first (most secure, no sudo needed)
    $result = dnm_socket_request([
        'action' => $action,
        'app'    => $app
    ]);
    if ($result !== null) {
        // Normalize socket response: 'active' -> 'status'
        if (isset($result['active']) && !isset($result['status'])) {
            $result['status'] = $result['active'];
        }
        return $result;
    }
    // Fallback to control.sh via sudo
    $result = dnm_control_request($action, $app);
    if ($result !== null) {
        return $result;
    }
    // Last resort: direct systemctl (usually fails for unprivileged users)
    $svcName = escapeshellarg($app . '.service');
    if ($action === 'start') {
        shell_exec("systemctl start $svcName");
    } elseif ($action === 'stop') {
        shell_exec("systemctl stop $svcName");
    } elseif ($action === 'restart') {
        shell_exec("systemctl restart $svcName");
    }
    usleep(500000);
    $st = get_service_status($app . '.service');
    return [
        'success' => ($st['active'] === 'active'),
        'status'  => $st['active'],
        'pid'     => $st['pid']
    ];
}

function dnm_logs($app, $lines = 50) {
    // Try socket daemon first
    $result = dnm_socket_request([
        'action' => 'logs',
        'app'    => $app,
        'lines'  => (int)$lines
    ]);
    if ($result !== null && isset($result['success'])) {
        // Normalize socket response: 'output' -> 'logs'
        if (isset($result['output']) && !isset($result['logs'])) {
            $result['logs'] = $result['output'];
        }
        return $result;
    }
    // Fallback to control.sh
    $result = dnm_control_request('logs', $app, $lines);
    if ($result !== null) {
        return $result;
    }
    // Last resort: direct journalctl
    $svcName = escapeshellarg($app . '.service');
    $logs = shell_exec("journalctl -u $svcName --no-pager -n $lines 2>&1");
    return ['success' => true, 'logs' => $logs];
}

$db = new DotNetManagerDB();
$message = '';
$messageType = 'info';

// AJAX handler for actions
if (isset($_GET['acc'])) {
    header('Content-Type: application/json');
    $appName = isset($_POST['app']) ? basename($_POST['app']) : '';
    $app = $appName ? $db->getAppByName($appName) : null;

    if (!$app || $app['username'] !== $currentUser) {
        echo json_encode(['success' => false, 'message' => 'Application not found or access denied.']);
        die;
    }

    switch ($_GET['acc']) {
        case 'start':
            $res = dnm_action('start', $appName);
            if (!empty($res['success'])) {
                $db->logAction($appName, 'START', 'Started by user via panel', $currentUser);
            }
            echo json_encode($res);
            die;
        case 'stop':
            $res = dnm_action('stop', $appName);
            if (!empty($res['success'])) {
                $db->logAction($appName, 'STOP', 'Stopped by user via panel', $currentUser);
            }
            echo json_encode($res);
            die;
        case 'restart':
            $res = dnm_action('restart', $appName);
            if (!empty($res['success'])) {
                $db->logAction($appName, 'RESTART', 'Restarted by user via panel', $currentUser);
            }
            echo json_encode($res);
            die;
        case 'status':
            echo json_encode(dnm_action('status', $appName));
            die;
        case 'logs':
            $lines = isset($_POST['lines']) ? (int)$_POST['lines'] : 50;
            echo json_encode(dnm_logs($appName, $lines));
            die;
    }
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    die;
}

// Normal page load
$apps = $db->getAppsByUser($currentUser);
$appList = [];
foreach ($apps as $app) {
    $st = get_service_status($app['name'] . '.service');
    $appList[] = array_merge($app, [
        'status' => $st['active'],
        'status_sub' => $st['sub'],
        'pid' => $st['pid'],
        'domain_url' => 'https://' . $app['domain']
    ]);
}

$mod['apps'] = $appList;
$mod['app_count'] = count($appList);
$mod['has_apps'] = count($appList) > 0 ? 1 : 0;
$mod['message'] = $message;
$mod['message_type'] = $messageType;
$mod['current_user'] = $currentUser;
$mod['version'] = '2.0';
