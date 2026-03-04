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

include 'includes/header.php';
?>

<h3>访问记录 - <?= htmlspecialchars($code['name']) ?></h3>

<form method="post">
  <div class="table-responsive">
    <table class="table table-bordered table-sm">
      <tr>
        <th><input type="checkbox" id="chk" onclick="document.querySelectorAll('.sel').forEach(c=>c.checked=this.checked)"></th>
        <th>时间</th>
        <th>IP</th>
        <th>位置</th>
        <th>UA</th>
      </tr>
      <?php foreach ($logs as $l): ?>
        <tr>
          <td><input type="checkbox" class="sel" name="sel[]" value="<?= $l['id'] ?>"></td>
          <td><?= htmlspecialchars($l['created_at']) ?></td>
          <td><?= htmlspecialchars($l['ip']) ?></td>
          <td><?= htmlspecialchars($l['location']) ?></td>
          <td style="max-width:360px; word-break: break-all;"><?= htmlspecialchars($l['user_agent']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>

  <button class="btn btn-danger" name="del_logs" onclick="return confirm('删除选中访问日志?');">删除选中</button>
</form>

<?php include 'includes/footer.php'; ?>
