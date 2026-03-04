<?php
require_once 'includes/functions.php';

global $db;
$type = $_GET['type'] ?? 'img';
$tok = $_GET['k'] ?? '';

$stmt = $db->prepare('SELECT * FROM codes WHERE token = ?');
$stmt->execute([$tok]);
$c = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$c) {
    exit;
}

function get_client_ip() {
    $keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    $fallback = '';

    foreach ($keys as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }

        $value = $_SERVER[$key];
        $parts = explode(',', $value);
        foreach ($parts as $part) {
            $ip = trim($part);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                if (!is_private_or_reserved_ip($ip)) {
                    return $ip;
                }
                if ($fallback === '') {
                    $fallback = $ip;
                }
            }
        }
    }

    return $fallback;
}

$ip = get_client_ip();
$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$now = date('Y-m-d H:i:s');

$serverIps = array_filter([
    $_SERVER['SERVER_ADDR'] ?? '',
    '127.0.0.1',
    '::1',
]);

if ($ip !== '' && in_array($ip, $serverIps, true)) {
    // 跳过服务端自身请求（如预加载）
    // 继续输出响应内容但不记录日志
} else {
    $loc = ($ip !== '') ? fetch_location($ip) : '未知';
    $db->prepare('INSERT INTO logs(code_id,ip,location,user_agent,created_at) VALUES (?,?,?,?,?)')
       ->execute([$c['id'], $ip ?: '未知', $loc, $ua, $now]);

    $usr = $db->prepare('SELECT notif_email,notify_on FROM users WHERE id = ?');
    $usr->execute([$c['user_id']]);
    $u = $usr->fetch(PDO::FETCH_ASSOC);
    if ($u && $u['notify_on'] && $u['notif_email']) {
        $displayIp = $ip ?: '未知';
        smtp_send($u['notif_email'], '追踪提醒', "{$c['name']} 被访问\nIP: {$displayIp} {$loc}\n{$now}");
    }
}

switch ($type) {
    case 'css':
        header('Content-Type: text/css; charset=utf-8');
        echo '/* tracker */';
        break;

    case 'bg':
    case 'img':
    case 'icon':
        header('Content-Type: image/gif');
        echo base64_decode('R0lGODlhAQABAPAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');
        break;

    case 'font':
        http_response_code(204);
        break;

    case 'prefetch':
    default:
        http_response_code(204);
        break;
}
?>
