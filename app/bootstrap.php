<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

/** 사이트를 열 수 없을 때 빈 500 화면 대신 원인을 보여준다 */
function setup_error(string $message): never
{
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit($message . "\n\n자세한 점검: 브라우저에서 이 사이트의 check.php 를 열어 보세요.");
}

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    setup_error("app/config.php 파일이 없습니다.\napp/config.sample.php 를 config.php 로 복사한 뒤 DB 정보를 입력하세요.");
}
try {
    $GLOBALS['CONFIG'] = require $configFile;
} catch (ParseError $e) {
    setup_error('app/config.php ' . $e->getLine() . "번째 줄 근처에 문법 오류가 있습니다.\n따옴표(')나 쉼표(,)가 빠지지 않았는지 확인하세요.");
}
if (!is_array($GLOBALS['CONFIG']) || !isset($GLOBALS['CONFIG']['db'])) {
    setup_error("app/config.php 를 읽지 못했습니다.\napp/config.sample.php 를 다시 복사해서 DB 정보만 바꿔 보세요.");
}

date_default_timezone_set($GLOBALS['CONFIG']['timezone'] ?? 'Asia/Seoul');
mb_internal_encoding('UTF-8');
ini_set('display_errors', empty($GLOBALS['CONFIG']['debug']) ? '0' : '1');
error_reporting(E_ALL);

require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/approval.php';
require __DIR__ . '/migrate.php';
require __DIR__ . '/products.php';
require __DIR__ . '/assets.php';
require __DIR__ . '/items.php';
require __DIR__ . '/revisions.php';
require __DIR__ . '/sales.php';
require __DIR__ . '/layout.php';

// DB 연결 확인 → 새 버전 파일을 올린 뒤 첫 접속 시 DB 자동 업그레이드
try {
    db();
    $dbOk = true;
} catch (PDOException) {
    $dbOk = false;
    if (basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'install.php') { // install.php 는 자체 안내
        setup_error("DB에 접속할 수 없습니다. app/config.php 의 DB 정보(호스트·이름·아이디·비밀번호)를 확인하세요.");
    }
}
if ($dbOk && db_version() < DB_VERSION) {
    db_migrate();
}
unset($dbOk);

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
