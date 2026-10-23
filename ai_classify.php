<?php
/**
 * 민원 분류 제안 (일일업무일지 › 민원 입력의 '✨ 분류 제안' 버튼이 부름)
 *   POST _csrf, text  →  JSON {ok, code, cat1, cat2, path, etc_text, reason, by: ai|rule, place: [type, id]|null}
 * 제안만 하고 저장하지 않는다. 내용은 app/ai_classify.php
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
header('Content-Type: application/json; charset=utf-8');
if (!can_menu($user, 'ops')) { http_response_code(403); exit(json_encode(['ok' => false, 'error' => "'운영관리' 메뉴 권한이 있어야 분류 제안을 쓸 수 있습니다."], JSON_UNESCAPED_UNICODE)); }
header('Cache-Control: no-store');
if (!is_post()) {
    http_response_code(405);
    exit(json_encode(['ok' => false, 'error' => 'POST 로 보내 주세요.']));
}
csrf_verify();
$text = trim((string) ($_POST['text'] ?? ''));
if (mb_strlen($text) < 2) exit(json_encode(['ok' => false, 'error' => '민원 내용을 먼저 적어 주세요.'], JSON_UNESCAPED_UNICODE));
session_write_close(); // AI 응답을 기다리는 동안 같은 사람의 다른 화면이 멈추지 않게
echo json_encode(['ok' => true] + cpl_suggest($text), JSON_UNESCAPED_UNICODE);
