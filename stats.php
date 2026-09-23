<?php
/**
 * 통계: 매출(기간별/월별/연간), 업무일지 목록. 엑셀 다운로드·인쇄(PDF 저장)
 *   stats.php?tab=sales&mode=range&from=2026-09-01&to=2026-09-30
 *   stats.php?tab=sales&mode=month&year=2026
 *   stats.php?tab=sales&mode=year
 *   stats.php?tab=daily&from=..&to=..
 *   + &export=xlsx
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/xlsx.php';

$user = require_login();
require_menu($user, 'ops');
$pdo = db();

$tab = ($_GET['tab'] ?? 'sales') === 'daily' ? 'daily' : 'sales';
$mode = in_array($_GET['mode'] ?? '', ['range', 'month', 'year'], true) ? $_GET['mode'] : 'month';
$year = (int) ($_GET['year'] ?? date('Y'));
if ($year < 2000 || $year > 2100) $year = (int) date('Y');
$from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = valid_date($_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
if ($from > $to) [$from, $to] = [$to, $from];
$approvedOnly = !empty($_GET['approved']);
$statuses = $approvedOnly ? ['approved'] : config('chart_statuses', ['pending', 'approved']);
$statusSql = 'j.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
$statusLabel = $approvedOnly ? '결재완료 문서만' : '결재중·결재완료 문서';

/* ───────────── 매출 통계 ───────────── */

