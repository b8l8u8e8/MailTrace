<?php
/**
 * 用户注册页面
 * 流程：
 *  1. 校验基本信息（用户名/密码/邮箱）
 *  2. 如果启用了卡密功能（require_invite = 1）则校验卡密有效且未使用，但此时不标记已使用
 *  3. 只检查 users 表避免未激活用户占用用户名；如存在则拒绝
 *  4. 删除同名 pending 记录（用户可反复请求重发激活邮件）
 *  5. 写入 pending（包含 invite_code），发送激活邮件
 */

require_once 'includes/auth.php';
require_once 'includes/functions.php';

if (cfg('allow_register') !== '1') {
    die('注册已关闭');
}

global $db;
$need_invite = cfg('require_invite') === '1';
$err = '';
$ok  = '';

$username = trim($_POST['user'] ?? '');
$email = trim($_POST['email'] ?? '');
$invite = trim($_POST['invite'] ?? '');
$password = $_POST['pass'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($username === '' || $password === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = '信息不完整';
    }

    if (!$err && $need_invite) {
        $st = $db->prepare('SELECT used FROM invite_codes WHERE code = ? LIMIT 1');
        $st->execute([$invite]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $err = '卡密无效';
        } elseif ($row['used']) {
            $err = '卡密已被使用';
        }
    }

    if (!$err) {
        $st = $db->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
        $st->execute([$username]);
        if ($st->fetch()) {
            $err = '用户名已存在';
        }
    }

    if (!$err) {
        $db->prepare('DELETE FROM pending WHERE username = ?')->execute([$username]);

        $hash  = password_hash($password, PASSWORD_DEFAULT);
        $token = gen_token();

        $db->prepare('INSERT INTO pending (username, password_hash, email, token, invite_code, created_at) VALUES (?,?,?,?,?,datetime("now"))')
           ->execute([$username, $hash, $email, $token, $invite]);

        $link = base_url() . '/activate.php?token=' . $token;
        $subject = '激活你的账户';
        $body = "请点击以下链接激活账户：\n\n{$link}\n\n如果无法点击，请复制到浏览器打开。";
        $debug = '';

        if (smtp_send($email, $subject, $body, $debug)) {
            $ok = '注册成功！请前往邮箱激活账户。<br>如果没有收到邮件，点击 <a href="resend.php?user=' . urlencode($username) . '">重新发送激活邮件</a>。';
        } else {
            $err = '邮件发送失败，请稍后重试。';
            error_log("邮件发送错误：收件人={$email}, 错误信息={$debug}");
        }

        $username = $email = $invite = $password = '';
    }
}

include 'includes/header.php';
$_siteName = cfg('site_name') ?: '追踪系统';
?>

<div class="auth-wrapper">
  <div class="auth-card">
    <div class="auth-brand">
      <div class="brand-logo">&#9993;</div>
      <h4><?= htmlspecialchars($_siteName) ?></h4>
      <p>创建新账户</p>
    </div>

    <div class="auth-nav">
      <a href="index.php">登录</a>
      <a class="active" href="register.php">注册</a>
    </div>

    <?php if ($err): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
    <?php elseif ($ok): ?>
      <div class="alert alert-success"><?= $ok ?></div>
    <?php endif; ?>

    <form method="post">
      <div class="mb-3">
        <label class="form-label">用户名</label>
        <input name="user" class="form-control" placeholder="请输入用户名" value="<?= htmlspecialchars($username) ?>" required>
      </div>
      <div class="mb-3">
        <label class="form-label">密码</label>
        <input name="pass" type="password" class="form-control" placeholder="请输入密码" required>
      </div>
      <div class="mb-3">
        <label class="form-label">邮箱</label>
        <input name="email" type="email" class="form-control" placeholder="请输入邮箱地址" value="<?= htmlspecialchars($email) ?>" required>
      </div>
      <?php if ($need_invite): ?>
      <div class="mb-3">
        <label class="form-label">卡密</label>
        <input name="invite" class="form-control" placeholder="请输入邀请卡密" value="<?= htmlspecialchars($invite) ?>" required>
      </div>
      <?php endif; ?>
      <button class="btn btn-success w-100" style="padding: 0.65rem;">注册</button>
    </form>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
