<?php
/**
 * 重新发送激活邮件
 */
require_once 'includes/auth.php';
require_once 'includes/functions.php';
global $db;

$user = trim($_GET['user'] ?? '');
if ($user === '') {
    die('参数错误');
}

$st = $db->prepare('SELECT email, token FROM pending WHERE username = ? LIMIT 1');
$st->execute([$user]);
$pend = $st->fetch(PDO::FETCH_ASSOC);

if (!$pend) {
    die('用户不存在，或已激活');
}

$link = base_url() . '/activate.php?token=' . $pend['token'];
$subject = '重发激活邮件';
$body = "请点击以下链接激活账户：\n\n{$link}\n\n如果无法点击，请复制到浏览器打开。";
$debug = ''; // 存储 SMTP 调试信息

// 使用 smtp_send() 替代 send_mail()
if (smtp_send($pend['email'], $subject, $body, $debug)) {
    echo '激活邮件已重新发送，请检查你的邮箱（包括垃圾箱）。';
} else {
    // 记录错误日志
    error_log("邮件重发失败: 收件人={$pend['email']}, 错误信息={$debug}");
    die('邮件发送失败，请稍后重试。');
}