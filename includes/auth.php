<?php
require_once __DIR__ . '/db.php';

session_start();

function login($u, $p) {
    global $db;

    $s = $db->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $s->execute([$u]);
    $usr = $s->fetch(PDO::FETCH_ASSOC);

    if (!$usr || !password_verify($p, $usr['password_hash'])) {
        return false;
    }

    $_SESSION['user'] = [
        'id' => (int)$usr['id'],
        'username' => $usr['username'],
        'is_admin' => (int)($usr['is_admin'] ?? 0),
        'notif_email' => $usr['notif_email'] ?? '',
        'notify_on' => (int)($usr['notify_on'] ?? 0),
        'auth_version' => (int)($usr['auth_version'] ?? 1),
    ];
    return true;
}

function current_user() {
    return $_SESSION['user'] ?? null;
}

function require_login() {
    if (!current_user()) {
        header('Location: index.php');
        exit;
    }
}

function is_admin() {
    return (int)(current_user()['is_admin'] ?? 0);
}

function logout($redirect = 'index.php') {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
    header('Location: ' . $redirect);
    exit;
}

function invalidate_user_auth($userId) {
    global $db;

    $stmt = $db->prepare('UPDATE users SET auth_version = COALESCE(auth_version, 1) + 1 WHERE id = ?');
    if ($stmt) {
        $stmt->execute([(int)$userId]);
    }
}

/* -------- 同步并校验会话中的用户信息 -------- */
function _sync_session_user() {
    global $db;

    if (!isset($_SESSION['user']['id'])) {
        return;
    }

    $id = (int)$_SESSION['user']['id'];
    $stmt = $db->prepare('
        SELECT id, username, is_admin, notif_email, notify_on, COALESCE(auth_version, 1) AS auth_version
        FROM users
        WHERE id = ?
        LIMIT 1
    ');

    if (!$stmt || !$stmt->execute([$id])) {
        unset($_SESSION['user']);
        return;
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        unset($_SESSION['user']);
        return;
    }

    $sessionVersion = (int)($_SESSION['user']['auth_version'] ?? 0);
    $dbVersion = (int)($row['auth_version'] ?? 1);

    // Backward compatibility: old sessions may not carry auth_version yet.
    if ($sessionVersion !== 0 && $sessionVersion !== $dbVersion) {
        unset($_SESSION['user']);
        return;
    }

    $_SESSION['user'] = [
        'id' => (int)$row['id'],
        'username' => $row['username'],
        'is_admin' => (int)($row['is_admin'] ?? 0),
        'notif_email' => $row['notif_email'] ?? '',
        'notify_on' => (int)($row['notify_on'] ?? 0),
        'auth_version' => $dbVersion,
    ];
}

_sync_session_user();

?>
