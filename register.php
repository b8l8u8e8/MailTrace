<?php
/**
 * 用户注册页面
 * 流程：
 *  1. 校验基本信息（用户名/密码/邮箱）
 *  2. 如果启用了卡密功能（require_invite = 1）则校验卡密有效且未使用，但 **此时不标记已使用**
 *  3. 只检查 users 表避免未激活用户占用用户名；如存在则拒绝
 *  4. 删除同名 pending 记录（用户可反复请求重发激活邮件）
 *  5. 写入 pending（包含 invite_code），发送激活邮件
 *
 * 注意：用户名只有在邮件激活成功后才真正写入 users 表并占用，卡密也只在激活成功后标记为 used=1
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

/* ---- 保留用户输入 —— 用于回填表单 ---- */
$username = trim($_POST['user'] ?? '');
$email    = trim($_POST['email'] ?? '');
$invite   = trim($_POST['invite'] ?? '');
$password = $_POST['pass'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* ---------- 基础校验 ---------- */
    if ($username === '' || $password === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = '信息不完整';
    }

    /* ---------- 卡密校验（需要时） ---------- */
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

    /* ---------- 重名校验（仅 users 表） ---------- */
    if (!$err) {
        $st = $db->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
        $st->execute([$username]);
        if ($st->fetch()) {
            $err = '用户名已存在';
        }
    }

    /* ---------- 写入 pending & 发送激活邮件 ---------- */
    if (!$err) {
        // 删除旧的 pending 记录，防止多次试错占用
        $db->prepare('DELETE FROM pending WHERE username = ?')->execute([$username]);

        $hash  = password_hash($password, PASSWORD_DEFAULT);
        $token = gen_token();

        $db->prepare('INSERT INTO pending (username, password_hash, email, token, invite_code, created_at) VALUES (?,?,?,?,?,datetime("now"))')
           ->execute([$username, $hash, $email, $token, $invite]);

        // 发送激活邮件（使用 smtp_send 替代 send_mail）
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
        
        // 清空表单变量，防止刷新重复提交
        $username = $email = $invite = $password = '';
    }
}

include 'includes/header.php';
?>

<?php if ($err): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
<?php elseif ($ok): ?>
  <div class="alert alert-success"><?= $ok ?></div>
<?php endif; ?>

<form method="post">
  <input name="user" class="form-control mb-2" placeholder="用户名" value="<?= htmlspecialchars($username) ?>" required>
  <input name="pass" type="password" class="form-control mb-2" placeholder="密码" value="<?= htmlspecialchars($password) ?>" required>
  <input name="email" type="email" class="form-control mb-2" placeholder="邮箱" value="<?= htmlspecialchars($email) ?>" required>
  <?php if ($need_invite): ?>
    <input name="invite" class="form-control mb-2" placeholder="卡密" value="<?= htmlspecialchars($invite) ?>" required>
  <?php endif; ?>
  <button class="btn btn-success w-100">注册</button>
</form>

</div></div>
<?php include 'includes/footer.php'; ?>