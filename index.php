<?php
require_once 'includes/auth.php';
require_once 'includes/functions.php';

$mode=$_GET['mode']??'login';
$err='';$msg='';
if($_SERVER['REQUEST_METHOD']=='POST'){
  if($_POST['act']=='login'){
     if(!login($_POST['user'],$_POST['pass'])) $err='登录失败';
     else header('Location: dashboard.php');
  }elseif($_POST['act']=='reg' && cfg('allow_register')=='1'){
     $u=trim($_POST['user']);$p=$_POST['pass'];$mail=$_POST['email'];
     if(!$u||!$p||!filter_var($mail,FILTER_VALIDATE_EMAIL)) $err='信息不完整';
     else{

// --- 邀请码校验逻辑 ---
                global $db;
$needInvite = cfg('require_invite')=='1';
$inviteCode = trim($_POST['invite'] ?? '');
if($needInvite){
    if(!$inviteCode){
        $err = '请填写卡密';
    } else {
        $stmt = $db->prepare('SELECT * FROM invite_codes WHERE code=? AND used=0');
        $stmt->execute([$inviteCode]);
        if(!$stmt->fetch()){
            $err = '卡密无效';
        }
    }
}
// ----------------------
        global $db; $hash=password_hash($p,PASSWORD_DEFAULT); $tok=gen_token();
        try{$db->prepare('INSERT INTO pending(username,password_hash,email,token) VALUES (?,?,?,?)')->execute([$u,$hash,$mail,$tok]);
            $activationLink = base_url().'/activate.php?token='.$tok;
$body = "您好 {$u},

感谢注册 Email Tracker！请点击以下链接激活您的账号：
{$activationLink}

如果无法直接点击，请将上述链接复制到浏览器打开。

祝好！
Email Tracker 团队";
smtp_send($mail,'欢迎注册 Email Tracker',$body);

// 标记卡密已使用
if(isset($needInvite) && $needInvite && !$err){
    $db->prepare('UPDATE invite_codes SET used=1,used_by=?,used_at=datetime("now") WHERE code=?')->execute([$u,$inviteCode]);
}
$msg='已发送激活邮件';
 $mode='login';
        }catch(PDOException $e){$err='用户名已存在';}
     }
  }
}
include 'includes/header.php';
?>
<div class="row justify-content-center"><div class="col-md-5">
<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link <?= $mode=='login'?'active':''?>" href="?mode=login">登录</a></li>
  <?php if(cfg('allow_register')=='1'): ?><li class="nav-item"><a class="nav-link <?= $mode=='reg'?'active':''?>" href="register.php">注册</a></li><?php endif;?>
</ul>

<?php if($err):?><div class="alert alert-danger"><?= $err;?></div><?php elseif($msg):?><div class="alert alert-success"><?= $msg;?></div><?php endif;?>

<?php if($mode=='reg' && cfg('allow_register')=='1'): ?>
<form method="post">
<input type="hidden" name="act" value="reg">
<input name="user" class="form-control mb-2" placeholder="用户名" required>
<input name="pass" type="password" class="form-control mb-2" placeholder="密码" required>
<input name="email" type="email" class="form-control mb-2" placeholder="邮箱" required>
<?php if(cfg('require_invite')=='1'): ?>
<input name="invite" class="form-control mb-2" placeholder="卡密" required>
<?php endif; ?>
<button class="btn btn-success w-100">注册</button></form>
<?php else: ?>
<form method="post">
<input type="hidden" name="act" value="login">
<input name="user" class="form-control mb-2" placeholder="用户名" required>
<input name="pass" type="password" class="form-control mb-2" placeholder="密码" required>
<button class="btn btn-primary w-100">登录</button></form>
<?php endif;?>
</div></div>
<?php include 'includes/footer.php'; ?>
