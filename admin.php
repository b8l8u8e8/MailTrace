<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';
require_login();
if(!is_admin()) die('无权');

global $db;
$msg='';
$debug="";
# 保存配置
if(isset($_POST['save_cfg'])){
  foreach(['smtp_host','smtp_port','smtp_user','smtp_pass','smtp_secure','smtp_from','allow_register','require_invite'] as $k){
    cfg($k, $_POST[$k] ?? '');
  }
  $msg='配置已保存';
}
# 测试邮件
if(isset($_POST['test_mail'])){
  $ok=smtp_send($_POST['test_to'],'SMTP 测试','成功发送',$debug);
  $msg=$ok?'发送成功':'发送失败';
}
# 生成卡密
if(isset($_POST['gen_inv'])){
  $n=max(1,intval($_POST['num']));
  $st=$db->prepare('INSERT INTO invite_codes(code) VALUES (?)');
  for($i=0;$i<$n;$i++){ $st->execute([gen_token()]); }
}
# 删除卡密
if(isset($_POST['del_inv']) && !empty($_POST['codes'])){
  foreach($_POST['codes'] as $c){ $db->prepare('DELETE FROM invite_codes WHERE code=?')->execute([$c]); }
}
# 添加用户
if(isset($_POST['add_user'])){
  $u=trim($_POST['new_user']); $p=$_POST['new_pass'];
  if($u && $p){
    try{
      $db->prepare('INSERT INTO users(username,password_hash,is_admin) VALUES (?,?,?)')
        ->execute([$u,password_hash($p,PASSWORD_DEFAULT),isset($_POST['new_admin'])?1:0]);
    }catch(PDOException $e){ $msg='用户名已存在'; }
  }
}
# 更新用户
if(isset($_POST['upd_user'])){
  $uid=intval($_POST['uid']); $newu=trim($_POST['uname']); $newp=$_POST['upass'];
  if($newu){
    try{ $db->prepare('UPDATE users SET username=? WHERE id=?')->execute([$newu,$uid]); }catch(PDOException $e){ $msg='用户名重复'; }
  }
  if($newp){ $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($newp,PASSWORD_DEFAULT),$uid]); }
}
# 删除用户
if(isset($_GET['del_user'])){
  $uid=intval($_GET['del_user']);
  if($uid!==current_user()['id']){
    $db->prepare('DELETE FROM logs WHERE code_id IN (SELECT id FROM codes WHERE user_id=?)')->execute([$uid]);
    $db->prepare('DELETE FROM codes WHERE user_id=?')->execute([$uid]);
    $db->prepare('DELETE FROM users WHERE id=?')->execute([$uid]);
  }
}

// 默认开启轻量模式：不做任何计数，直接把 cnt/rec 置空，页面能秒开
$light = ($_GET['light'] ?? '1') === '1';

if ($light) {
    // 模板里仍然有 cnt/rec 两列，这里返回 NULL 占位，模板不用改
    $users = $db->query('SELECT u.*, NULL AS cnt, NULL AS rec FROM users u')
                ->fetchAll(PDO::FETCH_ASSOC);
} else {
    // 需要时才做精确统计（原来的重查询）
    $users=$db->query('SELECT u.*, (SELECT COUNT(*) FROM codes WHERE user_id=u.id) cnt, (SELECT COUNT(*) FROM logs WHERE code_id IN (SELECT id FROM codes WHERE user_id=u.id)) rec FROM users u')->fetchAll(PDO::FETCH_ASSOC);
}

$invites=$db->query('SELECT * FROM invite_codes')->fetchAll(PDO::FETCH_ASSOC);
include 'includes/header.php';
?>

<?php
// ===== 放在 <h3> 上面，生成两个开关链接（保留现有查询参数） =====
$qs = $_GET;
unset($qs['light']);
$self   = basename($_SERVER['PHP_SELF']);
$on     = $qs; $on['light']  = '1';
$off    = $qs; $off['light'] = '0';
$urlOn  = $self.'?'.http_build_query($on);   // 轻量模式
$urlOff = $self.'?'.http_build_query($off);  // 含计数

// （重要）后端也要识别 light，才能真的关闭那些统计 SQL
$light = ($_GET['light'] ?? '1') === '1';
?>

