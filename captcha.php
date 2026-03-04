<?php
require_once __DIR__ . '/includes/auth.php';

$pool = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
$code = '';
for ($i = 0; $i < 5; $i++) {
    $code .= $pool[random_int(0, strlen($pool) - 1)];
}

$_SESSION['login_captcha'] = [
    'code' => strtolower($code),
    'time' => time(),
];

header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$lines = [];
for ($i = 0; $i < 5; $i++) {
    $x1 = random_int(0, 120);
    $y1 = random_int(0, 40);
    $x2 = random_int(0, 120);
    $y2 = random_int(0, 40);
    $opacity = random_int(20, 50) / 100;
    $lines[] = "<line x1=\"{$x1}\" y1=\"{$y1}\" x2=\"{$x2}\" y2=\"{$y2}\" stroke=\"#6c757d\" stroke-opacity=\"{$opacity}\" stroke-width=\"1\"/>";
}

$textX = 10;
$chars = [];
for ($i = 0; $i < strlen($code); $i++) {
    $char = htmlspecialchars($code[$i], ENT_QUOTES, 'UTF-8');
    $rotate = random_int(-18, 18);
    $y = random_int(26, 34);
    $chars[] = "<text x=\"{$textX}\" y=\"{$y}\" transform=\"rotate({$rotate} {$textX} {$y})\" fill=\"#212529\" font-size=\"23\" font-family=\"Arial, sans-serif\" font-weight=\"700\">{$char}</text>";
    $textX += 22;
}

echo '<svg xmlns="http://www.w3.org/2000/svg" width="120" height="40" viewBox="0 0 120 40">';
echo '<rect width="120" height="40" fill="#f8f9fa"/>';
echo implode('', $lines);
echo implode('', $chars);
echo '</svg>';
