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
    $updateFailed = false;
    $credentialChanged = false;

    $stCurrent = $db->prepare('SELECT username FROM users WHERE id = ? LIMIT 1');
    $stCurrent->execute([$uid]);
    $target = $stCurrent->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        $msg = '用户不存在';
        $updateFailed = true;
    }

    if (!$updateFailed && $newu !== '' && $newu !== $target['username']) {
        try {
            $db->prepare('UPDATE users SET username=? WHERE id=?')->execute([$newu, $uid]);
            $credentialChanged = true;
        } catch (PDOException $e) {
            $msg = '用户名重复';
            $updateFailed = true;
        }
    }

    if (!$updateFailed && $newp !== '') {
        $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($newp, PASSWORD_DEFAULT), $uid]);
        $credentialChanged = true;
    }

    if (!$updateFailed && $credentialChanged) {
        invalidate_user_auth($uid);
        if ($uid === current_user()['id']) {
            logout('index.php?relogin=1');
        }
    }

    if (!$updateFailed) {
        $msg = '用户信息已更新';
    }
}

if (isset($_POST['del_user'])) {
    $uid = intval($_POST['del_user']);
    if ($uid !== current_user()['id']) {
        $db->prepare('DELETE FROM logs WHERE code_id IN (SELECT id FROM codes WHERE user_id=?)')->execute([$uid]);
        $db->prepare('DELETE FROM codes WHERE user_id=?')->execute([$uid]);
        $db->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
        $msg = '用户已删除';
    }
}

// 访问记录管理
if (isset($_POST['clear_all_logs'])) {
    $db->exec('DELETE FROM logs');
    $msg = '已清空全部访问记录';
}

if (isset($_POST['trim_logs'])) {
    $keep = max(1, intval($_POST['keep_count'] ?? 100));
    // 对每个追踪代码，只保留最新 N 条
    $codes_all = $db->query('SELECT DISTINCT code_id FROM logs')->fetchAll(PDO::FETCH_COLUMN);
    $del_st = $db->prepare('DELETE FROM logs WHERE code_id = ? AND id NOT IN (SELECT id FROM logs WHERE code_id = ? ORDER BY created_at DESC LIMIT ?)');
    $total_deleted = 0;
    foreach ($codes_all as $cid) {
        $del_st->execute([$cid, $cid, $keep]);
        $total_deleted += $del_st->rowCount();
    }
    $msg = "已裁剪完成，共删除 {$total_deleted} 条旧记录（每个追踪代码保留最新 {$keep} 条）";
}

if (isset($_POST['clear_days_logs'])) {
    $days = max(1, intval($_POST['days'] ?? 30));
    $st = $db->prepare("DELETE FROM logs WHERE created_at < datetime('now', ? || ' days')");
    $st->execute(['-' . $days]);
    $deleted = $st->rowCount();
    $msg = "已删除 {$days} 天前的访问记录，共 {$deleted} 条";
}

