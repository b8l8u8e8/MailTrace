<?php
/**
 * 邮件激活脚本
 *  - 只要 token 匹配且卡密仍未被使用，就将用户正式写入 users 表
 *  - 激活成功后才标记 invite_codes.used = 1
 *  - 若卡密已被使用或 token 无效，激活失败
 */

require_once 'includes/auth.php';
require_once 'includes/functions.php';
global $db;

$tok = $_GET['token'] ?? '';

$st = $db->prepare('SELECT * FROM pending WHERE token = ? LIMIT 1');
$st->execute([$tok]);
$pend = $st->fetch(PDO::FETCH_ASSOC);

$msg = '';

if (!$pend) {
    $msg = '激活链接无效或已使用';
} else {
    $need_invite = cfg('require_invite') === '1';
    $invite_ok   = true;

    if ($need_invite) {
        $invite = $pend['invite_code'] ?? '';
        $st2 = $db->prepare('SELECT used FROM invite_codes WHERE code = ? LIMIT 1');
        $st2->execute([$invite]);
        $row = $st2->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $invite_ok = false;
            $msg = '激活失败：卡密不存在';
        } elseif ($row['used']) {
            $invite_ok = false;
            $msg = '激活失败：卡密已被使用';
        }
    }

    if ($invite_ok) {
        // 再次确认用户名未被抢注
        $st3 = $db->prepare('SELECT 1 FROM users WHERE username = ? LIMIT 1');
        $st3->execute([$pend['username']]);
        if ($st3->fetch()) {
            $msg = '激活失败：用户名已存在';
        } else {
            // 事务处理
            try {
                $db->beginTransaction();

                $db->prepare('INSERT INTO users (username, password_hash, email, created_at) VALUES (?,?,?,datetime("now"))')
                   ->execute([$pend['username'], $pend['password_hash'], $pend['email']]);

                if ($need_invite) {
                    $db->prepare('UPDATE invite_codes SET used = 1, used_at = datetime("now") WHERE code = ?')->execute([$invite]);
                }

                $db->prepare('DELETE FROM pending WHERE id = ?')->execute([$pend['id']]);

                $db->commit();
                $msg = '激活成功，可以登录了！';
            } catch (Exception $e) {
                $db->rollBack();
                $msg = '激活失败，请联系管理员';
            }
        }
    }
}

include 'includes/header.php';
?>
<div class="alert alert-info"><?=$msg?></div>
<?php if ($msg === '激活成功，可以登录了！'): ?>
  <a class="btn btn-success" href="index.php">立即登录</a>
<?php endif; ?>
<?php include 'includes/footer.php'; ?>
