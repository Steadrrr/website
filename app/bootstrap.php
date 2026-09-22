<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("app/config.php 파일이 없습니다.\napp/config.sample.php 를 config.php 로 복사한 뒤 DB 정보를 입력하세요.");
}
$GLOBALS['CONFIG'] = require $configFile;

date_default_timezone_set($GLOBALS['CONFIG']['timezone'] ?? 'Asia/Seoul');
mb_internal_encoding('UTF-8');
ini_set('display_errors', empty($GLOBALS['CONFIG']['debug']) ? '0' : '1');
error_reporting(E_ALL);

require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/approval.php';
require __DIR__ . '/sales.php';
require __DIR__ . '/layout.php';

$https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_name('FORESTLOG');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => config('base_url') === '' ? '/' : config('base_url') . '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
