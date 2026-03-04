<?php require_once 'includes/functions.php';
$type=$_GET['type']??'img'; $tok=$_GET['k']??''; global $db;
$stmt=$db->prepare('SELECT * FROM codes WHERE token=?');$stmt->execute([$tok]);$c=$stmt->fetch(PDO::FETCH_ASSOC); if(!$c) exit;
$ip=$_SERVER['REMOTE_ADDR']??'';$ua=$_SERVER['HTTP_USER_AGENT']??'';$loc=fetch_location($ip);$now=date('Y-m-d H:i:s');
// Skip self-server requests (e.g., when sending email)
$serverIps = [$_SERVER['SERVER_ADDR'] ?? '', '127.0.0.1', '::1'];
if(in_array($ip, $serverIps)){
    // do not record or notify for internal calls
    exit;
}
$db->prepare('INSERT INTO logs(code_id,ip,location,user_agent,created_at) VALUES (?,?,?,?,?)')->execute([$c['id'],$ip,$loc,$ua,$now]);
$usr=$db->prepare('SELECT notif_email,notify_on FROM users WHERE id=?');$usr->execute([$c['user_id']]);$u=$usr->fetch(PDO::FETCH_ASSOC);
if($u && $u['notify_on'] && $u['notif_email']) smtp_send($u['notif_email'],'追踪提醒',"{$c['name']} 被访问\nIP:$ip $loc\n$now");

switch($type){
    case 'css':
        header('Content-Type:text/css');
        echo '/* tracker */';
        break;
    case 'bg':
    case 'img':
    case 'icon':
        header('Content-Type:image/gif');
        echo base64_decode('R0lGODlhAQABAPAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');
        break;
    case 'font':
        // minimal empty font: returning gif disguised as woff still triggers request
        header('Content-Type:font/woff');
        echo base64_decode('d09GRgABAAAAA...');
        break;
    case 'prefetch':
    default:
        // generic 204 no content
        http_response_code(204);
}

?>
