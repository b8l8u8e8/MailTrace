<?php
/**
 * 邮件追踪系统 - 通用函数
 */
require_once __DIR__ . '/db.php';

/**
 * Return the base URL of the application (protocol + host + path up to current directory)
 */
function base_url() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    return $scheme . '://' . $host . ($path ? '/' . ltrim($path, '/') : '');
}

/**
 * Read or update a key/value pair in the configs table.
 */
function cfg($key, $value = null) {
    global $db;
    if ($value === null) {
        $stmt = $db->prepare('SELECT value FROM configs WHERE key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['value'] ?? '';
    }

    $stmt = $db->prepare('INSERT INTO configs(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([$key, $value]);
    return $value;
}

/** Generate a 32-character random token */
function gen_token() {
    return bin2hex(random_bytes(16));
}

/**
 * HTTP GET helper with short timeout.
 */
function http_get_text($url, $timeout = 1.8) {
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'MailTrace/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp !== false && $code >= 200 && $code < 300) {
            return $resp;
        }
        return null;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'header' => "User-Agent: MailTrace/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);

    $resp = @file_get_contents($url, false, $ctx);
    return ($resp === false) ? null : $resp;
}

/**
 * Decode JSON and auto-handle GBK responses.
 */
function decode_json_auto($raw) {
    if (!is_string($raw) || $raw === '') {
        return null;
    }

    $raw = trim($raw);
    $data = json_decode($raw, true);
    if (is_array($data)) {
        return $data;
    }

    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($raw, 'UTF-8', 'UTF-8,GBK,GB2312,GB18030');
        $data = json_decode($converted, true);
        if (is_array($data)) {
            return $data;
        }
    }

    return null;
}

function is_private_or_reserved_ip($ip) {
    if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

function format_location_parts(array $parts) {
    $parts = array_values(array_filter(array_map('trim', $parts), static function ($v) {
        return $v !== '' && $v !== 'XX' && $v !== '-';
    }));

    return $parts ? implode(' ', array_unique($parts)) : '';
}

/**
 * Domestic-first IP location lookup with fallback.
 */
function fetch_location($ip) {
    static $cache = [];

    if (isset($cache[$ip])) {
        return $cache[$ip];
    }

    if (!$ip || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return $cache[$ip] = '未知';
    }

    if (is_private_or_reserved_ip($ip)) {
        return $cache[$ip] = '内网IP';
    }

    // 1) China Telecom PConline (domestic)
    $raw = http_get_text('https://whois.pconline.com.cn/ipJson.jsp?json=true&ip=' . urlencode($ip), 1.8);
    $d = decode_json_auto($raw);
    if (is_array($d) && empty($d['err'])) {
        // addr 字段通常已包含完整的省市+ISP信息，优先使用；为空时回退拼接 pro+city
        $addr = trim($d['addr'] ?? '');
        if ($addr !== '' && $addr !== 'XX') {
            $loc = $addr;
        } else {
            $loc = format_location_parts([
                $d['pro'] ?? '',
                $d['city'] ?? '',
            ]);
        }
        if ($loc !== '') {
            return $cache[$ip] = $loc;
        }
    }

    // 2) Taobao IP service (domestic)
    $raw = http_get_text('https://ip.taobao.com/outGetIpInfo?ip=' . urlencode($ip) . '&accessKey=alibaba-inc', 1.8);
    $d = decode_json_auto($raw);
    if (is_array($d) && isset($d['code']) && (int)$d['code'] === 0 && !empty($d['data']) && is_array($d['data'])) {
        $loc = format_location_parts([
            $d['data']['country'] ?? '',
            $d['data']['region'] ?? '',
            $d['data']['city'] ?? '',
            $d['data']['isp'] ?? '',
        ]);
        if ($loc !== '') {
            return $cache[$ip] = $loc;
        }
    }

    // 3) Fallback to international provider
    $raw = http_get_text('http://ip-api.com/json/' . urlencode($ip) . '?lang=zh-CN', 1.5);
    $d = decode_json_auto($raw);
    if (is_array($d) && ($d['status'] ?? '') === 'success') {
        $loc = format_location_parts([
            $d['country'] ?? '',
            $d['regionName'] ?? '',
            $d['city'] ?? '',
        ]);
        if ($loc !== '') {
            return $cache[$ip] = $loc;
        }
    }

    return $cache[$ip] = '未知';
}

/**
 * Lightweight SMTP sender (SSL 465 / TLS 587 / plain 25).
 */
function smtp_send($to, $subject, $body, &$debug = '') {
    $host = cfg('smtp_host');
    if (!$host) {
        // SMTP not configured: fallback to mail()
        return mail($to, $subject, $body, "Content-Type:text/plain; charset=utf-8");
    }

    $port = intval(cfg('smtp_port') ?: 25);
    $user = cfg('smtp_user');
    $pass = cfg('smtp_pass');
    $secure = strtolower(cfg('smtp_secure')); // '', 'ssl', 'tls'

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $ctx = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ]);

    $fp = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        $debug = "连接 SMTP 失败: $errno $errstr";
        return false;
    }

    $read = function () use ($fp, &$debug) {
        $out = '';
        while ($l = fgets($fp)) {
            $out .= $l;
            if (preg_match('/^\d{3} /', $l)) {
                break;
            }
        }
        $debug .= $out;
        return $out;
    };

    $send = function ($cmd) use ($fp, $read, &$debug) {
        fputs($fp, $cmd . "\r\n");
        $debug .= "> $cmd\r\n";
        return $read();
    };

    $read();
    $send('EHLO localhost');

    if ($secure === 'tls') {
        $send('STARTTLS');
        stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $send('EHLO localhost');
    }

    if ($user) {
        $send('AUTH LOGIN');
        $send(base64_encode($user));
        $send(base64_encode($pass));
    }

    $from = cfg('smtp_from') ?: $user;
    $send("MAIL FROM:<{$from}>");
    $send("RCPT TO:<{$to}>");
    $send('DATA');

    fputs(
        $fp,
        "Subject: {$subject}\r\n" .
        "From: {$from}\r\n" .
        "To: {$to}\r\n" .
        "Content-Type:text/plain; charset=utf-8\r\n\r\n" .
        "{$body}\r\n.\r\n"
    );

    $read();
    $send('QUIT');
    fclose($fp);
    return true;
}
?>
