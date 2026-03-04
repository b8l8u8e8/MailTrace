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

/* ---- 确保每次打开页面都使用数据库中的最新提醒设置 ---- */
$st_notif = $db->prepare('SELECT notif_email, notify_on FROM users WHERE id = ? LIMIT 1');
$st_notif->execute([$u['id']]);
if ($row = $st_notif->fetch(PDO::FETCH_ASSOC)) {
    $u['notif_email'] = $row['notif_email'];
    $u['notify_on']   = $row['notify_on'];
    $_SESSION['user']['notif_email'] = $row['notif_email'];
    $_SESSION['user']['notify_on']   = $row['notify_on'];
}
$msg = '';

/* ---------- 添加追踪代码 ---------- */
if (isset($_POST['add'])) {
    $name = trim($_POST['name'] ?? '');
    if ($name !== '') {
        $token = gen_token();
        $db->prepare('INSERT INTO codes (user_id, name, token, created_at) VALUES (?,?,?,datetime("now"))')
           ->execute([$u['id'], $name, $token]);
        $msg = '已添加追踪代码';
    }
}

/* ---------- 删除追踪代码 ---------- */
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

/* ---------- 保存邮件提醒 ---------- */
if (isset($_POST['save_notify'])) {
    $email  = trim($_POST['email'] ?? '');
    $enable = isset($_POST['enable']) ? 1 : 0;

    $db->prepare('UPDATE users SET notif_email = ?, notify_on = ? WHERE id = ?')
       ->execute([$email, $enable, $u['id']]);

    // 更新会话，确保刷新立即生效
    $_SESSION['user']['notif_email'] = $email;
    $_SESSION['user']['notify_on']   = $enable;

    $u['notif_email'] = $email;
    $u['notify_on']   = $enable;

    $msg = '提醒设置已保存';
}

/* ---------- 获取当前用户所有代码 ---------- */
$codes = [];
$st = $db->prepare('SELECT * FROM codes WHERE user_id = ? ORDER BY id DESC');
$st->execute([$u['id']]);
$codes = $st->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>
<h3>面板</h3>

<!-- ===== 追踪代码类型说明 ===== -->
<div class="alert alert-info">
<h5 class="mb-2">下面 5 种邮件追踪代码说明</h5>
<ol class="mb-0 ps-3">
<li><strong>隐藏图片追踪</strong>：插入不可见的1×1像素图片，邮件加载时服务器记录访问行为。(目测只要能发出追踪代码都可以追踪)</li>
<li><strong>CSS导入追踪</strong>：通过CSS@import加载远程样式表，服务器记录样式请求动作。</li>
<li><strong>背景图片追踪</strong>：将追踪链接设为元素背景图，邮件渲染时服务器记录资源加载。</li>
<li><strong>预加载资源追踪</strong>：利用浏览器prefetch提前加载追踪链接，服务器记录预加载行为。</li>
<li><strong>字体资源追踪</strong>：通过@font-face引用远程字体文件，服务器记录字体加载请求。</li>
</ol>
</div>

<?php if ($msg): ?>
  <div class="alert alert-success"><?php echo htmlspecialchars($msg); ?></div>
<?php endif; ?>

<!-- ===== 添加代码 ===== -->
<form class="row g-2" method="post">
  <div class="col-auto">
    <input name="name" class="form-control" placeholder="邮件备注">
  </div>
  <button class="btn btn-success col-auto" name="add">添加</button>
</form>

<!-- ===== 邮件提醒设置 ===== -->
<form method="post" class="card p-3 my-3">
  <h5>邮件提醒(邮件被访问时提醒)</h5>

  <label class="form-check form-switch mb-2">
    <input type="checkbox" class="form-check-input" name="enable" <?php echo $u['notify_on'] ? 'checked' : ''; ?>>
    启用
  </label>

  <input name="email" class="form-control mb-2" placeholder="接收邮箱" value="<?php echo htmlspecialchars($u['notif_email'] ?? ''); ?>">

  <button class="btn btn-primary" name="save_notify">保存</button>
</form>

<!-- ===== 追踪代码列表 ===== -->
<form method="post" class="card p-3">
  <h5>我的追踪</h5>
  <?php if (!$codes): ?>
      <p class="text-muted">暂无代码</p>
  <?php else: ?>
  <table class="table table-sm align-middle">
    <thead>
      <tr>
        <th><input type="checkbox" id="chkall" onclick="document.querySelectorAll('.sel').forEach(el=>el.checked=this.checked);"></th>
        <th>邮件备注</th>
        <th>5种追踪代码,请根据上面代码解释选择你觉得最合适的一种,注意每个邮件备注代码都不一样,因此才能区分不同邮件的追踪情况</th>
        <th>操作</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($codes as $c): ?>
      <tr>
        <td><input type="checkbox" class="sel" name="sel[]" value="<?php echo $c['id']; ?>"></td>
        <td><?php echo htmlspecialchars($c['name']); ?></td>
        <td style="max-width:450px">
          <textarea rows="1" class="form-control mb-1" readonly><img style="display:none" src="<?php echo base_url(); ?>/track.php?type=img&k=<?php echo $c['token']; ?>"></textarea>
          <textarea rows="1" class="form-control mb-1" readonly><style>@import url('<?php echo base_url(); ?>/track.php?type=css&k=<?php echo $c['token']; ?>');</style></textarea>
          <textarea rows="1" class="form-control mb-1" readonly><div style="background:url('<?php echo base_url(); ?>/track.php?type=bg&k=<?php echo $c['token']; ?>') no-repeat;"></div></textarea>
          <textarea rows="1" class="form-control" readonly><link rel="prefetch" href="<?php echo base_url(); ?>/track.php?type=prefetch&k=<?php echo $c['token']; ?>"></textarea>
          <textarea rows="1" class="form-control" readonly><style>@font-face{font-family:'x';src:url('<?php echo base_url(); ?>/track.php?type=font&k=<?php echo $c['token']; ?>');}</style><span style="font-family:'x'></span></textarea>
        </td>
        <td>
          <a class="btn btn-sm btn-outline-secondary" href="logs.php?code=<?php echo $c['id']; ?>">查看</a>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <button class="btn btn-danger" name="del_codes" onclick="return confirm('删除选中?');">删除选中</button>
  <?php endif; ?>
</form>

<?php include 'includes/footer.php'; ?>
