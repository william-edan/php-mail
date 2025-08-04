<?php
// 设置内部编码为UTF-8
mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');

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
$worker->count = 10;
$worker->onWorkerStart = function (Worker $worker) {
    $redis = new \Redis();
    $redis->connect('127.0.0.1');
    $env = parse_ini_file('.env', true);
    $error_num = 0;
    $warm_emails = explode(",",$env['warm_emails']);
    $warm_email_count = count($warm_emails);
    $check_email = $env['check_email'];
    $run_warm_email = $env['run_warm_email'];
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
        if(empty($task_email)){
            $task_email = $redis->lPop('task');
        }

        if (empty($task_email) && file_get_contents('status.txt')) {
            file_put_contents('status.txt' , '');
            sleep(10);
            continue;
        }
        $is_warm_flag = false;
        if(in_array($task_email,$warm_emails)){
            $is_warm_flag = true;
        }
        if($is_warm_flag){
            $smtp_incr_index = $redis->incr('test-smtp-index')-1;
        }else{
            $start_num = $redis->incr('process');
            $smtp_incr_index = $redis->incr('smtp-index')-1;
        }
        $smtp_index = $smtp_incr_index % $redis->lLen('smtp');
        $smtp_item_str = $redis->lIndex('smtp',$smtp_index);
        
        // 解析SMTP配置：host----port----account----password----secure----from_address
        $smtp_parts = explode('----', $smtp_item_str);
        
        if (count($smtp_parts) >= 6) {
            // 新格式：包含端口和安全设置
            list($smtp_host, $smtp_port, $smtp_account, $smtp_password, $smtp_secure, $smtp_from_str) = $smtp_parts;
            $smtp_port = intval($smtp_port); // 确保是整数
        } elseif (count($smtp_parts) >= 4) {
            // 旧格式兼容：host----account----password----from_address
            list($smtp_host, $smtp_account, $smtp_password, $smtp_from_str) = $smtp_parts;
            // 使用默认值
            $smtp_port = 2525;
            $smtp_secure = '';
        } else {
            // 配置格式错误
            file_put_contents('debug.log',
                date('Y-m-d H:i:s') . " [ERROR] SMTP配置格式错误: " . $smtp_item_str . PHP_EOL,
                FILE_APPEND);
            continue;
        }
        
        // 验证端口号
        if ($smtp_port <= 0 || $smtp_port > 65535) {
            $smtp_port = 2525; // 默认端口

        }

        $from_address = sprintf($from_address_list_str,$from_address_index);
        $from_address_domain = explode('@', $from_address)[1];
        //title,template从列表中按循环取
// 获取 Redis 列表长度
        $title_len = $redis->lLen('title-list');
        $template_len = $redis->lLen('temp-list');
        $from_len = $redis->lLen('from-list');

// 随机获取标题
        if ($title_len > 0) {
            $title_index = rand(0, $title_len - 1);
            $title = $redis->lIndex('title-list', $title_index);
            $title = replacePlaceholders($title, $redis);
        } else {
            $title = '默认标题';
        }

// 随机获取模板内容
        if ($template_len > 0) {
            $template_index = rand(0, $template_len - 1);
            $template = $redis->lIndex('temp-list', $template_index);
            $content = template2content($template, $task_email);
            $content = replacePlaceholders($content, $redis);
        } else {
            $content = '默认内容';
        }

// 随机获取发件人名称
        if ($from_len > 0) {
            $from_index = rand(0, $from_len - 1);
            $from_name = $redis->lIndex('from-list', $from_index);
            $from_name = replacePlaceholders($from_name, $redis);
        } else {
            $from_name = '默认发件人';
        }

        $task_email_name = explode('@', $task_email)[0];

        $message_id = sprintf('<%s-%s@%s>', random_char(8), random_char(8), random_char(8));

        $from_address = $smtp_from_str;
        $mailer = new PHPMailer(true);
        
        // 获取客户端模拟配置
        $client_type = $env['client_simulation'] ?? 'random';
        $client_config = getClientSimulation($client_type);
        
        // 获取字符集配置
        $charset_type = $env['charset_type'] ?? 'utf8';  // 默认UTF-8，但不强制
        $charset_config = getCharsetConfig($charset_type);
        
        // 新功能配置
        $enable_rfc2047_subject = $env['enable_rfc2047_subject'] ?? 'true';
        $enable_multipart_alternative = $env['enable_multipart_alternative'] ?? 'true';
        $subject_encoding_type = $env['subject_encoding_type'] ?? 'B';  // B=Base64, Q=Quoted-Printable
        
        try {
            // 临时启用详细调试
            $mailer->SMTPDebug = 3;  // 0=关闭, 1=客户端, 2=客户端+服务器, 3=详细
            $mailer->Debugoutput = function($str, $level) {
                file_put_contents('smtp_debug.log', 
                    date('Y-m-d H:i:s') . " [LEVEL{$level}] " . trim($str) . PHP_EOL, 
                    FILE_APPEND);
            };
            $mailer->isSMTP();
            $mailer->SMTPAuth = true;
            $mailer->Host = $smtp_host;
            $mailer->Port = $smtp_port;
            $mailer->Username = $smtp_account;
            $mailer->Password = $smtp_password;
            
            // 简单协议设置：465端口用明文，其他端口按配置
            if ($smtp_port == 465) {
                // 465端口使用明文（禁用自动STARTTLS）
                $mailer->SMTPAutoTLS = false;  // 关键：禁用自动TLS协商
            } else {
                // 其他端口：按配置的协议
                if (!empty($smtp_secure)) {
                    if ($smtp_secure == 'tls') {
                        $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    } else {
                        $mailer->SMTPSecure = $smtp_secure;
                    }
                    
                    $mailer->SMTPOptions = [
                        'ssl' => [
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                            'allow_self_signed' => true
                        ]
                    ];

                }
            }
            // 增加超时时间
            $mailer->Timeout = 60;  // 60秒超时
            
            // 应用字符集和编码配置
            $mailer->CharSet = $charset_config['charset'];
            $mailer->Encoding = $charset_config['encoding'];
            
            // 生成随机Message-ID
            $domain = explode('@', $from_address)[1] ?? 'example.com';
            $mailer->MessageID = generateMessageId($domain);
            
            // 确保发件人名称正确编码
            $from_name = mb_convert_encoding($from_name, $charset_config['charset'], 'auto');
            $encoded_from_name = mb_encode_mimeheader($from_name, $charset_config['charset'], 'B');
            $mailer->setFrom($from_address, $encoded_from_name);
            $mailer->Sender = $from_address;
            
            // 客户端模拟配置
            $mailer->Hostname = random_char(8) . '.' . $domain;
            $mailer->XMailer = $client_config['x_mailer'];
            $mailer->Priority = intval($client_config['x_priority']);
            
            // 添加自定义邮件头模拟真实客户端
            $mailer->addCustomHeader('User-Agent', $client_config['user_agent']);
            $mailer->addCustomHeader('X-Priority', $client_config['x_priority']);
            $mailer->addCustomHeader('X-MSMail-Priority', 'Normal');
            $mailer->addCustomHeader('X-MimeOLE', 'Produced By Microsoft MimeOLE V6.00.2900.2180');
            $mailer->addAddress($task_email);
            
            // 确保内容和标题编码正确
            $content = mb_convert_encoding($content, $charset_config['charset'], 'auto');
            $title = mb_convert_encoding($title, $charset_config['charset'], 'auto');
            
            // 编码邮件主题
            $encodedSubject = encodeSubjectRFC2047($title, $charset_config['charset'], $subject_encoding_type);
            $mailer->Subject = $encodedSubject;
            // ===== 新功能2: multipart/alternative 邮件结构 =====
            
            // 设置为HTML邮件
            $mailer->isHTML(true);
            $mailer->Body = $content;  // HTML版本（保留链接）

            // 生成纯文本版本（移除链接）
            $plainTextContent = htmlToPlainNoLink($content);
            $mailer->AltBody = $plainTextContent;  // 纯文本版本

            // 检查收件人是否为空
            if (empty($task_email) || trim($task_email) === '') {
                throw new Exception("收件人邮箱地址为空");
            }
            
            // 记录发送详情
            $protocol_used = $smtp_port == 465 ? '明文' : ($smtp_secure ?: '明文');
            $log_content = date('Y-m-d H:i:s') . " [SEND] 准备发送邮件" . PHP_EOL .
                "  服务器: {$smtp_host}:{$smtp_port} ({$protocol_used})" . PHP_EOL .
                "  发件人: {$from_address}" . PHP_EOL .
                "  收件人: {$task_email}" . PHP_EOL .
                "  标题: {$title}" . PHP_EOL .
                "  内容长度: " . mb_strlen($content) . " 字符" . PHP_EOL .
                "  内容预览: " . mb_substr(strip_tags($content), 0, 100) . "..." . PHP_EOL;
            
            // 确保日志内容编码正确
            $log_content = mb_convert_encoding($log_content, $charset_config['charset'], 'auto');
            file_put_contents('debug.log', $log_content, FILE_APPEND | LOCK_EX);
            
            // 简单发送，不重试
            $mailer->send();
            $error_num = 0;
            
            // 记录成功信息
            $success_log = date('Y-m-d H:i:s') . " [SUCCESS] 邮件发送成功到 {$task_email}" . PHP_EOL;
            $success_log = mb_convert_encoding($success_log, $charset_config['charset'], 'auto');
            file_put_contents('debug.log', $success_log, FILE_APPEND | LOCK_EX);
            
            $mailer->smtpClose();


        } catch (Exception $e) {
            $error_num = $error_num + 1;
            $errorMsg = $e->getMessage();
            
            // 记录错误和分析
            $current_protocol = $smtp_port == 465 ? '明文' : ($smtp_secure ?: '明文');
            $error_analysis = analyzeSmtpError($errorMsg, $smtp_host, $smtp_port, $smtp_secure);
            
            // 原始错误记录
            $raw_error_log = date('Y-m-d H:i:s') . '|' . $smtp_host.'|' .
                $smtp_port.'|' . $smtp_account . '|' . $smtp_password.'|' . $smtp_secure.'|' . $from_address.'|' . $from_name . '|' .
                $task_email . '|'. $content.'|' . $title.'|' . $errorMsg . PHP_EOL;
            $raw_error_log = mb_convert_encoding($raw_error_log, $charset_config['charset'], 'auto');
            file_put_contents(date('Y-m-d') . '-error.txt', $raw_error_log, FILE_APPEND | LOCK_EX);
            
            // 详细错误信息记录
            $error_log = date('Y-m-d H:i:s') . " [ERROR] 邮件发送失败" . PHP_EOL .
                "  服务器: {$smtp_host}:{$smtp_port} ({$current_protocol})" . PHP_EOL .
                "  发件人: {$from_address}" . PHP_EOL .
                "  收件人: {$task_email}" . PHP_EOL .
                "  标题: {$title}" . PHP_EOL .
                "  内容长度: " . mb_strlen($content) . " 字符" . PHP_EOL .
                "  错误信息: {$errorMsg}" . PHP_EOL .
                "  分析: {$error_analysis}" . PHP_EOL;
            
            // 确保错误日志编码正确
            $error_log = mb_convert_encoding($error_log, $charset_config['charset'], 'auto');
            file_put_contents('debug.log', $error_log, FILE_APPEND | LOCK_EX);
            
            $redis->incr('error');
            // sleep($error_num);
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

    }
};

