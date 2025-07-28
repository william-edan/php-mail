<?php
// 关闭所有输出缓冲
while (ob_get_level()) {
    ob_end_clean();
}

// 禁用错误输出到浏览器
ini_set('display_errors', 0);
error_reporting(0);

// 设置正确的响应头
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// 确保没有BOM或其他字符
if (ob_get_level()) ob_end_clean();

try {
    $redis = new Redis();
    $redis->connect('127.0.0.1');

    // 查询所有变量（只查询var_前缀的变量）
    if (isset($_GET['all']) && $_GET['all'] == '1') {
        $keys = $redis->keys('var_*');
        $result = [];
        foreach ($keys as $key) {
            $type = $redis->type($key);
            if ($type == Redis::REDIS_LIST) {
                $data = $redis->lRange($key, 0, -1);
                $result[$key] = $data;
            }
        }
        $json = json_encode($result, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            echo json_encode(['error' => 'JSON编码失败']);
        } else {
            echo $json;
        }
        exit;
    }

    // 根据key搜索
    $search_key = $_GET['key'] ?? '';
    if ($search_key) {
        $keys = $redis->keys('*' . $search_key . '*');
        $result = [];
        foreach ($keys as $key) {
            $type = $redis->type($key);
            if ($type == Redis::REDIS_LIST) {
                $data = $redis->lRange($key, 0, -1);
                $result[$key] = $data;
            }
        }
        $json = json_encode($result, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            echo json_encode(['error' => 'JSON编码失败']);
        } else {
            echo $json;
        }
        exit;
    }

    echo json_encode([]);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
} 