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
        if(empty($task_email)){
            $task_email = $redis->lPop('task');
        }

        file_put_contents('debug.log',
            date('Y-m-d H:i:s') . " [DEBUG] 获取邮件: " . $task_email . PHP_EOL,
            FILE_APPEND);

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
        // $smtp_secure='ssl';
        list($smtp_host,$smtp_account,$smtp_password,$smtp_from_str) = explode('----', $smtp_item_str);
        $smtp_port = 2525;
        // $smtp_port = 587;
        $smtp_secure='';
        // $smtp_port = 2525;
        // $smtp_secure='ssl';

        // $from_address_list = explode(',', $from_address_list_str);
        // shuffle($from_address_list);
        // $from_address = $from_address_list[0];
        // $ip_not_dot_str = str_replace(".", "", $smtp_host);
        // $from_address = sprintf($from_address_list_str,$from_address_index.'-'.$ip_not_dot_str);
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




        // $content = $template;
        $task_email_name = explode('@', $task_email)[0];




        if($is_warm_flag){
            // $from_name = 'Apple ID';
            // $title = 'Apple ID';
            // $content = random_char(8);
        }else{
            // $from_name = 'Apple ID';
            // $title = 'Apple ID';
            // $content = random_char(8);
        }
        // $from_name = 'test';
        // $title = 'test';
        // $content = random_char(8);
        if($task_email==$check_email||$task_email==$run_warm_email||$task_email==$warm_emails[0]||$task_email==$warm_emails[1]){
            $title = random_char(rand(4, 8)).','.$title;
        }else{
            $title = $task_email_name.','.$title;
        }
        // $title = $title.'('.random_char(rand(4, 8)).')';
        $message_id = sprintf('<%s-%s@%s>', random_char(8), random_char(8), random_char(8));
        // $message_id = sprintf('<%s-%s@%s>', random_char(8), random_char(8), $from_address_domain);
        // $from_address = random_char(rand(4, 8)).'@'.$from_address_domain;
        // $from_address = random_char(8).'@'.$from_address_domain;
        // $ip_no_dot = str_replace(".","",$smtp_host);
        // $from_address = 'support-'.$ip_no_dot.'@'.$from_address_domain;
        // $from_address = 'no-reply@'.random_char(8).'.google.com';
        // $from_address = random_char(8).'@'.$smtp_from_domain;
        $from_address = $smtp_from_str;
        $mailer = new PHPMailer(true);
        try {
            $mailer->SMTPDebug = false;
            $mailer->isSMTP();
            $mailer->SMTPAuth = true;
            $mailer->Host = $smtp_host;
            $mailer->Port = $smtp_port;
            $mailer->Username = $smtp_account;
            $mailer->Password = $smtp_password;
            // $mailer->SMTPSecure = $smtp_secure;
            $mailer->CharSet = PHPMailer::CHARSET_UTF8;
            $mailer->MessageID = $message_id;
            $mailer->setFrom($from_address, $from_name);
            $mailer->Sender = $from_address;
            $mailer->Hostname = random_char(8);
            $mailer->XMailer = random_char(8);
            // $mailer->Priority = 3;
            $mailer->addAddress($task_email);
            $mailer->isHTML(true);
            $mailer->Subject = $title;
            $mailer->Body = $content;
            $mailer->send();
            $error_num = 0;
            $mailer->smtpClose();


        } catch (Exception $e) {
            $error_num = $error_num + 1;
            $errorMsg = $e->getMessage();
            file_put_contents(date('Y-m-d') . '-error.txt', date('Y-m-d H:i:s') . '|' . $smtp_host.'|' .
                $smtp_port.'|' . $smtp_account . '|' . $smtp_password.'|' . $smtp_secure.'|' . $from_address.'|' . $from_name . '|' .
                $task_email . '|'. $content.'|' . $title.'|' . $errorMsg . PHP_EOL, FILE_APPEND);
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

Worker::runAll();