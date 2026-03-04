<?php require_once 'includes/auth.php'; require_once 'includes/functions.php'; require_login(); global $db; $u=current_user(); $msg='';$err='';
if(isset($_POST['delete'])){ # delete account
    $id=$u['id'];
    $db->prepare('DELETE FROM logs WHERE code_id IN (SELECT id FROM codes WHERE user_id=?)')->execute([$id]);
    $db->prepare('DELETE FROM codes WHERE user_id=?')->execute([$id]);
    $db->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
    logout();
}
if($_SERVER['REQUEST_METHOD']=='POST' && isset($_POST['save'])){
    $newuser=trim($_POST['username']); $cur=$_POST['cur']; $newp=$_POST['new'];
    $st=$db->prepare('SELECT * FROM users WHERE id=?');$st->execute([$u['id']]);$row=$st->fetch(PDO::FETCH_ASSOC);
    if($row && password_verify($cur,$row['password_hash'])){
        if($newuser && $newuser!=$u['username']){
            try{$db->prepare('UPDATE users SET username=? WHERE id=?')->execute([$newuser,$u['id']]);$_SESSION['user']['username']=$newuser;}catch(PDOException $e){$err='用户名已存在';}
        }
        if($newp) $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([password_hash($newp,PASSWORD_DEFAULT),$u['id']]);
        if(!$err) $msg='已更新';
    }else $err='当前密码错误';
}
include 'includes/header.php';?>
<div class="row justify-content-center"><div class="col-md-4"><h3>个人资料</h3>
<?php if($err):?><div class="alert alert-danger"><?php echo $err;?></div><?php elseif($msg):?><div class="alert alert-success"><?php echo $msg;?></div><?php endif;?>
<form method="post">
<input type="hidden" name="save" value="1">
<input name="username" class="form-control mb-2" value="<?php echo htmlspecialchars(current_user()['username']);?>" placeholder="用户名">
<input name="cur" type="password" class="form-control mb-2" placeholder="当前密码" required>
<input name="new" type="password" class="form-control mb-2" placeholder="新密码(可留空)">
<button class="btn btn-primary w-100">保存</button>
</form>
<form method="post" onsubmit="return confirm('确定删除账户? 不可找回');"><button class="btn btn-danger w-100 mt-3" name="delete">删除账户</button></form>
</div></div><?php include 'includes/footer.php'; ?>
