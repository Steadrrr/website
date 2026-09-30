<?php
/**
 * 객실판매관리 작성 화면의 '예약 엑셀로 채우기'
 *   입실예정 숙박상품 목록 엑셀을 읽어 그 날 묵는 예약의 객실명·인원·할인·금액만 세션에 두고 작성 화면으로 → 입력칸이 채워진다.
 *   개인정보(고객 이름·아이디·생년월일·전화번호·주문번호)는 읽지 않고, 올린 파일은 저장하지 않는다. 저장은 작성 화면에서 작성자가 한다.
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/rooms_xls.php';

$user = require_login();
if ($menu = journal_menu('rooms')) require_menu($user, $menu);
if (!is_post()) redirect('journal.php?type=rooms');
csrf_verify();

$id = (int) post('id');
$date = post('date');
$f = $_FILES['file'] ?? null;
$back = $id ? "write.php?id=$id" : 'write.php?type=rooms&date=' . (valid_date($date) ? $date : date('Y-m-d'));
if (!$f || $f['error'] !== UPLOAD_ERR_OK) { flash('파일을 올리지 못했습니다. 다시 골라 주세요.', 'error'); redirect($back); }
try {
    $stays = rooms_xls_parse($f['tmp_name'], (string) $f['name']);
} catch (Throwable $e) {
    flash($e instanceof RuntimeException ? $e->getMessage() : '엑셀을 읽지 못했습니다. 입실예정 숙박상품 목록 엑셀(.xls)인지 확인하세요.', 'error');
    redirect($back);
}
@unlink($f['tmp_name']); // 올린 파일은 바로 지운다 (PHP도 요청이 끝나면 지움)
$live = array_values(array_filter($stays, fn($s) => !rooms_xls_cancelled($s['status'])));
// 쓸 날짜: 입력칸 일자에 묵는 예약이 있으면 그 날, 아니면 가장 많은 입실일
$covers = fn(string $d) => array_values(array_filter($live, fn($s) => $s['from'] <= $d && $d < $s['to']));
if (valid_date($date) && $covers($date)) $use = $date;
else {
    $cnt = array_count_values(array_column($live ?: $stays, 'from'));
    arsort($cnt);
    $use = (string) array_key_first($cnt);
}
$today = $covers($use);
$_SESSION[ROOMS_FILL_KEY] = ['date' => $use,
    'stays' => array_map(fn($s) => array_intersect_key($s, array_flip(['room', 'from', 'to', 'nights', 'guests', 'dc', 'amount'])), $today),
    'cancel' => count($stays) - count($live), 'others' => count($live) - count($today)];

$st = db()->prepare("SELECT id FROM journals WHERE type = 'rooms' AND work_date = ?");
$st->execute([$use]);
$existing = (int) $st->fetchColumn();
if ($existing && $existing !== $id) {
    $j = journal_find($existing);
    if (!$j || !can_edit_journal($j, $user)) { flash(date('Y.n.j', strtotime($use)) . ' 일일객실판매(문서 ' . $existing . ')가 이미 있고, 임시저장이라 작성자만 고칠 수 있습니다.', 'error'); redirect($back); }
    if ($id || $date !== $use) flash(date('Y.n.j', strtotime($use)) . ' 일일객실판매가 이미 있어 그 문서(' . $existing . ')를 고치는 화면으로 왔습니다.', 'info');
    redirect("write.php?id=$existing&fill=1");
}
if ($id && ($j = journal_find($id)) && $j['work_date'] === $use) redirect("write.php?id=$id&fill=1");
if ($id) flash('엑셀의 입실일(' . date('Y.n.j', strtotime($use)) . ')이 이 문서의 일자와 달라 새 작성 화면으로 왔습니다.', 'info');
redirect("write.php?type=rooms&date=$use&fill=1");
