<?php
/**
 * 민원관리 (고객 › 민원관리, 운영관리 권한)
 *   complaints.php?unit=day|week|month&date=2026-09-25[&status=][&cat1=][&q=][&edit=민원id]
 *   위쪽 '민원 입력'에서 한 건씩 등록(바로 저장), 목록에서 고치기·삭제, 처리상태 변경·완료 처리. 결재 없음 (현황 관리).
 *   권한 있는 사람은 누구나 입력·수정·삭제. 업무일지 보기·인쇄에는 그 날 민원이 읽기 전용으로 나온다.
 * 월별 통계는 민원통계(complaint_stats.php). 예전에 업무일지에서 입력한 민원은 journal_id 가 남아 있다.
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/xlsx.php';

$user = require_login();
require_menu($user, 'ops');
$pdo = db();
$tree = cpl_tree();

/* ───────────── 처리상태 변경 · 완료 처리 ───────────── */
if (is_post() && in_array(post('action'), ['add', 'update', 'delete'], true)) {
    csrf_verify();
    $back = (string) ($_POST['back'] ?? 'complaints.php');
    if (!preg_match('/^complaints\.php(\?|$)/', $back)) $back = 'complaints.php';
    if (post('action') === 'delete') {
        if ($c = cpl_find((int) post('id'))) {
            $pdo->prepare('DELETE FROM complaints WHERE id = ?')->execute([$c['id']]);
            if ($c['journal_id']) journal_log((int) $c['journal_id'], $user, '민원 삭제 (민원관리)', cpl_path($c, false) . ' · ' . mb_strimwidth($c['content'], 0, 60, '…'));
            flash('민원을 삭제했습니다.', 'success');
        }
        redirect($back);
    }
    $editId = post('action') === 'update' ? (int) ($_POST['cp']['e']['id'] ?? 0) : 0;
    if ($editId && !cpl_find($editId)) abort(404, '민원을 찾을 수 없습니다 (다른 사람이 지웠을 수 있습니다).');
    $workDate = valid_date(post('work_date')) ? post('work_date') : '';
    [$parsed, $entryErrors] = cpl_parse($workDate ?: date('Y-m-d'));
    if (!$workDate) $entryErrors[] = '접수일을 확인하세요.';
    if (!$parsed && !$entryErrors) $entryErrors[] = '대분류·중분류·소분류와 민원 내용을 입력하세요.';
    if (!$entryErrors) {
        $newId = cpl_store($parsed[0], $workDate, $editId);
        flash($editId ? '민원을 고쳤습니다.' : '민원을 등록했습니다.', 'success');
        // 등록한 날짜의 목록으로 (지금 보던 단위·조건 유지)
        parse_str((string) parse_url($back, PHP_URL_QUERY), $bq);
        unset($bq['edit']);
        redirect('complaints.php?' . http_build_query(['date' => $workDate] + $bq) . '#c' . $newId);
    }
    // 입력 오류: 아래에서 입력한 값 그대로 다시 그린다
    $entryRow = ($parsed[0] ?? null) ?: array_merge(cpl_empty(), array_map(fn($v) => is_string($v) ? trim($v) : $v, (array) ($_POST['cp']['e'] ?? [])));
    $entryRow['id'] = $editId;
    $entryDate = $workDate ?: date('Y-m-d');
} elseif (is_post()) {
    csrf_verify();
    $ids = array_values(array_unique(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $one = (int) post('id');
    if ($one) $ids = [$one];
    $status = post('action') === 'bulk_done' ? 'done' : post('status');
    $doneDate = post('done_date') ?: date('Y-m-d');
    $action = trim(post('cpl_action'));
    if (!$ids || !isset(CPL_STATUS[$status]) || ($status === 'done' && !valid_date($doneDate))) {
        flash('처리할 민원과 처리상태(완료면 처리일자)를 확인하세요.', 'error');
        redirect($_POST['back'] ?? 'complaints.php');
    }
    $n = 0;
    foreach ($ids as $id) {
        $st = $pdo->prepare('SELECT * FROM complaints WHERE id = ?');
        $st->execute([$id]);
        if (!$c = $st->fetch()) continue;
        $newAction = $one ? ($action !== '' ? $action : null) : ($action !== '' ? trim(($c['action'] ? $c['action'] . "\n" : '') . $action) : $c['action']);
        $pdo->prepare('UPDATE complaints SET status = ?, done_date = ?, action = ?, updated_by = ?, updated_at = NOW() WHERE id = ?')
            ->execute([$status, $status === 'done' ? $doneDate : null, $newAction, $user['id'], $id]);
        if ($c['journal_id'] && ($c['status'] !== $status || (string) $c['action'] !== (string) $newAction)) { // 예전 업무일지 민원은 일지 기록에도
            journal_log((int) $c['journal_id'], $user, '민원 처리 (민원관리)', cpl_path($c, false) . ' → ' . CPL_STATUS[$status][0] . ($status === 'done' ? " $doneDate" : ''));
        }
        $n++;
    }
    flash("민원 {$n}건을 '" . CPL_STATUS[$status][0] . "'(으)로 저장했습니다.", 'success');
    redirect($_POST['back'] ?? 'complaints.php');
}

// 예전 주소(월별 통계 탭)는 통계 › 민원통계로 보낸다
if (($_GET['tab'] ?? '') === 'stats') redirect('complaint_stats.php' . (isset($_GET['ym']) ? '?ym=' . urlencode((string) $_GET['ym']) : ''));

/* ═════════════ 목록 (일자·주·월별) ═════════════ */
$unit = in_array($_GET['unit'] ?? '', ['day', 'week', 'month'], true) ? $_GET['unit'] : 'day';
$date = valid_date($_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
[$from, $to, $label, $prevD, $nextD] = match ($unit) {
    'week'  => [$m = date('Y-m-d', strtotime('monday this week', strtotime($date))), $s = date('Y-m-d', strtotime("$m +6 days")),
                date('n/j', strtotime($m)) . ' ~ ' . date('n/j', strtotime($s)), date('Y-m-d', strtotime("$m -7 days")), date('Y-m-d', strtotime("$m +7 days"))],
    'month' => [$m = date('Y-m-01', strtotime($date)), date('Y-m-t', strtotime($m)), date('Y년 n월', strtotime($m)),
                date('Y-m-d', strtotime("$m -1 month")), date('Y-m-d', strtotime("$m +1 month"))],
    default => [$date, $date, date('Y년 n월 j일', strtotime($date)) . ' (' . weekday_ko($date) . ')',
                date('Y-m-d', strtotime("$date -1 day")), date('Y-m-d', strtotime("$date +1 day"))],
};
$status = $_GET['status'] ?? '';
if (!isset(CPL_STATUS[$status]) && $status !== 'undone') $status = '';
$cat1 = isset(CPL_TREE[$_GET['cat1'] ?? '']) ? $_GET['cat1'] : '';
$q = trim((string) ($_GET['q'] ?? ''));

$where = ['c.work_date BETWEEN ? AND ?'];
$args = [$from, $to];
if ($status === 'undone') $where[] = "c.status <> 'done'";
elseif ($status) { $where[] = 'c.status = ?'; $args[] = $status; }
if ($cat1) { $where[] = 'c.cat1 = ?'; $args[] = $cat1; }
if ($q !== '') { $where[] = '(c.content LIKE ? OR c.action LIKE ? OR c.place_name LIKE ? OR c.etc_text LIKE ?)'; array_push($args, ...array_fill(0, 4, "%$q%")); }
$st = $pdo->prepare('SELECT c.*, u.name AS receiver_name, ub.name AS updated_name FROM complaints c
                       LEFT JOIN users u ON u.id = c.receiver_id LEFT JOIN users ub ON ub.id = c.updated_by
                      WHERE ' . implode(' AND ', $where) . ' ORDER BY c.work_date DESC, (c.cat1 = ?) DESC, c.id');
$st->execute([...$args, CPL_URGENT]);
$rows = $st->fetchAll();
// 기간과 상관없는 미완료 전체 (놓친 민원 확인)
$undone = (int) $pdo->query("SELECT COALESCE(SUM(qty), 0) FROM complaints WHERE status <> 'done'")->fetchColumn();
$undoneUrgent = (int) $pdo->query("SELECT COALESCE(SUM(qty), 0) FROM complaints WHERE status <> 'done' AND cat1 = '" . CPL_URGENT . "'")->fetchColumn();
$qs = fn(array $o) => 'complaints.php?' . http_build_query(array_filter(array_merge(['unit' => $unit, 'date' => $date, 'status' => $status, 'cat1' => $cat1, 'q' => $q], $o), fn($v) => $v !== ''));
$back = $qs([]);

if (($_GET['export'] ?? '') === 'xlsx') {
    xlsx_send("민원목록_{$from}_{$to}.xlsx", [[
        'name' => '민원', 'title' => "민원 목록 ($label)", 'subtitle' => '출력 ' . date('Y-m-d H:i') . ' · ' . $user['name'],
        'header' => ['접수일', '대분류', '중분류', '소분류', '접수경로', '장소', '민원 내용', '건수', '처리상태', '처리일자', '조치내용·비고', '접수자'],
        'rows' => array_map(fn($c) => [$c['work_date'], cpl_name($c['cat1']), cpl_name($c['cat2']), cpl_path($c, false), CPL_CHANNELS[$c['channel']] ?? '',
            $c['place_name'] ?? '', $c['content'], (int) $c['qty'], CPL_STATUS[$c['status']][0] ?? '', $c['done_date'] ?? '', $c['action'] ?? '', $c['receiver_name'] ?? ''], $rows),
        'widths' => [11, 10, 14, 24, 8, 16, 40, 6, 8, 11, 30, 8],
    ]]);
}

// 위쪽 입력 칸: 새 민원(보고 있는 날짜) 또는 ?edit=id 고치기. 입력 오류 때는 위에서 만든 $entryRow 그대로
if (!isset($entryRow)) {
    $editRow = !empty($_GET['edit']) ? cpl_find((int) $_GET['edit']) : null;
    $entryRow = $editRow ?: cpl_empty();
    $entryDate = $editRow ? $editRow['work_date'] : ($unit === 'day' ? $date : date('Y-m-d'));
}

layout_header('민원관리', 'complaints');
?>
<section class="card">
  <div class="card-head">
    <h1>민원관리</h1>
    <div class="actions no-margin no-print">
      <?php if (can_menu($user, 'stat')): ?><a class="btn ghost" href="<?= e(url('complaint_stats.php?ym=' . substr($from, 0, 7))) ?>">민원통계 ›</a><?php endif ?>
      <a class="btn" href="<?= e(url($qs(['export' => 'xlsx']))) ?>">엑셀</a>
      <button class="btn" type="button" onclick="window.print()">인쇄</button>
      <a class="btn primary" href="#cplEntry">+ 민원 입력</a>
    </div>
  </div>
  <?php if ($undone): ?>
    <p class="flash <?= $undoneUrgent ? 'flash-error' : 'flash-warn' ?> no-print">완료되지 않은 민원이 모두 <b><?= $undone ?>건</b> 있습니다<?= $undoneUrgent ? " (긴급·안전 <b>{$undoneUrgent}건</b>)" : '' ?>.
      <a href="<?= e(url('complaints.php?unit=month&date=' . date('Y-m-d') . '&status=undone')) ?>">이번 달 미완료 보기</a></p>
  <?php endif ?>
  <div class="cpl-nav no-print">
    <div class="stat-units">
      <?php foreach (['day' => '일자별', 'week' => '주별', 'month' => '월별'] as $u => $ul): ?>
        <a href="<?= e(url($qs(['unit' => $u]))) ?>" class="<?= $unit === $u ? 'on' : '' ?>"><?= $ul ?></a>
      <?php endforeach ?>
    </div>
    <a class="btn small" href="<?= e(url($qs(['date' => $prevD]))) ?>">‹</a>
    <b><?= e($label) ?></b>
    <a class="btn small" href="<?= e(url($qs(['date' => $nextD]))) ?>">›</a>
    <a class="btn small ghost" href="<?= e(url($qs(['date' => date('Y-m-d')]))) ?>">오늘</a>
  </div>
  <form method="get" class="filters no-print">
    <input type="hidden" name="unit" value="<?= e($unit) ?>">
    <label>날짜<input type="date" name="date" value="<?= e($date) ?>"></label>
    <label>대분류<select name="cat1"><option value="">전체</option><?php foreach ($tree['tree'] as $c1 => $x): ?><option value="<?= $c1 ?>" <?= $cat1 === (string) $c1 ? 'selected' : '' ?>><?= $c1 ?>. <?= e($x['name']) ?></option><?php endforeach ?></select></label>
    <label>처리상태<select name="status"><option value="">전체</option><option value="undone" <?= $status === 'undone' ? 'selected' : '' ?>>미완료 (미조치+처리중)</option>
      <?php foreach (CPL_STATUS as $s => [$sl]): ?><option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $sl ?></option><?php endforeach ?></select></label>
    <label>검색<input type="search" name="q" value="<?= e($q) ?>" placeholder="내용·조치·장소"></label>
    <button class="btn small primary">조회</button>
  </form>
  <?php cpl_summary_html($rows, $label) ?>
</section>

<?php foreach ($entryErrors ?? [] as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach ?>
<?php cpl_entry_form($entryRow, $entryDate, $back) ?>

<form method="post" class="card" id="cplBulk">
  <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
  <?php if ($rows): ?>
  <div class="bulk-bar no-print">
    <label class="inline-check"><input type="checkbox" data-check-all> 전체선택</label>
    <span class="muted small" data-selected-count>0건 선택</span>
    <label class="inline-check">처리일자 <input type="date" name="done_date" value="<?= date('Y-m-d') ?>"></label>
    <input name="cpl_action" placeholder="조치내용 (선택, 기존 내용 뒤에 추가)" class="bulk-comment">
    <button class="btn primary" name="action" value="bulk_done" data-bulk onclick="return confirm('체크한 민원을 모두 완료 처리할까요?')">선택 민원 완료 처리</button>
  </div>
  <?php endif ?>
  <div class="table-scroll">
  <table class="table cpl-table cpl-manage">
    <thead><tr><th class="check-col no-print"></th><th>접수일</th><th>분류</th><th>장소</th><th>민원 내용</th><th class="right">건수</th><th>처리상태 · 조치</th><th>접수자</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $c): $fid = 'c' . $c['id'] . 'f'; ?>
      <tr class="<?= $c['cat1'] === CPL_URGENT ? 'urgent' : '' ?> <?= $c['status'] === 'done' ? 'done' : '' ?>">
        <td class="check-col no-print"><?php if ($c['status'] !== 'done'): ?><input type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>" data-row-check><?php endif ?></td>
        <td class="nowrap"><?= e(date('n/j', strtotime($c['work_date']))) ?> (<?= weekday_ko($c['work_date']) ?>)<br><small class="muted"><?= e(CPL_CHANNELS[$c['channel']] ?? '') ?></small></td>
        <td><small class="muted"><?= e(cpl_name($c['cat1'])) ?> › <?= e(cpl_name($c['cat2'])) ?></small><br><?= e(cpl_path($c, false)) ?></td>
        <td class="small"><?= $c['place_name'] ? '<small class="muted">' . ($c['place_type'] === 'room' ? '객실' : '시설') . '</small><br>' . e($c['place_name']) : '-' ?></td>
        <td class="pre-wrap" id="c<?= (int) $c['id'] ?>"><?= e($c['content']) ?>
          <div class="cpl-row-actions no-print">
            <a class="btn small ghost" href="<?= e(url($qs(['edit' => $c['id']]) . '#cplEntry')) ?>">수정</a>
            <button class="btn small ghost danger" form="d<?= (int) $c['id'] ?>" onclick="return confirm('이 민원을 삭제할까요?')">삭제</button>
            <?php if ($c['journal_id']): ?><a class="small" href="<?= e(url('view.php?id=' . $c['journal_id'])) ?>">업무일지 ›</a><?php endif ?>
          </div></td>
        <td class="right"><?= (int) $c['qty'] ?></td>
        <td class="cpl-edit">
          <div class="nowrap"><?= cpl_status_badge($c['status']) ?> <?= $c['done_date'] ? '<small class="muted">' . e($c['done_date']) . '</small>' : '' ?></div>
          <?php if ($c['action']): ?><div class="pre-wrap small"><?= e($c['action']) ?></div><?php endif ?>
          <details class="no-print"><summary class="small">처리상태 변경</summary>
            <div class="cpl-edit-form">
              <select name="status" form="<?= $fid ?>"><?php foreach (CPL_STATUS as $s => [$sl]): ?><option value="<?= $s ?>" <?= $c['status'] === $s ? 'selected' : '' ?>><?= $sl ?></option><?php endforeach ?></select>
              <input type="date" name="done_date" value="<?= e($c['done_date'] ?: date('Y-m-d')) ?>" form="<?= $fid ?>" title="완료일 때 처리일자">
              <textarea name="cpl_action" rows="2" form="<?= $fid ?>" placeholder="조치내용·비고"><?= e($c['action'] ?? '') ?></textarea>
              <button class="btn small primary" form="<?= $fid ?>">저장</button>
            </div>
          </details>
        </td>
        <td class="nowrap small"><?= e($c['receiver_name'] ?? '') ?><?php if (($c['updated_name'] ?? '') !== '' && $c['updated_name'] !== $c['receiver_name']): ?><br><small class="muted">수정 <?= e($c['updated_name']) ?></small><?php endif ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="center muted">이 기간에 민원이 없습니다.</td></tr><?php endif ?>
    </tbody>
  </table>
  </div>
</form>
<?php foreach ($rows as $c): ?>
  <form method="post" id="c<?= (int) $c['id'] ?>f" class="hidden-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>"></form>
  <form method="post" id="d<?= (int) $c['id'] ?>" class="hidden-form"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>"></form>
<?php endforeach ?>
<script>
(function () {
  const form = document.getElementById('cplBulk');
  const rows = [...form.querySelectorAll('[data-row-check]')];
  const all = form.querySelector('[data-check-all]');
  if (!all) return;
  const sync = () => {
    const n = rows.filter((c) => c.checked).length;
    form.querySelector('[data-selected-count]').textContent = n + '건 선택';
    all.checked = n > 0 && n === rows.length;
    form.querySelectorAll('[data-bulk]').forEach((b) => { b.disabled = n === 0; });
  };
  all.addEventListener('change', () => { rows.forEach((c) => { c.checked = all.checked; }); sync(); });
  rows.forEach((c) => c.addEventListener('change', sync));
  sync();
})();
</script>
<?php layout_footer();
