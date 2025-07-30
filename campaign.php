<?php
$key = $_GET['key'];
if (empty($key) || $key != '798') {
    http_response_code(404);
    exit();
}
$redis = new \Redis();
$redis->connect('127.0.0.1');
$env = parse_ini_file('.env', true);
$error_num = 0;
$status = file_get_contents('status.txt') ? '开启' : '关闭';
$task_count = $redis->get('task-count') ? $redis->get('task-count') :0;
$process = $redis->get('process') ? $redis->get('process') : 0;
$smtp_count = $redis->lLen('smtp');
// $smtp_index = $redis->get('smtp-index') ? $redis->get('smtp-index') : 0;
$error_count = $redis->get('error') ? $redis->get('error') : 0;
$smtp_index = ($redis->get('smtp-index') ? $redis->get('smtp-index') : 0) % ($redis->lLen('smtp')?$redis->lLen('smtp'):1);
$from_count = $redis->lLen('from-list') ? $redis->lLen('from-list') : 0;
$smtp_0 = $redis->lIndex('smtp', $smtp_index);
// $smtp_0 = explode('----', $smtp_0)[2];
$smtp_0 = $smtp_0;
// $from_index = floor($process/((500*$smtp_count)?(500*$smtp_count):1));
$from_address_index = $env['from_address_index'];
$from_address_list_str = $env['from_address_list'];
$from_address_list = explode(',', $from_address_list_str);
shuffle($from_address_list);
$current_from = $from_address_list[0];
$current_from = sprintf($current_from, $from_address_index);
$email_list = $env['email_list'];
$redirect = $env['redirect'];
?>
<!DOCTYPE html>
<html>
<head>
    <title>Campaign 管理面板</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- 引入 Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
        }
        .status-badge {
            font-size: 1.2em;
        }
        .table-striped>tbody>tr:nth-of-type(odd)>* {
            --bs-table-accent-bg: #f2f2f2;
        }
        .section-title {
            font-weight: bold;
            margin-top: 2rem;
            margin-bottom: 1rem;
        }
        .btn-group {
            margin-bottom: 1rem;
        }
        .placeholder-link {
            color: #0d6efd;
            text-decoration: underline;
            cursor: pointer;
        }
    </style>
