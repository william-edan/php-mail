<?php
require_once __DIR__ . '/vendor/autoload.php';


use Channel\Client;
use Channel\Server;
use PhpImap\Mailbox;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use Workerman\Connection\TcpConnection;
use Workerman\Lib\Timer;
use Workerman\Worker;


Worker::$daemonize = true;
Worker::$pidFile = basename(__FILE__) . '.pid';
Worker::$logFile = 'workerman.log';

new Server('0.0.0.0');
$commander = new Worker('http://0.0.0.0:2207');
$commander->name = '$commander';
$commander->onWorkerStart = function () {
    Client::connect('127.0.0.1');
};
//命令接口，暴露 给外部调用
$commander->onMessage = function (TcpConnection $conn, $request) {
    if (preg_match('/\/([^?]+)/', $request->uri(), $matches)) {
        $data = array_merge($request->get(), $request->post());
        Client::publish($matches[1], $data);
        $conn->send('Command sent');
    } else {
        $conn->send('Invalid request');
    }
    $conn->close();
};


$worker = new Worker();
$redis_for_config = new \Redis();
$redis_for_config->connect('127.0.0.1');
$thread_count = $redis_for_config->get('thread_count') ?: 10;
$worker->count = intval($thread_count);
$redis_for_config->close();
$worker->onWorkerStart = function (Worker $worker) {
    $redis = new \Redis();
    $redis->connect('127.0.0.1');
    $env = parse_ini_file('.env', true);
    $error_num = 0;
    $warm_emails = explode(",",$env['warm_emails']);
    $warm_email_count = count($warm_emails);
    $check_email = $env['check_email'];
    $run_warm_email = $env['run_warm_email'];
//    $from_name_str = $env['from_name_str'];
    $from_address_list_str = $env['from_address_list'];
    $from_address_index = $env['from_address_index'];
    $test_interval = intval($env['test_interval']);
    $stop_process = intval($env['stop_process']);
    $smtp_count = $redis->lLen('smtp');

    while (true) {
        if (!file_get_contents('status.txt') && $redis->lLen('test')==0) {
            sleep(10);
            continue;
        }
        $task_email = $redis->lPop('test');
        file_put_contents('debug.log',
            date('Y-m-d H:i:s') . " [DEBUG] 获取任务: " . $task_email . PHP_EOL,
            FILE_APPEND);
        if(empty($task_email)){
            $task_email = $redis->lPop('task');
        }
        if (empty($task_email) && file_get_contents('status.txt')) {
            file_put_contents('status.txt' , '');
            sleep(10);
            continue;
        }

        // 调试日志：开始处理邮件
        file_put_contents('debug.log', 
            date('Y-m-d H:i:s') . " [DEBUG] 开始处理邮件: " . $task_email . PHP_EOL, 
            FILE_APPEND);

        try {
            $start_num = $start_num + 1;
            $redis->incr('process');

            // 获取SMTP配置
            $smtp_list = $redis->lRange('smtp-list', 0, -1);
            if (empty($smtp_list)) {
                file_put_contents('debug.log', 
                    date('Y-m-d H:i:s') . " [ERROR] SMTP列表为空" . PHP_EOL, 
                    FILE_APPEND);
                continue;
            }

            $smtp_index = $redis->get('smtp_index') ?: 0;
            $smtp_config = $smtp_list[$smtp_index % count($smtp_list)];
            $smtp_parts = explode('|', $smtp_config);

            if (count($smtp_parts) < 5) {
                file_put_contents('debug.log', 
                    date('Y-m-d H:i:s') . " [ERROR] SMTP配置格式错误: " . $smtp_config . PHP_EOL, 
                    FILE_APPEND);
                continue;
            }

            list($smtp_host, $smtp_port, $smtp_account, $smtp_password, $smtp_secure) = $smtp_parts;

            // 调试日志：SMTP配置
            file_put_contents('debug.log', 
                date('Y-m-d H:i:s') . " [DEBUG] 使用SMTP: " . $smtp_host . ":" . $smtp_port . " 账号: " . $smtp_account . PHP_EOL, 
                FILE_APPEND);

            // 获取发件人信息
            $from_list = $redis->lRange('from-list', 0, -1);
            if (empty($from_list)) {
                file_put_contents('debug.log', 
                    date('Y-m-d H:i:s') . " [ERROR] 发件人列表为空" . PHP_EOL, 
                    FILE_APPEND);
                continue;
            }

            $from_index = $redis->get('from_index') ?: 0;
            $from_item = $from_list[$from_index % count($from_list)];
            $from_parts = explode('|', $from_item);
            
            if (count($from_parts) >= 2) {
                $from_address = $from_parts[0];
                $from_name = $from_parts[1];
            } else {
                $from_address = $from_parts[0];
                $from_name = $from_parts[0];
            }

            // 应用占位符替换
            $from_name = replacePlaceholders($from_name, $redis);

            // 调试日志：发件人信息
            file_put_contents('debug.log', 
                date('Y-m-d H:i:s') . " [DEBUG] 发件人: " . $from_address . " (" . $from_name . ")" . PHP_EOL, 
                FILE_APPEND);

            // 获取邮件主题
            $title_list = $redis->lRange('title-list', 0, -1);
            if (empty($title_list)) {
                file_put_contents('debug.log', 
                    date('Y-m-d H:i:s') . " [ERROR] 主题列表为空" . PHP_EOL, 
                    FILE_APPEND);
                continue;
            }

            $title_index = rand(0, count($title_list) - 1);
            $curr_title = $title_list[$title_index];
            $title = replacePlaceholders($curr_title, $redis);

            // 调试日志：邮件主题
            file_put_contents('debug.log', 
                date('Y-m-d H:i:s') . " [DEBUG] 邮件主题: " . $title . PHP_EOL, 
                FILE_APPEND);

            // 获取邮件模板
            $temp_list = $redis->lRange('temp-list', 0, -1);
            if (empty($temp_list)) {
                file_put_contents('debug.log', 
                    date('Y-m-d H:i:s') . " [ERROR] 模板列表为空" . PHP_EOL, 
                    FILE_APPEND);
                continue;
            }

            $temp_index = rand(0, count($temp_list) - 1);
            $temp_content = $temp_list[$temp_index];
            $template = replacePlaceholders($temp_content, $redis);

            // 调试日志：模板信息
            file_put_contents('debug.log', 
                date('Y-m-d H:i:s') . " [DEBUG] 使用模板索引: " . $temp_index . " 模板长度: " . strlen($template) . PHP_EOL, 
                FILE_APPEND);

            // 创建PHPMailer实例
            $mail = new PHPMailer(true);

            // 调试日志：开始配置SMTP
            file_put_contents('debug.log', 
                date('Y-m-d H:i:s') . " [DEBUG] 开始配置SMTP连接" . PHP_EOL, 
                FILE_APPEND);

            // SMTP配置
            $mail->isSMTP();
            $mail->Host = $smtp_host;
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_account;
            $mail->Password = $smtp_password;
            $mail->Port = $smtp_port;
            
            if (strtolower($smtp_secure) === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif (strtolower($smtp_secure) === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            $mail->CharSet = 'UTF-8';
            $mail->setFrom($from_address, $from_name);
            $mail->addAddress($task_email);
            $mail->Subject = $title;

            // 判断是否为HTML内容
            if (strpos($template, '<html>') !== false || strpos($template, '<HTML>') !== false) {
                $mail->isHTML(true);
                $mail->Body = $template;
            } else {
                $mail->isHTML(false);
                $mail->Body = $template;
            }

            // 调试日志：开始发送邮件
            file_put_contents('debug.log', 
                date('Y-m-d H:i:s') . " [DEBUG] 开始发送邮件到: " . $task_email . PHP_EOL, 
                FILE_APPEND);

            // 发送邮件
            $send_result = $mail->send();

            // 调试日志：发送结果
            file_put_contents('debug.log', 
                date('Y-m-d H:i:s') . " [SUCCESS] 邮件发送成功到: " . $task_email . " 结果: " . ($send_result ? 'true' : 'false') . PHP_EOL, 
                FILE_APPEND);

            // 发送成功后的处理
            $redis->set('smtp_index', ($smtp_index + 1) % count($smtp_list));
            $redis->set('from_index', ($from_index + 1) % count($from_list));

            // 更新SMTP发送计数
            $smtp_key = 'smtp_send_count_' . md5($smtp_config);
            $redis->incr($smtp_key);

            // 实现发送间隔
            if (isset($env['send_interval_min']) && isset($env['send_interval_max'])) {
                $min_interval = intval($env['send_interval_min']) * 1000; // 转换为微秒
                $max_interval = intval($env['send_interval_max']) * 1000;
                $interval = rand($min_interval, $max_interval);
                
                file_put_contents('debug.log', 
                    date('Y-m-d H:i:s') . " [DEBUG] 发送间隔: " . ($interval/1000) . "毫秒" . PHP_EOL, 
                    FILE_APPEND);
                
                usleep($interval);
            }

            // 自动冷却检查
            if (isset($env['enable_auto_cooling']) && $env['enable_auto_cooling'] === 'true') {
                $current_count = $redis->get($smtp_key) ?: 0;
                $threshold = intval($env['cooling_threshold'] ?? 50);
                
                if ($current_count >= $threshold) {
                    $cooling_duration = intval($env['cooling_duration'] ?? 300);
                    
                    file_put_contents('debug.log', 
                        date('Y-m-d H:i:s') . " [DEBUG] 触发自动冷却，SMTP: " . $smtp_host . " 发送数: " . $current_count . " 冷却: " . $cooling_duration . "秒" . PHP_EOL, 
                        FILE_APPEND);
                    
                    sleep($cooling_duration);
                    $redis->del($smtp_key); // 重置计数
                }
            }

            // 自动预热检查
            if (isset($env['auto_warm_enabled']) && $env['auto_warm_enabled'] === 'true') {
                $current_sent = $redis->get('process') ?: 0;
                if ($current_sent > 0 && $current_sent % intval($env['auto_warm_interval']) == 0) {
                    executeWarmup($redis, $env);
                    file_put_contents('debug.log',
                        date('Y-m-d H:i:s') . " [DEBUG] 自动预热触发，已发送: {$current_sent}封" . PHP_EOL,
                        FILE_APPEND);
                }
            }
        } catch (Exception $e) {
            $error_num = $error_num + 1;
            $errorMsg = $e->getMessage();
            
            // 详细的错误日志
            file_put_contents('debug.log', 
                date('Y-m-d H:i:s') . " [ERROR] 发送失败到: " . $task_email . " 错误: " . $errorMsg . PHP_EOL, 
                FILE_APPEND);
            
            file_put_contents(date('Y-m-d') . '-error.txt', date('Y-m-d H:i:s') . '|' . $smtp_host.'|' . $smtp_port.'|' . $smtp_account . '|' . $smtp_password.'|' . $smtp_secure.'|' . $from_address.'|' . $from_name . '|' . $task_email . '|' . $errorMsg . PHP_EOL, FILE_APPEND);
            $redis->incr('error');
        }
        if ($error_num > 30) {
            file_put_contents('status.txt', '');
            file_put_contents(date('Y-m-d') . '-error.txt', date('Y-m-d H:i:s') . '|error count>10|' . $errorMsg . PHP_EOL, FILE_APPEND);
        }
        if($start_num>0 && $start_num>=$stop_process){
            file_put_contents('status.txt', '');
        }
        if($start_num>0 && $start_num%$test_interval==0){
            $redis->rPush('test',$check_email);
            // $redis->rPush('test',$run_warm_email);
            // $redis->incr('test-smtp-index');
            // $check_email_from = $check_email.'--*--'.sprintf($from_temp_str, $from_index);
            // $redis->rPush('test',$check_email_from);
        }
    }
};

//自动测试启动
$auto_test = new Worker();
$auto_test->name = '$auto_test';
$auto_test->onWorkerStart = function () {
    Client::connect('127.0.0.1');
    Client::on('start_auto', function () {
        $redis = new \Redis();
        $redis->connect('127.0.0.1');
        $env = parse_ini_file('.env', true);

        $email_file = $env['email_list'];
        $max_queue_size = intval($env['queue_buffer_size']) ?: 1000;
        $file_handle = null;
        $file_position = 0;

        while (true) {
            // ✅ 检查是否应该开始读取文件
            $should_feed = file_get_contents('status.txt') && file_exists($email_file);

            if (!$should_feed) {
                // 未启动或文件不存在，等待
                if ($file_handle) {
                    fclose($file_handle);
                    $file_handle = null;
                }
                sleep(5);
                continue;
            }

            // ✅ 打开文件句柄（如果尚未打开）
            if (!$file_handle) {
                $file_handle = fopen($email_file, 'r');
                // 恢复上次读取位置
                $saved_position = $redis->get('email_file_position') ?: 0;
                fseek($file_handle, $saved_position);
                $file_position = $saved_position;

                // 记录开始时间
                $redis->set('feeding_start_time', time());
            }

            $current_queue_size = $redis->lLen('task');

            if ($current_queue_size < $max_queue_size) {
                $emails_to_add = $max_queue_size - $current_queue_size;
                $added_count = 0;

                for ($i = 0; $i < $emails_to_add; $i++) {
                    $email = fgets($file_handle);
                    if ($email === false) {
                        // ✅ 文件读取完毕
                        $redis->set('file_reading_completed', 1);
                        $redis->set('feeding_end_time', time());
                        break;
                    }

                    $email = trim($email);
                    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $redis->rPush('task', $email);
                        $added_count++;
                    }
                }

                // ✅ 保存文件读取位置
                if ($file_handle) {
                    $file_position = ftell($file_handle);
                    $redis->set('email_file_position', $file_position);
                }

                // ✅ 更新统计信息
                if ($added_count > 0) {
                    $total_loaded = $redis->incr('total_emails_loaded', $added_count);
                    $redis->set('last_feed_time', time());
                }
            }

            sleep(1); // 每秒检查一次
        }

        if ($file_handle) {
            fclose($file_handle);
        }
    });



};

$start_a_test = new Worker();
$start_a_test->name = '$start_a_test';
$start_a_test->onWorkerStart = function () {
    Client::connect('127.0.0.1');
    Client::on('start_a_test_ab9197713', function () {
        $redis = new \Redis();
        $redis->connect('127.0.0.1');
        $env = parse_ini_file('.env', true);
        $check_email = $env['check_email'];
        $smtp_count = $redis->lLen('smtp');
        for($i=0;$i<$smtp_count;$i++){
            $redis->rPush('test',$check_email);
        }
    });
};



//预热
$start_a_warm = new Worker();
$start_a_warm->name = '$start_a_warm';
$start_a_warm->onWorkerStart = function () {
    Client::connect('127.0.0.1');
    Client::on('start_a_warm_ab5253234', function () {
        $redis = new \Redis();
        $redis->connect('127.0.0.1');
        $env = parse_ini_file('.env', true);
        executeWarmup($redis,$env);
    });
};




// function encrypt($data)
// {
//     if (empty($data)) return null;
//     $env = parse_ini_file('.env', true);
//     $key = $env['encrypt_key'];
//     return bin2hex(openssl_encrypt($data, 'AES-128-ECB', $key, OPENSSL_RAW_DATA));
// }

function template2content($template, $email)
{

    $env = parse_ini_file('.env', true);
    // $redirect = 'https://a' . session_create_id() .'.'.$env['redirect'] . '/go?id=' . encrypt($email);
    $redirect='';
    $redirect_list = explode(',',$env['redirect']);
    $redirect_key=array_rand($redirect_list);
    $redirect = $redirect_list[$redirect_key];
    $redirect = $env['redirect'];
    $receive_name = explode('@',$email)[0];
    $content = $template;
    $content = preg_replace('/\{\$redirect}/', $redirect, $template);
    $random_count = substr_count($content,'{$random_char}');
    for ($i=1; $i<=$random_count; $i++)
    {
        $content = preg_replace('/\{\$random_char}/', random_char(rand(4, 8)), $content, 1);
    }
    $content = preg_replace('/\{\$addr}/', $email, $content);
    $content = preg_replace('/\{\$rand}/', random_char(6), $content);
    $content = preg_replace('/\{\$random}/', random_char(rand(4, 10)), $content);
    $content = preg_replace('/\{\$class}/', random_char(6), $content);
    $content = preg_replace('/\{\$email}/', $email, $content);
    $content = preg_replace('/\{\$receive_name}/', $receive_name, $content);
    $content = preg_replace('/\{\$hide_tag}/', hide_tag(), $content);
    $content = preg_replace('/\{\$id}/', random_char(6), $content);
    $content = preg_replace('/\{\$today}/', date("Y年m月d日"), $content);
    $content = preg_replace('/\{\$date_time}/', date('Y-m-d H:i:s'), $content);
    $content = preg_replace('/\{\$now}/', date('Y-m-d H:i:s'), $content);
    $content = preg_replace('/\{\$rand_ip}/', randip(), $content);
    return $content;
}

function random_char($length = 8)
{
    $chars = 'abcdefghijklmnopqrstuvwxyz';
    $str = '';
    for ($i = 0; $i < $length; $i++) {
        $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
    }
    // $str = $str . rand(10, 99);
    return $str;
}



function hide_tag()
{
    $num = rand(1, 3);
    $item = '';
    $chars = 'abcdefghijklmnopqrstuvwxyz';
    for ($i = 0; $i < $num; $i++) {
        $words = substr($chars, mt_rand(0, strlen($chars) - 1), 1) . rand(0, 9) . substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        $item .= '<' . $words . '>';
        $item .= '</' . $words . '>';
    }
    return $item;
}

function randip()
{
    $ip_1 = -1;
    $ip_2 = -1;
    $ip_3 = rand(0,255);
    $ip_4 = rand(0,255);
    $ipall = array(
        array(array(58,14),array(58,25)),
        array(array(58,30),array(58,63)),
        array(array(58,66),array(58,67)),
        array(array(60,200),array(60,204)),
        array(array(60,160),array(60,191)),
        array(array(60,208),array(60,223)),
        array(array(117,48),array(117,51)),
        array(array(117,57),array(117,57)),
        array(array(121,8),array(121,29)),
        array(array(121,192),array(121,199)),
        array(array(123,144),array(123,149)),
        array(array(124,112),array(124,119)),
        array(array(125,64),array(125,98)),
        array(array(222,128),array(222,143)),
        array(array(222,160),array(222,163)),
        array(array(220,248),array(220,252)),
        array(array(211,163),array(211,163)),
        array(array(210,21),array(210,22)),
        array(array(125,32),array(125,47))
    );
    $ip_p = rand(0,count($ipall)-1);#随机生成需要IP段
    $ip_1 = $ipall[$ip_p][0][0];
    if($ipall[$ip_p][0][1] == $ipall[$ip_p][1][1]){
        $ip_2 = $ipall[$ip_p][0][1];
    }else{
        $ip_2 = rand(intval($ipall[$ip_p][0][1]),intval($ipall[$ip_p][1][1]));
    }
    $member = null;
    $ipall  = null;
    return $ip_1.'.'.$ip_2.'.'.$ip_3.'.'.$ip_4;
}

function setEnvVal($env_path, $key, $val) {
    //获取数据
    $env_content = @file($env_path);
    $env_data = preg_grep('/^#' . $key . '=|^' . $key . '=/', $env_content);
    $old_value = $env_data ? preg_replace('/\r|\n/', '', array_shift($env_data)) : '';

    //写入数据
    $new_data = $key . '=' . $val;
    if($old_value) {
        $regex = '/^' . preg_quote($old_value, '/') . '/m';
        return (bool) @file_put_contents($env_path, preg_replace($regex, $new_data, implode($env_content, '')));
    }

    return (bool) @file_put_contents($env_path, PHP_EOL . $new_data, FILE_APPEND);
}

function replacePlaceholders($text, $redis) {
    // 匹配 {xxx} 格式的占位符
    if (preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $text, $matches)) {
        foreach ($matches[1] as $placeholder) {
            $key = $placeholder;
            $list_length = $redis->lLen($key);
            if ($list_length > 0) {
                $random_index = rand(0, $list_length - 1);
                $value = $redis->lIndex($key, $random_index);
                if ($value !== false) {
                    $text = str_replace('{var_'.$placeholder.'}', $value, $text);
                }
            }
        }
    }
    return $text;
}

function executeWarmup($redis, $env) {
    $warm_emails_file = $env['warm_emails_file'];
    if (!file_exists($warm_emails_file)) {
        return ['code'=>1, 'msg'=>'warm emails file not found'];
    }

    // 读取预热邮箱列表
    $warm_emails = file($warm_emails_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $warm_email_count = count($warm_emails);
    $smtp_count = $redis->lLen('smtp');
    $warm_per_smtp = intval($env['warm_per_smtp']) ?: 1;

    // 计算预热邮件总数
    $total_warm_emails = $smtp_count * $warm_per_smtp * $warm_email_count;

    // 为每个SMTP分配预热任务
    for ($smtp_index = 0; $smtp_index < $smtp_count; $smtp_index++) {
        foreach ($warm_emails as $warm_email) {
            for ($i = 0; $i < $warm_per_smtp; $i++) {
                // 标记为预热邮件，包含SMTP索引信息
                $warm_task = $warm_email . '|||warm|||' . $smtp_index;
                $redis->rPush('test', $warm_task);
            }
        }
    }

    return ['code'=>0, 'msg'=>"预热任务已添加: {$total_warm_emails}封邮件"];
}





Worker::runAll();