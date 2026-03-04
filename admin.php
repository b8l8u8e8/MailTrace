<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();
if (!is_admin()) {
    die('无权');
}

global $db;
$msg = '';
$debug = '';
$newInvites = [];

if (isset($_POST['save_cfg'])) {
    foreach (['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_secure','smtp_from','allow_register','require_invite','login_captcha'] as $k) {
        cfg($k, $_POST[$k] ?? '');
    }
    $msg = '配置已保存';
}

if (isset($_POST['test_mail'])) {
    $ok = smtp_send($_POST['test_to'], 'SMTP 测试', '成功发送', $debug);
    $msg = $ok ? '发送成功' : '发送失败';
}

if (isset($_POST['gen_inv'])) {
    $n = max(1, min(500, intval($_POST['num'] ?? 1)));
    $st = $db->prepare('INSERT INTO invite_codes(code) VALUES (?)');
    for ($i = 0; $i < $n; $i++) {
        $token = gen_token();
        $st->execute([$token]);
        $newInvites[] = $token;
    }
    $msg = "已生成 {$n} 个卡密";
}

if (isset($_POST['del_inv']) && !empty($_POST['codes']) && is_array($_POST['codes'])) {
    $st = $db->prepare('DELETE FROM invite_codes WHERE code = ?');
    foreach ($_POST['codes'] as $code) {
        $st->execute([$code]);
    }
    $msg = '已删除选中卡密';
}

if (isset($_POST['export_inv'])) {
    $scope = $_POST['export_scope'] ?? 'selected';
    $codes = [];

    if ($scope === 'selected') {
        $selected = array_values(array_filter($_POST['codes'] ?? [], static function ($v) {
            return is_string($v) && $v !== '' && strlen($v) <= 128;
        }));

        if ($selected) {
            $placeholders = implode(',', array_fill(0, count($selected), '?'));
            $st = $db->prepare("SELECT code FROM invite_codes WHERE code IN ({$placeholders}) ORDER BY rowid DESC");
            $st->execute($selected);
            $codes = $st->fetchAll(PDO::FETCH_COLUMN);
        }
    } elseif ($scope === 'unused') {
        $codes = $db->query('SELECT code FROM invite_codes WHERE used = 0 ORDER BY rowid DESC')->fetchAll(PDO::FETCH_COLUMN);
    } elseif ($scope === 'used') {
        $codes = $db->query('SELECT code FROM invite_codes WHERE used = 1 ORDER BY rowid DESC')->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $codes = $db->query('SELECT code FROM invite_codes ORDER BY rowid DESC')->fetchAll(PDO::FETCH_COLUMN);
    }

    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="invite_codes_' . date('Ymd_His') . '.txt"');
    echo implode("\r\n", $codes);
    exit;
}

if (isset($_POST['add_user'])) {
    $u = trim($_POST['new_user'] ?? '');
    $p = $_POST['new_pass'] ?? '';
    if ($u && $p) {
        try {
            $db->prepare('INSERT INTO users(username,password_hash,is_admin) VALUES (?,?,?)')
               ->execute([$u, password_hash($p, PASSWORD_DEFAULT), isset($_POST['new_admin']) ? 1 : 0]);
            $msg = '用户添加成功';
        } catch (PDOException $e) {
            $msg = '用户名已存在';
        }
    }
}

if (isset($_POST['upd_user'])) {
    $uid = intval($_POST['uid'] ?? 0);
    $newu = trim($_POST['uname'] ?? '');
    $newp = $_POST['upass'] ?? '';

    if ($newu !== '') {
        try {
            $db->prepare('UPDATE users SET username=? WHERE id=?')->execute([$newu, $uid]);
        } catch (PDOException $e) {
            $msg = '用户名重复';
        }
    }

    if ($newp !== '') {
        $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($newp, PASSWORD_DEFAULT), $uid]);
    }

    if ($msg === '') {
        $msg = '用户信息已更新';
    }
}

