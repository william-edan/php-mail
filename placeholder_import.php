<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = $_POST['key'] ?? '';
    if (!$key) {
        echo '请填写变量key';
        exit;
    }
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo '文件上传失败';
        exit;
    }
    $redis = new Redis();
    $redis->connect('127.0.0.1');
    $lines = file($_FILES['file']['tmp_name']);
    $redis->del($key); // 清空原有
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line !== '') {
            $redis->rPush($key, $line);
        }
    }
    echo '导入成功，共导入' . $redis->lLen($key) . '条数据';
    exit;
}
?> 