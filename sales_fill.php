<?php
/**
 * 매출보고 작성 화면의 '매표 엑셀로 채우기'
 *   act=upload : 판매 엑셀을 읽어 그 날(입력칸의 일자, 없으면 엑셀의 날짜) 상품명별 합계만 세션에 두고 작성 화면으로 → 입장권·프로그램 판매가 채워진다
 *   act=map    : 엑셀 상품명마다 가져올 곳을 저장(다음부터 자동)하고 작성 화면으로
 * 저장은 작성 화면에서 작성자가 한다. 엑셀의 예약자·판매자 등 개인정보는 읽지 않는다.
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/sales_xls.php';

$user = require_login();
if ($menu = journal_menu('sales')) require_menu($user, $menu);
if (!is_post()) redirect('journal.php?type=sales');
csrf_verify();

if (post('act') === 'map') {
    $targets = imp_targets();
    $map = [];
    foreach ((array) ($_POST['name'] ?? []) as $i => $name) {
        $to = (string) ($_POST['map'][$i] ?? '');
        $map[(string) $name] = isset($targets[$to]) ? $to : '';
    }
    imp_map_remember($map);
    $back = post('back');
    redirect((preg_match('/^write\.php\?(id=\d+|type=sales&date=\d{4}-\d{2}-\d{2})$/', $back) ? $back : 'journal.php?type=sales') . '&fill=1');
}

$id = (int) post('id');
$date = post('date');
$f = $_FILES['file'] ?? null;
$back = $id ? "write.php?id=$id" : 'write.php?type=sales&date=' . (valid_date($date) ? $date : date('Y-m-d'));
if (!$f || $f['error'] !== UPLOAD_ERR_OK) { flash('파일을 올리지 못했습니다. 다시 골라 주세요.', 'error'); redirect($back); }
try {
    $x = imp_parse($f['tmp_name'], (string) $f['name']);
} catch (Throwable $e) {
    flash($e->getMessage(), 'error');
    redirect($back);
}
// 쓸 날짜: 입력칸의 일자가 엑셀에 있으면 그 날, 아니면 엑셀의 (마지막) 날짜
$dates = array_keys($x['agg']);
$use = in_array($date, $dates, true) ? $date : end($dates);
$cats = [];
foreach ($x['agg'][$use] as $name => $_) $cats[$name] = array_keys($x['names'][$name]['cats'] ?? []);
$_SESSION[SALES_FILL_KEY] = ['date' => $use, 'file' => (string) $f['name'], 'byName' => $x['agg'][$use], 'cats' => $cats,
    'others' => array_values(array_diff($dates, [$use]))];

// 그 날 매출보고가 있으면 그 문서를 고치는 화면으로, 없으면 새로 작성
$st = db()->prepare("SELECT id FROM journals WHERE type = 'sales' AND work_date = ?");
$st->execute([$use]);
$existing = (int) $st->fetchColumn();
if ($existing && $existing !== $id) {
    $j = journal_find($existing);
    if (!$j || !can_edit_journal($j, $user)) { flash(date('Y.n.j', strtotime($use)) . ' 매출보고(문서 ' . $existing . ')가 이미 있고, 임시저장이라 작성자만 고칠 수 있습니다.', 'error'); redirect($back); }
    if ($id || !valid_date($date) || $date !== $use) flash(date('Y.n.j', strtotime($use)) . ' 매출보고가 이미 있어 그 문서(' . $existing . ')를 고치는 화면으로 왔습니다.', 'info');
    redirect("write.php?id=$existing&fill=1");
}
if ($id && ($j = journal_find($id)) && $j['work_date'] === $use) redirect("write.php?id=$id&fill=1");
if ($id) flash('엑셀의 날짜(' . date('Y.n.j', strtotime($use)) . ')가 이 매출보고의 일자와 달라 새 매출보고 작성 화면으로 왔습니다.', 'info');
redirect("write.php?type=sales&date=$use&fill=1");
