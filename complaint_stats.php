<?php
/**
 * 민원통계 (통계 › 민원통계)
 *   complaint_stats.php?ym=2026-09[&export=xlsx]            월별 대분류·중분류별 건수(처리상태별), 객실별 불편·불만(재발 객실)
 *   complaint_stats.php?unit=year&y=2026[&export=xlsx]      연간 — 같은 표 + 1~12월 추이(대분류·중분류별)
 * 민원은 일일업무일지에서 입력하고, 처리는 운영관리 › 민원관리에서 한다.
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/xlsx.php';

$user = require_login();
require_menu($user, 'stat');
$pdo = db();
$tree = cpl_tree();

$unit = ($_GET['unit'] ?? '') === 'year' ? 'year' : 'month';
$ym = preg_match('/^\d{4}-\d{2}$/', $_GET['ym'] ?? '') && valid_date(($_GET['ym'] ?? '') . '-01') ? $_GET['ym'] : date('Y-m');
$y = preg_match('/^\d{4}$/', $_GET['y'] ?? '') && (int) $_GET['y'] >= 2000 && (int) $_GET['y'] <= 2100 ? (int) $_GET['y'] : (int) substr($ym, 0, 4);
if ($unit === 'year') {
    $from = "$y-01-01";
    $to = "$y-12-31";
} else {
    $from = "$ym-01";
    $to = date('Y-m-t', strtotime($from));
}
$period = $unit === 'year' ? '해' : '달';
$st = $pdo->prepare('SELECT * FROM complaints WHERE work_date BETWEEN ? AND ?');
$st->execute([$from, $to]);
$rows = $st->fetchAll();
[$by1, $bySt, $total] = cpl_summary($rows);
$by2 = $by2St = $by3 = [];
$rooms = [];
$byM1 = $byM2 = $byMOpen = []; // 연간: [월][대분류/중분류] 건수
foreach ($rows as $c) {
    $q = (int) $c['qty'];
    $mo = (int) substr($c['work_date'], 5, 2);
    $byM1[$mo][$c['cat1']] = ($byM1[$mo][$c['cat1']] ?? 0) + $q;
    $byM2[$mo][$c['cat2']] = ($byM2[$mo][$c['cat2']] ?? 0) + $q;
    $byM1[$mo]['all'] = ($byM1[$mo]['all'] ?? 0) + $q;
    if ($c['status'] !== 'done') $byMOpen[$mo] = ($byMOpen[$mo] ?? 0) + $q;
    $by2[$c['cat2']] = ($by2[$c['cat2']] ?? 0) + $q;
    $by2St[$c['cat2']][$c['status']] = ($by2St[$c['cat2']][$c['status']] ?? 0) + $q;
    $by3[$c['cat3']] = ($by3[$c['cat3']] ?? 0) + $q;
    if ($c['cat1'] === CPL_COMPLAINT && $c['place_type'] === 'room') {
        $k = (int) $c['place_id'];
        $rooms[$k] ??= ['name' => $c['place_name'], 'total' => 0, 'times' => 0, 'mids' => [], 'open' => 0, 'months' => []];
        $rooms[$k]['months'][$mo] = ($rooms[$k]['months'][$mo] ?? 0) + $q;
        $rooms[$k]['total'] += $q;
        $rooms[$k]['times']++;
        $rooms[$k]['mids'][$c['cat2']] = ($rooms[$k]['mids'][$c['cat2']] ?? 0) + $q;
        if ($c['status'] !== 'done') $rooms[$k]['open'] += $q;
    }
}
uasort($rooms, fn($a, $b) => [$b['total'], $b['times']] <=> [$a['total'], $a['times']]);
foreach ($rooms as &$r) ksort($r['months']);
unset($r);
$title = ($unit === 'year' ? "{$y}년" : date('Y년 n월', strtotime($from))) . ' 민원 통계';
$roomMonths = fn($r) => implode(' · ', array_map(fn($m, $v) => "{$m}월 $v", array_keys($r['months']), $r['months']));

if (($_GET['export'] ?? '') === 'xlsx') {
    $mids = [];
    foreach ($tree['tree'] as $c1 => $x) foreach ($x['mids'] as $c2 => $m) {
        $mids[] = ["$c1. {$x['name']}", "$c2 {$m['name']}", $by2[$c2] ?? 0, $by2St[$c2]['open'] ?? 0, $by2St[$c2]['progress'] ?? 0, $by2St[$c2]['done'] ?? 0];
    }
    $sheets = [
        ['name' => '분류별', 'title' => $title . ' · 대분류·중분류별', 'subtitle' => '출력 ' . date('Y-m-d H:i') . ' · ' . $user['name'],
            'header' => ['대분류', '중분류', '건수', '미조치', '처리중', '완료'], 'rows' => $mids,
            'footer' => [['합계', '', $total, $bySt['open'], $bySt['progress'], $bySt['done']]], 'widths' => [16, 22, 8, 8, 8, 8]],
        ['name' => '객실별 불편·불만', 'title' => $title . ' · 객실별 불편·불만', 'subtitle' => '같은 객실에서 2번 이상 = 재발',
            'header' => array_merge(['객실', '건수', '접수 횟수', '미완료', '내용 (중분류별)'], $unit === 'year' ? ['월별'] : []),
            'rows' => array_map(fn($r) => array_merge([$r['name'], $r['total'], $r['times'], $r['open'], implode(', ', array_map(fn($k, $v) => cpl_name($k) . " $v", array_keys($r['mids']), $r['mids']))], $unit === 'year' ? [$roomMonths($r)] : []), array_values($rooms)),
            'widths' => [22, 8, 10, 8, 50, 30]],
    ];
    if ($unit === 'year') {
        $trend = [];
        foreach ($tree['tree'] as $c1 => $x) {
            $trend[] = array_merge(["$c1. {$x['name']}", ''], array_map(fn($m) => $byM1[$m][$c1] ?? 0, range(1, 12)), [$by1[$c1]]);
            foreach ($x['mids'] as $c2 => $m2) $trend[] = array_merge(['', "$c2 {$m2['name']}"], array_map(fn($m) => $byM2[$m][$c2] ?? 0, range(1, 12)), [$by2[$c2] ?? 0]);
        }
        $sheets[] = ['name' => '월별 추이', 'title' => $title . ' · 월별 추이', 'subtitle' => '출력 ' . date('Y-m-d H:i') . ' · ' . $user['name'],
            'header' => array_merge(['대분류', '중분류'], array_map(fn($m) => "{$m}월", range(1, 12)), ['계']), 'rows' => $trend,
            'footer' => [array_merge(['합계', ''], array_map(fn($m) => $byM1[$m]['all'] ?? 0, range(1, 12)), [$total]),
                         array_merge(['미완료', ''], array_map(fn($m) => $byMOpen[$m] ?? 0, range(1, 12)), [array_sum($byMOpen)])],
            'widths' => array_merge([16, 22], array_fill(0, 12, 6), [8])];
    }
    xlsx_send('민원통계_' . ($unit === 'year' ? $y : $ym) . '.xlsx', $sheets);
}

layout_header('민원통계', 'cpl_stats');
$prev = date('Y-m', strtotime("$from -1 month"));
$next = date('Y-m', strtotime("$from +1 month"));
$thisYm = $y === (int) date('Y') ? date('Y-m') : "$y-01";
$monthUrl = fn($m) => url(sprintf('complaint_stats.php?ym=%04d-%02d', $y, $m));
?>
<section class="card">
  <div class="card-head">
<h1>민원통계 <small class="muted"><?= $unit === 'year' ? '연간' : '월별' ?></small></h1>
<div class="actions no-margin no-print">
  <?php if (can_menu($user, 'ops')): ?><a class="btn ghost" href="<?= e(url('complaints.php?unit=month&date=' . $from)) ?>">민원관리 목록 ›</a><?php endif ?>
  <a class="btn" href="<?= e(url($unit === 'year' ? "complaint_stats.php?unit=year&y=$y&export=xlsx" : "complaint_stats.php?ym=$ym&export=xlsx")) ?>">엑셀</a>
  <button class="btn" type="button" onclick="window.print()">인쇄</button>
</div>
  </div>
  <div class="cpl-nav no-print">
<div class="stat-units">
  <a class="<?= $unit === 'month' ? 'on' : '' ?>" href="<?= e(url('complaint_stats.php?ym=' . ($unit === 'year' ? $thisYm : $ym))) ?>">월별</a>
  <a class="<?= $unit === 'year' ? 'on' : '' ?>" href="<?= e(url("complaint_stats.php?unit=year&y=$y")) ?>">연간</a>
</div>
<?php if ($unit === 'year'): ?>
<a class="btn small" href="<?= e(url('complaint_stats.php?unit=year&y=' . ($y - 1))) ?>">‹ 이전해</a>
<b><?= $y ?>년</b>
<a class="btn small" href="<?= e(url('complaint_stats.php?unit=year&y=' . ($y + 1))) ?>">다음해 ›</a>
<?php else: ?>
<a class="btn small" href="<?= e(url("complaint_stats.php?ym=$prev")) ?>">‹ 이전달</a>
<b><?= e(date('Y년 n월', strtotime($from))) ?></b>
<a class="btn small" href="<?= e(url("complaint_stats.php?ym=$next")) ?>">다음달 ›</a>
<form method="get" class="inline"><input type="month" name="ym" value="<?= e($ym) ?>" onchange="this.form.submit()"></form>
<?php endif ?>
  </div>
  <?php cpl_summary_html($rows, $title) ?>
</section>

<?php
// 그래프: 민원 건수 (월별 보기는 일별, 연간은 월별)
if ($unit === 'year') {
    $cLabels = array_map(fn($m) => "{$m}월", range(1, 12));
    $cData = array_map(fn($m) => $byM1[$m]['all'] ?? 0, range(1, 12));
} else {
    $cB = chart_buckets($from, $to, 'day');
    $cData = array_fill_keys(array_keys($cB), 0);
    foreach ($rows as $c) $cData[$c['work_date']] = ($cData[$c['work_date']] ?? 0) + (int) $c['qty'];
    $cLabels = array_values($cB);
}
stat_chart('cplChart', '민원 건수 추이 (' . ($unit === 'year' ? '월별' : '일별') . ')', $cLabels, [['label' => '민원 건수', 'data' => array_values($cData), 'color' => '#c0392b']], '건');
?>

<?php if ($unit === 'year'): ?>
<section class="card">
  <h2>월별 추이 <small class="muted">월을 누르면 그 달 통계</small></h2>
  <div class="table-scroll">
  <table class="table cpl-stat cpl-trend">
    <thead><tr><th>분류</th><?php foreach (range(1, 12) as $m): ?><th class="right"><a href="<?= e($monthUrl($m)) ?>"><?= $m ?>월</a></th><?php endforeach ?><th class="right">계</th></tr></thead>
    <tbody>
    <?php foreach ($tree['tree'] as $c1 => $x): ?>
      <tr class="cpl-cat1 <?= $c1 === CPL_URGENT ? 'urgent' : '' ?>"><th><?= $c1 ?>. <?= e($x['name']) ?></th>
        <?php foreach (range(1, 12) as $m): ?><th class="right"><?= $byM1[$m][$c1] ?? '' ?></th><?php endforeach ?><th class="right"><?= $by1[$c1] ?></th></tr>
      <?php foreach ($x['mids'] as $c2 => $m2): if (!($by2[$c2] ?? 0)) continue; ?>
        <tr><td class="indent"><?= $c2 ?> <?= e($m2['name']) ?></td>
          <?php foreach (range(1, 12) as $m): ?><td class="right"><?= $byM2[$m][$c2] ?? '' ?></td><?php endforeach ?><td class="right"><b><?= $by2[$c2] ?></b></td></tr>
      <?php endforeach ?>
    <?php endforeach ?>
    </tbody>
    <tfoot>
      <tr><th>합계</th><?php foreach (range(1, 12) as $m): ?><th class="right"><?= $byM1[$m]['all'] ?? 0 ?></th><?php endforeach ?><th class="right"><?= $total ?></th></tr>
      <tr><td>미완료</td><?php foreach (range(1, 12) as $m): ?><td class="right <?= ($byMOpen[$m] ?? 0) ? 'warn' : '' ?>"><?= $byMOpen[$m] ?? '' ?></td><?php endforeach ?><td class="right"><?= array_sum($byMOpen) ?: '' ?></td></tr>
    </tfoot>
  </table>
  </div>
  <p class="muted small">건수가 있는 중분류만 표시합니다. 전체 중분류는 아래 표에서 볼 수 있습니다.</p>
</section>
<?php endif ?>

<div class="grid2">
  <section class="card">
<h2>대분류 · 중분류별 건수</h2>
<table class="table cpl-stat">
  <thead><tr><th>분류</th><th class="right">건수</th><th class="right">미조치</th><th class="right">처리중</th><th class="right">완료</th></tr></thead>
  <tbody>
  <?php foreach ($tree['tree'] as $c1 => $x): ?>
    <tr class="cpl-cat1 <?= $c1 === CPL_URGENT ? 'urgent' : '' ?>"><th><?= $c1 ?>. <?= e($x['name']) ?></th><th class="right"><?= $by1[$c1] ?></th><th colspan="3"></th></tr>
    <?php foreach ($x['mids'] as $c2 => $m): $n = $by2[$c2] ?? 0; ?>
      <tr class="<?= $n ? '' : 'zero' ?>"><td class="indent"><?php if ($unit === 'month'): ?><a href="<?= e(url('complaints.php?' . http_build_query(['unit' => 'month', 'date' => $from, 'cat1' => $c1, 'q' => '']))) ?>"><?= $c2 ?> <?= e($m['name']) ?></a><?php else: ?><?= $c2 ?> <?= e($m['name']) ?><?php endif ?>
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
<p class="muted small">대분류 '3. 불편·불만' 중 장소를 객실로 입력한 민원입니다. 같은 <?= $unit === 'year' ? '해' : '달' ?>에 2번 이상 접수된 객실은 <b class="warn">재발</b>로 표시됩니다.</p>
<table class="table cpl-stat">
  <thead><tr><th>객실</th><th class="right">건수</th><th class="right">접수</th><th>내용</th><?php if ($unit === 'year'): ?><th>월별</th><?php endif ?><th class="right">미완료</th></tr></thead>
  <tbody>
  <?php foreach ($rooms as $r): ?>
    <tr class="<?= $r['times'] >= 2 ? 'recur' : '' ?>"><td><b><?= e($r['name']) ?></b><?= $r['times'] >= 2 ? ' <span class="badge cpl-badge" style="--c:#c0392b">재발</span>' : '' ?></td>
      <td class="right"><?= $r['total'] ?></td><td class="right"><?= $r['times'] ?>회</td>
      <td class="small"><?= e(implode(', ', array_map(fn($k, $v) => cpl_name($k) . " $v", array_keys($r['mids']), $r['mids']))) ?></td>
      <?php if ($unit === 'year'): ?><td class="small nowrap"><?= e($roomMonths($r)) ?></td><?php endif ?>
      <td class="right <?= $r['open'] ? 'warn' : '' ?>"><?= $r['open'] ?: '-' ?></td></tr>
  <?php endforeach ?>
  <?php if (!$rooms): ?><tr><td colspan="<?= $unit === 'year' ? 6 : 5 ?>" class="center muted">이 <?= $period ?>에 객실 불편·불만 민원이 없습니다.</td></tr><?php endif ?>
  </tbody>
</table>
  </section>
</div>
<?php
layout_footer();
