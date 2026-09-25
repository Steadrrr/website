<?php
/**
 * 프로그램 통계: program_stats.php?unit=day|week|month&prog=healing|kidsforest|guide&from=&to=[&approved=1][&export=xlsx]
 * 산림치유센터·유아숲체험원·숲해설 운영보고의 회차·인원(성별·연령대)·유료/무료·금액을 일별·주별·월별로 집계
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/xlsx.php';

$user = require_login();
require_menu($user, 'stat');
$pdo = db();

$unit = in_array($_GET['unit'] ?? '', ['day', 'week', 'month'], true) ? $_GET['unit'] : 'day';
$prog = isset(PROGRAM_TYPES[$_GET['prog'] ?? '']) ? $_GET['prog'] : '';
$today = date('Y-m-d');
$defaultFrom = match ($unit) {
    'week'  => date('Y-m-d', strtotime('monday this week -11 weeks')),
    'month' => date('Y-01-01'),
    default => date('Y-m-01'),
};
$from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : $defaultFrom;
$to = valid_date($_GET['to'] ?? '') ? $_GET['to'] : $today;
if ($to < $from) [$from, $to] = [$to, $from];
$maxDays = ['day' => 366, 'week' => 366 * 3, 'month' => 366 * 10][$unit];
if ((strtotime($to) - strtotime($from)) / 86400 > $maxDays) $from = date('Y-m-d', strtotime("$to -$maxDays days"));
$approvedOnly = !empty($_GET['approved']);
$statuses = $approvedOnly ? ['approved'] : config('chart_statuses', ['pending', 'approved']);
$statusLabel = $approvedOnly ? '결재완료' : '결재중·결재완료';

// 기간 구간 (빈 구간도 표시)
$labels = [];
$d = new DateTimeImmutable($unit === 'week' ? date('Y-m-d', strtotime('monday this week', strtotime($from))) : ($unit === 'month' ? substr($from, 0, 7) . '-01' : $from));
$end = new DateTimeImmutable($to);
for ($i = 0; $d <= $end && $i < 4000; $i++) {
    if ($unit === 'day') { $labels[$d->format('Y-m-d')] = $d->format('n/j') . '(' . weekday_ko($d->format('Y-m-d')) . ')'; $d = $d->modify('+1 day'); }
    elseif ($unit === 'week') { $labels[$d->format('Y-m-d')] = $d->format('n/j') . ' ~ ' . $d->modify('+6 days')->format('n/j'); $d = $d->modify('+7 days'); }
    else { $labels[$d->format('Y-m')] = $d->format('Y년 n월'); $d = $d->modify('+1 month'); }
}
$keyExpr = match ($unit) {
    'week'  => "DATE_FORMAT(DATE_SUB(j.work_date, INTERVAL WEEKDAY(j.work_date) DAY), '%Y-%m-%d')",
    'month' => "DATE_FORMAT(j.work_date, '%Y-%m')",
    default => "DATE_FORMAT(j.work_date, '%Y-%m-%d')",
};

$types = $prog ? [$prog] : array_keys(PROGRAM_TYPES);
$ageCols = program_people_cols();
$sumSql = 'COUNT(p.id) AS sessions, COUNT(DISTINCT j.id) AS days, ' . implode(', ', array_map(fn($c) => "SUM(p.$c) AS $c", $ageCols))
    . ', SUM(p.total) AS total, SUM(IF(p.fee_type = \'paid\', p.total, 0)) AS paid, SUM(IF(p.fee_type = \'discount\', p.total, 0)) AS discount, SUM(IF(p.fee_type = \'free\', p.total, 0)) AS free, SUM(p.amount) AS amount';
$where = 'j.type IN (' . implode(',', array_fill(0, count($types), '?')) . ') AND j.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ') AND j.work_date BETWEEN ? AND ?';
$args = [...$types, ...$statuses, $from, $to];
$run = function (string $select, string $group) use ($pdo, $where, $args): array {
    $st = $pdo->prepare("SELECT $select FROM journals j JOIN program_sessions p ON p.journal_id = j.id WHERE $where GROUP BY $group");
    $st->execute($args);
    return $st->fetchAll();
};
$blank = ['sessions' => 0, 'days' => 0, 'total' => 0, 'paid' => 0, 'discount' => 0, 'free' => 0, 'amount' => 0, 'm' => 0, 'f' => 0] + array_fill_keys($ageCols, 0);
$norm = function (array $r) use ($blank, $ageCols): array {
    $o = $blank;
    foreach ($blank as $k => $_) if (isset($r[$k])) $o[$k] = (int) $r[$k];
    foreach (PROGRAM_AGES as $a => $_) { $o['m'] += $o["m_$a"]; $o['f'] += $o["f_$a"]; }
    foreach (PROGRAM_AGES as $a => $_) $o["age_$a"] = $o["m_$a"] + $o["f_$a"];
    return $o;
};

$data = [];
foreach ($labels as $k => $_) $data[$k] = $norm([]);
foreach ($run("$keyExpr AS k, $sumSql", 'k') as $r) if (isset($data[$r['k']])) $data[$r['k']] = $norm($r);
$sum = $norm($run($sumSql, "'all'")[0] ?? []);
$byProg = [];
foreach (PROGRAM_TYPES as $t => $label) if (in_array($t, $types, true)) $byProg[$t] = $norm([]);
foreach ($run("j.type AS t, $sumSql", 'j.type') as $r) $byProg[$r['t']] = $norm($r);

$cols = ['sessions' => '회차', 'm' => '남', 'f' => '여'];
foreach (PROGRAM_AGES as $a => $label) $cols["age_$a"] = $label;
$cols += ['total' => '인원 합계', 'paid' => '유료', 'discount' => '할인', 'free' => '무료', 'amount' => '금액(원)'];
$unitLabel = ['day' => '일별', 'week' => '주별', 'month' => '월별'][$unit];
$title = ($prog ? PROGRAM_TYPES[$prog] : '프로그램 전체') . " {$unitLabel} 통계 ($from ~ $to)";

if (($_GET['export'] ?? '') === 'xlsx') {
    $sub = $statusLabel . ' 집계 · 출력 ' . date('Y-m-d H:i') . ' · ' . $user['name'];
    $matrix = [];
    foreach (PROGRAM_AGES as $a => $label) $matrix[] = [$label, $sum["m_$a"], $sum["f_$a"], $sum["age_$a"]];
    xlsx_send(str_replace([' ', '(', ')', '~'], ['_', '', '', '-'], $title) . '.xlsx', [
        ['name' => $unitLabel, 'title' => config('site_name') . ' ' . $title, 'subtitle' => $sub, 'header' => ['기간', ...array_values($cols)],
            'rows' => array_map(fn($k) => [$labels[$k], ...array_map(fn($c) => $data[$k][$c], array_keys($cols))], array_keys($data)),
            'footer' => [['합계', ...array_map(fn($c) => $sum[$c], array_keys($cols))]], 'widths' => [16, 8, 8, 8, 8, 8, 8, 8, 10, 10, 8, 8, 8, 13]],
        ['name' => '분야별', 'title' => $title . ' · 분야별', 'subtitle' => $sub, 'header' => ['분야', '운영일', ...array_values($cols)],
            'rows' => array_map(fn($t) => [PROGRAM_TYPES[$t], $byProg[$t]['days'], ...array_map(fn($c) => $byProg[$t][$c], array_keys($cols))], array_keys($byProg)),
            'footer' => [['합계', $sum['days'], ...array_map(fn($c) => $sum[$c], array_keys($cols))]], 'widths' => [16, 8, 8, 8, 8, 8, 8, 8, 8, 10, 10, 8, 8, 8, 13]],
        ['name' => '성별·연령별', 'title' => $title . ' · 성별·연령별', 'subtitle' => $sub, 'header' => ['연령대', '남', '여', '계'],
            'rows' => $matrix, 'footer' => [['합계', $sum['m'], $sum['f'], $sum['total']]], 'widths' => [14, 10, 10, 10]],
    ]);
}

$q = fn(array $o) => 'program_stats.php?' . http_build_query(array_filter(array_merge(['unit' => $unit, 'prog' => $prog, 'from' => $from, 'to' => $to, 'approved' => $approvedOnly ? 1 : null], $o), fn($v) => $v !== null && $v !== ''));

layout_header('프로그램 통계', 'prog_stats');
?>
<section class="card no-print">
  <div class="card-head">
    <h1>프로그램 통계</h1>
    <div class="actions no-margin">
      <a class="btn" href="<?= e(url($q(['export' => 'xlsx']))) ?>">엑셀 다운로드</a>
      <button class="btn" type="button" onclick="window.print()">인쇄 · PDF</button>
    </div>
  </div>
  <form method="get" class="filters">
    <div class="stat-units">
      <?php foreach (['day' => '일별', 'week' => '주별', 'month' => '월별'] as $u => $label): ?>
        <a href="<?= e(url('program_stats.php?' . http_build_query(array_filter(['unit' => $u, 'prog' => $prog])))) ?>" class="<?= $unit === $u ? 'on' : '' ?>"><?= $label ?></a>
      <?php endforeach ?>
    </div>
    <input type="hidden" name="unit" value="<?= e($unit) ?>">
    <label>분야
      <select name="prog">
        <option value="">전체</option>
        <?php foreach (PROGRAM_TYPES as $t => $label): ?><option value="<?= $t ?>" <?= $prog === $t ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
      </select>
    </label>
    <label>시작<input type="date" name="from" value="<?= e($from) ?>"></label>
    <label>종료<input type="date" name="to" value="<?= e($to) ?>"></label>
    <label class="inline-check"><input type="checkbox" name="approved" value="1" <?= $approvedOnly ? 'checked' : '' ?>> 결재완료만</label>
    <button class="btn small primary">조회</button>
  </form>
</section>

<section class="card">
  <h2><?= e($title) ?> <small class="muted"><?= e($statusLabel) ?> 집계</small></h2>
  <div class="kpis k4">
    <div class="kpi"><span>운영 회차</span><b><?= number_format($sum['sessions']) ?>회</b><small class="muted">운영일 <?= number_format($sum['days']) ?>일</small></div>
    <div class="kpi"><span>참여 인원</span><b><?= number_format($sum['total']) ?>명</b><small class="muted">남 <?= number_format($sum['m']) ?> · 여 <?= number_format($sum['f']) ?></small></div>
    <div class="kpi"><span>유료 / 할인 / 무료</span><b><?= number_format($sum['paid']) ?> / <?= number_format($sum['discount']) ?> / <?= number_format($sum['free']) ?>명</b></div>
    <div class="kpi total"><span>프로그램 금액</span><b><?= e(won($sum['amount'])) ?></b></div>
  </div>
  <div class="table-scroll">
  <table class="table stats-table">
    <thead><tr><th>기간</th><?php foreach ($cols as $label): ?><th class="right"><?= e($label) ?></th><?php endforeach ?></tr></thead>
    <tbody>
    <?php foreach ($data as $k => $r): ?>
      <tr class="<?= $r['sessions'] ? '' : 'zero' ?>"><td class="nowrap"><?= e($labels[$k]) ?></td>
        <?php foreach ($cols as $c => $_): ?><td class="right <?= $c === 'total' ? 'strong' : '' ?>"><?= $r[$c] ? number_format($r[$c]) : '-' ?></td><?php endforeach ?></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th>합계</th><?php foreach ($cols as $c => $_): ?><th class="right"><?= number_format($sum[$c]) ?></th><?php endforeach ?></tr></tfoot>
  </table>
  </div>
</section>

<div class="grid2">
  <section class="card">
    <h2>분야별</h2>
    <div class="table-scroll">
    <table class="table">
      <thead><tr><th>분야</th><th class="right">운영일</th><th class="right">회차</th><th class="right">인원</th><th class="right">유료</th><th class="right">할인</th><th class="right">무료</th><th class="right">금액</th></tr></thead>
      <tbody>
      <?php foreach ($byProg as $t => $r): ?>
        <tr><td><a href="<?= e(url($q(['prog' => $t]))) ?>"><?= e(PROGRAM_TYPES[$t]) ?></a></td>
          <?php foreach (['days', 'sessions', 'total', 'paid', 'discount', 'free', 'amount'] as $c): ?><td class="right"><?= number_format($r[$c]) ?></td><?php endforeach ?></tr>
      <?php endforeach ?>
      </tbody>
      <tfoot><tr><th>합계</th><?php foreach (['days', 'sessions', 'total', 'paid', 'discount', 'free', 'amount'] as $c): ?><th class="right"><?= number_format($sum[$c]) ?></th><?php endforeach ?></tr></tfoot>
    </table>
    </div>
  </section>
  <section class="card">
    <h2>성별 · 연령별 인원</h2>
    <table class="table">
      <thead><tr><th>연령대</th><th class="right">남</th><th class="right">여</th><th class="right">계</th></tr></thead>
      <tbody>
      <?php foreach (PROGRAM_AGES as $a => $label): ?>
        <tr><td><?= e($label) ?></td><td class="right"><?= number_format($sum["m_$a"]) ?></td><td class="right"><?= number_format($sum["f_$a"]) ?></td><td class="right"><b><?= number_format($sum["age_$a"]) ?></b></td></tr>
      <?php endforeach ?>
      </tbody>
      <tfoot><tr><th>합계</th><th class="right"><?= number_format($sum['m']) ?></th><th class="right"><?= number_format($sum['f']) ?></th><th class="right"><?= number_format($sum['total']) ?></th></tr></tfoot>
    </table>
  </section>
</div>
<p class="muted small no-print">주별은 월요일~일요일 기준입니다. 금액은 인원 × 1인 참가비(유료·할인, 작성 당시 금액)입니다. 임시저장·반려된 보고서는 집계하지 않습니다.</p>
<?php layout_footer();
