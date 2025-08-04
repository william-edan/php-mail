<?php
/**
 * 测试新增的邮件功能
 * 1. RFC 2047主题编码
 * 2. multipart/alternative 邮件结构
 */

require_once __DIR__ . '/vendor/autoload.php';

// 包含send.php中的函数
require_once 'send.php';

echo "=== 邮件新功能测试 ===\n\n";

// 测试1: RFC 2047主题编码
echo "1. 测试RFC 2047主题编码功能\n";
echo "-------------------------------\n";

$testSubjects = [
    '【三菱UFJ銀行】重要なお知らせ',
    '🚨 緊急通知：アカウントセキュリティ更新が必要です',
    'Apple ID安全验证 - 立即处理避免账户被锁定',
    'Your PayPal account has been limited - Verify now',
    '超级长的邮件主题测试，包含中文、English、日本語、😀😃😄😁emoji等各种字符，测试分段编码功能是否正常工作',
    'Simple ASCII subject'  // 纯ASCII，不应该被编码
];

foreach ($testSubjects as $i => $subject) {
    echo "原主题 " . ($i+1) . ": " . $subject . "\n";
    
    // 测试Base64编码
    $encodedB64 = encodeSubjectRFC2047($subject, 'UTF-8', 'B');
    echo "Base64编码: " . $encodedB64 . "\n";
    
    // 测试Quoted-Printable编码
    $encodedQP = encodeSubjectRFC2047($subject, 'UTF-8', 'Q');
    echo "QP编码: " . $encodedQP . "\n";
    
    echo "长度: " . strlen($encodedB64) . " 字节\n";
    echo "---\n";
}

echo "\n2. 测试multipart/alternative功能\n";
echo "-----------------------------------\n";

$testHtmlContents = [
    // 简单HTML
    '<p>亲爱的用户，</p><p>您的账户需要验证。请<a href="https://example.com/verify">点击这里</a>进行验证。</p><p>谢谢！</p>',
    
    // 复杂HTML
    '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>银行通知</title>
</head>
<body>
    <div style="font-family: Arial, sans-serif;">
        <h2>【重要通知】账户安全验证</h2>
        <p>尊敬的客户：</p>
        <p>我们检测到您的账户存在异常活动。为了保护您的资金安全，请立即进行身份验证。</p>
        <div style="margin: 20px 0;">
            <a href="https://fake-bank.com/verify" style="background: #007cba; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">立即验证</a>
        </div>
        <p>如果您无法点击上述链接，请复制以下网址到浏览器：<br>
        <a href="https://fake-bank.com/verify">https://fake-bank.com/verify</a></p>
        <hr>
        <p style="font-size: 12px; color: #666;">此邮件由系统自动发送，请勿回复。</p>
    </div>
</body>
</html>',
    
    // 纯文本（不含HTML标签）
    '这是一封纯文本邮件，不包含任何HTML标签。应该被识别为纯文本格式。'
];

foreach ($testHtmlContents as $i => $content) {
    echo "测试内容 " . ($i+1) . ":\n";
    echo "是否HTML格式: " . (isHtmlContent($content) ? '是' : '否') . "\n";
    
    if (isHtmlContent($content)) {
        $plainText = htmlToPlainNoLink($content);
        echo "HTML版本长度: " . strlen($content) . " 字符\n";
        echo "纯文本版本长度: " . strlen($plainText) . " 字符\n";
        echo "纯文本版本内容:\n";
        echo "<<<PLAINTEXT\n";
        echo $plainText . "\n";
        echo "PLAINTEXT>>>\n";
    } else {
        echo "内容类型: 纯文本\n";
        echo "内容: " . substr($content, 0, 100) . (strlen($content) > 100 ? '...' : '') . "\n";
    }
    echo "---\n";
}

echo "\n3. 功能组合测试\n";
echo "----------------\n";

// 模拟一个完整的邮件发送场景
$testEmail = [
    'subject' => '🏦【中国银行】您的网银登录密码即将过期',
    'content' => '
    <div style="font-family: \'Microsoft YaHei\', sans-serif; max-width: 600px;">
        <img src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==" alt="中国银行" style="height: 50px;">
        <h2 style="color: #c41e3a;">安全提醒</h2>
        <p>尊敬的客户：</p>
        <p>您好！我们系统检测到您的网银登录密码将在<strong>3天后</strong>过期。</p>
        <p>为确保您的账户安全，请尽快登录网银更新您的密码：</p>
        <div style="text-align: center; margin: 30px 0;">
            <a href="https://fake-boc.com/update-password" 
               style="background: linear-gradient(45deg, #c41e3a, #8b0000); 
                      color: white; 
                      padding: 15px 30px; 
                      text-decoration: none; 
                      border-radius: 8px; 
                      font-weight: bold;
                      display: inline-block;">
                🔒 立即更新密码
            </a>
        </div>
        <div style="background: #f8f9fa; padding: 15px; border-left: 4px solid #c41e3a; margin: 20px 0;">
            <p><strong>⚠️ 重要提醒：</strong></p>
            <ul>
                <li>请在密码过期前及时更新</li>
                <li>新密码应包含大小写字母、数字和特殊字符</li>
                <li>不要使用生日、手机号等易猜测的密码</li>
            </ul>
        </div>
        <p>如有疑问，请拨打客服热线：95566</p>
        <hr style="border: none; border-top: 1px solid #eee; margin: 30px 0;">
        <p style="font-size: 12px; color: #888;">
            中国银行股份有限公司<br>
            此邮件为系统自动发送，请勿直接回复
        </p>
    </div>'
];

echo "邮件主题: " . $testEmail['subject'] . "\n";
echo "RFC2047编码后: " . encodeSubjectRFC2047($testEmail['subject']) . "\n\n";

echo "邮件内容分析:\n";
echo "- 是否HTML格式: " . (isHtmlContent($testEmail['content']) ? '是' : '否') . "\n";
echo "- HTML版本长度: " . strlen($testEmail['content']) . " 字符\n";

$plainVersion = htmlToPlainNoLink($testEmail['content']);
echo "- 纯文本版本长度: " . strlen($plainVersion) . " 字符\n";
echo "- 链接处理: 已移除所有超链接，保留链接文字\n\n";

echo "纯文本版本预览:\n";
echo "<<<PLAINTEXT\n";
echo $plainVersion . "\n";
echo "PLAINTEXT>>>\n";

echo "\n✅ 测试完成！\n";
echo "这些功能现在已集成到send.php中，可通过.env配置文件控制启用/禁用。\n"; 