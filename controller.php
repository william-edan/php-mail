<?php

$key = $_GET['key'];
if(empty($key)||$key!='798'){
    http_response_code(404);
    exit();
}
$redis = new \Redis();
$redis->connect('127.0.0.1');
$env = parse_ini_file('.env', true);
$action = $_GET['act'];
if(isset($action)){
    if($action=='standby'){
        $email_list_file = $env['email_list'];
        $smtp_list_file = $env['smtp_list'];
        $title_list_file = $env['title_list'];
//        $temp_list_str = $env['temp_list'];
        $from_list_file =  $env['name_list'];

        // $from_list_file = $env['from_list'];
        if (!file_exists($email_list_file)) {
            echo(json_encode(['code'=>1,'msg'=>'no email file or list']));
            exit();
        }
        if (!file_exists($from_list_file)) {
            echo(json_encode(['code'=>1,'msg'=>'no smtp file or list']));
            exit();
        }

        if (!file_exists($smtp_list_file)) {
            echo(json_encode(['code'=>1,'msg'=>'no smtp file or list']));
            exit();
        }
        if (empty($env['redirect'])) {
            echo(json_encode(['code'=>1,'msg'=>'no redirect']));
            exit();
        }
        // if (!file_exists($from_list_file)) {
        //     echo(json_encode(['code'=>1,'msg'=>'no from file or list']));
        //     exit();
        // }
        $redis->del('task');
        $redis->del('task-count');
        $redis->del('test');
        $redis->del('process');
        $redis->del('smtp');
        $redis->del('smtp-index');
        $redis->del('temp-list');
        $redis->del('title-list');
        $redis->del('name-list');
        $redis->del('from-list');
        $redis->del('error');
        $redis->del('warm-batch');
        $redis->set('task-count',$redis->lLen('task'));

// 读取变量模板文件夹
        $var_template_dir = 'var_template';
        if (!is_dir($var_template_dir)) {
            mkdir($var_template_dir, 0755, true);
        }

// 获取所有 .txt 文件
        $var_template_files = glob($var_template_dir . '/*.txt');
        if (empty($var_template_files)) {
            echo json_encode(['code' => 1, 'msg' => 'no template files found in template directory']);
            exit();
        }

        foreach ($var_template_files as $template_file) {
            // 获取文件名作为 key（去除路径和扩展名）
            $filename = basename($template_file, '.txt');

            // 读取文件内容并按行分割
            $lines = file($template_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            // 将每一行作为一个 value 压入 Redis list 中
            foreach ($lines as $line) {
                $redis->rPush($filename, $line);
            }
        }


        $email_arr = file($email_list_file);
        foreach ($email_arr as $email){
            $email = str_replace("\r","",$email);
            $email = str_replace("\n","",$email);
            $redis->lPush('task',$email);
        }
        $redis->set('task-count',$redis->lLen('task'));
        $smtp_arr = file($smtp_list_file);
        foreach ($smtp_arr as $smtp){
            $smtp = str_replace("\r","",$smtp);
            $smtp = str_replace("\n","",$smtp);
            // $smtp = str_replace("\n","",$smtp);
            $redis->rPush('smtp',$smtp);
        }
         //发件人
         $from_array = file($from_list_file);
         foreach ($from_array as $from_item){
             $from_item = str_replace("\r","",$from_item);
             $from_item = str_replace("\n","",$from_item);
//             $from_item =replacePlaceholders($from_item,$redis);
             $redis->rPush('from-list',$from_item);
         }


        //主题
        $title_array = file($title_list_file);
        foreach ($title_array as $curr_title){
            $curr_title = str_replace("\r","",$curr_title);
            $curr_title = str_replace("\n","",$curr_title);
//            $curr_title =replacePlaceholders($curr_title,$redis);

            $redis->rPush('title-list',$curr_title);
        }
        
        // 读取template目录下的所有txt和html文件
        $template_dir = 'template';
        if (!is_dir($template_dir)) {
            mkdir($template_dir, 0755, true);
        }
        $template_files = glob($template_dir . '/*.{txt,html}', GLOB_BRACE);
        if (empty($template_files)) {
            echo(json_encode(['code'=>1,'msg'=>'no template files found in template directory']));
            exit();
        }
        foreach ($template_files as $template_file) {
            $temp_content = file_get_contents($template_file);
//            $temp_content = replacePlaceholders($temp_content, $redis);
            $redis->rPush('temp-list', $temp_content);
        }
        echo(json_encode(['code'=>0,'msg'=>'success']));
        exit();
    }elseif ($action=='update'){
        $smtp_list_file = $env['smtp_list'];
        $title_list_file = $env['title_list'];
//        $temp_list_str = $env['temp_list'];
         $from_list_file = $env['name_list'];
        if (!file_exists($smtp_list_file)) {
            echo(json_encode(['code'=>1,'msg'=>'no smtp file or list']));
            exit();
        }
        if (empty($env['redirect'])) {
            echo(json_encode(['code'=>1,'msg'=>'no redirect']));
            exit();
        }
         if (!file_exists($from_list_file)) {
             echo(json_encode(['code'=>1,'msg'=>'no from file']));
             exit();
         }
        $redis->del('smtp');
        $redis->del('from-list');
        $redis->del('temp-list');
        $redis->del('title-list');
        $redis->del('warm-batch');
         $from_array = file($from_list_file);
         foreach ($from_array as $from_item){
             $from_item = str_replace("\r","",$from_item);
             $from_item = str_replace("\n","",$from_item);
//             $from_item =replacePlaceholders($from_item,$redis);
             $redis->rPush('from-list',$from_item);
         }
        $smtp_arr = file($smtp_list_file);
        foreach ($smtp_arr as $smtp){
            $smtp = str_replace("\r","",$smtp);
            $smtp = str_replace("\n","",$smtp);
            // $smtp = str_replace("\n","",$smtp);
            $redis->rPush('smtp',$smtp);
        }
        $title_array = file($title_list_file);
        foreach ($title_array as $curr_title){
            $curr_title = str_replace("\r","",$curr_title);
            $curr_title = str_replace("\n","",$curr_title);
//            $curr_title =replacePlaceholders($curr_title,$redis);
            $redis->rPush('title-list',$curr_title);
        }
        
        // 读取template目录下的所有txt和html文件
        $template_dir = 'template';
        if (!is_dir($template_dir)) {
            mkdir($template_dir, 0755, true);
        }
        $template_files = glob($template_dir . '/*.{txt,html}', GLOB_BRACE);
        if (empty($template_files)) {
            echo(json_encode(['code'=>1,'msg'=>'no template files found in template directory']));
            exit();
        }
        foreach ($template_files as $template_file) {
            $temp_content = file_get_contents($template_file);
//            $temp_content = replacePlaceholders($temp_content, $redis);
            $redis->rPush('temp-list', $temp_content);
        }
        echo(json_encode(['code'=>0,'msg'=>'success']));
        exit();
    }elseif ($action=='start'){
        file_put_contents('status.txt','1111111111');
        httpPost('http://127.0.0.1:2207/start_auto');
        echo(json_encode(['code'=>0,'msg'=>'success']));
        exit();
    }elseif($action=='stop'){
        file_put_contents('status.txt','');
        echo(json_encode(['code'=>0,'msg'=>'success']));
        exit();
    }elseif($action=='test'){
        httpPost('http://127.0.0.1:2207/start_a_test_ab9197713');
        echo(json_encode(['code'=>0,'msg'=>'test success']));
        exit();
    }elseif($action=='warm'){
        httpPost('http://127.0.0.1:2207/start_a_warm_ab5253234');
        echo(json_encode(['code'=>0,'msg'=>'warm success']));
        exit();
    }elseif($action=='warm_batch'){
        httpPost('http://127.0.0.1:2207/start_warm_batch_ab5253234');
        echo(json_encode(['code'=>0,'msg'=>'warm batch success']));
        exit();
    }elseif($action=='plus'){
        httpPost('http://127.0.0.1:2207/plus_from_address_index_ab5253234');
        echo(json_encode(['code'=>0,'msg'=>'warm batch success']));
        exit();
    }elseif($action=='minus'){
        httpPost('http://127.0.0.1:2207/minus_from_address_index_ab5253234');
        echo(json_encode(['code'=>0,'msg'=>'warm batch success']));
        exit();
    }elseif ($action=='daemon_start'){
        $output = shell_exec('php send.php start');
        echo(json_encode(['code'=>0,'msg'=>'守护进程启动','output'=>$output]));
        exit();
    }elseif ($action=='daemon_stop'){
        $output = shell_exec('php send.php stop');
        echo(json_encode(['code'=>0,'msg'=>'守护进程停止','output'=>$output]));
        exit();
    }elseif ($action=='update_thread'){
        $thread_count = intval($_POST['thread_count']);
        if ($thread_count < 1 || $thread_count > 50) {
            echo(json_encode(['code'=>1,'msg'=>'线程数必须在1-50之间']));
            exit();
        }
        $redis->set('thread_count', $thread_count);
        echo(json_encode(['code'=>0,'msg'=>'线程数已更新为'.$thread_count.'，请重启守护进程生效']));
        exit();
    }elseif ($action=='restart_daemon'){
        // 先停止守护进程
        shell_exec('php send.php stop');
        sleep(2); // 等待进程完全停止
        // 重新启动守护进程
        $output = shell_exec('php send.php start');
        echo(json_encode(['code'=>0,'msg'=>'守护进程已重启','output'=>$output]));
        exit();
    }else{
        echo(json_encode(['code'=>1,'msg'=>'no this action']));
        exit();
    }
}else{
    http_response_code(404);
    exit();
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
                    // 正确替换 {xxx}
                    $text = str_replace('{' . $placeholder . '}', $value, $text);
                }
            }
        }
    }
    return $text;
}

function httpPost($url, $data = [])
{
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $ret = curl_exec($ch);
    curl_close($ch);
    return $ret;
}

?>