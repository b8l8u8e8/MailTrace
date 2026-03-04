<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$err = '';
$msg = '';
$loginCaptchaEnabled = (cfg('login_captcha') === '' || cfg('login_captcha') === '1');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['act'] ?? '') === 'login') {
        if ($loginCaptchaEnabled) {
            $captchaInput = strtolower(trim($_POST['captcha'] ?? ''));
            $captcha = $_SESSION['login_captcha'] ?? null;
            $captchaOk = false;

            if ($captcha && isset($captcha['code'], $captcha['time'])) {
                $captchaOk = hash_equals($captcha['code'], $captchaInput) && (time() - (int)$captcha['time'] <= 300);
            }

            unset($_SESSION['login_captcha']);

            if (!$captchaOk) {
                $err = '验证码错误或已过期';
            }
        }

        if (!$err) {
            if (!login($_POST['user'], $_POST['pass'])) {
                $err = '登录失败';
            } else {
                header('Location: dashboard.php');
                exit;
            }
        }
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
      <p>登录以继续使用</p>
    </div>

    <?php if (cfg('allow_register') == '1'): ?>
    <div class="auth-nav">
      <a class="active" href="index.php">登录</a>
      <a href="register.php">注册</a>
    </div>
    <?php endif; ?>

    <?php if ($err): ?><div class="alert alert-danger"><?= htmlspecialchars($err) ?></div><?php elseif ($msg): ?><div class="alert alert-success"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <form method="post" autocomplete="off">
      <input type="hidden" name="act" value="login">
      <div class="mb-3">
        <label class="form-label">用户名</label>
        <input name="user" class="form-control" placeholder="请输入用户名" required>
      </div>
      <div class="mb-3">
        <label class="form-label">密码</label>
        <input name="pass" type="password" class="form-control" placeholder="请输入密码" required>
      </div>

      <?php if ($loginCaptchaEnabled): ?>
        <div class="mb-3">
          <label class="form-label">验证码</label>
          <div class="captcha-wrap">
            <img id="captchaImg" class="captcha-img" src="captcha.php?t=<?= time() ?>" alt="验证码" title="点击刷新验证码" onclick="refreshCaptcha()">
            <input name="captcha" class="form-control" placeholder="请输入验证码" maxlength="5" required>
          </div>
          <div class="form-text mt-1">看不清可点击验证码图片刷新</div>
        </div>
      <?php endif; ?>

      <button class="btn btn-primary w-100" style="padding: 0.65rem;">登录</button>
    </form>

    <?php if ($loginCaptchaEnabled): ?>
    <script>
    function refreshCaptcha() {
      var img = document.getElementById('captchaImg');
      if (img) {
        img.src = 'captcha.php?t=' + Date.now();
      }
    }
    </script>
    <?php endif; ?>
  </div>
</div>
<?php include 'includes/footer.php'; ?>
