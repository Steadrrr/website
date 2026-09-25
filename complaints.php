<?php
/**
 * 민원관리 (운영관리 › 민원관리)
 *   complaints.php?unit=day|week|month&date=2026-09-25[&status=][&cat1=][&q=]   일자·주·월별 조회, 처리상태 변경·완료 처리
 *   complaints.php?tab=stats&ym=2026-09                                           월별 통계 (대분류·중분류별, 객실별 불편·불만)
 * 민원은 일일업무일지 작성 화면에서 입력한다. 여기서 바꾼 처리상태는 업무일지의 작성·수정 기록에도 남는다 (결재는 그대로).
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/xlsx.php';

$user = require_login();
require_menu($user, 'ops');
$pdo = db();
$tree = cpl_tree();

/* ───────────── 처리상태 변경 · 완료 처리 ───────────── */
if (is_post()) {
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
        if ($c['status'] !== $status || (string) $c['action'] !== (string) $newAction) {
            journal_log((int) $c['journal_id'], $user, '민원 처리 (민원관리)', cpl_path($c, false) . ' → ' . CPL_STATUS[$status][0] . ($status === 'done' ? " $doneDate" : ''));
        }
        $n++;
    }
    flash("민원 {$n}건을 '" . CPL_STATUS[$status][0] . "'(으)로 저장했습니다.", 'success');
    redirect($_POST['back'] ?? 'complaints.php');
}

$tab = ($_GET['tab'] ?? '') === 'stats' ? 'stats' : 'list';

