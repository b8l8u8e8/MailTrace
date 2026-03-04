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

<div class="page-header">
  <h3>&#9776; 面板</h3>
  <div class="page-desc">管理你的邮件追踪代码与提醒设置</div>
</div>

<?php if ($msg): ?>
  <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<!-- 添加追踪代码 -->
<div class="card-section">
  <div class="card-section-title">
    <span class="section-icon section-icon-green">+</span>
    添加追踪代码
  </div>
  <form class="row g-2 align-items-end" method="post">
    <div class="col-sm-6 col-md-5">
      <label class="form-label">邮件备注</label>
      <input name="name" class="form-control" placeholder="例如：给张三的报价单">
    </div>
    <div class="col-auto">
      <button class="btn btn-success" name="add">添加</button>
    </div>
  </form>
</div>

<!-- 邮件提醒 -->
<div class="card-section">
  <div class="card-section-title">
    <span class="section-icon section-icon-blue">&#9993;</span>
    邮件提醒
    <span class="badge badge-status <?= !empty($u['notify_on']) ? 'badge-status-on' : 'badge-status-off' ?>" style="margin-left: auto; background: <?= !empty($u['notify_on']) ? 'var(--success-light)' : 'var(--gray-100)' ?>; color: <?= !empty($u['notify_on']) ? 'var(--success)' : 'var(--gray-500)' ?>;">
      <?= !empty($u['notify_on']) ? '已开启' : '未开启' ?>
    </span>
  </div>
  <form method="post">
    <div class="row g-2 align-items-end">
      <div class="col-sm-6 col-md-5">
        <label class="form-label">接收邮箱</label>
        <input name="email" class="form-control" placeholder="接收提醒的邮箱地址" value="<?= htmlspecialchars($u['notif_email'] ?? '') ?>">
      </div>
      <div class="col-auto d-flex align-items-center" style="padding-bottom: 2px;">
        <label class="form-check form-switch mb-0">
          <input type="checkbox" class="form-check-input" name="enable" <?= !empty($u['notify_on']) ? 'checked' : '' ?>>
          <span class="form-check-label" style="font-size: 0.85rem;">启用提醒</span>
        </label>
      </div>
      <div class="col-auto">
        <button class="btn btn-primary" name="save_notify">保存</button>
      </div>
    </div>
  </form>
</div>

<!-- 追踪说明 -->
<details class="card-section" style="cursor: default;">
  <summary style="cursor: pointer; font-weight: 600; font-size: 0.9rem; color: var(--gray-600); list-style: none; display: flex; align-items: center; gap: 0.4rem;">
    <span style="font-size: 0.7rem; transition: transform 0.2s;">&#9654;</span>
    5 种追踪代码说明（点击展开）
  </summary>
  <ol class="mt-2 mb-0 ps-3" style="font-size: 0.85rem; color: var(--gray-600);">
    <li class="mb-1"><strong>隐藏图片追踪</strong>：通过 1x1 图片请求记录访问。</li>
    <li class="mb-1"><strong>CSS 导入追踪</strong>：通过 CSS 资源请求记录访问。</li>
    <li class="mb-1"><strong>背景图片追踪</strong>：通过背景图资源请求记录访问。</li>
    <li class="mb-1"><strong>预加载追踪</strong>：通过 prefetch 请求记录访问。</li>
    <li><strong>字体追踪</strong>：通过字体资源请求记录访问。</li>
  </ol>
</details>

<!-- 我的追踪列表 -->
<div class="card-section">
  <div class="card-section-title">
    <span class="section-icon section-icon-cyan">&#9783;</span>
    我的追踪
    <?php if ($codes): ?>
      <span class="badge" style="background: var(--gray-100); color: var(--gray-600); margin-left: auto;"><?= count($codes) ?> 个</span>
    <?php endif; ?>
  </div>

  <?php if (!$codes): ?>
    <div class="empty-state">
      <div class="empty-icon">&#128232;</div>
      <p>暂无追踪代码，点击上方「添加」开始使用</p>
    </div>
  <?php else: ?>
    <form method="post">
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th style="width: 36px;"><input type="checkbox" id="chkall" onclick="document.querySelectorAll('.sel').forEach(el=>el.checked=this.checked);"></th>
              <th>邮件备注</th>
              <th>追踪代码</th>
              <th style="width: 70px;">操作</th>
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
                <td><strong><?= htmlspecialchars($c['name']) ?></strong></td>
                <td>
                  <details class="snippet-group">
                    <summary class="snippet-toggle" style="list-style: none; font-size: 0.82rem; color: var(--gray-500);">
                      <span class="arrow">&#9654;</span> 展开 <?= count($snippets) ?> 种追踪代码
                    </summary>
                    <div class="mt-2">
                      <?php foreach ($snippets as $label => $snippet): ?>
                        <div class="mb-2">
                          <div class="snippet-label"><?= $label ?></div>
                          <div class="d-flex gap-1 align-items-start">
                            <textarea rows="2" class="form-control code-text flex-grow-1" readonly onclick="this.select();"><?= htmlspecialchars($snippet) ?></textarea>
                            <button type="button" class="btn btn-sm btn-outline-secondary snippet-copy-btn" onclick="var t=this.previousElementSibling;t.select();document.execCommand('copy');this.textContent='已复制';var b=this;setTimeout(function(){b.textContent='复制'},1500);">复制</button>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </details>
                </td>
                <td>
                  <a class="btn btn-sm btn-outline-primary" href="logs.php?code=<?= $c['id'] ?>">查看</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div style="padding: 0.75rem 0 0;">
        <button class="btn btn-danger btn-sm" name="del_codes" onclick="return confirm('确定删除选中的追踪代码?');">删除选中</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