if (isset($_GET['del_user'])) {
    $uid = intval($_GET['del_user']);
    if ($uid !== current_user()['id']) {
        $db->prepare('DELETE FROM logs WHERE code_id IN (SELECT id FROM codes WHERE user_id=?)')->execute([$uid]);
        $db->prepare('DELETE FROM codes WHERE user_id=?')->execute([$uid]);
        $db->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
        $msg = '用户已删除';
    }
}

$light = ($_GET['light'] ?? '1') === '1';
if ($light) {
    $users = $db->query('SELECT u.*, NULL AS cnt, NULL AS rec FROM users u')->fetchAll(PDO::FETCH_ASSOC);
} else {
    $users = $db->query('SELECT u.*, (SELECT COUNT(*) FROM codes WHERE user_id=u.id) cnt, (SELECT COUNT(*) FROM logs WHERE code_id IN (SELECT id FROM codes WHERE user_id=u.id)) rec FROM users u')->fetchAll(PDO::FETCH_ASSOC);
}

$invites = $db->query('SELECT * FROM invite_codes ORDER BY rowid DESC')->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';

$qs = $_GET;
unset($qs['light']);
$self = basename($_SERVER['PHP_SELF']);
$on = $qs;
$on['light'] = '1';
$off = $qs;
$off['light'] = '0';
$urlOn = $self . '?' . http_build_query($on);
$urlOff = $self . '?' . http_build_query($off);
?>

<h3 class="d-flex align-items-center gap-2 flex-wrap">
  后台管理
  <a href="<?= htmlspecialchars($urlOn) ?>" class="btn btn-sm btn-outline-secondary">轻量模式</a>
  <a href="<?= htmlspecialchars($urlOff) ?>" class="btn btn-sm btn-outline-secondary">含计数</a>
  <span class="badge text-bg-<?= $light ? 'secondary' : 'primary' ?> ms-1"><?= $light ? '轻量中' : '含计数' ?></span>
</h3>
<?php if ($msg): ?><div class="alert alert-info"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<form method="post" class="card p-3 mb-3">
  <h5>SMTP / 注册设置</h5>
  <?php foreach(['smtp_host'=>'SMTP 主机','smtp_port'=>'端口','smtp_user'=>'账号','smtp_pass'=>'密码','smtp_secure'=>'加密(ssl/tls/none)','smtp_from'=>'发信邮箱','allow_register'=>'允许注册(1/0)','require_invite'=>'需卡密(1/0)','login_captcha'=>'登录验证码(1/0)'] as $k => $lab): ?>
    <div class="mb-2">
      <label class="form-label"><?= $lab ?></label>
      <input name="<?= $k ?>" value="<?= htmlspecialchars(cfg($k)) ?>" class="form-control">
    </div>
  <?php endforeach; ?>
  <button class="btn btn-primary" name="save_cfg">保存设置</button>
</form>

<form method="post" class="card p-3 mb-3">
  <h5>SMTP 测试</h5>
  <div class="input-group">
    <input name="test_to" class="form-control" placeholder="收件邮箱" required>
    <button class="btn btn-outline-secondary" name="test_mail">发送测试</button>
  </div>
  <?php if ($debug): ?><pre class="mt-2 mb-0 text-muted small"><?= htmlspecialchars($debug) ?></pre><?php endif; ?>
</form>

