<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();
global $db;

$u = current_user();
$msg = '';
$err = '';

if (isset($_POST['delete'])) {
    $id = $u['id'];
    $db->prepare('DELETE FROM logs WHERE code_id IN (SELECT id FROM codes WHERE user_id=?)')->execute([$id]);
    $db->prepare('DELETE FROM codes WHERE user_id=?')->execute([$id]);
    $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    logout();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save'])) {
    $newuser = trim($_POST['username']);
    $cur = $_POST['cur'];
    $newp = $_POST['new'];
    $st = $db->prepare('SELECT * FROM users WHERE id=?');
    $st->execute([$u['id']]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if ($row && password_verify($cur, $row['password_hash'])) {
        if ($newuser && $newuser != $u['username']) {
            try {
                $db->prepare('UPDATE users SET username=? WHERE id=?')->execute([$newuser, $u['id']]);
                $_SESSION['user']['username'] = $newuser;
            } catch (PDOException $e) {
                $err = '用户名已存在';
            }
        }
        if ($newp) {
            $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($newp, PASSWORD_DEFAULT), $u['id']]);
        }
        if (!$err) {
            $msg = '已更新';
        }
    } else {
        $err = '当前密码错误';
    }
}

include 'includes/header.php';
?>

<div class="auth-wrapper">
  <div class="auth-card">
    <div class="auth-brand">
      <div class="brand-logo" style="background: linear-gradient(135deg, #6b7280, #374151);">&#9998;</div>
      <h4>个人资料</h4>
      <p><?= htmlspecialchars(current_user()['username']) ?></p>
    </div>

    <?php if ($err): ?>
      <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
    <?php elseif ($msg): ?>
      <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="save" value="1">
      <div class="mb-3">
        <label class="form-label">用户名</label>
        <input name="username" class="form-control" value="<?= htmlspecialchars(current_user()['username']) ?>" placeholder="用户名">
      </div>
      <div class="mb-3">
        <label class="form-label">当前密码</label>
        <input name="cur" type="password" class="form-control" placeholder="请输入当前密码" required>
      </div>
      <div class="mb-3">
        <label class="form-label">新密码</label>
        <input name="new" type="password" class="form-control" placeholder="留空则不修改">
      </div>
      <button class="btn btn-primary w-100" style="padding: 0.65rem;">保存修改</button>
    </form>

    <hr style="border-color: var(--gray-200); margin: 1.5rem 0 1rem;">

    <form method="post" onsubmit="return confirm('确定删除账户? 所有数据将不可恢复！');">
      <button class="btn btn-danger w-100" name="delete" style="padding: 0.65rem;">删除账户</button>
      <div class="form-text mt-2 text-center">删除后所有数据将永久丢失，无法恢复</div>
    </form>
  </div>
</div>

<?php include 'includes/footer.php'; ?>