</head>
<body>
<div class="container py-4">
    <h1 class="text-center mb-4">Campaign 管理面板 <span class="badge bg-<?php echo file_get_contents('status.txt') ? 'success' : 'secondary'; ?> status-badge"><?php echo file_get_contents('status.txt') ? '运行中' : '已停止'; ?></span></h1>
    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header section-title">系统状态</div>
                <div class="card-body p-2">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><th>任务总数</th><td id="task_count"><?php echo $task_count ?></td></tr>
                        <tr><th>已处理</th><td id="process"><?php echo $process ?></td></tr>
                        <tr><th>错误数</th><td id="error_count"><?php echo $error_count ?></td></tr>
                        <tr><th>SMTP数量</th><td id="smtp_count"><?php echo $smtp_count ?></td></tr>
                        <tr><th>当前SMTP索引</th><td id="smtp_index"><?php echo $smtp_index ?></td></tr>
                        <tr><th>发件人数量</th><td id="from_count"><?php echo $from_count ?></td></tr>
                        <tr><th>发送线程数</th><td id="thread_count"><?php 
                            $thread_count = $redis->get('thread_count') ?: 10;
                            echo $thread_count; 
                        ?></td></tr>
                        <tr><th>守护进程状态</th><td id="daemon_status"><?php 
                            $pid_file = 'send.php.pid';
                            $daemon_status = '未知';
                            $daemon_class = 'text-secondary';
                            
                            if (file_exists($pid_file)) {
                                $pid = trim(file_get_contents($pid_file));
                                if ($pid && posix_kill($pid, 0)) {
                                    $daemon_status = '运行中 (PID: ' . $pid . ')';
                                    $daemon_class = 'text-success';
                                } else {
                                    $daemon_status = '已停止';
                                    $daemon_class = 'text-danger';
                                }
                            } else {
                                $daemon_status = '已停止';
                                $daemon_class = 'text-danger';
                            }
                            echo '<span class="' . $daemon_class . '">' . $daemon_status . '</span>';
                        ?></td></tr>
                        <tr><th>进程运行时间</th><td id="daemon_uptime"><?php 
                            $uptime = '未运行';
                            if (file_exists($pid_file)) {
                                $pid = trim(file_get_contents($pid_file));
                                if ($pid && posix_kill($pid, 0)) {
                                    $start_time = filectime($pid_file);
                                    $uptime_seconds = time() - $start_time;
                                    $hours = floor($uptime_seconds / 3600);
                                    $minutes = floor(($uptime_seconds % 3600) / 60);
                                    $seconds = $uptime_seconds % 60;
                                    $uptime = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
                                }
                            }
                            echo $uptime;
                        ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header section-title">当前配置</div>
                <div class="card-body p-2">
                    <table class="table table-sm table-borderless mb-0">
                        <tr><th>发件人</th><td><?php echo $current_from?$current_from:'无' ?></td></tr>
                        <tr><th>发件人列表</th><td><?php echo $redis->lLen('from-list') ?></td></tr>
                        <tr><th>模板数量</th><td><?php echo $redis->lLen('temp-list') ?></td></tr>
                        <tr><th>主题数量</th><td><?php echo $redis->lLen('title-list') ?></td></tr>
                        <tr><th>测试队列</th><td><?php echo $redis->lLen('test-task') ?> | <?php echo $redis->lIndex('task',0) ?></td></tr>
                        <tr><th>当前SMTP</th><td><?php echo $smtp_0 ?></td></tr>
                        <tr><th>发送线程数</th><td id="thread_count"><?php 
                            $thread_count = $redis->get('thread_count') ?: 10;
                            echo $thread_count; 
                        ?></td></tr>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header section-title">线程控制</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label>发送线程数：</label>
                        <div class="input-group">
                            <input type="number" id="thread_input" class="form-control" placeholder="输入线程数(1-50)" min="1" max="50" value="<?php echo $thread_count; ?>">
                            <button id="update_thread_btn" class="btn btn-primary">更新线程数</button>
                        </div>
                        <small class="text-muted">建议范围：1-50个线程，过多可能影响系统性能</small>
                    </div>
                    <div class="mb-3">
                        <button id="restart_daemon_btn" class="btn btn-warning">重启守护进程</button>
                        <small class="text-muted d-block">修改线程数后需要重启守护进程才能生效</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- 客户端模拟配置 -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header section-title">客户端模拟配置</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">邮件客户端模拟：</label>
                        <select id="client_simulation" class="form-select">
                            <option value="random" <?php echo ($env['client_simulation'] ?? 'random') == 'random' ? 'selected' : ''; ?>>随机选择</option>
                            <option value="thunderbird" <?php echo ($env['client_simulation'] ?? '') == 'thunderbird' ? 'selected' : ''; ?>>Mozilla Thunderbird</option>
                            <option value="outlook" <?php echo ($env['client_simulation'] ?? '') == 'outlook' ? 'selected' : ''; ?>>Microsoft Outlook</option>
                            <option value="apple_mail" <?php echo ($env['client_simulation'] ?? '') == 'apple_mail' ? 'selected' : ''; ?>>Apple Mail</option>
                            <option value="gmail" <?php echo ($env['client_simulation'] ?? '') == 'gmail' ? 'selected' : ''; ?>>Gmail</option>
                            <option value="foxmail" <?php echo ($env['client_simulation'] ?? '') == 'foxmail' ? 'selected' : ''; ?>>Foxmail</option>
                        </select>
                        <small class="text-muted">选择要模拟的邮件客户端，影响邮件头信息</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">字符集编码：</label>
                        <select id="charset_type" class="form-select">
                            <option value="auto" <?php echo ($env['charset_type'] ?? 'auto') == 'auto' ? 'selected' : ''; ?>>自动选择</option>
                            <option value="utf8" <?php echo ($env['charset_type'] ?? '') == 'utf8' ? 'selected' : ''; ?>>UTF-8</option>
                            <option value="gbk" <?php echo ($env['charset_type'] ?? '') == 'gbk' ? 'selected' : ''; ?>>GBK</option>
                            <option value="gb2312" <?php echo ($env['charset_type'] ?? '') == 'gb2312' ? 'selected' : ''; ?>>GB2312</option>
                            <option value="iso88591" <?php echo ($env['charset_type'] ?? '') == 'iso88591' ? 'selected' : ''; ?>>ISO-8859-1</option>
                            <option value="big5" <?php echo ($env['charset_type'] ?? '') == 'big5' ? 'selected' : ''; ?>>Big5</option>
                        </select>
                        <small class="text-muted">选择邮件字符集和编码方式</small>
                    </div>
                    
                    <div class="mb-3">
                        <button id="save_client_config" class="btn btn-success">保存配置</button>
                        <small class="text-muted d-block">修改后需要重启守护进程才能生效</small>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card">
                <div class="card-header section-title">操作面板</div>
                <div class="card-body">
                    <div class="btn-group mb-2" role="group">
                        <button id="warm" class="btn btn-outline-warning">预热</button>
                        <button id="test" class="btn btn-outline-info">回测</button>
                    </div>
                    <div class="btn-group mb-2" role="group">
                        <button id="standby" class="btn btn-outline-secondary">重置队列</button>
                        <button id="update" class="btn btn-outline-secondary">刷新配置</button>
                    </div>
                    <div class="btn-group mb-2" role="group">
                        <button id="start" class="btn btn-success">启动</button>
                        <button id="stop" class="btn btn-danger">停止</button>
                    </div>
                    <div class="btn-group mb-2" role="group">
                        <button id="daemon_start" class="btn btn-outline-success">邮件程序启动</button>
                        <button id="daemon_stop" class="btn btn-outline-danger">邮件程序停止</button>
                    </div>
                </div>
            </div>
        </div>
    </div>



