<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

require_login();
global $db;

$cid = (int)($_GET['code'] ?? 0);
$check = $db->prepare('SELECT * FROM codes WHERE id = ? AND user_id = ?');
$check->execute([$cid, current_user()['id']]);
$code = $check->fetch(PDO::FETCH_ASSOC);
if (!$code) {
    die('无效');
}

if (isset($_POST['del_logs']) && isset($_POST['sel']) && is_array($_POST['sel'])) {
    $st = $db->prepare('DELETE FROM logs WHERE id = ? AND code_id = ?');
    foreach ($_POST['sel'] as $lid) {
        if (is_numeric($lid)) {
            $st->execute([$lid, $cid]);
        }
    }
}

$logs = $db->prepare('SELECT * FROM logs WHERE code_id = ? ORDER BY created_at DESC');
$logs->execute([$cid]);
$logList = $logs->fetchAll(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<div class="page-header" style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
  <div>
    <h3>访问记录 - <?= htmlspecialchars($code['name']) ?></h3>
    <div class="page-desc">共 <?= count($logList) ?> 条记录</div>
  </div>
  <a href="dashboard.php" class="btn btn-outline-secondary btn-sm" style="margin-left: auto;">&#8592; 返回面板</a>
</div>

<div class="card-section">
  <?php if (!$logList): ?>
    <div class="empty-state">
      <div class="empty-icon">&#128065;</div>
      <p>暂无访问记录</p>
    </div>
  <?php else: ?>
    <form method="post">
      <div class="table-responsive">
        <table class="table table-sm">
          <thead>
            <tr>
              <th style="width: 36px;"><input type="checkbox" id="chk" onclick="document.querySelectorAll('.sel').forEach(c=>c.checked=this.checked)"></th>
              <th>时间</th>
              <th>IP</th>
              <th>位置</th>
              <th style="min-width: 180px;">用户代理</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($logList as $l): ?>
              <tr>
                <td><input type="checkbox" class="sel" name="sel[]" value="<?= $l['id'] ?>"></td>
                <td style="white-space: nowrap;"><?= htmlspecialchars($l['created_at']) ?></td>
                <td><code><?= htmlspecialchars($l['ip']) ?></code></td>
                <td><?= htmlspecialchars($l['location']) ?></td>
                <td class="ua-cell"><?= htmlspecialchars($l['user_agent']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div style="padding: 0.75rem 0 0;">
        <button class="btn btn-danger btn-sm" name="del_logs" onclick="return confirm('删除选中访问日志?');">删除选中</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
