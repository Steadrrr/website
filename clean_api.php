<?php
/**
 * 객실 청소관리 앱(clean.php)의 데이터 주소 — JSON
 *   GET  ?act=state&date=YYYY-MM-DD           그 날 객실 상태
 *   POST act=do  date, room, op, _csrf         퇴실처리·청소완료·비품지급·되돌리기 (퇴실·청소완료는 알림)
 *   POST act=subscribe / unsubscribe  endpoint, _csrf   이 기기 알림 켜기·끄기
 *   POST act=sw_events  {"endpoint": …}        알림을 받은 앱(clean-sw.js)이 띄울 내용 (로그인 없이 구독한 기기만)
 */
require __DIR__ . '/app/bootstrap.php';

function clean_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

$act = (string) ($_GET['act'] ?? $_POST['act'] ?? '');

if ($act === 'sw_events') {
    $in = json_decode((string) file_get_contents('php://input'), true);
    $sub = is_array($in) && is_string($in['endpoint'] ?? null) ? push_find($in['endpoint']) : null;
    clean_json(['events' => $sub ? clean_events_for($sub) : []]);
    exit;
}

$user = current_user();
if (!$user) { clean_json(['error' => '로그인이 필요합니다.', 'login' => url('login.php')], 401); exit; }
if (!can_menu($user, 'room')) { clean_json(['error' => "'객실관리' 메뉴 사용 권한이 없습니다. 관리자에게 메뉴 권한을 요청하세요."], 403); exit; }

$date = valid_date((string) ($_GET['date'] ?? $_POST['date'] ?? '')) ? (string) ($_GET['date'] ?? $_POST['date']) : date('Y-m-d');

if (!is_post()) {
    clean_json(clean_state($date));
    exit;
}
if (!hash_equals(csrf_token(), (string) ($_POST['_csrf'] ?? ''))) { clean_json(['error' => '화면이 오래되었습니다. 새로고침하세요.'], 400); exit; }

switch ($act) {
    case 'do':
        [$err, $notify] = clean_act($date, (int) ($_POST['room'] ?? 0), (string) ($_POST['op'] ?? ''), $user);
        clean_json(['error' => $err ?: null] + clean_state($date), $err ? 409 : 200);
        if ($notify) {
            // 응답을 먼저 보내고 알림 (휴대폰 화면이 기다리지 않게)
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            push_notify_all((int) $user['id']);
        }
        exit;
    case 'subscribe':
        $ok = push_subscribe((int) $user['id'], (string) ($_POST['endpoint'] ?? ''));
        clean_json($ok ? ['ok' => true] : ['error' => '알림 주소가 올바르지 않습니다.'], $ok ? 200 : 400);
        exit;
    case 'unsubscribe':
        push_unsubscribe((string) ($_POST['endpoint'] ?? ''));
        clean_json(['ok' => true]);
        exit;
}
clean_json(['error' => '알 수 없는 요청입니다.'], 400);
