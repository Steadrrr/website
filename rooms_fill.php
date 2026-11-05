<?php
/**
 * 객실판매관리 작성 화면의 '예약 복사해서 붙여넣기' — 붙여넣은 예약 목록에서 객실·일정·할인·인원만 읽어
 * 그 날 묵는 예약을 세션에 두고 작성 화면으로 → 입력칸이 채워진다 (저장은 작성자가).
 * 붙여넣은 글 자체는 저장하지 않는다.
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/rooms_paste.php';

$user = require_login();
if ($menu = journal_menu('rooms')) require_menu($user, $menu);
if (!is_post()) redirect('journal.php?type=rooms');
csrf_verify();

$id = (int) post('id');
$date = post('date');
$back = $id ? "write.php?id=$id" : 'write.php?type=rooms&date=' . (valid_date($date) ? $date : date('Y-m-d'));
$stays = rooms_paste_parse((string) ($_POST['text'] ?? ''));
unset($_POST['text']);
if (!$stays) {
    flash("붙여넣은 글에서 예약을 찾지 못했습니다. 예약마다 '객실 줄'과 '2026-11-06~2026-11-07  할인  인원' 줄이 있는지 확인하세요.", 'error');
    redirect($back);
}
// 쓸 날짜: 입력칸 일자에 묵는 예약이 있으면 그 날, 아니면 가장 많은 입실일
$covers = fn(string $d) => array_values(array_filter($stays, fn($s) => $s['from'] <= $d && $d < $s['to']));
if (valid_date($date) && $covers($date)) $use = $date;
else {
    $cnt = array_count_values(array_column($stays, 'from'));
    arsort($cnt);
    $use = (string) array_key_first($cnt);
}
$today = $covers($use);
$_SESSION[ROOMS_FILL_KEY] = ['date' => $use, 'stays' => $today, 'others' => count($stays) - count($today)];

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
if ($id) flash('예약의 입실일(' . date('Y.n.j', strtotime($use)) . ')이 이 문서의 일자와 달라 새 작성 화면으로 왔습니다.', 'info');
redirect("write.php?type=rooms&date=$use&fill=1");
