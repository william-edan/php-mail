<?php
header('Content-Type: application/json; charset=utf-8');

$key = $_GET['key'];
if(empty($key)||$key!='798'){
    http_response_code(404);
    exit();
}

$pid_file = 'send.php.pid';
$status = [
    'daemon_running' => false,
    'daemon_pid' => null,
    'daemon_status' => '已停止',
    'daemon_class' => 'text-danger',
    'daemon_uptime' => '未运行'
];

if (file_exists($pid_file)) {
    $pid = trim(file_get_contents($pid_file));
    if ($pid && function_exists('posix_kill') && posix_kill($pid, 0)) {
        $status['daemon_running'] = true;
        $status['daemon_pid'] = $pid;
        $status['daemon_status'] = '运行中 (PID: ' . $pid . ')';
        $status['daemon_class'] = 'text-success';
        
        // 计算运行时间
        $start_time = filectime($pid_file);
        $uptime_seconds = time() - $start_time;
        $hours = floor($uptime_seconds / 3600);
        $minutes = floor(($uptime_seconds % 3600) / 60);
        $seconds = $uptime_seconds % 60;
        $status['daemon_uptime'] = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }
}

echo json_encode($status, JSON_UNESCAPED_UNICODE);
?> 