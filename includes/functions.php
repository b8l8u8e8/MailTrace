
<?php
/**
 * Common helper functions for Email Tracker
 */
require_once __DIR__.'/db.php';

/**
 * Return the base URL of the application (protocol + host + path up to current directory)
 */
function base_url(){
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $path   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    return $scheme . '://' . $host . ($path ? '/' . ltrim($path, '/') : '');
}

/**
 * Read or update a key/value pair in the configs table.
 *  - If only $key is given, return the current value or empty string.
 *  - If $value is also supplied, write the value and return it.
 */
function cfg($key, $value = null){
    global $db;
    if ($value === null){
        $stmt = $db->prepare('SELECT value FROM configs WHERE key = ? LIMIT 1');
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['value'] ?? '';
    } else {
        // Upsert (SQLite)
        $stmt = $db->prepare('INSERT INTO configs(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $stmt->execute([$key, $value]);
        return $value;
    }
}

/** Generate a 32‑character random token */
function gen_token(){
    return bin2hex(random_bytes(16));
}

/**
 * Query a public API to convert an IP address to a rough location.
 */
function fetch_location($ip){
    $json = @file_get_contents("http://ip-api.com/json/{$ip}?lang=zh-CN");
    if($json){
        $d = json_decode($json, true);
        if($d && $d['status'] === 'success'){
            return $d['country'].' '.$d['regionName'].' '.$d['city'];
        }
    }
    return '未知';
}

/**
 * 轻量 SMTP 发送（支持 SSL 465 / TLS 587 / 明文 25）
 * @return bool  发送成功返回 true
 * @param string $to      收件人邮箱
 * @param string $subject 邮件主题
 * @param string $body    邮件正文
 * @param string &$debug  调试信息（可选）
 */
function smtp_send($to, $subject, $body, &$debug = ''){
    $host   = cfg('smtp_host');
    if(!$host){
        // 未配置 SMTP，退化到 mail()
        return mail($to, $subject, $body, "Content-Type:text/plain; charset=utf-8");
    }
    $port   = intval(cfg('smtp_port') ?: 25);
    $user   = cfg('smtp_user');
    $pass   = cfg('smtp_pass');
    $secure = strtolower(cfg('smtp_secure')); // '', 'ssl', 'tls'

    $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $ctx    = stream_context_create([
        'ssl' => [
            'verify_peer'       => false,
            'verify_peer_name'  => false,
            'allow_self_signed' => true,
        ]
    ]);

    $fp = @stream_socket_client($remote, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
    if(!$fp){
        $debug = "连接 SMTP 失败: $errno $errstr";
        return false;
    }

    $read = function() use ($fp, &$debug){
        $out = '';
        while($l = fgets($fp)){
            $out .= $l;
            if(preg_match('/^\d{3} /', $l)) break;
        }
        $debug .= $out;
        return $out;
    };
    $send = function($cmd) use ($fp, $read, &$debug){
        fputs($fp, $cmd . "\r\n");
        $debug .= "> $cmd\r\n";
        return $read();
    };

    $read();
    $send('EHLO localhost');

    if($secure === 'tls'){
        $send('STARTTLS');
        stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $send('EHLO localhost');
    }

    if($user){
        $send('AUTH LOGIN');
        $send(base64_encode($user));
        $send(base64_encode($pass));
    }

    $from = cfg('smtp_from') ?: $user;
    $send("MAIL FROM:<{$from}>");
    $send("RCPT TO:<{$to}>");
    $send("DATA");
    fputs(
        $fp,
        "Subject: {$subject}\r\n".
        "From: {$from}\r\n".
        "To: {$to}\r\n".
        "Content-Type:text/plain; charset=utf-8\r\n\r\n".
        "{$body}\r\n.\r\n"
    );
    $read();
    $send('QUIT');
    fclose($fp);
    return true;
}
?>
