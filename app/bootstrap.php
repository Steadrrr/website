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

/** 처리 중 오류가 나면 빈 화면 대신 안내와 오류 내용을 보여준다 (서버는 display_errors 가 꺼져 있어 빈 화면이 되던 문제) */
function fatal_page(string $what, string $file, int $line): void
{
    error_log("forestlog: $what ($file:$line)");
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    $h = fn(string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>오류</title></head>'
        . '<body style="font-family:sans-serif;max-width:720px;margin:2rem auto;padding:0 1rem;line-height:1.6;color:#222;background:#fff">'
        . '<h2 style="color:#b3261e">처리 중 오류가 났습니다</h2>'
        . ($post ? '<p><b>방금 저장한 내용은 저장되지 않았습니다.</b> 브라우저의 <b>뒤로 가기</b>로 돌아가면 입력한 내용이 남아 있는 경우가 많습니다.</p>' : '')
        . '<p>이 화면을 캡처해서 개발 담당자에게 보내 주세요.</p>'
        . '<pre style="white-space:pre-wrap;background:#f6f6f6;border:1px solid #ddd;padding:.8rem;border-radius:6px">' . $h($what) . "\n" . $h(basename(dirname($file)) . '/' . basename($file) . ':' . $line)
        . "\n" . $h(date('Y-m-d H:i:s') . ' · ' . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '')) . '</pre>'
        . '<p><a href="javascript:history.back()">← 뒤로 가기</a></p></body></html>';
}
set_exception_handler(function (Throwable $e): void {
    fatal_page(get_class($e) . ': ' . $e->getMessage(), $e->getFile(), $e->getLine());
});
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        $msg = $err['message'];
        if (str_contains($msg, 'Allowed memory size')) $msg = "서버 메모리가 부족합니다 (사진이 너무 큰 경우가 많습니다).\n$msg";
        elseif (str_contains($msg, 'Maximum execution time')) $msg = "처리 시간이 너무 오래 걸렸습니다 (사진이 많거나 큰 경우).\n$msg";
        fatal_page($msg, $err['file'], $err['line']);
    }
});

require __DIR__ . '/helpers.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/approval.php';
require __DIR__ . '/migrate.php';
require __DIR__ . '/products.php';
require __DIR__ . '/assets.php';
require __DIR__ . '/items.php';
require __DIR__ . '/revisions.php';
require __DIR__ . '/events.php';
require __DIR__ . '/attendance.php';
require __DIR__ . '/programs.php';
require __DIR__ . '/complaints.php';
require __DIR__ . '/vault.php';
require __DIR__ . '/ar.php';
require __DIR__ . '/supplies.php';
require __DIR__ . '/purchase.php';
require __DIR__ . '/changelog.php';
require __DIR__ . '/help.php';
require __DIR__ . '/charts.php';
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
    try {
        db_migrate();
    } catch (Throwable $e) {
        setup_error("DB 자동 업그레이드 중 오류가 났습니다. (기존 자료는 그대로입니다)\n"
            . '현재 DB 버전 ' . db_version() . ' → 새 버전 ' . DB_VERSION . "\n오류 내용: " . $e->getMessage()
            . "\n\n이 화면을 캡처해서 개발 담당자에게 보내 주세요.");
    }
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