$light = ($_GET['light'] ?? '1') === '1';
if ($light) {
    $users = $db->query('SELECT u.*, NULL AS cnt, NULL AS rec FROM users u')->fetchAll(PDO::FETCH_ASSOC);
} else {
    // 使用 JOIN 聚合代替嵌套子查询，避免性能问题
    $users = $db->query('
        SELECT u.*,
               COUNT(DISTINCT c.id) AS cnt,
               COUNT(l.id) AS rec
        FROM users u
        LEFT JOIN codes c ON c.user_id = u.id
        LEFT JOIN logs l ON l.code_id = c.id
        GROUP BY u.id
    ')->fetchAll(PDO::FETCH_ASSOC);
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

<div class="page-header" style="display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap;">
  <h3>&#9881; 后台管理</h3>
  <div class="admin-mode-toggle">
    <a href="<?= htmlspecialchars($urlOn) ?>" class="<?= $light ? 'active' : '' ?>">轻量模式</a>
    <a href="<?= htmlspecialchars($urlOff) ?>" class="<?= !$light ? 'active' : '' ?>">含计数</a>
  </div>
</div>

<?php if ($msg): ?><div class="alert alert-info"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<!-- SMTP / 注册设置 -->
<div class="card-section">
  <div class="card-section-title">
    <span class="section-icon section-icon-blue">&#9881;</span>
    SMTP / 注册设置
  </div>
  <form method="post">
    <div class="row g-3">
      <?php
      $fields = [
        'smtp_host'      => ['SMTP 主机', 'text', 'col-md-6'],
        'smtp_port'      => ['端口', 'text', 'col-md-3'],
        'smtp_secure'    => ['加密方式 (ssl/tls)', 'text', 'col-md-3'],
        'smtp_user'      => ['SMTP 账号', 'text', 'col-md-6'],
        'smtp_pass'      => ['SMTP 密码', 'password', 'col-md-6'],
        'smtp_from'      => ['发信邮箱', 'email', 'col-md-6'],
      ];
      foreach ($fields as $k => [$lab, $type, $col]):
      ?>
        <div class="<?= $col ?>">
          <label class="form-label"><?= $lab ?></label>
          <input type="<?= $type ?>" name="<?= $k ?>" value="<?= htmlspecialchars(cfg($k)) ?>" class="form-control">
        </div>
      <?php endforeach; ?>
    </div>
    <div class="row g-3 mt-1">
      <div class="col-md-4">
        <label class="form-check form-switch mb-0">
          <input type="hidden" name="allow_register" value="0">
          <input type="checkbox" name="allow_register" value="1" class="form-check-input" <?= cfg('allow_register') === '1' ? 'checked' : '' ?>>
          <span class="form-check-label">允许注册</span>
        </label>
      </div>
      <div class="col-md-4">
        <label class="form-check form-switch mb-0">
          <input type="hidden" name="require_invite" value="0">
          <input type="checkbox" name="require_invite" value="1" class="form-check-input" <?= cfg('require_invite') === '1' ? 'checked' : '' ?>>
          <span class="form-check-label">需要卡密</span>
        </label>
      </div>
      <div class="col-md-4">
        <label class="form-check form-switch mb-0">
          <input type="hidden" name="login_captcha" value="0">
          <input type="checkbox" name="login_captcha" value="1" class="form-check-input" <?= cfg('login_captcha') === '1' ? 'checked' : '' ?>>
          <span class="form-check-label">登录验证码</span>
        </label>
      </div>
    </div>
    <div class="mt-3">
      <button class="btn btn-primary" name="save_cfg">保存设置</button>
    </div>
  </form>
</div>

<!-- SMTP 测试 -->
<div class="card-section">
  <div class="card-section-title">
    <span class="section-icon section-icon-green">&#9993;</span>
    SMTP 测试
  </div>
  <form method="post">
    <div class="input-group" style="max-width: 480px;">
      <input name="test_to" class="form-control" placeholder="收件邮箱" required>
      <button class="btn btn-outline-secondary" name="test_mail">发送测试</button>
    </div>
    <?php if ($debug): ?><pre class="mt-2 mb-0" style="font-size: 0.78rem; color: var(--gray-500); background: var(--gray-50); padding: 0.75rem; border-radius: 0.375rem;"><?= htmlspecialchars($debug) ?></pre><?php endif; ?>
  </form>
</div>

<!-- 访问记录管理 -->
<?php
  $totalLogs = (int)$db->query('SELECT COUNT(*) FROM logs')->fetchColumn();
?>
<div class="card-section">
  <div class="card-section-title">
    <span class="section-icon section-icon-red">&#128465;</span>
    访问记录管理
    <span class="badge" style="background: var(--gray-100); color: var(--gray-600); margin-left: auto;"><?= $totalLogs ?> 条记录</span>
  </div>

  <div class="row g-3">
    <!-- 按天数清理 -->
    <div class="col-md-6">
      <form method="post" onsubmit="return confirm('确定删除指定天数之前的访问记录?');">
        <label class="form-label">清理旧记录</label>
        <div class="d-flex gap-2 align-items-center">
          <span style="white-space: nowrap; font-size: 0.85rem; color: var(--gray-600);">删除</span>
          <input type="number" name="days" value="30" min="1" max="3650" class="form-control form-control-sm" style="width: 80px;">
          <span style="white-space: nowrap; font-size: 0.85rem; color: var(--gray-600);">天前的记录</span>
          <button class="btn btn-outline-secondary btn-sm" name="clear_days_logs">执行</button>
        </div>
      </form>
    </div>

    <!-- 按数量裁剪 -->
    <div class="col-md-6">
      <form method="post" onsubmit="return confirm('确定裁剪? 每个追踪代码仅保留最新的指定条数。');">
        <label class="form-label">裁剪记录（按追踪代码）</label>
        <div class="d-flex gap-2 align-items-center">
          <span style="white-space: nowrap; font-size: 0.85rem; color: var(--gray-600);">每个保留最新</span>
          <input type="number" name="keep_count" value="100" min="1" max="10000" class="form-control form-control-sm" style="width: 80px;">
          <span style="white-space: nowrap; font-size: 0.85rem; color: var(--gray-600);">条</span>
          <button class="btn btn-outline-secondary btn-sm" name="trim_logs">执行</button>
        </div>
      </form>
    </div>
  </div>

  <div class="mt-3 pt-3" style="border-top: 1px solid var(--gray-100);">
    <form method="post" onsubmit="return confirm('确定清空全部访问记录? 此操作不可恢复！');">
      <button class="btn btn-danger btn-sm" name="clear_all_logs">清空全部访问记录</button>
      <span class="form-text ms-2">删除所有用户的所有访问记录，不可恢复</span>
    </form>
  </div>
</div>

<!-- 卡密管理 -->
<div class="card-section">
  <div class="card-section-title">
    <span class="section-icon section-icon-orange">&#9733;</span>
    卡密管理
    <span class="badge" style="background: var(--gray-100); color: var(--gray-600); margin-left: auto;"><?= count($invites) ?> 个</span>
  </div>

  <form method="post">
    <div class="row g-2 align-items-end mb-3">
      <div class="col-auto">
        <label class="form-label">生成数量</label>
        <input type="number" name="num" value="5" min="1" max="500" class="form-control" style="width: 100px;">
      </div>
      <div class="col-auto">
        <button class="btn btn-success" name="gen_inv">生成卡密</button>
      </div>
    </div>

    <?php if ($newInvites): ?>
      <div class="alert alert-success py-2 mb-3">
        本次新生成 <?= count($newInvites) ?> 个卡密
      </div>
      <div class="input-group mb-3" style="max-width: 600px;">
        <textarea id="newInvitesText" class="form-control code-text" rows="4" readonly><?= htmlspecialchars(implode("\n", $newInvites)) ?></textarea>
        <button type="button" class="btn btn-outline-primary" onclick="copyTextById('newInvitesText')">复制</button>
      </div>
    <?php endif; ?>

    <input type="hidden" name="export_scope" value="selected">

    <div class="d-flex gap-2 flex-wrap mb-3">
      <button class="btn btn-outline-secondary btn-sm" name="export_inv" value="1" onclick="this.form.export_scope.value='selected'">导出选中</button>
      <button class="btn btn-outline-secondary btn-sm" name="export_inv" value="1" onclick="this.form.export_scope.value='all'">导出全部</button>
      <button class="btn btn-outline-secondary btn-sm" name="export_inv" value="1" onclick="this.form.export_scope.value='unused'">导出未使用</button>
      <button class="btn btn-danger btn-sm" name="del_inv" onclick="return confirm('删除选中卡密?');">删除选中</button>
    </div>

    <?php if ($invites): ?>
    <div class="table-responsive">
      <table class="table table-sm">
        <thead>
          <tr>
            <th style="width: 36px;"><input type="checkbox" onclick="document.querySelectorAll('.invsel').forEach(e=>e.checked=this.checked)"></th>
            <th>卡密</th>
            <th>状态</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($invites as $inv): ?>
            <tr>
              <td><input type="checkbox" class="invsel" name="codes[]" value="<?= htmlspecialchars($inv['code']) ?>"></td>
              <td><code style="font-size: 0.8rem;"><?= htmlspecialchars($inv['code']) ?></code></td>
              <td>
                <?php if ($inv['used']): ?>
                  <span class="badge badge-status badge-status-off" style="background: var(--danger-light); color: var(--danger);">已使用</span>
                <?php else: ?>
                  <span class="badge badge-status badge-status-on" style="background: var(--success-light); color: var(--success);">可用</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php else: ?>
      <div class="empty-state">
        <p>暂无卡密</p>
      </div>
    <?php endif; ?>
  </form>
</div>

<!-- 添加用户 -->
<div class="card-section">
  <div class="card-section-title">
    <span class="section-icon section-icon-green">+</span>
    添加用户
  </div>
  <form method="post">
    <div class="row g-2 align-items-end">
      <div class="col-md-4">
        <label class="form-label">用户名</label>
        <input name="new_user" class="form-control" placeholder="用户名" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">密码</label>
        <input name="new_pass" class="form-control" placeholder="密码" required>
      </div>
      <div class="col-md-2 d-flex align-items-center" style="padding-bottom: 2px;">
        <label class="form-check form-switch mb-0">
          <input type="checkbox" name="new_admin" class="form-check-input">
          <span class="form-check-label" style="font-size: 0.85rem;">管理员</span>
        </label>
      </div>
      <div class="col-md-2">
        <button class="btn btn-success w-100" name="add_user">添加</button>
      </div>
    </div>
  </form>
</div>

<!-- 用户列表 -->
<div class="card-section">
  <div class="card-section-title">
    <span class="section-icon section-icon-cyan">&#9783;</span>
    用户列表
    <span class="badge" style="background: var(--gray-100); color: var(--gray-600); margin-left: auto;"><?= count($users) ?> 人</span>
  </div>

  <div class="table-responsive">
    <table class="table table-sm">
      <thead>
        <tr>
          <th>ID</th>
          <th>用户名</th>
          <th>角色</th>
          <?php if (!$light): ?><th>追踪数</th><th>记录数</th><?php endif; ?>
          <th style="min-width: 320px;">操作</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($users as $u): ?>
          <tr>
            <td><?= $u['id'] ?></td>
            <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
            <td>
              <?php if ($u['is_admin']): ?>
                <span class="badge" style="background: var(--primary-50); color: var(--primary);">管理员</span>
              <?php else: ?>
                <span class="badge" style="background: var(--gray-100); color: var(--gray-600);">用户</span>
              <?php endif; ?>
            </td>
            <?php if (!$light): ?>
              <td><?= $u['cnt'] ?></td>
              <td><?= $u['rec'] ?></td>
            <?php endif; ?>
            <td>
              <div class="d-flex gap-1 align-items-center flex-wrap">
                <form method="post" class="d-inline-flex gap-1 align-items-center flex-wrap">
                  <input type="hidden" name="upd_user" value="1">
                  <input type="hidden" name="uid" value="<?= $u['id'] ?>">
                  <input name="uname" class="form-control form-control-sm" style="width: 140px;" placeholder="新用户名">
                  <input name="upass" type="password" class="form-control form-control-sm" style="width: 140px;" placeholder="新密码">
                  <button class="btn btn-sm btn-outline-primary">修改</button>
                </form>
                <?php if ($u['id'] !== current_user()['id']): ?>
                  <form method="post" class="d-inline" onsubmit="return confirm('确定删除该用户及其所有数据?');">
                    <input type="hidden" name="del_user" value="<?= $u['id'] ?>">
                    <button class="btn btn-sm btn-danger">删除</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
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