//自动测试启动
$auto_test = new Worker();
$auto_test->name = '$auto_test';
$auto_test->onWorkerStart = function () {
    Client::connect('127.0.0.1');
    Client::on('start_auto', function () {

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
            file_put_contents('debug.log',
                date('Y-m-d H:i:s') . " [DEBUG] 发送测试邮件: " . $check_email . PHP_EOL,
                FILE_APPEND);
            $redis->rPush('test',$check_email);
        }
    });
};

$plus_from_address_index = new Worker();
$plus_from_address_index->name = '$plus_from_address_index';
$plus_from_address_index->onWorkerStart = function () {
    Client::connect('127.0.0.1');
    Client::on('plus_from_address_index_ab5253234', function () {
        $env = parse_ini_file('.env', true);
        $from_address_index = $env['from_address_index'];
        $add_1_address = intval($from_address_index)+1;
        $set_env_res = setEnvVal('.env', 'from_address_index', $add_1_address);
        // echo(json_encode(['code'=>0,'msg'=>'test success']));
    });
};

$minus_from_address_index = new Worker();
$minus_from_address_index->name = '$minus_from_address_index';
$minus_from_address_index->onWorkerStart = function () {
    Client::connect('127.0.0.1');
    Client::on('minus_from_address_index_ab5253234', function () {
        $env = parse_ini_file('.env', true);
        $from_address_index = $env['from_address_index'];
        $minus_1_address = intval($from_address_index)-1;
        $set_env_res = setEnvVal('.env', 'from_address_index', $minus_1_address);
        // echo(json_encode(['code'=>0,'msg'=>'test success']));
    });
};

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

