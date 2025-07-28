<?php
// 测试placeholder_view.php的功能
echo "测试开始...\n";

// 测试查询所有变量
$url = 'http://localhost/placeholder_view.php?all=1';
echo "请求URL: $url\n";

$response = file_get_contents($url);
echo "原始响应: " . $response . "\n";

if ($response !== false) {
    $decoded = json_decode($response, true);
    if ($decoded !== null) {
        echo "JSON解析成功:\n";
        print_r($decoded);
    } else {
        echo "JSON解析失败: " . json_last_error_msg() . "\n";
    }
} else {
    echo "请求失败\n";
}
?> 