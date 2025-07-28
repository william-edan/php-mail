<?php
// 测试占位符替换功能
require_once __DIR__ . '/vendor/autoload.php';

$redis = new Redis();
$redis->connect('127.0.0.1');

// 测试数据
$test_text = "你好，这是一个测试邮件，域名是：{var_域名}，公司是：{var_公司}";

echo "原始文本: " . $test_text . "\n";

// 应用占位符替换
$result = replacePlaceholders($test_text, $redis);

echo "替换后: " . $result . "\n";

function replacePlaceholders($text, $redis) {
    // 匹配 {var_xxx} 格式的占位符
    if (preg_match_all('/\{var_([a-zA-Z0-9_]+)\}/', $text, $matches)) {
        foreach ($matches[1] as $placeholder) {
            $key = 'var_' . $placeholder;
            $list_length = $redis->lLen($key);
            echo "检查key: $key, 长度: $list_length\n";
            if ($list_length > 0) {
                // 随机获取一个值
                $random_index = rand(0, $list_length - 1);
                $value = $redis->lIndex($key, $random_index);
                echo "随机选择索引: $random_index, 值: $value\n";
                if ($value !== false) {
                    $text = str_replace('{var_'.$placeholder.'}', $value, $text);
                }
            }
        }
    }
    return $text;
}
?> 