$start_warm_batch = new Worker();
$start_warm_batch->name = '$start_warm_batch';
$start_warm_batch->onWorkerStart = function () {
    Client::connect('127.0.0.1');
    Client::on('start_warm_batch_ab5253234', function () {
//        $redis = new \Redis();
//        $redis->connect('127.0.0.1');
//        $env = parse_ini_file('.env', true);
//        $from_temp_str = $env['from_temp_str'];
//        $from_temp_str_index = intval($env['from_temp_str_index']);
//        $end_index = $from_temp_str_index+4;
//        $receiver_emails = explode(",",$env['receive_emails']);
//        $receiver_count = count($receiver_emails);
//        $warm_num = intval($env['warm_num']);
////        $smtp_count = $redis->lLen('smtp');
//        $receiver_warm_num = ceil($warm_num/$receiver_count);
//        for($index=$from_temp_str_index;$index<$end_index;$index++){
//            $from_temp_str_roll = sprintf($from_temp_str, strval($index));
//            for($i=0;$i<$receiver_count;$i++){
//                $receiver_email = $receiver_emails[$i];
//                for($j=0;$j<$receiver_warm_num;$j++){
//                    $redis->rPush('test',$receiver_email.'||||'.$from_temp_str_roll);
//                }
//            }
//        }
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
                    $text = str_replace('{'.$placeholder.'}', $value, $text);
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

// 邮件客户端模拟配置
function getClientSimulation($client_type = 'random') {
    $clients = [
        'thunderbird' => [
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:91.0) Gecko/20100101 Thunderbird/91.13.0',
            'x_mailer' => 'Mozilla Thunderbird',
            'x_priority' => '3',
            'charset' => 'UTF-8',
            'encoding' => '8bit'
        ],
        'outlook' => [
            'user_agent' => 'Microsoft-MacOutlook/16.66.22101001',
            'x_mailer' => 'Microsoft Outlook 16.0',
            'x_priority' => '3',
            'charset' => 'UTF-8',
            'encoding' => 'quoted-printable'
        ],
        'apple_mail' => [
            'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15',
            'x_mailer' => 'Apple Mail (2.3445.104.11)',
            'x_priority' => '3',
            'charset' => 'UTF-8',
            'encoding' => '7bit'
        ],
        'gmail' => [
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'x_mailer' => 'Gmail',
            'x_priority' => '3',
            'charset' => 'UTF-8',
            'encoding' => 'quoted-printable'
        ],
        'foxmail' => [
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'x_mailer' => 'Foxmail 7.2.23',
            'x_priority' => '3',
            'charset' => 'GBK',
            'encoding' => 'base64'
        ]
    ];
    
    if ($client_type === 'random') {
        $client_keys = array_keys($clients);
        $client_type = $client_keys[array_rand($client_keys)];
    }
    
    return isset($clients[$client_type]) ? $clients[$client_type] : $clients['thunderbird'];
}

// 字符集和编码配置
function getCharsetConfig($charset = 'auto') {
    $charsets = [
        'utf8' => ['charset' => 'UTF-8', 'encoding' => 'quoted-printable'],
        'gbk' => ['charset' => 'GBK', 'encoding' => 'base64'],
        'gb2312' => ['charset' => 'GB2312', 'encoding' => 'base64'],
        'iso88591' => ['charset' => 'ISO-8859-1', 'encoding' => '7bit'],
        'big5' => ['charset' => 'Big5', 'encoding' => 'base64']
    ];
    
    if ($charset === 'auto') {
        $charset_keys = array_keys($charsets);
        $charset = $charset_keys[array_rand($charset_keys)];
    }
    
    return isset($charsets[$charset]) ? $charsets[$charset] : $charsets['utf8'];
}

// 生成随机Message-ID
function generateMessageId($domain = null) {
    if (!$domain) {
        $domains = ['gmail.com', 'outlook.com', 'yahoo.com', 'hotmail.com'];
        $domain = $domains[array_rand($domains)];
    }
    
    $unique_id = uniqid() . '.' . mt_rand(1000, 9999);
    return '<' . $unique_id . '@' . $domain . '>';
}

/**
 * RFC 2047主题编码 - 支持分段式长主题编码
 * 处理包含日文/中文/Emoji的长主题
 */
function encodeSubjectRFC2047($subject, $charset = 'UTF-8', $encoding = 'B', $maxLineLength = 40) {
    // 如果主题只包含ASCII字符，直接返回
    if (mb_check_encoding($subject, 'ASCII')) {
        return $subject;
    }
    
    $header = [
        'input-charset'     => $charset,
        'output-charset'    => $charset,
        'line-length'       => $maxLineLength,
        'line-break-chars'  => "\r\n",
        'scheme'            => $encoding  // B=Base64, Q=Quoted-Printable
    ];
    
    $encodedLine = iconv_mime_encode('Subject', $subject, $header);
    
    // 去掉 "Subject: " 前缀，只返回编码后的内容
    if (strpos($encodedLine, 'Subject: ') === 0) {
        return substr($encodedLine, 9);
    }
    
    return $encodedLine;
}

/**
 * 把 HTML 转为纯文本，保留锚文本但移除超链接
 * 用于生成 multipart/alternative 的纯文本版本
 */
function htmlToPlainNoLink($html) {
    if (empty($html)) {
        return '';
    }
    
    // 检查是否有DOM扩展
    if (!extension_loaded('dom')) {
        // 简单的HTML标签移除作为降级方案
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        return trim($text);
    }
    
    try {
        // 1. 用 DOM 解析
        $dom = new DOMDocument();
        // 关闭错误输出，处理不规范 HTML
        libxml_use_internal_errors(true);
        
        // 添加meta标签确保UTF-8编码
        $htmlWithMeta = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
        $dom->loadHTML($htmlWithMeta, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        // 2. 处理换行标签
        foreach ($dom->getElementsByTagName('br') as $br) {
            $br->parentNode->insertBefore($dom->createTextNode("\n"), $br);
        }
        
        foreach ($dom->getElementsByTagName('p') as $p) {
            $p->appendChild($dom->createTextNode("\n\n"));
        }
        
        foreach ($dom->getElementsByTagName('div') as $div) {
            $div->appendChild($dom->createTextNode("\n"));
        }

        // 3. 遍历所有 <a>，只保留可见文字，移除链接
        $links = $dom->getElementsByTagName('a');
        $linksArray = [];
        foreach ($links as $link) {
            $linksArray[] = $link;
        }
        
        foreach ($linksArray as $a) {
            $textNode = $dom->createTextNode($a->textContent);
            $a->parentNode->replaceChild($textNode, $a);
        }

        // 4. 获取文本内容
        $text = $dom->textContent;

        // 5. 格式化文本
        // 句末标点后加换行
        $text = preg_replace('/([。！？?!.])(\s*)/', "$1\n", $text);
        // 压缩多个空格为一个
        $text = preg_replace("/[ \t]{2,}/", ' ', $text);
        // 压缩多个换行为最多两个
        $text = preg_replace("/\n{3,}/", "\n\n", $text);
        // 移除行首行尾空格
        $text = preg_replace("/^[ \t]+|[ \t]+$/m", '', $text);

        return trim($text);
        
    } catch (Exception $e) {
        // DOM解析失败时的降级方案
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        return trim($text);
    }
}

/**
 * 检测内容是否包含HTML标签
 */
function isHtmlContent($content) {
    return $content !== strip_tags($content);
}

/**
 * 检查字符串是否为纯ASCII
 * 
 * @param string $str 待检查的字符串
 * @return bool true表示纯ASCII，false表示包含非ASCII字符
 */
function isAscii($str) {
    return mb_check_encoding($str, 'ASCII');
}

/**
 * 智能SMTP错误分析和建议
 * 
 * @param string $errorMsg 错误消息
 * @param string $host SMTP主机
 * @param int $port SMTP端口
 * @param string $secure 安全协议
 * @return string 分析结果和建议
 */
function analyzeSmtpError($errorMsg, $host, $port, $secure) {
    $analysis = "SMTP错误分析: ";
    $suggestions = [];
    
    // SSL/TLS协议错误
    if (strpos($errorMsg, 'SSL23_GET_SERVER_HELLO:unknown protocol') !== false ||
        strpos($errorMsg, 'SSL operation failed') !== false) {
        
        $analysis .= "SSL协议不匹配 - 服务器可能运行明文SMTP";
        $suggestions[] = "建议修改配置去掉SSL参数";
        $suggestions[] = "将 '{$host}----{$port}----user----pass----ssl----email' 改为 '{$host}----{$port}----user----pass--------email'";
        
        if ($port == 465) {
            $suggestions[] = "特别注意：465端口通常用于SSL，但此服务器运行明文协议";
        }
        
    } elseif (strpos($errorMsg, 'authentication failed') !== false ||
              strpos($errorMsg, 'Could not authenticate') !== false ||
              strpos($errorMsg, '535 5.7.8') !== false) {
        
        $analysis .= "SMTP认证失败";
        $suggestions[] = "检查用户名和密码是否正确";
        $suggestions[] = "确认SMTP服务器是否启用了认证";
        $suggestions[] = "检查账户是否被锁定或暂停";
        
        if ($port == 587) {
            $suggestions[] = "587端口通常需要STARTTLS，确认secure参数为'tls'";
        }
        
    } elseif (strpos($errorMsg, 'Could not connect') !== false ||
              strpos($errorMsg, 'Connection refused') !== false) {
        
        $analysis .= "连接失败";
        $suggestions[] = "检查服务器地址和端口是否正确";
        $suggestions[] = "确认网络连接正常";
        $suggestions[] = "检查防火墙设置";
        
    } elseif (strpos($errorMsg, 'STARTTLS') !== false) {
        
        $analysis .= "STARTTLS协议问题";
        $suggestions[] = "尝试将secure参数从'tls'改为'ssl'或留空";
        
        if ($port == 25) {
            $suggestions[] = "25端口通常不支持加密，尝试明文连接";
        }
        
    } elseif (strpos($errorMsg, 'Invalid address') !== false) {
        
        $analysis .= "邮件地址格式错误";
        $suggestions[] = "检查发件人和收件人邮箱地址格式";
        $suggestions[] = "确认邮箱地址不包含特殊字符";
        
    } else {
        $analysis .= "未知错误类型";
        $suggestions[] = "检查SMTP服务器状态";
        $suggestions[] = "查看完整错误日志获取更多信息";
    }
    
    // 添加通用端口建议
    $port_advice = "";
    switch ($port) {
        case 25:
            $port_advice = " | 端口25: 标准SMTP，通常明文";
            break;
        case 465:
            $port_advice = " | 端口465: 传统SSL，但此服务器可能运行明文";
            break;
        case 587:
            $port_advice = " | 端口587: 现代SMTP，通常使用STARTTLS";
            break;
        case 2525:
            $port_advice = " | 端口2525: 备用SMTP端口";
            break;
    }
    
    $result = $analysis . $port_advice . " | 建议: " . implode('; ', $suggestions);
    
    return $result;
}

Worker::runAll();