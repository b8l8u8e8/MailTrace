<?php
/**
 * 重新发送激活邮件
 */
require_once 'includes/auth.php';
require_once 'includes/functions.php';
global $db;

$user = trim($_GET['user'] ?? '');
$msg = '';
$success = false;

if ($user === '') {
    $msg = '参数错误';
} else {
    $st = $db->prepare('SELECT email, token FROM pending WHERE username = ? LIMIT 1');
    $st->execute([$user]);
    $pend = $st->fetch(PDO::FETCH_ASSOC);

    if (!$pend) {
        $msg = '用户不存在，或已激活';
    } else {
        $link = base_url() . '/activate.php?token=' . $pend['token'];
        $subject = '重发激活邮件';
        $body = "请点击以下链接激活账户：\n\n{$link}\n\n如果无法点击，请复制到浏览器打开。";
        $debug = '';

        if (smtp_send($pend['email'], $subject, $body, $debug)) {
            $msg = '激活邮件已重新发送，请检查你的邮箱（包括垃圾箱）。';
            $success = true;
        } else {
            error_log("邮件重发失败: 收件人={$pend['email']}, 错误信息={$debug}");
            $msg = '邮件发送失败，请稍后重试。';
        }
    }
}

include 'includes/header.php';
?>

<div class="result-card">
  <?php if ($success): ?>
    <div class="result-icon result-icon-success">&#9993;</div>
    <h4>邮件已发送</h4>
    <p><?= htmlspecialchars($msg) ?></p>
    <a class="btn btn-primary" href="index.php">返回登录</a>
  <?php else: ?>
    <div class="result-icon result-icon-error">&#10007;</div>
    <h4>发送失败</h4>
    <p><?= htmlspecialchars($msg) ?></p>
    <a class="btn btn-outline-secondary" href="index.php">返回首页</a>
  <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