/* ═════════════ 월별 통계 ═════════════ */
if ($tab === 'stats') {
    $ym = preg_match('/^\d{4}-\d{2}$/', $_GET['ym'] ?? '') && valid_date(($_GET['ym'] ?? '') . '-01') ? $_GET['ym'] : date('Y-m');
    $from = "$ym-01";
    $to = date('Y-m-t', strtotime($from));
    $st = $pdo->prepare('SELECT * FROM complaints WHERE work_date BETWEEN ? AND ?');
    $st->execute([$from, $to]);
    $rows = $st->fetchAll();
    [$by1, $bySt, $total] = cpl_summary($rows);
    $by2 = $by2St = $by3 = [];
    $rooms = [];
    foreach ($rows as $c) {
        $q = (int) $c['qty'];
        $by2[$c['cat2']] = ($by2[$c['cat2']] ?? 0) + $q;
        $by2St[$c['cat2']][$c['status']] = ($by2St[$c['cat2']][$c['status']] ?? 0) + $q;
        $by3[$c['cat3']] = ($by3[$c['cat3']] ?? 0) + $q;
        if ($c['cat1'] === CPL_COMPLAINT && $c['place_type'] === 'room') {
            $k = (int) $c['place_id'];
            $rooms[$k] ??= ['name' => $c['place_name'], 'total' => 0, 'times' => 0, 'mids' => [], 'open' => 0];
            $rooms[$k]['total'] += $q;
            $rooms[$k]['times']++;
            $rooms[$k]['mids'][$c['cat2']] = ($rooms[$k]['mids'][$c['cat2']] ?? 0) + $q;
            if ($c['status'] !== 'done') $rooms[$k]['open'] += $q;
        }
    }
    uasort($rooms, fn($a, $b) => [$b['total'], $b['times']] <=> [$a['total'], $a['times']]);
    $title = date('Y년 n월', strtotime($from)) . ' 민원 통계';

    if (($_GET['export'] ?? '') === 'xlsx') {
        $mids = [];
        foreach ($tree['tree'] as $c1 => $x) foreach ($x['mids'] as $c2 => $m) {
            $mids[] = ["$c1. {$x['name']}", "$c2 {$m['name']}", $by2[$c2] ?? 0, $by2St[$c2]['open'] ?? 0, $by2St[$c2]['progress'] ?? 0, $by2St[$c2]['done'] ?? 0];
        }
        xlsx_send("민원통계_$ym.xlsx", [
            ['name' => '분류별', 'title' => $title . ' · 대분류·중분류별', 'subtitle' => '출력 ' . date('Y-m-d H:i') . ' · ' . $user['name'],
                'header' => ['대분류', '중분류', '건수', '미조치', '처리중', '완료'], 'rows' => $mids,
                'footer' => [['합계', '', $total, $bySt['open'], $bySt['progress'], $bySt['done']]], 'widths' => [16, 22, 8, 8, 8, 8]],
            ['name' => '객실별 불편·불만', 'title' => $title . ' · 객실별 불편·불만', 'subtitle' => '같은 객실에서 2번 이상 = 재발',
                'header' => ['객실', '건수', '접수 횟수', '미완료', '내용 (중분류별)'],
                'rows' => array_map(fn($r) => [$r['name'], $r['total'], $r['times'], $r['open'], implode(', ', array_map(fn($k, $v) => cpl_name($k) . " $v", array_keys($r['mids']), $r['mids']))], array_values($rooms)),
                'widths' => [22, 8, 10, 8, 50]],
        ]);
    }

    layout_header('민원 통계', 'complaints');
    $prev = date('Y-m', strtotime("$from -1 month"));
    $next = date('Y-m', strtotime("$from +1 month"));
    ?>
<section class="card">
  <div class="card-head">
    <h1>민원관리 <small class="muted">월별 통계</small></h1>
    <div class="actions no-margin no-print">
      <a class="btn ghost" href="<?= e(url('complaints.php?unit=month&date=' . $from)) ?>">목록 보기 ›</a>
      <a class="btn" href="<?= e(url("complaints.php?tab=stats&ym=$ym&export=xlsx")) ?>">엑셀</a>
      <button class="btn" type="button" onclick="window.print()">인쇄</button>
    </div>
  </div>
  <div class="cpl-nav no-print">
    <a class="btn small" href="<?= e(url("complaints.php?tab=stats&ym=$prev")) ?>">‹ 이전달</a>
    <b><?= e(date('Y년 n월', strtotime($from))) ?></b>
    <a class="btn small" href="<?= e(url("complaints.php?tab=stats&ym=$next")) ?>">다음달 ›</a>
    <form method="get" class="inline"><input type="hidden" name="tab" value="stats"><input type="month" name="ym" value="<?= e($ym) ?>" onchange="this.form.submit()"></form>
  </div>
  <?php cpl_summary_html($rows, $title) ?>
</section>

<div class="grid2">
  <section class="card">
    <h2>대분류 · 중분류별 건수</h2>
    <table class="table cpl-stat">
      <thead><tr><th>분류</th><th class="right">건수</th><th class="right">미조치</th><th class="right">처리중</th><th class="right">완료</th></tr></thead>
      <tbody>
      <?php foreach ($tree['tree'] as $c1 => $x): ?>
        <tr class="cpl-cat1 <?= $c1 === CPL_URGENT ? 'urgent' : '' ?>"><th><?= $c1 ?>. <?= e($x['name']) ?></th><th class="right"><?= $by1[$c1] ?></th><th colspan="3"></th></tr>
        <?php foreach ($x['mids'] as $c2 => $m): $n = $by2[$c2] ?? 0; ?>
          <tr class="<?= $n ? '' : 'zero' ?>"><td class="indent"><a href="<?= e(url('complaints.php?' . http_build_query(['unit' => 'month', 'date' => $from, 'cat1' => $c1, 'q' => '']))) ?>"><?= $c2 ?> <?= e($m['name']) ?></a>
            <?php if ($n): ?><br><small class="muted"><?= e(implode(' · ', array_filter(array_map(fn($k, $v) => ($by3[$k] ?? 0) ? $v . ' ' . $by3[$k] : null, array_keys($m['leaves']), $m['leaves'])))) ?></small><?php endif ?></td>
            <td class="right"><b><?= $n ?: '-' ?></b></td><td class="right"><?= $by2St[$c2]['open'] ?? '' ?></td><td class="right"><?= $by2St[$c2]['progress'] ?? '' ?></td><td class="right"><?= $by2St[$c2]['done'] ?? '' ?></td></tr>
        <?php endforeach ?>
      <?php endforeach ?>
      </tbody>
      <tfoot><tr><th>합계</th><th class="right"><?= $total ?></th><th class="right"><?= $bySt['open'] ?></th><th class="right"><?= $bySt['progress'] ?></th><th class="right"><?= $bySt['done'] ?></th></tr></tfoot>
    </table>
  </section>
  <section class="card">
    <h2>객실별 불편·불만 <small class="muted">재발 객실 확인</small></h2>
    <p class="muted small">대분류 '3. 불편·불만' 중 장소를 객실로 입력한 민원입니다. 같은 달에 2번 이상 접수된 객실은 <b class="warn">재발</b>로 표시됩니다.</p>
    <table class="table cpl-stat">
      <thead><tr><th>객실</th><th class="right">건수</th><th class="right">접수</th><th>내용</th><th class="right">미완료</th></tr></thead>
      <tbody>
      <?php foreach ($rooms as $r): ?>
        <tr class="<?= $r['times'] >= 2 ? 'recur' : '' ?>"><td><b><?= e($r['name']) ?></b><?= $r['times'] >= 2 ? ' <span class="badge cpl-badge" style="--c:#c0392b">재발</span>' : '' ?></td>
          <td class="right"><?= $r['total'] ?></td><td class="right"><?= $r['times'] ?>회</td>
          <td class="small"><?= e(implode(', ', array_map(fn($k, $v) => cpl_name($k) . " $v", array_keys($r['mids']), $r['mids']))) ?></td>
          <td class="right <?= $r['open'] ? 'warn' : '' ?>"><?= $r['open'] ?: '-' ?></td></tr>
      <?php endforeach ?>
      <?php if (!$rooms): ?><tr><td colspan="5" class="center muted">이 달에 객실 불편·불만 민원이 없습니다.</td></tr><?php endif ?>
      </tbody>
    </table>
  </section>
</div>
<?php
    layout_footer();
    exit;
}

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
$st = $pdo->prepare('SELECT c.*, u.name AS receiver_name, j.status AS journal_status, j.author_id FROM complaints c
                       JOIN journals j ON j.id = c.journal_id LEFT JOIN users u ON u.id = c.receiver_id
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

layout_header('민원관리', 'complaints');
?>
<section class="card">
  <div class="card-head">
    <h1>민원관리</h1>
    <div class="actions no-margin no-print">
      <a class="btn ghost" href="<?= e(url('complaints.php?tab=stats&ym=' . substr($from, 0, 7))) ?>">월별 통계 ›</a>
      <a class="btn" href="<?= e(url($qs(['export' => 'xlsx']))) ?>">엑셀</a>
      <button class="btn" type="button" onclick="window.print()">인쇄</button>
      <a class="btn primary" href="<?= e(url('journal.php?type=daily&date=' . date('Y-m-d'))) ?>">+ 업무일지에서 민원 입력</a>
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
    <?php foreach ($rows as $c): $fid = 'c' . $c['id']; ?>
      <tr class="<?= $c['cat1'] === CPL_URGENT ? 'urgent' : '' ?> <?= $c['status'] === 'done' ? 'done' : '' ?>">
        <td class="check-col no-print"><?php if ($c['status'] !== 'done'): ?><input type="checkbox" name="ids[]" value="<?= (int) $c['id'] ?>" data-row-check><?php endif ?></td>
        <td class="nowrap"><?= e(date('n/j', strtotime($c['work_date']))) ?> (<?= weekday_ko($c['work_date']) ?>)<br><small class="muted"><?= e(CPL_CHANNELS[$c['channel']] ?? '') ?></small></td>
        <td><small class="muted"><?= e(cpl_name($c['cat1'])) ?> › <?= e(cpl_name($c['cat2'])) ?></small><br><?= e(cpl_path($c, false)) ?></td>
        <td class="small"><?= $c['place_name'] ? '<small class="muted">' . ($c['place_type'] === 'room' ? '객실' : '시설') . '</small><br>' . e($c['place_name']) : '-' ?></td>
        <td class="pre-wrap"><?= e($c['content']) ?><br><a class="small no-print" href="<?= e(url('view.php?id=' . $c['journal_id'])) ?>">업무일지 ›</a></td>
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
        <td class="nowrap small"><?= e($c['receiver_name'] ?? '') ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="center muted">이 기간에 민원이 없습니다.</td></tr><?php endif ?>
    </tbody>
  </table>
  </div>
</form>
<?php foreach ($rows as $c): ?>
  <form method="post" id="c<?= (int) $c['id'] ?>" class="hidden-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="back" value="<?= e($back) ?>"></form>
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
