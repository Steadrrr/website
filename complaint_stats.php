<?php
/**
 * 민원통계 (통계 › 민원통계)
 *   complaint_stats.php?ym=2026-09[&export=xlsx]   월별 대분류·중분류별 건수(처리상태별), 객실별 불편·불만(재발 객실)
 * 민원은 일일업무일지에서 입력하고, 처리는 운영관리 › 민원관리에서 한다.
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/xlsx.php';

$user = require_login();
require_menu($user, 'stat');
$pdo = db();
$tree = cpl_tree();

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

layout_header('민원통계', 'cpl_stats');
$prev = date('Y-m', strtotime("$from -1 month"));
$next = date('Y-m', strtotime("$from +1 month"));
?>
<section class="card">
  <div class="card-head">
<h1>민원통계 <small class="muted">월별</small></h1>
<div class="actions no-margin no-print">
  <?php if (can_menu($user, 'ops')): ?><a class="btn ghost" href="<?= e(url('complaints.php?unit=month&date=' . $from)) ?>">민원관리 목록 ›</a><?php endif ?>
  <a class="btn" href="<?= e(url("complaint_stats.php?ym=$ym&export=xlsx")) ?>">엑셀</a>
  <button class="btn" type="button" onclick="window.print()">인쇄</button>
</div>
  </div>
  <div class="cpl-nav no-print">
<a class="btn small" href="<?= e(url("complaint_stats.php?ym=$prev")) ?>">‹ 이전달</a>
<b><?= e(date('Y년 n월', strtotime($from))) ?></b>
<a class="btn small" href="<?= e(url("complaint_stats.php?ym=$next")) ?>">다음달 ›</a>
<form method="get" class="inline"><input type="month" name="ym" value="<?= e($ym) ?>" onchange="this.form.submit()"></form>
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
