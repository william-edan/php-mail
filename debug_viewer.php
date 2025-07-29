<?php
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>调试日志查看器</title>
    <meta charset="utf-8">
    <style>
        body { font-family: monospace; margin: 20px; }
        .log-container { 
            border: 1px solid #ccc; 
            padding: 10px; 
            margin: 10px 0; 
            max-height: 400px; 
            overflow-y: scroll; 
            background: #f5f5f5; 
        }
        .debug { color: #0066cc; }
        .error { color: #cc0000; font-weight: bold; }
        .success { color: #009900; }
        .refresh-btn { 
            padding: 10px 20px; 
            margin: 5px; 
            background: #007cba; 
            color: white; 
            border: none; 
            cursor: pointer; 
        }
        .clear-btn { 
            padding: 10px 20px; 
            margin: 5px; 
            background: #cc0000; 
            color: white; 
            border: none; 
            cursor: pointer; 
        }
    </style>
</head>
<body>
    <h1>邮件系统调试日志查看器</h1>
    
    <button class="refresh-btn" onclick="location.reload()">刷新日志</button>
    <button class="clear-btn" onclick="clearLogs()">清空调试日志</button>
    
    <h2>调试日志 (debug.log)</h2>
    <div class="log-container" id="debug-log">
        <?php
        $debug_file = 'debug.log';
        if (file_exists($debug_file)) {
            $lines = file($debug_file, FILE_IGNORE_NEW_LINES);
            $lines = array_slice($lines, -100); // 只显示最后100行
            foreach ($lines as $line) {
                $class = '';
                if (strpos($line, '[DEBUG]') !== false) $class = 'debug';
                if (strpos($line, '[ERROR]') !== false) $class = 'error';
                if (strpos($line, '[SUCCESS]') !== false) $class = 'success';
                echo "<div class='$class'>" . htmlspecialchars($line) . "</div>";
            }
        } else {
            echo "<div>调试日志文件不存在，请先启动守护进程并发送邮件</div>";
        }
        ?>
    </div>
    
    <h2>今日错误日志 (<?php echo date('Y-m-d'); ?>-error.txt)</h2>
    <div class="log-container" id="error-log">
        <?php
        $error_file = date('Y-m-d') . '-error.txt';
        if (file_exists($error_file)) {
            $lines = file($error_file, FILE_IGNORE_NEW_LINES);
            $lines = array_slice($lines, -50); // 只显示最后50行
            foreach ($lines as $line) {
                echo "<div class='error'>" . htmlspecialchars($line) . "</div>";
            }
        } else {
            echo "<div>今日暂无错误日志</div>";
        }
        ?>
    </div>
    
    <h2>系统状态</h2>
    <div class="log-container">
        <?php
        try {
            $redis = new Redis();
            $redis->connect('127.0.0.1');
            
            echo "<div><strong>任务队列状态：</strong></div>";
            echo "<div>待发送任务数：" . $redis->lLen('task') . "</div>";
            echo "<div>测试任务数：" . $redis->lLen('test-task') . "</div>";
            echo "<div>已处理邮件数：" . ($redis->get('process') ?: 0) . "</div>";
            echo "<div>错误计数：" . ($redis->get('error') ?: 0) . "</div>";
            
            echo "<div><strong>配置状态：</strong></div>";
            echo "<div>SMTP配置数：" . $redis->lLen('smtp-list') . "</div>";
            echo "<div>发件人配置数：" . $redis->lLen('from-list') . "</div>";
            echo "<div>邮件主题数：" . $redis->lLen('title-list') . "</div>";
            echo "<div>邮件模板数：" . $redis->lLen('temp-list') . "</div>";
            echo "<div>当前线程数：" . ($redis->get('thread_count') ?: 10) . "</div>";
            
            $redis->close();
        } catch (Exception $e) {
            echo "<div class='error'>Redis连接失败：" . $e->getMessage() . "</div>";
        }
        ?>
    </div>
    
    <h2>守护进程状态</h2>
    <div class="log-container">
        <?php
        $pid_file = 'send.php.pid';
        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            if ($pid && function_exists('posix_kill') && posix_kill($pid, 0)) {
                echo "<div class='success'>守护进程运行中 (PID: $pid)</div>";
                
                // 计算运行时间
                $start_time = filectime($pid_file);
                $uptime = time() - $start_time;
                $hours = floor($uptime / 3600);
                $minutes = floor(($uptime % 3600) / 60);
                $seconds = $uptime % 60;
                echo "<div>运行时间：{$hours}小时 {$minutes}分钟 {$seconds}秒</div>";
            } else {
                echo "<div class='error'>守护进程未运行 (PID文件存在但进程不存在)</div>";
            }
        } else {
            echo "<div class='error'>守护进程未启动 (PID文件不存在)</div>";
        }
        ?>
    </div>

    <script>
        function clearLogs() {
            if (confirm('确定要清空调试日志吗？')) {
                fetch('debug_viewer.php?action=clear', {method: 'POST'})
                .then(() => location.reload());
            }
        }
        
        // 自动刷新
        setInterval(() => {
            location.reload();
        }, 10000); // 每10秒自动刷新
    </script>
</body>
</html>

<?php
// 处理清空日志请求
if (isset($_GET['action']) && $_GET['action'] === 'clear') {
    if (file_exists('debug.log')) {
        file_put_contents('debug.log', '');
    }
    exit('日志已清空');
}
?> 