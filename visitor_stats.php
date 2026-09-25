<?php
/**
 * 통계 › 입장객통계: 매출보고의 입장권 판매만 (유료·무료 매수, 금액, 현금·카드)
 *   visitor_stats.php?unit=range&from=2026-09-01&to=2026-09-30
 *   visitor_stats.php?unit=week|month|year&date=2026-09-25     (그 날이 속한 주·달·해, 이전·다음 이동)
 *   + &approved=1 (결재완료만) &export=xlsx
 * 그래프: 유료·무료·합계 꺾은선 (주간·월간·3개월 이하 기간은 일별, 연간·긴 기간은 월별)
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/xlsx.php';

$user = require_login();
require_menu($user, 'stat');
$pdo = db();

const VS_UNITS = ['range' => '기간별', 'week' => '주간', 'month' => '월간', 'year' => '연간'];
$unit = isset(VS_UNITS[$_GET['unit'] ?? '']) ? $_GET['unit'] : 'month';
$date = valid_date($_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
[$from, $to, $prevDate, $nextDate, $periodLabel] = match ($unit) {
    'week'  => [$m = date('Y-m-d', strtotime('monday this week', strtotime($date))), $s = date('Y-m-d', strtotime("$m +6 days")),
                date('Y-m-d', strtotime("$m -7 days")), date('Y-m-d', strtotime("$m +7 days")), date('Y년 n/j', strtotime($m)) . ' ~ ' . date('n/j', strtotime($s))],
    'month' => [$m = date('Y-m-01', strtotime($date)), date('Y-m-t', strtotime($m)), date('Y-m-d', strtotime("$m -1 month")), date('Y-m-d', strtotime("$m +1 month")), date('Y년 n월', strtotime($m))],
    'year'  => [$y = date('Y', strtotime($date)) . '-01-01', substr($y, 0, 4) . '-12-31', (substr($y, 0, 4) - 1) . '-01-01', (substr($y, 0, 4) + 1) . '-01-01', substr($y, 0, 4) . '년'],
    default => [null, null, null, null, ''],
};
if ($unit === 'range') {
    $from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
    $to = valid_date($_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
    if ($to < $from) [$from, $to] = [$to, $from];
    if ((strtotime($to) - strtotime($from)) / 86400 > 366 * 3) $from = date('Y-m-d', strtotime("$to -" . (366 * 3) . ' days'));
    $periodLabel = "$from ~ $to";
}
$approvedOnly = !empty($_GET['approved']);
$statuses = $approvedOnly ? ['approved'] : config('chart_statuses', ['pending', 'approved']);
$statusLabel = $approvedOnly ? '결재완료' : '결재중·결재완료';
$in = implode(',', array_fill(0, count($statuses), '?'));

// 표·그래프 구간: 일별(3개월 이하) 또는 월별
$gran = chart_gran($from, $to);
$buckets = chart_buckets($from, $to, $gran);
$blank = ['paid' => 0, 'free' => 0, 'total' => 0, 'amount' => 0, 'cash' => 0, 'card' => 0, 'days' => 0];
$data = array_fill_keys(array_keys($buckets), $blank);
$weekdays = array_fill(0, 7, ['paid' => 0, 'free' => 0, 'total' => 0, 'days' => 0]);

$st = $pdo->prepare("SELECT j.work_date, SUM(IF(l.is_free = 0, l.qty, 0)) AS paid, SUM(IF(l.is_free = 1, l.qty, 0)) AS free, SUM(l.amount) AS amount
                       FROM journals j JOIN sales_lines l ON l.journal_id = j.id
                      WHERE j.type = 'sales' AND l.grp = 'ticket' AND j.status IN ($in) AND j.work_date BETWEEN ? AND ?
                      GROUP BY j.work_date");
$st->execute([...$statuses, $from, $to]);
foreach ($st as $r) {
    $k = chart_key($r['work_date'], $gran);
    if (!isset($data[$k])) continue;
    $data[$k]['paid'] += (int) $r['paid'];
    $data[$k]['free'] += (int) $r['free'];
    $data[$k]['amount'] += (int) $r['amount'];
    $data[$k]['days']++;
    $w = (int) date('w', strtotime($r['work_date']));
    $weekdays[$w]['paid'] += (int) $r['paid'];
    $weekdays[$w]['free'] += (int) $r['free'];
    $weekdays[$w]['days']++;
}
$st = $pdo->prepare("SELECT j.work_date, m.ticket_cash FROM journals j JOIN sales_meta m ON m.journal_id = j.id
                      WHERE j.type = 'sales' AND j.status IN ($in) AND j.work_date BETWEEN ? AND ?");
$st->execute([...$statuses, $from, $to]);
foreach ($st as $r) {
    $k = chart_key($r['work_date'], $gran);
    if (isset($data[$k])) $data[$k]['cash'] += (int) $r['ticket_cash'];
}
$sum = $blank;
foreach ($data as &$row) {
    $row['total'] = $row['paid'] + $row['free'];
    $row['card'] = max(0, $row['amount'] - $row['cash']);
    foreach ($blank as $c => $_) $sum[$c] += $row[$c];
}
unset($row);
foreach ($weekdays as &$w) $w['total'] = $w['paid'] + $w['free'];
unset($w);
// 입장권별
$st = $pdo->prepare("SELECT l.name, l.is_free, SUM(l.qty) AS qty, SUM(l.amount) AS amount
                       FROM journals j JOIN sales_lines l ON l.journal_id = j.id
                      WHERE j.type = 'sales' AND l.grp = 'ticket' AND j.status IN ($in) AND j.work_date BETWEEN ? AND ?
                      GROUP BY l.name, l.is_free ORDER BY l.is_free, MIN(l.product_id), l.name");
$st->execute([...$statuses, $from, $to]);
$products = $st->fetchAll();
$busiest = $sum['total'] ? array_search(max(array_column($data, 'total')), array_column($data, 'total'), true) : false;
$busiestKey = $busiest !== false ? array_keys($data)[$busiest] : null;

$keyHead = $gran === 'day' ? '일자' : '월';
$title = '입장객 통계 (' . VS_UNITS[$unit] . ") $periodLabel";
$wd = ['일', '월', '화', '수', '목', '금', '토'];

if (($_GET['export'] ?? '') === 'xlsx') {
    $sub = $statusLabel . ' 매출보고 집계 · 출력 ' . date('Y-m-d H:i') . ' · ' . $user['name'];
    xlsx_send(str_replace([' ', '(', ')', '~'], ['_', '', '', '-'], $title) . '.xlsx', [
        ['name' => $gran === 'day' ? '일별' : '월별', 'title' => config('site_name') . ' ' . $title, 'subtitle' => $sub,
            'header' => [$keyHead, '유료(매)', '무료(매)', '합계(매)', '입장권 금액', '현금', '카드'],
            'rows' => array_map(fn($k) => [$buckets[$k], $data[$k]['paid'], $data[$k]['free'], $data[$k]['total'], $data[$k]['amount'], $data[$k]['cash'], $data[$k]['card']], array_keys($data)),
            'footer' => [['합계', $sum['paid'], $sum['free'], $sum['total'], $sum['amount'], $sum['cash'], $sum['card']]], 'widths' => [14, 10, 10, 10, 14, 13, 13]],
        ['name' => '입장권별', 'title' => $title . ' · 입장권별', 'subtitle' => $sub, 'header' => ['입장권', '구분', '수량(매)', '금액'],
            'rows' => array_map(fn($p) => [$p['name'], $p['is_free'] ? '무료' : '유료', (int) $p['qty'], (int) $p['amount']], $products),
            'footer' => [['합계', '', $sum['total'], $sum['amount']]], 'widths' => [24, 8, 12, 14]],
        ['name' => '요일별', 'title' => $title . ' · 요일별', 'subtitle' => $sub, 'header' => ['요일', '운영일', '유료', '무료', '합계', '하루 평균'],
            'rows' => array_map(fn($i) => [$wd[$i], $weekdays[$i]['days'], $weekdays[$i]['paid'], $weekdays[$i]['free'], $weekdays[$i]['total'],
                $weekdays[$i]['days'] ? round($weekdays[$i]['total'] / $weekdays[$i]['days'], 1) : 0], [1, 2, 3, 4, 5, 6, 0]),
            'widths' => [8, 10, 10, 10, 10, 12]],
    ]);
}

$q = fn(array $o = []) => 'visitor_stats.php?' . http_build_query(array_filter(array_merge(
    ['unit' => $unit, 'date' => $unit === 'range' ? null : $date, 'from' => $unit === 'range' ? $from : null, 'to' => $unit === 'range' ? $to : null, 'approved' => $approvedOnly ? 1 : null], $o),
    fn($v) => $v !== null && $v !== ''));

layout_header('입장객통계', 'visit_stats');
?>
<style>@page { size: A4 landscape; margin: 10mm; }</style>
<section class="card">
  <div class="card-head">
    <h1>입장객통계 <small class="muted"><?= e(VS_UNITS[$unit]) ?></small></h1>
    <div class="actions no-margin no-print">
      <a class="btn" href="<?= e(url($q(['export' => 'xlsx']))) ?>">엑셀</a>
      <button class="btn" type="button" onclick="window.print()">인쇄</button>
    </div>
  </div>
  <div class="cpl-nav no-print">
    <div class="stat-units">
      <?php foreach (VS_UNITS as $u => $ul): ?><a class="<?= $unit === $u ? 'on' : '' ?>" href="<?= e(url('visitor_stats.php?' . http_build_query(array_filter(['unit' => $u, 'date' => $u === 'range' ? null : $date, 'approved' => $approvedOnly ? 1 : null])))) ?>"><?= $ul ?></a><?php endforeach ?>
    </div>
    <?php if ($unit === 'range'): ?>
      <form method="get" class="inline-form">
        <input type="hidden" name="unit" value="range"><?php if ($approvedOnly): ?><input type="hidden" name="approved" value="1"><?php endif ?>
        <input type="date" name="from" value="<?= e($from) ?>"> ~ <input type="date" name="to" value="<?= e($to) ?>"><button class="btn small primary">조회</button>
      </form>
    <?php else: ?>
      <a class="btn small" href="<?= e(url($q(['date' => $prevDate]))) ?>">‹</a>
      <b><?= e($periodLabel) ?></b>
      <a class="btn small" href="<?= e(url($q(['date' => $nextDate]))) ?>">›</a>
      <a class="btn ghost small" href="<?= e(url($q(['date' => date('Y-m-d')]))) ?>">오늘</a>
    <?php endif ?>
    <label class="inline-check"><input type="checkbox" <?= $approvedOnly ? 'checked' : '' ?> onchange="location.href=<?= e(json_encode(url($q(['approved' => $approvedOnly ? null : 1])))) ?>"> 결재완료만</label>
  </div>
  <div class="kpis k4">
    <div class="kpi total"><span>입장객 합계</span><b><?= number_format($sum['total']) ?>명</b><small class="muted"><?= e($periodLabel) ?></small></div>
    <div class="kpi"><span>유료</span><b><?= number_format($sum['paid']) ?>명</b><small class="muted"><?= $sum['total'] ? round($sum['paid'] / $sum['total'] * 100, 1) : 0 ?>%</small></div>
    <div class="kpi"><span>무료</span><b><?= number_format($sum['free']) ?>명</b><small class="muted"><?= $sum['total'] ? round($sum['free'] / $sum['total'] * 100, 1) : 0 ?>%</small></div>
    <div class="kpi"><span>입장권 금액</span><b><?= e(won($sum['amount'])) ?></b><small class="muted">현금 <?= e(won($sum['cash'])) ?> · 카드 <?= e(won($sum['card'])) ?></small></div>
  </div>
  <p class="muted small">매출보고의 입장권 판매 매수를 입장객 수로 봅니다 (쉬자파크숙박 입실·퇴실 자동 입장권은 무료에 포함). <?= e($statusLabel) ?> 매출보고 집계.
    <?php if ($busiestKey): ?>가장 많은 <?= $gran === 'day' ? '날' : '달' ?>: <b><?= e($buckets[$busiestKey]) ?></b> <?= number_format($data[$busiestKey]['total']) ?>명.<?php endif ?></p>
</section>

<?php stat_chart('visitChart', '입장객 추이 (' . ($gran === 'day' ? '일별' : '월별') . ')', array_values($buckets), [
    ['label' => '합계', 'data' => array_column($data, 'total'), 'color' => '#1b5e20', 'type' => 'line'],
    ['label' => '유료', 'data' => array_column($data, 'paid'), 'color' => '#1a73e8', 'type' => 'line'],
    ['label' => '무료', 'data' => array_column($data, 'free'), 'color' => '#e8710a', 'type' => 'line'],
], '명') ?>

<section class="card">
  <h2><?= $gran === 'day' ? '일별' : '월별' ?> 입장객</h2>
  <div class="table-scroll">
  <table class="table stats-table">
    <thead><tr><th><?= $keyHead ?></th><th class="right">유료</th><th class="right">무료</th><th class="right">합계</th><th class="right">입장권 금액</th><th class="right">현금</th><th class="right">카드</th></tr></thead>
    <tbody>
    <?php foreach ($data as $k => $r): ?>
      <tr class="<?= $r['total'] ? '' : 'zero' ?> <?= $k === $busiestKey ? 'best' : '' ?>"><td class="nowrap"><?= e($buckets[$k]) ?></td>
        <?php foreach (['paid', 'free', 'total', 'amount', 'cash', 'card'] as $c): ?><td class="right <?= $c === 'total' ? 'strong' : '' ?>"><?= $r[$c] ? number_format($r[$c]) : '-' ?></td><?php endforeach ?></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th>합계</th><?php foreach (['paid', 'free', 'total', 'amount', 'cash', 'card'] as $c): ?><th class="right"><?= number_format($sum[$c]) ?></th><?php endforeach ?></tr></tfoot>
  </table>
  </div>
</section>

<div class="grid2">
  <section class="card">
    <h2>입장권별</h2>
    <table class="table">
      <thead><tr><th>입장권</th><th>구분</th><th class="right">수량</th><th class="right">비율</th><th class="right">금액</th></tr></thead>
      <tbody>
      <?php foreach ($products as $p): ?>
        <tr><td><?= e($p['name']) ?></td><td><?= $p['is_free'] ? '무료' : '유료' ?></td><td class="right"><?= number_format($p['qty']) ?></td>
          <td class="right"><?= $sum['total'] ? round($p['qty'] / $sum['total'] * 100, 1) . '%' : '' ?></td><td class="right"><?= number_format($p['amount']) ?></td></tr>
      <?php endforeach ?>
      <?php if (!$products): ?><tr><td colspan="5" class="center muted">자료 없음</td></tr><?php endif ?>
      </tbody>
      <tfoot><tr><th colspan="2">합계</th><th class="right"><?= number_format($sum['total']) ?></th><th></th><th class="right"><?= number_format($sum['amount']) ?></th></tr></tfoot>
    </table>
  </section>
  <section class="card">
    <h2>요일별</h2>
    <table class="table">
      <thead><tr><th>요일</th><th class="right">운영일</th><th class="right">유료</th><th class="right">무료</th><th class="right">합계</th><th class="right">하루 평균</th></tr></thead>
      <tbody>
      <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $i): $w = $weekdays[$i]; ?>
        <tr class="<?= $i === 0 ? 'sun' : ($i === 6 ? 'sat' : '') ?>"><td><?= $wd[$i] ?></td><td class="right"><?= $w['days'] ?></td><td class="right"><?= number_format($w['paid']) ?></td>
          <td class="right"><?= number_format($w['free']) ?></td><td class="right"><b><?= number_format($w['total']) ?></b></td><td class="right"><?= $w['days'] ? number_format($w['total'] / $w['days'], 1) : '-' ?></td></tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </section>
</div>
<?php layout_footer();
