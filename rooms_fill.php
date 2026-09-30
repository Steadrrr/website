<?php
/**
 * 객실판매관리 작성 화면의 '예약 엑셀로 채우기' — 올린 엑셀의 제목으로 두 가지를 구분한다
 *   입실예정 숙박상품 목록 : 그 날 묵는 예약의 객실명·인원·할인·금액만 세션에 두고 작성 화면으로 → 입력칸이 채워진다 (저장은 작성자가)
 *   퇴실완료 상품 목록     : 예약마다 묵은 날짜를 계산해 날짜별 미리보기(GET ?out=1) → '반영하기'(act=out_run)로 여러 날짜의 일일객실판매를 만들거나 고친다
 * 개인정보(고객 이름·아이디·생년월일·전화번호·주문번호)는 읽지 않고, 올린 파일은 저장하지 않는다.
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/rooms_xls.php';

$user = require_login();
if ($menu = journal_menu('rooms')) require_menu($user, $menu);
if (!is_post()) {
    if (!empty($_GET['out']) && isset($_SESSION[ROOMS_OUT_KEY])) { rooms_out_page($user); exit; }
    redirect('journal.php?type=rooms');
}
csrf_verify();

// 퇴실완료 엑셀 반영하기 / 취소
if (in_array(post('act'), ['out_run', 'out_cancel'], true)) {
    $out = $_SESSION[ROOMS_OUT_KEY] ?? null;
    if (!$out || post('act') === 'out_cancel') { unset($_SESSION[ROOMS_OUT_KEY]); redirect('journal.php?type=rooms'); }
    $plan = rooms_out_plan($out['stays'], $user);
    $r = rooms_out_apply($plan, $user, post('status') !== 'draft', $out['file']);
    unset($_SESSION[ROOMS_OUT_KEY]);
    flash("퇴실완료 엑셀을 반영했습니다: 새로 만든 일일객실판매 {$r['made']}건, 고친 문서 {$r['edited']}건."
        . ($r['skipped'] ? " 다른 사람의 임시저장이라 고치지 못한 날 {$r['skipped']}일." : ''), 'success');
    redirect('journal.php?type=rooms&ym=' . substr((string) array_key_first($plan), 0, 7));
}

$id = (int) post('id');
$date = post('date');
$f = $_FILES['file'] ?? null;
$back = $id ? "write.php?id=$id" : 'write.php?type=rooms&date=' . (valid_date($date) ? $date : date('Y-m-d'));
if (!$f || $f['error'] !== UPLOAD_ERR_OK) { flash('파일을 올리지 못했습니다. 다시 골라 주세요.', 'error'); redirect($back); }
try {
    ['kind' => $kind, 'stays' => $stays] = rooms_xls_parse($f['tmp_name'], (string) $f['name']);
} catch (Throwable $e) {
    flash($e instanceof RuntimeException ? $e->getMessage() : '엑셀을 읽지 못했습니다. 입실예정 숙박상품 목록 엑셀(.xls)인지 확인하세요.', 'error');
    redirect($back);
}
@unlink($f['tmp_name']); // 올린 파일은 바로 지운다 (PHP도 요청이 끝나면 지움)
$live = array_values(array_filter($stays, fn($s) => !rooms_xls_cancelled($s['status'])));
$keep = fn(array $s) => array_intersect_key($s, array_flip(['room', 'from', 'to', 'nights', 'guests', 'dc', 'amount'])); // 세션에 두는 것 (개인정보 없음)
if ($kind === 'checkout') {
    $_SESSION[ROOMS_OUT_KEY] = ['file' => (string) $f['name'], 'stays' => array_map($keep, $live), 'cancel' => count($stays) - count($live)];
    redirect('rooms_fill.php?out=1');
}
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
    'stays' => array_map($keep, $today),
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

/** 퇴실완료 엑셀 미리보기: 날짜마다 새로 만들기 / 객실 추가·수정 / 그대로 */
function rooms_out_page(array $user): void
{
    $out = $_SESSION[ROOMS_OUT_KEY];
    $plan = rooms_out_plan($out['stays'], $user);
    $sum = ['new' => 0, 'edit' => 0, 'same' => 0, 'locked' => 0, 'rooms' => 0, 'newRooms' => 0];
    foreach ($plan as $it) {
        if (!$it['rooms'] || !$it['changed']) $sum['same']++;
        elseif (!$it['editable']) $sum['locked']++;
        elseif ($it['journal']) { $sum['edit']++; $sum['rooms'] += $it['changed']; }
        else { $sum['new']++; $sum['newRooms'] += count($it['rooms']); }
    }
    $dates = array_keys($plan);
    $how = ['add' => '추가', 'change' => '수정', 'same' => '그대로'];
    layout_header('퇴실완료 엑셀 반영', 'rooms');
    ?>
<section class="card">
  <div class="card-head">
    <h1>퇴실완료 엑셀 반영 <small class="muted"><?= e($out['file']) ?></small></h1>
  </div>
  <p class="small">퇴실완료 상품 목록은 <b>퇴실일</b> 기준이라, 예약마다 <b>묵은 날(입실일 ~ 퇴실 전날)</b>의 일일객실판매에 넣습니다.
    이미 있는 날은 엑셀의 객실만 <b>추가·수정</b>하고, 엑셀에 없는 객실·지역상품권 환급·메모는 그대로 둡니다. <b>아직 저장되지 않았습니다.</b></p>
  <div class="kpis k4">
    <div class="kpi"><span>예약</span><b><?= count($out['stays']) ?>건</b><small class="muted"><?= e(date('Y.n.j', strtotime(reset($dates)))) ?> ~ <?= e(date('n.j', strtotime(end($dates)))) ?> 묵은 날 <?= count($dates) ?>일<?= $out['cancel'] ? ' · 취소 ' . (int) $out['cancel'] . '건 제외' : '' ?></small></div>
    <div class="kpi total"><span>새로 만들 일일객실판매</span><b><?= $sum['new'] ?>일</b><small class="muted">객실 <?= $sum['newRooms'] ?>실</small></div>
    <div class="kpi"><span>고칠 일일객실판매</span><b><?= $sum['edit'] ?>일</b><small class="muted">객실 추가·수정 <?= $sum['rooms'] ?>건</small></div>
    <div class="kpi"><span>바뀌는 것 없음</span><b><?= $sum['same'] ?>일</b><?= $sum['locked'] ? '<small class="warn">고칠 수 없음 ' . $sum['locked'] . '일</small>' : '' ?></div>
  </div>
</section>

<section class="card">
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>날짜</th><th>일일객실판매</th><th>객실 (입실인원 · 요금구분 · 할인)</th><th>확인할 것</th></tr></thead>
    <tbody>
    <?php foreach ($plan as $date => $it): $j = $it['journal']; ?>
      <tr class="<?= $it['changed'] && $it['editable'] ? '' : 'inactive' ?>">
        <td class="nowrap"><b><?= e(date('n.j', strtotime($date))) ?>(<?= e(weekday_ko($date)) ?>)</b></td>
        <td class="nowrap small"><?php if (!$j): ?><span class="badge st-pending">새로 만들기</span>
          <?php else: ?><a href="<?= e(url('view.php?id=' . (int) $j['id'])) ?>" target="_blank">문서 <?= (int) $j['id'] ?></a> <span class="badge st-<?= e($j['status']) ?>"><?= e(JOURNAL_STATUS[$j['status']] ?? $j['status']) ?></span>
            <?php if (!$it['editable']): ?><br><span class="warn">다른 사람의 임시저장 — 고칠 수 없음</span><?php elseif ($it['changed'] && is_revision_edit($j)): ?><br><span class="muted">수정 이력 남기고 결재 다시</span><?php endif ?>
          <?php endif ?></td>
        <td class="small"><?php foreach ($it['rooms'] as $r): $l = $r['line']; ?>
          <div><?= $r['how'] === 'same' ? '<span class="muted">' : '<b>' ?><?= e($l['name']) ?> <?= (int) $l['guests'] ?>명 · <?= e(RATE_TYPES[$l['rate']]) ?><?= $l['dc_reason'] ? ' · ' . e(ROOM_DC_REASONS[$l['dc_reason']]) . ' 할인' : '' ?><?= $r['nights'] > 1 ? ' · ' . (int) $r['nights'] . '박' : '' ?>
            — <?= $how[$r['how']] ?><?= $r['how'] === 'change' ? ' (기존 ' . (int) $r['old']['guests'] . '명' . (!empty($r['old']['dc_reason']) ? ' · ' . e(ROOM_DC_REASONS[$r['old']['dc_reason']] ?? '') . ' 할인' : '') . ')' : '' ?><?= $r['how'] === 'same' ? '</span>' : '</b>' ?></div>
        <?php endforeach ?></td>
        <td class="small warn"><?php if ($it['unknown']): ?>사이트에 없는 객실: <?= e(implode(', ', array_keys($it['unknown']))) ?><br><?php endif ?><?= implode('<br>', array_map('e', $it['warn'])) ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
  <form method="post" class="actions" style="justify-content:flex-start;flex-wrap:wrap;gap:12px">
    <?= csrf_field() ?>
    <label class="inline-check"><input type="radio" name="status" value="submit" checked> 새로 만드는 날은 <b>결재 올리기</b></label>
    <label class="inline-check"><input type="radio" name="status" value="draft"> 새로 만드는 날은 <b>임시저장</b></label>
    <button class="btn primary" name="act" value="out_run" <?= $sum['new'] + $sum['edit'] ? '' : 'disabled' ?>>반영하기 (<?= $sum['new'] + $sum['edit'] ?>일)</button>
    <button class="btn ghost" name="act" value="out_cancel">취소</button>
  </form>
  <p class="muted small">개인정보(고객 이름·아이디·생년월일·전화번호·주문번호)는 읽지 않았고, 올린 파일은 저장하지 않았습니다. 이미 결재를 올린 문서를 고치면 작성 화면에서 고칠 때와 같이 수정 이력이 남고 결재가 처음부터 다시 올라갑니다.</p>
</section>
    <?php
    layout_footer();
}
