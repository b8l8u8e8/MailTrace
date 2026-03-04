<?php
require_once __DIR__.'/db.php';
session_start();
function login($u,$p){ global $db; $s=$db->prepare('SELECT * FROM users WHERE username=?');$s->execute([$u]);$usr=$s->fetch(PDO::FETCH_ASSOC); if($usr && password_verify($p,$usr['password_hash'])){$_SESSION['user']=['id'=>$usr['id'],'username'=>$usr['username'],'is_admin'=>$usr['is_admin']]; return true;} return false;}
function current_user(){return $_SESSION['user']??null;}
function require_login(){ if(!current_user()){ header('Location:index.php'); exit; } }
function is_admin(){return current_user()['is_admin']??0;}
function logout(){session_destroy(); header('Location:index.php'); exit;}
/* -------- 同步提醒设置到会话 -------- */
function _sync_notify_fields() {
    global $db;
    if (!isset($_SESSION['user']['id'])) return;
    $id = intval($_SESSION['user']['id']);
    $stmt = $db->prepare('SELECT notif_email, notify_on FROM users WHERE id = ?');
    if ($stmt && $stmt->execute([$id])) {
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $_SESSION['user']['notif_email'] = $row['notif_email'];
            $_SESSION['user']['notify_on']   = $row['notify_on'];
        }
    }
}
_sync_notify_fields();

?>
