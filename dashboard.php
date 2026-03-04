<?php
/**
 * 用户面板
 * 功能：
 *  1. 生成追踪代码
 *  2. 删除追踪代码
 *  3. 设置邮件提醒开关与接收邮箱
 */

require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();
global $db;

$u = current_user();
$msg = '';

$st_notif = $db->prepare('SELECT notif_email, notify_on FROM users WHERE id = ? LIMIT 1');
$st_notif->execute([$u['id']]);
if ($row = $st_notif->fetch(PDO::FETCH_ASSOC)) {
    $u['notif_email'] = $row['notif_email'];
    $u['notify_on'] = $row['notify_on'];
    $_SESSION['user']['notif_email'] = $row['notif_email'];
    $_SESSION['user']['notify_on'] = $row['notify_on'];
}

if (isset($_POST['add'])) {
    $name = trim($_POST['name'] ?? '');
    if ($name !== '') {
        $token = gen_token();
        $db->prepare('INSERT INTO codes (user_id, name, token, created_at) VALUES (?,?,?,datetime("now"))')
           ->execute([$u['id'], $name, $token]);
        $msg = '已添加追踪代码';
    }
}

if (isset($_POST['del_codes']) && !empty($_POST['sel']) && is_array($_POST['sel'])) {
    $ids = array_filter($_POST['sel'], 'is_numeric');
    if ($ids) {
        $stmt = $db->prepare('DELETE FROM codes WHERE id = ? AND user_id = ?');
        foreach ($ids as $id) {
            $stmt->execute([$id, $u['id']]);
        }
        $msg = '已删除所选代码';
    }
}

if (isset($_POST['save_notify'])) {
    $email = trim($_POST['email'] ?? '');
    $enable = isset($_POST['enable']) ? 1 : 0;

    $db->prepare('UPDATE users SET notif_email = ?, notify_on = ? WHERE id = ?')
       ->execute([$email, $enable, $u['id']]);

    $_SESSION['user']['notif_email'] = $email;
    $_SESSION['user']['notify_on'] = $enable;

    $u['notif_email'] = $email;
    $u['notify_on'] = $enable;

    $msg = '提醒设置已保存';
}

$codes = [];
$st = $db->prepare('SELECT * FROM codes WHERE user_id = ? ORDER BY id DESC');
$st->execute([$u['id']]);
$codes = $st->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<h3>面板</h3>

<div class="alert alert-info">
  <h5 class="mb-2">5 种追踪代码说明</h5>
  <ol class="mb-0 ps-3">
    <li><strong>隐藏图片追踪</strong>：通过 1x1 图片请求记录访问。</li>
    <li><strong>CSS 导入追踪</strong>：通过 CSS 资源请求记录访问。</li>
    <li><strong>背景图片追踪</strong>：通过背景图资源请求记录访问。</li>
    <li><strong>预加载追踪</strong>：通过 prefetch 请求记录访问。</li>
    <li><strong>字体追踪</strong>：通过字体资源请求记录访问。</li>
  </ol>
</div>

<?php if ($msg): ?>
  <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<form class="row g-2 mb-3" method="post">
  <div class="col-sm-6 col-md-4">
    <input name="name" class="form-control" placeholder="邮件备注">
  </div>
  <div class="col-auto">
    <button class="btn btn-success" name="add">添加</button>
  </div>
</form>

<form method="post" class="card p-3 my-3">
  <h5>邮件提醒（邮件被访问时提醒）</h5>

  <label class="form-check form-switch mb-2">
    <input type="checkbox" class="form-check-input" name="enable" <?= !empty($u['notify_on']) ? 'checked' : '' ?>>
    启用
  </label>

  <input name="email" class="form-control mb-2" placeholder="接收邮箱" value="<?= htmlspecialchars($u['notif_email'] ?? '') ?>">

  <button class="btn btn-primary" name="save_notify">保存</button>
</form>

<form method="post" class="card p-3">
  <h5>我的追踪</h5>

  <?php if (!$codes): ?>
    <p class="text-muted mb-0">暂无代码</p>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <thead>
          <tr>
            <th><input type="checkbox" id="chkall" onclick="document.querySelectorAll('.sel').forEach(el=>el.checked=this.checked);"></th>
            <th>邮件备注</th>
            <th style="min-width: 560px;">追踪代码（每条备注对应独立代码）</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($codes as $c): ?>
            <?php
              $snippets = [
                '隐藏图片' => '<img style="display:none" src="' . base_url() . '/track.php?type=img&k=' . $c['token'] . '">',
                'CSS 导入' => '<style>@import url(\'' . base_url() . '/track.php?type=css&k=' . $c['token'] . '\');</style>',
                '背景图片' => '<div style="background:url(\'' . base_url() . '/track.php?type=bg&k=' . $c['token'] . '\') no-repeat;"></div>',
                '预加载' => '<link rel="prefetch" href="' . base_url() . '/track.php?type=prefetch&k=' . $c['token'] . '">',
                '字体追踪' => '<style>@font-face{font-family:\'x\';src:url(\'' . base_url() . '/track.php?type=font&k=' . $c['token'] . '\');}</style><span style="font-family:\'x\'"></span>',
              ];
            ?>
            <tr>
              <td><input type="checkbox" class="sel" name="sel[]" value="<?= $c['id'] ?>"></td>
              <td><?= htmlspecialchars($c['name']) ?></td>
              <td>
                <?php foreach ($snippets as $label => $snippet): ?>
                  <div class="mb-1">
                    <div class="small text-muted mb-1"><?= $label ?></div>
                    <textarea rows="1" class="form-control code-text" readonly><?= htmlspecialchars($snippet) ?></textarea>
                  </div>
                <?php endforeach; ?>
              </td>
              <td>
                <a class="btn btn-sm btn-outline-secondary" href="logs.php?code=<?= $c['id'] ?>">查看</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <button class="btn btn-danger" name="del_codes" onclick="return confirm('删除选中?');">删除选中</button>
  <?php endif; ?>
</form>

<?php include 'includes/footer.php'; ?>