</div>





<script src="https://gcore.jsdelivr.net/npm/jquery@3.4.1/dist/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // 按钮事件
    $("#warm_plus").click(function () {
        $.ajax({
            url: "/controller.php?key=798&act=plus", success: function (result) {
                alert(result);
                location.reload();
            }
        });
    });
    $("#warm_minus").click(function () {
        $.ajax({
            url: "/controller.php?key=798&act=minus", success: function (result) {
                alert(result);
                location.reload();
            }
        });
    });
    $("#warm").click(function () {
        $.ajax({
            url: "/controller.php?key=798&act=warm", success: function (result) {
                alert(result);
            }
        });
    });
    $("#test").click(function () {
        $.ajax({
            url: "/controller.php?key=798&act=test", success: function (result) {
                alert(result);
            }
        });
    });
    $("#standby").click(function () {
        $.ajax({
            url: "/controller.php?key=798&act=standby", success: function (result) {
                alert(result);
                location.reload();
            }
        });
    });
    $("#update").click(function () {
        $.ajax({
            url: "/controller.php?key=798&act=update", success: function (result) {
                alert(result);
                location.reload();
            }
        });
    });
    $("#start").click(function () {
        $.ajax({
            url: "/controller.php?key=798&act=start", success: function (result) {
                alert(result);
                location.reload();
            }
        });
    });
    $("#stop").click(function () {
        $.ajax({
            url: "/controller.php?key=798&act=stop", success: function (result) {
                alert(result);
                location.reload();
            }
        });
    });

    // 变量导入
    $('#import_var_btn').click(function() {
        $('#importVarModal').modal('show');
    });

    // 变量数据存储
    var varData = {};
    var currentPage = 1;
    var pageSize = 5; // 每页显示5条记录

    // 渲染分页表格
    function renderVarTable(data, page) {
        var keys = Object.keys(data);
        var totalPages = Math.ceil(keys.length / pageSize);
        var start = (page - 1) * pageSize;
        var end = start + pageSize;
        var pageKeys = keys.slice(start, end);
        
        var html = '<table class="table table-sm table-striped">';
        html += '<thead><tr><th>Key</th><th>数量</th><th>操作</th></tr></thead><tbody>';
        
        for(var i = 0; i < pageKeys.length; i++) {
            var key = pageKeys[i];
            var values = data[key];
            html += '<tr><td>' + key + '</td><td>' + values.length + '</td><td><button class="btn btn-sm btn-info view-details" data-key="' + key + '">查看详情</button></td></tr>';
        }
        
        html += '</tbody></table>';
        $('#var_content').html(html);
        
        // 更新分页信息
        $('#page_info').text('第' + page + '页/共' + totalPages + '页');
        $('#prev_page').toggleClass('disabled', page <= 1);
        $('#next_page').toggleClass('disabled', page >= totalPages);
        $('#var_pagination').show();
        
        // 绑定查看详情事件
        $('.view-details').click(function() {
            var key = $(this).data('key');
            var values = varData[key];
            showVarDetails(key, values);
        });
    }
    
    // 显示变量详情的模态框
    function showVarDetails(key, values) {
        var content = '<div class="mb-3"><strong>Key:</strong> ' + key + '</div>';
        content += '<div class="mb-3"><strong>数量:</strong> ' + values.length + '</div>';
        content += '<div><strong>内容:</strong></div>';
        content += '<div style="max-height:300px;overflow:auto;border:1px solid #ddd;padding:10px;background:#f9f9f9;">';
        content += '<pre style="margin:0;font-size:12px;">' + values.join('\n') + '</pre>';
        content += '</div>';
        
        $('#varDetailsModal .modal-body').html(content);
        $('#varDetailsModal').modal('show');
    }

    // 查看所有变量
    $('#view_all_var_btn').click(function() {
        $.get('placeholder_view.php?all=1', function(data){
            try {
                var obj = data;
                if(Object.keys(obj).length > 0){
                    varData = obj;
                    currentPage = 1;
                    renderVarTable(varData, currentPage);
                }else{
                    $('#var_content').html('<p class="text-muted">暂无变量数据</p>');
                    $('#var_pagination').hide();
                }
            } catch(e) {
                console.error('JSON解析错误:', e);
                $('#var_content').html('<p class="text-muted">查询失败: ' + e.message + '</p>');
                $('#var_pagination').hide();
            }
        }).fail(function(xhr, status, error) {
            console.error('AJAX请求失败:', status, error);
            $('#var_content').html('<p class="text-muted">请求失败: ' + error + '</p>');
            $('#var_pagination').hide();
        });
    });

    // 搜索变量
    $('#search_var_btn').click(function() {
        var key = $('#search_var_input').val().trim();
        if(!key){ alert('请输入搜索key'); return; }
        var searchKey = 'var_' + key;
        $.get('placeholder_view.php?key='+encodeURIComponent(searchKey), function(data){
            try {
                var obj = JSON.parse(data);
                if(Object.keys(obj).length > 0){
                    varData = obj;
                    currentPage = 1;
                    renderVarTable(varData, currentPage);
                }else{
                    $('#var_content').html('<p class="text-muted">未找到匹配的变量</p>');
                    $('#var_pagination').hide();
                }
            } catch(e) {
                $('#var_content').html('<p class="text-muted">查询失败</p>');
                $('#var_pagination').hide();
            }
        });
    });

    // 回车键搜索
    $('#search_var_input').keypress(function(e) {
        if(e.which == 13) { // 回车键
            $('#search_var_btn').click();
        }
    });

    // 更新线程数
    $('#update_thread_btn').click(function() {
        var threadCount = $('#thread_input').val();
        if (!threadCount || threadCount < 1 || threadCount > 50) {
            alert('请输入有效的线程数(1-50)');
            return;
        }
        $.ajax({
            url: 'controller.php?key=798&act=update_thread',
            type: 'POST',
            data: { thread_count: threadCount },
            success: function(result) {
                var res = JSON.parse(result);
                alert(res.msg);
                if (res.code === 0) {
                    $('#thread_count').text(threadCount);
                }
            },
            error: function() {
                alert('更新失败，请重试');
            }
        });
    });

    // 重启守护进程
    $('#restart_daemon_btn').click(function() {
        if (!confirm('确定要重启守护进程吗？这将暂停邮件发送几秒钟。')) {
            return;
        }
        $.ajax({
            url: 'controller.php?key=798&act=restart_daemon',
            success: function(result) {
                var res = JSON.parse(result);
                alert(res.msg);
            },
            error: function() {
                alert('重启失败，请重试');
            }
        });
    });

    // 保存客户端配置
    $('#save_client_config').click(function() {
        var client_simulation = $('#client_simulation').val();
        var charset_type = $('#charset_type').val();
        
        $.ajax({
            url: 'controller.php?key=798&act=save_client_config',
            type: 'POST',
            data: {
                client_simulation: client_simulation,
                charset_type: charset_type
            },
            success: function(result) {
                var res = JSON.parse(result);
                if (res.code === 0) {
                    alert(res.msg + '\n建议重启守护进程使配置生效。');
                } else {
                    alert('保存失败：' + res.msg);
                }
            },
            error: function() {
                alert('保存失败，请重试');
            }
        });
    });

    // 守护进程启动
    $('#daemon_start').click(function() {
        if (!confirm('确定要启动邮件程序守护进程吗？')) {
            return;
        }
        $(this).prop('disabled', true).text('启动中...');
        $.ajax({
            url: 'controller.php?key=798&act=daemon_start',
            type: 'POST',
            success: function(result) {
                var res = JSON.parse(result);
                alert(res.msg);
                $('#daemon_start').prop('disabled', false).text('邮件程序启动');
                // 刷新守护进程状态
                setTimeout(function() {
                    $.get('daemon_status.php?key=798', function(data) {
                        if (typeof data === 'string') {
                            data = JSON.parse(data);
                        }
                        $("#daemon_status").html('<span class="' + data.daemon_class + '">' + data.daemon_status + '</span>');
                        $("#daemon_uptime").text(data.daemon_uptime);
                    });
                }, 2000);
            },
            error: function() {
                alert('启动失败，请重试');
                $('#daemon_start').prop('disabled', false).text('邮件程序启动');
            }
        });
    });

    // 守护进程停止
    $('#daemon_stop').click(function() {
        if (!confirm('确定要停止邮件程序守护进程吗？这将停止所有邮件发送。')) {
            return;
        }
        $(this).prop('disabled', true).text('停止中...');
        $.ajax({
            url: 'controller.php?key=798&act=daemon_stop',
            type: 'POST',
            success: function(result) {
                var res = JSON.parse(result);
                alert(res.msg);
                $('#daemon_stop').prop('disabled', false).text('邮件程序停止');
                // 刷新守护进程状态
                setTimeout(function() {
                    $.get('daemon_status.php?key=798', function(data) {
                        if (typeof data === 'string') {
                            data = JSON.parse(data);
                        }
                        $("#daemon_status").html('<span class="' + data.daemon_class + '">' + data.daemon_status + '</span>');
                        $("#daemon_uptime").text(data.daemon_uptime);
                    });
                }, 1000);
            },
            error: function() {
                alert('停止失败，请重试');
                $('#daemon_stop').prop('disabled', false).text('邮件程序停止');
            }
        });
    });

    // 分页事件
    $('#prev_page').click(function() {
        if(currentPage > 1) {
            currentPage--;
            renderVarTable(varData, currentPage);
        }
    });

    $('#next_page').click(function() {
        var totalPages = Math.ceil(Object.keys(varData).length / pageSize);
        if(currentPage < totalPages) {
            currentPage++;
            renderVarTable(varData, currentPage);
        }
    });

    // 确认导入变量
    $('#confirm_import_btn').click(function() {
        var key = $('#var_key').val().trim();
        var file = $('#var_file')[0].files[0];
        if (!key) {
            alert('请输入变量key');
            return;
        }
        if (!file) {
            alert('请选择变量文件');
            return;
        }
        // 组合前缀和key
        var fullKey = 'var_' + key;
        var formData = new FormData();
        formData.append('key', fullKey);
        formData.append('file', file);
        $.ajax({
            url: 'placeholder_import.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                alert(res);
                $('#importVarModal').modal('hide');
                $('#var_file').val('');
                $('#var_key').val('');
                $('#var_content').html('<p class="text-muted">变量内容将显示在这里...</p>');
            },
            error: function() {
                alert('导入失败，请重试');
            }
        });
    });

    // 占位符导入
    $('#ph_import_btn').click(function() {
        var form = $('#placeholderForm')[0];
        var formData = new FormData(form);
        $.ajax({
            url: 'placeholder_import.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(res) {
                alert(res);
                $('#placeholderModal').modal('hide');
            },
            error: function() {
                alert('导入失败，请重试');
            }
        });
    });

    // 关键数据自动刷新（每5秒）
    setInterval(function() {
        // 刷新基本统计数据
        $.get(window.location.href, function(data) {
            const parser = new DOMParser();
            const doc = parser.parseFromString(data, 'text/html');
            $("#task_count").text(doc.querySelector('#task_count').textContent);
            $("#process").text(doc.querySelector('#process').textContent);
            $("#error_count").text(doc.querySelector('#error_count').textContent);
            $("#smtp_count").text(doc.querySelector('#smtp_count').textContent);
            $("#smtp_index").text(doc.querySelector('#smtp_index').textContent);
            $("#from_count").text(doc.querySelector('#from_count').textContent);
            $("#thread_count").text(doc.querySelector('#thread_count').textContent);
        });
        
        // 单独刷新守护进程状态
        $.get('daemon_status.php?key=798', function(data) {
            if (typeof data === 'string') {
                data = JSON.parse(data);
            }
            $("#daemon_status").html('<span class="' + data.daemon_class + '">' + data.daemon_status + '</span>');
            $("#daemon_uptime").text(data.daemon_uptime);
        }).fail(function() {
            $("#daemon_status").html('<span class="text-warning">状态获取失败</span>');
            $("#daemon_uptime").text('未知');
        });
    }, 5000);
</script>
</body>
</html>