<form method="post" class="card p-3 mb-3">
  <h5>卡密管理</h5>
  <div class="row g-2 align-items-center mb-2">
    <div class="col-auto"><input type="number" name="num" value="5" min="1" max="500" class="form-control"></div>
    <div class="col-auto"><button class="btn btn-success" name="gen_inv">生成卡密</button></div>
  </div>

  <?php if ($newInvites): ?>
    <div class="alert alert-success py-2 mb-2">
      本次新生成 <?= count($newInvites) ?> 个卡密，可直接复制导入。
    </div>
    <div class="input-group mb-3">
      <textarea id="newInvitesText" class="form-control code-text" rows="4" readonly><?= htmlspecialchars(implode("\n", $newInvites)) ?></textarea>
      <button type="button" class="btn btn-outline-primary" onclick="copyTextById('newInvitesText')">复制本次生成</button>
    </div>
  <?php endif; ?>

  <input type="hidden" name="export_scope" value="selected">

  <div class="d-flex gap-2 flex-wrap mb-2">
    <button class="btn btn-outline-secondary" name="export_inv" value="1" onclick="this.form.export_scope.value='selected'">导出选中TXT</button>
    <button class="btn btn-outline-secondary" name="export_inv" value="1" onclick="this.form.export_scope.value='all'">导出全部TXT</button>
    <button class="btn btn-outline-secondary" name="export_inv" value="1" onclick="this.form.export_scope.value='unused'">导出未使用TXT</button>
    <button class="btn btn-danger" name="del_inv" onclick="return confirm('删除选中?');">删除选中</button>
  </div>

  <div class="table-responsive">
    <table class="table table-bordered mt-2">
      <tr>
        <th><input type="checkbox" onclick="document.querySelectorAll('.invsel').forEach(e=>e.checked=this.checked)"></th>
        <th>卡密</th>
        <th>已用</th>
      </tr>
      <?php foreach ($invites as $inv): ?>
        <tr>
          <td><input type="checkbox" class="invsel" name="codes[]" value="<?= htmlspecialchars($inv['code']) ?>"></td>
          <td><code><?= htmlspecialchars($inv['code']) ?></code></td>
          <td><?= $inv['used'] ? '是' : '否' ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
</form>

<form method="post" class="card p-3 mb-3">
  <h5>添加用户</h5>
  <div class="row g-2 align-items-center">
    <div class="col-md-4"><input name="new_user" class="form-control" placeholder="用户名" required></div>
    <div class="col-md-4"><input name="new_pass" class="form-control" placeholder="密码" required></div>
    <div class="col-md-2">
      <label class="form-check form-switch mt-2">
        <input type="checkbox" name="new_admin" class="form-check-input"> 管理员
      </label>
    </div>
    <div class="col-md-2"><button class="btn btn-success w-100" name="add_user">添加</button></div>
  </div>
</form>

<h5>用户列表</h5>
<div class="table-responsive">
  <table class="table table-striped">
    <tr><th>ID</th><th>用户名</th><th>管理员</th><th>追踪</th><th>记录</th><th>编辑</th></tr>
    <?php foreach($users as $u): ?>
      <tr>
        <td><?= $u['id'] ?></td>
        <td><?= htmlspecialchars($u['username']) ?></td>
        <td><?= $u['is_admin'] ? '是' : '否' ?></td>
        <td><?= $u['cnt'] ?></td>
        <td><?= $u['rec'] ?></td>
        <td>
          <form method="post" class="d-inline-flex gap-1 flex-wrap">
            <input type="hidden" name="upd_user" value="1">
            <input type="hidden" name="uid" value="<?= $u['id'] ?>">
            <input name="uname" class="form-control form-control-sm" placeholder="新用户名">
            <input name="upass" class="form-control form-control-sm" placeholder="新密码">
            <button class="btn btn-sm btn-outline-primary">修改</button>
          </form>
          <?php if ($u['id'] !== current_user()['id']): ?>
            <a class="btn btn-sm btn-danger ms-1" href="admin.php?del_user=<?= $u['id'] ?>" onclick="return confirm('删除?');">删</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<script>
function copyTextById(id) {
  var el = document.getElementById(id);
  if (!el) return;

  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(el.value).then(function () {
      alert('已复制到剪贴板');
    }).catch(function () {
      alert('复制失败，请手动复制');
    });
    return;
  }

  el.focus();
  el.select();
  try {
    document.execCommand('copy');
    alert('已复制到剪贴板');
  } catch (e) {
    alert('复制失败，请手动复制');
  }
}
</script>

<?php include 'includes/footer.php'; ?>