if ($tab === 'sales') {
    // 구간 정의: [키 SQL, 시작, 끝, [키 => 라벨]]
    if ($mode === 'range') {
        if ((strtotime($to) - strtotime($from)) / 86400 > 366) $to = date('Y-m-d', strtotime($from . ' +366 days'));
        $keySql = "DATE_FORMAT(j.work_date, '%Y-%m-%d')";
        $rangeFrom = $from; $rangeTo = $to;
        $labels = [];
        for ($d = new DateTimeImmutable($from); $d->format('Y-m-d') <= $to; $d = $d->modify('+1 day')) {
            $labels[$d->format('Y-m-d')] = $d->format('Y-m-d') . ' (' . weekday_ko($d->format('Y-m-d')) . ')';
        }
        $title = "매출 통계 (기간별) {$from} ~ {$to}";
        $keyHead = '일자';
    } elseif ($mode === 'year') {
        $minYear = (int) ($pdo->query("SELECT MIN(YEAR(work_date)) FROM journals WHERE type = 'sales'")->fetchColumn() ?: date('Y'));
        $keySql = "DATE_FORMAT(j.work_date, '%Y')";
        $rangeFrom = "$minYear-01-01"; $rangeTo = date('Y') . '-12-31';
        $labels = [];
        for ($y = $minYear; $y <= (int) date('Y'); $y++) $labels[(string) $y] = "{$y}년";
        $title = "매출 통계 (연간) {$minYear}~" . date('Y');
        $keyHead = '연도';
    } else {
        $keySql = "DATE_FORMAT(j.work_date, '%Y-%m')";
        $rangeFrom = "$year-01-01"; $rangeTo = "$year-12-31";
        $labels = [];
        for ($m = 1; $m <= 12; $m++) $labels[sprintf('%d-%02d', $year, $m)] = "{$m}월";
        $title = "매출 통계 (월별) {$year}년";
        $keyHead = '월';
    }

    $run = function (string $sql) use ($pdo, $statuses, $rangeFrom, $rangeTo): array {
        $st = $pdo->prepare($sql);
        $st->execute([...$statuses, $rangeFrom, $rangeTo]);
        return $st->fetchAll();
    };
    $metrics = ['paid' => 0, 'free' => 0, 'ticket_amt' => 0, 'cash' => 0, 'card' => 0, 'rooms' => 0, 'guests' => 0, 'room_amt' => 0, 'rent_qty' => 0, 'rent_amt' => 0, 'voucher' => 0, 'total' => 0];
    $data = array_fill_keys(array_keys($labels), $metrics);

    foreach ($run("SELECT $keySql AS k,
                SUM(IF(l.grp = 'ticket' AND l.is_free = 0, l.qty, 0)) AS paid,
                SUM(IF(l.grp = 'ticket' AND l.is_free = 1, l.qty, 0)) AS free,
                SUM(IF(l.grp = 'ticket', l.amount, 0)) AS ticket_amt,
                SUM(IF(l.grp = 'room', l.qty, 0)) AS rooms,
                SUM(IF(l.grp = 'room', l.guests, 0)) AS guests,
                SUM(IF(l.grp = 'room', l.amount, 0)) AS room_amt,
                SUM(IF(l.grp IN ('rental', 'lodge'), l.qty, 0)) AS rent_qty,
                SUM(IF(l.grp IN ('rental', 'lodge'), l.amount, 0)) AS rent_amt
           FROM journals j JOIN sales_lines l ON l.journal_id = j.id
          WHERE j.type = 'sales' AND $statusSql AND j.work_date BETWEEN ? AND ? GROUP BY k") as $r) {
        if (!isset($data[$r['k']])) continue;
        foreach (['paid', 'free', 'ticket_amt', 'rooms', 'guests', 'room_amt', 'rent_qty', 'rent_amt'] as $m) $data[$r['k']][$m] = (int) $r[$m];
    }
    foreach ($run("SELECT $keySql AS k, SUM(m.ticket_cash) AS cash FROM journals j JOIN sales_meta m ON m.journal_id = j.id
          WHERE j.type = 'sales' AND $statusSql AND j.work_date BETWEEN ? AND ? GROUP BY k") as $r) {
        if (isset($data[$r['k']])) $data[$r['k']]['cash'] = (int) $r['cash'];
    }
    foreach ($run("SELECT $keySql AS k, SUM(m.denom * m.qty) AS v FROM journals j JOIN voucher_moves m ON m.journal_id = j.id
          WHERE j.type = 'sales' AND m.direction = 'out' AND $statusSql AND j.work_date BETWEEN ? AND ? GROUP BY k") as $r) {
        if (isset($data[$r['k']])) $data[$r['k']]['voucher'] = (int) $r['v'];
    }
    $sum = $metrics;
    foreach ($data as $k => &$row) {
        $row['card'] = max($row['ticket_amt'] - $row['cash'], 0);
        $row['total'] = $row['ticket_amt'] + $row['room_amt'] + $row['rent_amt'];
        foreach ($row as $m => $v) $sum[$m] += $v;
    }
    unset($row);

    // 상품별 합계
    $tickets = $run("SELECT l.name, l.is_free, SUM(l.qty) AS qty, SUM(l.amount) AS amount
          FROM journals j JOIN sales_lines l ON l.journal_id = j.id
         WHERE j.type = 'sales' AND l.grp = 'ticket' AND $statusSql AND j.work_date BETWEEN ? AND ?
         GROUP BY l.name, l.is_free ORDER BY MIN(l.product_id), l.name");
    $rooms = $run("SELECT l.name, SUM(l.qty) AS qty, SUM(l.guests) AS guests, SUM(l.amount) AS amount,
                SUM(IF(l.rate = 'weekday', l.qty, 0)) AS weekday, SUM(IF(l.rate = 'weekend', l.qty, 0)) AS weekend,
                SUM(IF(l.rate = 'peak', l.qty, 0)) AS peak, SUM(IF(l.discounted = 1, l.qty, 0)) AS dc
          FROM journals j JOIN sales_lines l ON l.journal_id = j.id
         WHERE j.type = 'sales' AND l.grp = 'room' AND $statusSql AND j.work_date BETWEEN ? AND ?
         GROUP BY l.name ORDER BY MIN(l.product_id), l.name");
    // 시설대관·대관 숙박시설별 (건수, 야간, 할인, 금액)
    $rentals = $run("SELECT l.grp, l.name, SUM(l.qty) AS qty, SUM(IF(l.rent_time = '2h', l.qty, 0)) AS t2h, SUM(IF(l.rent_time = '4h', l.qty, 0)) AS t4h,
                SUM(IF(l.rent_time = 'day', l.qty, 0)) AS tday, SUM(IF(l.night = 1, l.qty, 0)) AS night, SUM(IF(l.dc_pct > 0, l.qty, 0)) AS dc, SUM(l.amount) AS amount
          FROM journals j JOIN sales_lines l ON l.journal_id = j.id
         WHERE j.type = 'sales' AND l.grp IN ('rental', 'lodge') AND $statusSql AND j.work_date BETWEEN ? AND ?
         GROUP BY l.grp, l.name ORDER BY l.grp, MIN(l.product_id), l.name");

    $head = [$keyHead, '입장권 유료(매)', '입장권 무료(매)', '입장권 금액', '└ 현금', '└ 카드', '판매 객실', '입실 인원', '객실 금액', '시설대관(건)', '시설대관 금액', '상품권 환급', '매출 합계'];
    $order = ['paid', 'free', 'ticket_amt', 'cash', 'card', 'rooms', 'guests', 'room_amt', 'rent_qty', 'rent_amt', 'voucher', 'total'];

    if (($_GET['export'] ?? '') === 'xlsx') {
        $sub = $statusLabel . ' 집계 · 출력 ' . date('Y-m-d H:i') . ' · ' . $user['name'];
        xlsx_send(str_replace(' ', '_', $title) . '.xlsx', [
            [
                'name' => '매출 요약', 'title' => config('site_name') . ' ' . $title, 'subtitle' => $sub, 'header' => $head,
                'rows' => array_map(fn($k) => [$labels[$k], ...array_map(fn($m) => $data[$k][$m], $order)], array_keys($data)),
                'footer' => [['합계', ...array_map(fn($m) => $sum[$m], $order)]],
                'widths' => [16, 11, 11, 13, 11, 11, 10, 10, 13, 11, 13, 12, 14],
            ],
            [
                'name' => '입장권별', 'title' => $title . ' · 입장권별', 'subtitle' => $sub, 'header' => ['상품', '구분', '수량(매)', '금액'],
                'rows' => array_map(fn($t) => [$t['name'], $t['is_free'] ? '무료' : '유료', (int) $t['qty'], (int) $t['amount']], $tickets),
                'footer' => [['합계', '', (int) array_sum(array_column($tickets, 'qty')), (int) array_sum(array_column($tickets, 'amount'))]],
                'widths' => [24, 8, 12, 14],
            ],
            [
                'name' => '객실별', 'title' => $title . ' · 객실별', 'subtitle' => $sub,
                'header' => ['객실', '판매 객실', '비수기 평일', '비수기 주말', '성수기', '할인', '입실 인원', '금액'],
                'rows' => array_map(fn($r) => [$r['name'], (int) $r['qty'], (int) $r['weekday'], (int) $r['weekend'], (int) $r['peak'], (int) $r['dc'], (int) $r['guests'], (int) $r['amount']], $rooms),
                'footer' => [['합계', ...array_map(fn($c) => (int) array_sum(array_column($rooms, $c)), ['qty', 'weekday', 'weekend', 'peak', 'dc', 'guests', 'amount'])]],
                'widths' => [24, 10, 11, 11, 9, 8, 10, 14],
            ],
            [
                'name' => '시설대관별', 'title' => $title . ' · 시설대관별', 'subtitle' => $sub,
                'header' => ['구분', '시설', '건수', '2시간', '4시간', '4시간 이상', '야간', '할인', '금액'],
                'rows' => array_map(fn($r) => [PRODUCT_GROUPS[$r['grp']], $r['name'], (int) $r['qty'], (int) $r['t2h'], (int) $r['t4h'], (int) $r['tday'], (int) $r['night'], (int) $r['dc'], (int) $r['amount']], $rentals),
                'footer' => [['합계', '', ...array_map(fn($c) => (int) array_sum(array_column($rentals, $c)), ['qty', 't2h', 't4h', 'tday', 'night', 'dc', 'amount'])]],
                'widths' => [14, 22, 8, 8, 8, 11, 8, 8, 14],
            ],
        ]);
    }
}

/* ───────────── 업무일지 목록 ───────────── */

if ($tab === 'daily') {
    if ((strtotime($to) - strtotime($from)) / 86400 > 366) $from = date('Y-m-d', strtotime($to . ' -366 days'));
    $st = $pdo->prepare(
        "SELECT j.*, u.name AS author_name FROM journals j JOIN users u ON u.id = j.author_id
          WHERE j.type = 'daily' AND $statusSql AND j.work_date BETWEEN ? AND ?
          ORDER BY j.work_date, j.id"
    );
    $st->execute([...$statuses, $from, $to]);
    $journals = $st->fetchAll();
    $title = "업무일지 {$from} ~ {$to}";

    if (($_GET['export'] ?? '') === 'xlsx') {
        xlsx_send(str_replace(' ', '_', $title) . '.xlsx', [[
            'name' => '업무일지', 'title' => config('site_name') . ' ' . $title,
            'subtitle' => $statusLabel . ' · ' . count($journals) . '건 · 출력 ' . date('Y-m-d H:i') . ' · ' . $user['name'],
            'header' => ['일자', '요일', '작성자', '날씨', '업무내용', '특이사항', '결재상태'],
            'rows' => array_map(fn($j) => [$j['work_date'], weekday_ko($j['work_date']), $j['author_name'], (string) $j['weather'],
                (string) $j['content'], (string) $j['remarks'], JOURNAL_STATUS[$j['status']]], $journals),
            'widths' => [12, 5, 10, 10, 60, 40, 10],
        ]]);
    }
}

$query = fn(array $over) => 'stats.php?' . http_build_query(array_filter([
    'tab' => $tab, 'mode' => $mode, 'year' => $year, 'from' => $from, 'to' => $to, 'approved' => $approvedOnly ? 1 : null, ...$over,
], fn($v) => $v !== null && $v !== ''));

layout_header($title, 'stats');
?>
<style>@page { size: A4 landscape; margin: 10mm; }</style>
<section class="card">
  <div class="card-head">
    <h1>통계</h1>
    <div class="actions no-margin no-print">
      <a class="btn" href="<?= e(url($query(['export' => 'xlsx']))) ?>">엑셀 다운로드</a>
      <button class="btn primary" onclick="window.print()">인쇄 / PDF 저장</button>
    </div>
  </div>
  <div class="tabs team-tabs no-print">
    <a href="<?= e(url('stats.php?tab=sales')) ?>" class="<?= $tab === 'sales' ? 'on' : '' ?>">매출 통계</a>
    <a href="<?= e(url('stats.php?tab=daily')) ?>" class="<?= $tab === 'daily' ? 'on' : '' ?>">업무일지</a>
  </div>

  <form class="filter no-print" method="get">
    <input type="hidden" name="tab" value="<?= $tab ?>">
    <?php if ($tab === 'sales'): ?>
      <select name="mode" onchange="this.form.submit()">
        <option value="range" <?= $mode === 'range' ? 'selected' : '' ?>>기간별 (일자별)</option>
        <option value="month" <?= $mode === 'month' ? 'selected' : '' ?>>월별</option>
        <option value="year" <?= $mode === 'year' ? 'selected' : '' ?>>연간</option>
      </select>
    <?php endif ?>
    <?php if ($tab === 'daily' || $mode === 'range'): ?>
      <input type="date" name="from" value="<?= e($from) ?>"> ~ <input type="date" name="to" value="<?= e($to) ?>">
    <?php elseif ($mode === 'month'): ?>
      <select name="year"><?php for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 9; $y--): ?><option <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option><?php endfor ?></select>년
    <?php endif ?>
    <label class="inline-check"><input type="checkbox" name="approved" value="1" <?= $approvedOnly ? 'checked' : '' ?>> 결재완료만</label>
    <button class="btn small primary">조회</button>
  </form>
</section>

<?php if ($tab === 'sales'): ?>
<section class="card">
  <h2><?= e($title) ?> <small class="muted"><?= e($statusLabel) ?> 집계</small></h2>
  <div class="kpis k4">
    <div class="kpi"><span>입장권 (유료 / 무료)</span><b><?= number_format($sum['paid'] + $sum['free']) ?>매</b><small class="muted">유료 <?= number_format($sum['paid']) ?> · 무료 <?= number_format($sum['free']) ?></small></div>
    <div class="kpi"><span>판매 객실 / 입실 인원</span><b><?= number_format($sum['rooms']) ?>실</b><small class="muted"><?= number_format($sum['guests']) ?>명</small></div>
    <div class="kpi"><span>상품권 환급</span><b><?= e(won($sum['voucher'])) ?></b></div>
    <div class="kpi total"><span>매출 합계</span><b><?= e(won($sum['total'])) ?></b><small class="muted">입장권 <?= e(won($sum['ticket_amt'])) ?> · 객실 <?= e(won($sum['room_amt'])) ?><?= $sum['rent_amt'] ? ' · 시설대관 ' . e(won($sum['rent_amt'])) : '' ?></small></div>
  </div>
  <div class="table-scroll">
  <table class="table stats-table">
    <thead>
      <tr><th rowspan="2"><?= e($keyHead) ?></th><th colspan="5" class="center">입장권</th><th colspan="3" class="center">객실</th><th colspan="2" class="center">시설대관</th><th rowspan="2" class="right">상품권<br>환급</th><th rowspan="2" class="right">매출 합계</th></tr>
      <tr><th class="right">유료(매)</th><th class="right">무료(매)</th><th class="right">금액</th><th class="right">현금</th><th class="right">카드</th>
        <th class="right">판매 객실</th><th class="right">입실 인원</th><th class="right">금액</th><th class="right">건수</th><th class="right">금액</th></tr>
    </thead>
    <tbody>
    <?php foreach ($data as $k => $r): $empty = $r['total'] === 0 && $r['free'] === 0 && $r['rooms'] === 0 && $r['rent_qty'] === 0; ?>
      <tr class="<?= $empty ? 'zero' : '' ?>">
        <td class="nowrap"><?= e($labels[$k]) ?></td>
        <?php foreach ($order as $m): ?><td class="right <?= $m === 'total' ? 'strong' : '' ?>"><?= $r[$m] ? number_format($r[$m]) : '-' ?></td><?php endforeach ?>
      </tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th>합계</th><?php foreach ($order as $m): ?><th class="right"><?= number_format($sum[$m]) ?></th><?php endforeach ?></tr></tfoot>
  </table>
  </div>
</section>

<div class="grid2">
  <section class="card">
    <h2>입장권별</h2>
    <table class="table">
      <thead><tr><th>상품</th><th>구분</th><th class="right">수량(매)</th><th class="right">금액</th></tr></thead>
      <tbody>
      <?php foreach ($tickets as $t): ?>
        <tr><td><?= e($t['name']) ?></td><td><?= $t['is_free'] ? '무료' : '유료' ?></td><td class="right"><?= number_format($t['qty']) ?></td><td class="right"><?= number_format($t['amount']) ?></td></tr>
      <?php endforeach ?>
      <?php if (!$tickets): ?><tr><td colspan="4" class="center muted">자료 없음</td></tr><?php endif ?>
      </tbody>
      <tfoot><tr><th colspan="2">합계</th><th class="right"><?= number_format(array_sum(array_column($tickets, 'qty'))) ?></th><th class="right"><?= number_format(array_sum(array_column($tickets, 'amount'))) ?></th></tr></tfoot>
    </table>
  </section>
  <section class="card">
    <h2>객실별</h2>
    <table class="table">
      <?php $rc = ['qty', 'weekday', 'weekend', 'peak', 'dc', 'guests', 'amount']; ?>
      <thead><tr><th>객실</th><th class="right">판매</th><th class="right">비수기<br>평일</th><th class="right">비수기<br>주말</th><th class="right">성수기</th><th class="right">할인</th><th class="right">인원</th><th class="right">금액</th></tr></thead>
      <tbody>
      <?php foreach ($rooms as $r): ?>
        <tr><td><?= e($r['name']) ?></td><?php foreach ($rc as $c): ?><td class="right"><?= number_format($r[$c]) ?></td><?php endforeach ?></tr>
      <?php endforeach ?>
      <?php if (!$rooms): ?><tr><td colspan="8" class="center muted">자료 없음</td></tr><?php endif ?>
      </tbody>
      <tfoot><tr><th>합계</th><?php foreach ($rc as $c): ?><th class="right"><?= number_format(array_sum(array_column($rooms, $c))) ?></th><?php endforeach ?></tr></tfoot>
    </table>
  </section>
</div>
<?php if ($rentals): $rtc = ['qty', 't2h', 't4h', 'tday', 'night', 'dc', 'amount']; ?>
<section class="card">
  <h2>시설대관별</h2>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>구분</th><th>시설</th><th class="right">건수</th><th class="right">2시간</th><th class="right">4시간</th><th class="right">4시간 이상</th><th class="right">야간</th><th class="right">할인</th><th class="right">금액</th></tr></thead>
    <tbody>
    <?php foreach ($rentals as $r): ?>
      <tr><td><?= e(PRODUCT_GROUPS[$r['grp']]) ?></td><td><?= e($r['name']) ?></td><?php foreach ($rtc as $c): ?><td class="right"><?= $r[$c] ? number_format($r[$c]) : '-' ?></td><?php endforeach ?></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th colspan="2">합계</th><?php foreach ($rtc as $c): ?><th class="right"><?= number_format(array_sum(array_column($rentals, $c))) ?></th><?php endforeach ?></tr></tfoot>
  </table>
  </div>
</section>
<?php endif ?>
<p class="muted small no-print">현금·카드는 입장권 기준입니다(카드 = 입장권 금액 − 현금). 이전 형식으로 작성된 매출보고는 통계에 포함되지 않습니다.</p>

<?php else: ?>
<section class="card">
  <h2><?= e($title) ?> <small class="muted"><?= count($journals) ?>건 · <?= e($statusLabel) ?></small></h2>
  <table class="table daily-list">
    <thead><tr><th>일자</th><th>작성자</th><th>날씨</th><th>업무내용</th><th>특이사항</th><th>상태</th></tr></thead>
    <tbody>
    <?php foreach ($journals as $j): ?>
      <tr>
        <td class="nowrap"><a href="<?= e(url('view.php?id=' . $j['id'])) ?>"><?= e($j['work_date']) ?></a> (<?= weekday_ko($j['work_date']) ?>)</td>
        <td class="nowrap"><?= e($j['author_name']) ?></td>
        <td class="nowrap"><?= e($j['weather']) ?></td>
        <td class="pre-cell"><?= e($j['content']) ?></td>
        <td class="pre-cell"><?= e($j['remarks']) ?></td>
        <td><?= journal_badges($j) ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$journals): ?><tr><td colspan="6" class="center muted">해당 기간의 업무일지가 없습니다.</td></tr><?php endif ?>
    </tbody>
  </table>
</section>
<?php endif ?>
<?php layout_footer();