<h3 style="display:flex;align-items:center;gap:8px;">
  后台管理
  <a href="<?php echo $urlOn;  ?>" class="btn btn-sm btn-outline-secondary">轻量模式</a>
  <a href="<?php echo $urlOff; ?>" class="btn btn-sm btn-outline-secondary">含计数</a>
  <span class="badge text-bg-<?php echo $light ? 'secondary' : 'primary'; ?>" style="margin-left:4px;">
    <?php echo $light ? '轻量中' : '含计数'; ?>
  </span>
</h3>
<?php if($msg):?><div class="alert alert-info"><?php echo $msg;?></div><?php endif;?>

<form method="post" class="card p-3 mb-3">
<h5>SMTP / 注册设置</h5>
<?php foreach(['smtp_host'=>'SMTP 主机','smtp_port'=>'端口','smtp_user'=>'账号','smtp_pass'=>'密码','smtp_secure'=>'加密(ssl/tls/none)','smtp_from'=>'发信邮箱','allow_register'=>'允许注册(1/0)','require_invite'=>'需卡密(1/0)'] as $k=>$lab): ?>
<div class="mb-2"><label class="form-label"><?php echo $lab;?></label><input name="<?php echo $k;?>" value="<?php echo htmlspecialchars(cfg($k));?>" class="form-control"></div>
<?php endforeach;?>
<button class="btn btn-primary" name="save_cfg">保存设置</button>
</form>

<form method="post" class="card p-3 mb-3">
<h5>SMTP 测试</h5>
<div class="input-group"><input name="test_to" class="form-control" placeholder="收件邮箱" required>
<button class="btn btn-outline-secondary" name="test_mail">发送测试</button></div>
<?php if($debug): ?><?php endif;?>
</form>

<form method="post" class="card p-3 mb-3">
<h5>卡密管理</h5>
<div class="row g-2">
  <div class="col-auto"><input type="number" name="num" value="5" min="1" max="100" class="form-control"></div>
  <div class="col-auto"><button class="btn btn-success" name="gen_inv">生成卡密</button></div>
</div>
<table class="table table-bordered mt-2"><tr><th><input type="checkbox" onclick="document.querySelectorAll('.invsel').forEach(e=>e.checked=this.checked)"></th><th>卡密</th><th>已用</th></tr>
<?php foreach($invites as $inv):?><tr><td><input type="checkbox" class="invsel" name="codes[]" value="<?php echo $inv['code'];?>"></td><td><?php echo $inv['code'];?></td><td><?php echo $inv['used']?'是':'否';?></td></tr><?php endforeach;?>
</table>
<button class="btn btn-danger" name="del_inv" onclick="return confirm('删除选中?');">删除选中</button>
</form>

<form method="post" class="card p-3 mb-3">
<h5>添加用户</h5>
<div class="row g-2">
<input name="new_user" class="form-control col" placeholder="用户名" required>
<input name="new_pass" class="form-control col" placeholder="密码" required>
<label class="form-check form-switch col-auto"><input type="checkbox" name="new_admin" class="form-check-input"> 管理员</label>
<button class="btn btn-success col-auto" name="add_user">添加</button>
</div>
</form>

<h5>用户列表</h5>
<table class="table table-striped"><tr><th>ID</th><th>用户名</th><th>管理员</th><th>追踪</th><th>记录</th><th>编辑</th></tr>
<?php foreach($users as $u):?>
<tr><td><?php echo $u['id'];?></td><td><?php echo htmlspecialchars($u['username']);?></td><td><?php echo $u['is_admin']?'是':'否';?></td><td><?php echo $u['cnt'];?></td><td><?php echo $u['rec'];?></td>
<td>
<form method="post" class="d-inline-flex gap-1">
<input type="hidden" name="upd_user" value="1"><input type="hidden" name="uid" value="<?php echo $u['id'];?>">
<input name="uname" class="form-control form-control-sm" placeholder="新用户名">
<input name="upass" class="form-control form-control-sm" placeholder="新密码">
<button class="btn btn-sm btn-outline-primary">修改</button>
</form>
<?php if($u['id']!==current_user()['id']):?>
<a class="btn btn-sm btn-danger ms-1" href="admin.php?del_user=<?php echo $u['id'];?>" onclick="return confirm('删除?');">删</a>
<?php endif;?>
</td></tr><?php endforeach;?>
</table>
<?php include 'includes/footer.php'; ?>
