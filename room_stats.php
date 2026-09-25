<?php
/**
 * 객실이용통계 (운영관리 › 객실이용통계)
 *   room_stats.php?unit=day|week|month|year&from=&to=[&type=객실분류][&cmp=prev|lastyear|custom&cfrom=&cto=][&approved=1][&export=xlsx]
 * 매출보고의 객실 판매(대관 숙박시설 제외)로 판매 객실·입실인원·매출·가동률·평균 객실단가·RevPAR 를
 * 일간·주간·월간·연간으로 집계하고, 두 기간을 비교한다.
 *   가동률 = 판매 객실(실·박) ÷ (객실 수 × 일수). 객실 수는 상품관리의 '판매 중' 객실 기준
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/xlsx.php';

$user = require_login();
require_menu($user, 'stat');
$pdo = db();

const RS_UNITS = ['day' => '일간', 'week' => '주간', 'month' => '월간', 'year' => '연간'];
$unit = isset(RS_UNITS[$_GET['unit'] ?? '']) ? $_GET['unit'] : 'day';
$today = date('Y-m-d');
[$defFrom, $defTo] = match ($unit) {
    'week'  => [date('Y-m-d', strtotime('monday this week -11 weeks')), $today],
    'month' => [date('Y-01-01'), $today],
    'year'  => [(date('Y') - 2) . '-01-01', $today],
    default => [date('Y-m-01'), $today],
};
$from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : $defFrom;
$to = valid_date($_GET['to'] ?? '') ? $_GET['to'] : $defTo;
if ($to < $from) [$from, $to] = [$to, $from];
$maxDays = ['day' => 366, 'week' => 366 * 2, 'month' => 366 * 5, 'year' => 366 * 10][$unit];
if ((strtotime($to) - strtotime($from)) / 86400 > $maxDays) $from = date('Y-m-d', strtotime("$to -$maxDays days"));

// 비교 기간: 직전 기간(같은 길이) / 작년 같은 기간 / 직접 입력
$cmp = in_array($_GET['cmp'] ?? '', ['prev', 'lastyear', 'custom'], true) ? $_GET['cmp'] : '';
$len = (int) round((strtotime($to) - strtotime($from)) / 86400);
[$cfrom, $cto] = match ($cmp) {
    'prev'     => [date('Y-m-d', strtotime("$from -" . ($len + 1) . ' days')), date('Y-m-d', strtotime("$from -1 day"))],
    'lastyear' => [date('Y-m-d', strtotime("$from -1 year")), date('Y-m-d', strtotime("$to -1 year"))],
    'custom'   => [valid_date($_GET['cfrom'] ?? '') ? $_GET['cfrom'] : date('Y-m-d', strtotime("$from -1 year")),
                   valid_date($_GET['cto'] ?? '') ? $_GET['cto'] : date('Y-m-d', strtotime("$to -1 year"))],
    default    => [null, null],
};
if ($cmp && $cto < $cfrom) [$cfrom, $cto] = [$cto, $cfrom];
if ($cmp && (strtotime($cto) - strtotime($cfrom)) / 86400 > $maxDays) $cfrom = date('Y-m-d', strtotime("$cto -$maxDays days"));

$approvedOnly = !empty($_GET['approved']);
$statuses = $approvedOnly ? ['approved'] : config('chart_statuses', ['pending', 'approved']);
$statusLabel = $approvedOnly ? '결재완료' : '결재중·결재완료';

// 객실 (대관 숙박시설 제외): 판매 중인 객실 수가 가동률의 분모. 분류(2인실·4인실·독채 등)를 고르면 그 분류 객실만
$types = room_types_all();
$typeId = (int) ($_GET['type'] ?? 0);
if (!isset($types[$typeId])) $typeId = 0;
$allRooms = array_filter(products_all(), fn($p) => $p['grp'] === 'room');
$roomProducts = $typeId ? array_filter($allRooms, fn($p) => (int) $p['room_type_id'] === $typeId) : $allRooms;
$activeRooms = array_filter($roomProducts, fn($p) => $p['is_active']);
$roomCount = max(1, count($activeRooms));

/** 구간 키 / 이름 */
function rs_key(string $date, string $unit): string
{
    return match ($unit) {
        'week'  => date('Y-m-d', strtotime('monday this week', strtotime($date))),
        'month' => substr($date, 0, 7),
        'year'  => substr($date, 0, 4),
        default => $date,
    };
}

function rs_label(string $key, string $unit, string $from, string $to): string
{
    return match ($unit) {
        'week'  => date('n/j', strtotime(max($key, $from))) . '~' . date('n/j', strtotime(min(date('Y-m-d', strtotime("$key +6 days")), $to))),
        'month' => date('Y년 n월', strtotime("$key-01")),
        'year'  => "{$key}년",
        default => date('n/j', strtotime($key)) . '(' . weekday_ko($key) . ')',
    };
}

/**
 * 한 기간의 집계
 * @return array{buckets: array, total: array, rooms: array, weekdays: array, days: int}
 */
function rs_period(string $from, string $to, string $unit, array $statuses, int $roomCount, array $roomProducts, bool $onlyThese = false): array
{
    $blank = ['days' => 0, 'sold' => 0, 'guests' => 0, 'amount' => 0, 'weekday' => 0, 'weekend' => 0, 'peak' => 0, 'dc' => 0];
    $buckets = [];
    $weekdays = array_fill(0, 7, ['days' => 0, 'sold' => 0, 'guests' => 0, 'amount' => 0]);
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
        $k = rs_key($d, $unit);
        $buckets[$k] ??= $blank + ['label' => rs_label($k, $unit, $from, $to)];
        $buckets[$k]['days']++;
        $weekdays[(int) date('w', strtotime($d))]['days']++;
    }
    $st = db()->prepare(
        "SELECT j.work_date, l.product_id, l.name, l.rate, l.discounted, l.qty, l.guests, l.amount
           FROM journals j JOIN sales_lines l ON l.journal_id = j.id
          WHERE j.type = 'sales' AND l.grp = 'room' AND j.work_date BETWEEN ? AND ?
            AND j.status IN (" . implode(',', array_fill(0, count($statuses), '?')) . ')'
            . ($onlyThese ? ' AND l.product_id IN (' . (implode(',', array_map('intval', array_keys($roomProducts))) ?: '0') . ')' : '')
    );
    $st->execute([$from, $to, ...$statuses]);
    $rooms = [];
    foreach ($roomProducts as $p) $rooms[(int) $p['id']] = ['name' => $p['name'], 'active' => (int) $p['is_active'], 'sold' => 0, 'guests' => 0, 'amount' => 0];
    foreach ($st as $r) {
        $k = rs_key($r['work_date'], $unit);
        $q = (int) $r['qty'];
        foreach (['sold' => $q, 'guests' => (int) $r['guests'], 'amount' => (int) $r['amount']] as $m => $v) {
            $buckets[$k][$m] += $v;
            $weekdays[(int) date('w', strtotime($r['work_date']))][$m] += $v;
        }
        if (isset(RATE_TYPES[$r['rate']])) $buckets[$k][$r['rate']] += $q;
        if ($r['discounted']) $buckets[$k]['dc'] += $q;
        $pid = (int) $r['product_id'];
        $rooms[$pid] ??= ['name' => $r['name'], 'active' => 0, 'sold' => 0, 'guests' => 0, 'amount' => 0];
        $rooms[$pid]['sold'] += $q;
        $rooms[$pid]['guests'] += (int) $r['guests'];
        $rooms[$pid]['amount'] += (int) $r['amount'];
    }
    $total = $blank;
    foreach ($buckets as &$b) {
        foreach ($blank as $m => $_) $total[$m] += $b[$m];
        $b += rs_ratios($b, $roomCount);
    }
    unset($b);
    $total += rs_ratios($total, $roomCount);
    $days = $total['days'];
    foreach ($rooms as &$rm) $rm['occ'] = $days ? $rm['sold'] / $days * 100 : 0;
    unset($rm);
    foreach ($weekdays as &$w) $w += rs_ratios($w, $roomCount);
    unset($w);
    return ['buckets' => $buckets, 'total' => $total, 'rooms' => $rooms, 'weekdays' => $weekdays, 'days' => $days];
}

/** 가동률(%), 평균 객실단가(ADR), 객실당 매출(RevPAR), 1실 평균 인원 */
function rs_ratios(array $b, int $roomCount): array
{
    $cap = $roomCount * max(1, $b['days']);
    return [
        'occ'   => $b['days'] ? $b['sold'] / $cap * 100 : 0,
        'adr'   => $b['sold'] ? $b['amount'] / $b['sold'] : 0,
        'revpar' => $b['days'] ? $b['amount'] / $cap : 0,
        'gpr'   => $b['sold'] ? $b['guests'] / $b['sold'] : 0,
    ];
}

$A = rs_period($from, $to, $unit, $statuses, $roomCount, $roomProducts, (bool) $typeId);
$B = $cmp ? rs_period($cfrom, $cto, $unit, $statuses, $roomCount, $roomProducts, (bool) $typeId) : null;

/** 객실 분류별 합계: 분류 id(0 = 미분류) => [name, rooms(판매 중 객실 수), sold, guests, amount, occ, adr] + 'total' */
function rs_by_type(array $P, array $allRooms, array $types): array
{
    $out = [];
    foreach ($types as $id => $t) $out[$id] = ['name' => $t['name'], 'rooms' => 0, 'sold' => 0, 'guests' => 0, 'amount' => 0];
    foreach ($allRooms as $p) {
        $tid = isset($types[(int) $p['room_type_id']]) ? (int) $p['room_type_id'] : 0;
        $out[$tid] ??= ['name' => '미분류', 'rooms' => 0, 'sold' => 0, 'guests' => 0, 'amount' => 0];
        if ($p['is_active']) $out[$tid]['rooms']++;
    }
    foreach ($P['rooms'] as $pid => $r) {
        $tid = (int) (products_all()[$pid]['room_type_id'] ?? 0);
        if (!isset($types[$tid])) $tid = 0;
        $out[$tid] ??= ['name' => '미분류', 'rooms' => 0, 'sold' => 0, 'guests' => 0, 'amount' => 0];
        foreach (['sold', 'guests', 'amount'] as $m) $out[$tid][$m] += $r[$m];
    }
    $out = array_filter($out, fn($r) => $r['rooms'] || $r['sold']);
    $total = ['name' => '합계', 'rooms' => 0, 'sold' => 0, 'guests' => 0, 'amount' => 0];
    foreach ($out as $r) foreach (['rooms', 'sold', 'guests', 'amount'] as $m) $total[$m] += $r[$m];
    $out['total'] = $total;
    foreach ($out as &$r) {
        $r['occ'] = $r['rooms'] && $P['days'] ? $r['sold'] / ($r['rooms'] * $P['days']) * 100 : 0;
        $r['adr'] = $r['sold'] ? $r['amount'] / $r['sold'] : 0;
        $r['gpr'] = $r['sold'] ? $r['guests'] / $r['sold'] : 0;
    }
    unset($r);
    return $out;
}
$typeRooms = $typeId ? $roomProducts : $allRooms;
$TA = rs_by_type($A, $typeRooms, $typeId ? [$typeId => $types[$typeId]] : $types);
$TB = $B ? rs_by_type($B, $typeRooms, $typeId ? [$typeId => $types[$typeId]] : $types) : null;

$pct = fn(float $v) => number_format($v, 1) . '%';
$diffTxt = function (float $a, float $b, bool $isPct = false): string {
    $d = $a - $b;
    $s = ($d > 0 ? '+' : ($d < 0 ? '−' : '')) . ($isPct ? number_format(abs($d), 1) . '%p' : number_format(abs($d)));
    if (!$isPct && $b) $s .= ' (' . ($d >= 0 ? '+' : '−') . number_format(abs($d) / $b * 100, 1) . '%)';
    return $s;
};
// 요약 지표: 키 => [이름, 형식]
$metrics = [
    'sold'   => ['판매 객실 (실·박)', 'n'],
    'occ'    => ['객실 가동률', 'p'],
    'guests' => ['입실 인원 (명)', 'n'],
    'gpr'    => ['1실 평균 인원 (명)', 'f'],
    'amount' => ['객실 매출 (원)', 'n'],
    'adr'    => ['평균 객실단가 (원)', 'n'],
    'revpar' => ['객실당 1일 매출 RevPAR (원)', 'n'],
    'weekday' => ['비수기 평일 판매', 'n'],
    'weekend' => ['비수기 주말 판매', 'n'],
    'peak'   => ['성수기 판매', 'n'],
    'dc'     => ['할인 판매', 'n'],
    'days'   => ['일수', 'n'],
];
$fmtM = fn($v, string $f) => match ($f) { 'p' => number_format($v, 1) . '%', 'f' => number_format($v, 2), default => number_format(round($v)) };
$periodLabel = fn(string $f, string $t) => date('Y.n.j', strtotime($f)) . ' ~ ' . date('Y.n.j', strtotime($t));
$title = '객실이용통계 ' . ($typeId ? $types[$typeId]['name'] . ' ' : '') . RS_UNITS[$unit] . ' (' . $periodLabel($from, $to) . ')';

/* ───────────── 엑셀 ───────────── */
if (($_GET['export'] ?? '') === 'xlsx') {
    $sub = "$statusLabel 집계 · 객실 {$roomCount}실 기준 · 출력 " . date('Y-m-d H:i') . ' · ' . $user['name'];
    $bucketSheet = fn(array $P, string $name, string $pl) => [
        'name' => $name, 'title' => "객실이용통계 $name ($pl)", 'subtitle' => $sub,
        'header' => ['기간', '일수', '판매 객실', '가동률(%)', '입실 인원', '1실 평균 인원', '객실 매출', '평균 객실단가', 'RevPAR', '비수기 평일', '비수기 주말', '성수기', '할인'],
        'rows' => array_map(fn($b) => [$b['label'], $b['days'], $b['sold'], round($b['occ'], 1), $b['guests'], round($b['gpr'], 2), $b['amount'], (int) round($b['adr']), (int) round($b['revpar']), $b['weekday'], $b['weekend'], $b['peak'], $b['dc']], array_values($P['buckets'])),
        'footer' => [['합계', $P['total']['days'], $P['total']['sold'], round($P['total']['occ'], 1), $P['total']['guests'], round($P['total']['gpr'], 2), $P['total']['amount'], (int) round($P['total']['adr']), (int) round($P['total']['revpar']), $P['total']['weekday'], $P['total']['weekend'], $P['total']['peak'], $P['total']['dc']]],
        'widths' => [16, 7, 10, 10, 10, 11, 13, 12, 11, 10, 10, 9, 8],
    ];
    $sheets = [$bucketSheet($A, '기간A ' . RS_UNITS[$unit], $periodLabel($from, $to))];
    if ($B) {
        $sheets[] = $bucketSheet($B, '기간B ' . RS_UNITS[$unit], $periodLabel($cfrom, $cto));
        $sheets[] = ['name' => '기간 비교', 'title' => '객실이용통계 기간 비교', 'subtitle' => 'A ' . $periodLabel($from, $to) . ' / B ' . $periodLabel($cfrom, $cto) . ' · ' . $sub,
            'header' => ['지표', '기간 A', '기간 B', '증감'],
            'rows' => array_map(fn($k) => [$metrics[$k][0], round($A['total'][$k], 2), round($B['total'][$k], 2), $diffTxt($A['total'][$k], $B['total'][$k], $metrics[$k][1] === 'p')], array_keys($metrics)),
            'widths' => [26, 14, 14, 20]];
    }
    $sheets[] = ['name' => '분류별', 'title' => '객실 분류별 (' . $periodLabel($from, $to) . ')', 'subtitle' => $sub,
        'header' => ['분류', '객실 수', '판매(박)', '가동률(%)', '입실 인원', '1실 평균 인원', '매출', '평균 객실단가', ...($TB ? ['비교 판매', '비교 가동률(%)', '비교 매출'] : [])],
        'rows' => array_map(fn($k, $r) => [$r['name'], $r['rooms'], $r['sold'], round($r['occ'], 1), $r['guests'], round($r['gpr'], 2), $r['amount'], (int) round($r['adr']),
            ...($TB ? [$TB[$k]['sold'] ?? 0, round($TB[$k]['occ'] ?? 0, 1), $TB[$k]['amount'] ?? 0] : [])], array_keys($TA), $TA),
        'widths' => [14, 8, 9, 10, 10, 11, 13, 12, 10, 12, 13]];
    $sheets[] = ['name' => '객실별', 'title' => '객실별 이용 (' . $periodLabel($from, $to) . ')', 'subtitle' => $sub,
        'header' => ['객실', '판매(박)', '가동률(%)', '입실 인원', '매출', ...($B ? ['비교 판매', '비교 가동률(%)', '비교 매출'] : [])],
        'rows' => array_map(fn($id, $r) => [$r['name'] . ($r['active'] ? '' : ' (판매중지)'), $r['sold'], round($r['occ'], 1), $r['guests'], $r['amount'],
            ...($B ? [$B['rooms'][$id]['sold'] ?? 0, round($B['rooms'][$id]['occ'] ?? 0, 1), $B['rooms'][$id]['amount'] ?? 0] : [])], array_keys($A['rooms']), $A['rooms']),
        'widths' => [24, 10, 10, 10, 13, 10, 12, 13]];
    $wd = ['일', '월', '화', '수', '목', '금', '토'];
    $sheets[] = ['name' => '요일별', 'title' => '요일별 가동률', 'subtitle' => $sub,
        'header' => ['요일', '일수', '판매', '가동률(%)', '입실 인원', '매출', ...($B ? ['비교 판매', '비교 가동률(%)'] : [])],
        'rows' => array_map(fn($i) => [$wd[$i], $A['weekdays'][$i]['days'], $A['weekdays'][$i]['sold'], round($A['weekdays'][$i]['occ'], 1), $A['weekdays'][$i]['guests'], $A['weekdays'][$i]['amount'],
            ...($B ? [$B['weekdays'][$i]['sold'], round($B['weekdays'][$i]['occ'], 1)] : [])], [1, 2, 3, 4, 5, 6, 0]),
        'widths' => [8, 8, 8, 10, 10, 13, 10, 12]];
    xlsx_send('객실이용통계_' . RS_UNITS[$unit] . "_{$from}_{$to}.xlsx", $sheets);
}

$q = fn(array $o) => 'room_stats.php?' . http_build_query(array_filter(array_merge(
    ['unit' => $unit, 'from' => $from, 'to' => $to, 'type' => $typeId ?: null, 'cmp' => $cmp, 'cfrom' => $cmp === 'custom' ? $cfrom : null, 'cto' => $cmp === 'custom' ? $cto : null, 'approved' => $approvedOnly ? 1 : null], $o),
    fn($v) => $v !== null && $v !== ''));
$occBar = fn(float $v) => '<span class="occ-bar"><i style="width:' . min(100, round($v, 1)) . '%"></i></span>';

layout_header('객실이용통계', 'room_stats');
?>
<section class="card no-print">
  <div class="card-head">
    <h1>객실이용통계 <small class="muted"><?= $typeId ? e($types[$typeId]['name']) . ' ' : '' ?>객실 <?= $roomCount ?>실 기준 · 대관 숙박시설 제외</small></h1>
    <div class="actions no-margin">
      <a class="btn" href="<?= e(url($q(['export' => 'xlsx']))) ?>">엑셀 다운로드</a>
      <button class="btn" type="button" onclick="window.print()">인쇄 · PDF</button>
    </div>
  </div>
  <form method="get" class="rs-form">
    <div class="stat-units">
      <?php foreach (RS_UNITS as $u => $label): ?>
        <a href="<?= e(url('room_stats.php?' . http_build_query(array_filter(['unit' => $u, 'type' => $typeId ?: null, 'cmp' => $cmp === 'custom' ? 'lastyear' : $cmp, 'approved' => $approvedOnly ? 1 : null])))) ?>" class="<?= $unit === $u ? 'on' : '' ?>"><?= $label ?></a>
      <?php endforeach ?>
    </div>
    <input type="hidden" name="unit" value="<?= e($unit) ?>">
    <label class="rs-type">객실 분류
      <select name="type">
        <option value="">전체 객실</option>
        <?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $typeId === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach ?>
      </select>
    </label>
    <div class="rs-periods">
      <fieldset><legend>기간 A</legend>
        <input type="date" name="from" value="<?= e($from) ?>"> ~ <input type="date" name="to" value="<?= e($to) ?>">
      </fieldset>
      <fieldset><legend>비교 기간 B</legend>
        <select name="cmp" onchange="this.form.querySelector('[data-custom]').hidden = this.value !== 'custom'">
          <option value="">비교 안 함</option>
          <option value="prev" <?= $cmp === 'prev' ? 'selected' : '' ?>>직전 기간 (같은 길이)</option>
          <option value="lastyear" <?= $cmp === 'lastyear' ? 'selected' : '' ?>>작년 같은 기간</option>
          <option value="custom" <?= $cmp === 'custom' ? 'selected' : '' ?>>직접 입력</option>
        </select>
        <span data-custom <?= $cmp === 'custom' ? '' : 'hidden' ?>><input type="date" name="cfrom" value="<?= e($cfrom ?? date('Y-m-d', strtotime("$from -1 year"))) ?>"> ~ <input type="date" name="cto" value="<?= e($cto ?? date('Y-m-d', strtotime("$to -1 year"))) ?>"></span>
      </fieldset>
    </div>
    <label class="inline-check"><input type="checkbox" name="approved" value="1" <?= $approvedOnly ? 'checked' : '' ?>> 결재완료만</label>
    <button class="btn primary">조회</button>
  </form>
</section>

<section class="card">
  <h2><?= e($title) ?> <small class="muted"><?= e($statusLabel) ?></small></h2>
  <?php $T = $A['total']; ?>
  <div class="kpis rs-kpis">
    <div class="kpi"><span>객실 가동률</span><b><?= $pct($T['occ']) ?></b><?= $occBar($T['occ']) ?>
      <?php if ($B): ?><small class="rs-diff <?= $T['occ'] >= $B['total']['occ'] ? 'up' : 'down' ?>">B 대비 <?= e($diffTxt($T['occ'], $B['total']['occ'], true)) ?></small><?php endif ?></div>
    <div class="kpi"><span>판매 객실</span><b><?= number_format($T['sold']) ?>실·박</b><small class="muted"><?= $roomCount ?>실 × <?= $A['days'] ?>일 중</small>
      <?php if ($B): ?><small class="rs-diff <?= $T['sold'] >= $B['total']['sold'] ? 'up' : 'down' ?>">B 대비 <?= e($diffTxt($T['sold'], $B['total']['sold'])) ?></small><?php endif ?></div>
    <div class="kpi"><span>입실 인원</span><b><?= number_format($T['guests']) ?>명</b><small class="muted">1실 평균 <?= number_format($T['gpr'], 2) ?>명</small>
      <?php if ($B): ?><small class="rs-diff <?= $T['guests'] >= $B['total']['guests'] ? 'up' : 'down' ?>">B 대비 <?= e($diffTxt($T['guests'], $B['total']['guests'])) ?></small><?php endif ?></div>
    <div class="kpi total"><span>객실 매출</span><b><?= e(won($T['amount'])) ?></b><small class="muted">평균 객실단가 <?= e(won(round($T['adr']))) ?> · RevPAR <?= e(won(round($T['revpar']))) ?></small>
      <?php if ($B): ?><small class="rs-diff <?= $T['amount'] >= $B['total']['amount'] ? 'up' : 'down' ?>">B 대비 <?= e($diffTxt($T['amount'], $B['total']['amount'])) ?></small><?php endif ?></div>
  </div>

  <?php if ($B): ?>
  <h3>기간 비교 <small class="muted">A <?= e($periodLabel($from, $to)) ?> · B <?= e($periodLabel($cfrom, $cto)) ?></small></h3>
  <div class="table-scroll">
  <table class="table rs-compare">
    <thead><tr><th>지표</th><th class="right">기간 A</th><th class="right">기간 B</th><th class="right">증감 (A − B)</th></tr></thead>
    <tbody>
    <?php foreach ($metrics as $k => [$label, $f]): $a = $A['total'][$k]; $b = $B['total'][$k]; ?>
      <tr><td><?= e($label) ?></td><td class="right"><b><?= $fmtM($a, $f) ?></b></td><td class="right"><?= $fmtM($b, $f) ?></td>
        <td class="right rs-diff <?= $a > $b ? 'up' : ($a < $b ? 'down' : '') ?>"><?= e($diffTxt($a, $b, $f === 'p')) ?></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
  <?php endif ?>

  <h3>객실 분류별 <small class="muted"><?= $typeId ? '' : '분류마다와 전체 합계' ?></small></h3>
  <div class="table-scroll">
  <table class="table rs-types">
    <thead><tr><th>분류</th><th class="right">객실 수</th><th class="right">판매(박)</th><th>가동률</th><th class="right">입실 인원</th><th class="right">1실 평균</th><th class="right">매출</th><th class="right">평균 객실단가</th>
      <?php if ($TB): ?><th class="right rs-b">B 판매</th><th class="right rs-b">B 가동률</th><th class="right rs-b">B 매출</th><th class="right">가동률 증감</th><th class="right">매출 증감</th><?php endif ?></tr></thead>
    <tbody>
    <?php foreach ($TA as $k => $r): $isTotal = $k === 'total'; $rb = $TB[$k] ?? null;
        if ($isTotal): ?></tbody><tfoot><?php endif ?>
      <tr>
        <<?= $isTotal ? 'th' : 'td' ?>><?php if (!$isTotal && $k && !$typeId): ?><a href="<?= e(url($q(['type' => $k]))) ?>"><?= e($r['name']) ?></a><?php else: ?><?= e($r['name']) ?><?php endif ?></<?= $isTotal ? 'th' : 'td' ?>>
        <td class="right"><?= $r['rooms'] ?>실</td><td class="right"><?= number_format($r['sold']) ?></td>
        <td class="nowrap"><?= $occBar($r['occ']) ?> <?= $pct($r['occ']) ?></td><td class="right"><?= number_format($r['guests']) ?></td>
        <td class="right"><?= number_format($r['gpr'], 2) ?></td><td class="right"><?= number_format($r['amount']) ?></td><td class="right"><?= number_format(round($r['adr'])) ?></td>
        <?php if ($TB): ?>
          <td class="right rs-b"><?= number_format($rb['sold'] ?? 0) ?></td><td class="right rs-b"><?= $pct($rb['occ'] ?? 0) ?></td><td class="right rs-b"><?= number_format($rb['amount'] ?? 0) ?></td>
          <td class="right rs-diff <?= $r['occ'] > ($rb['occ'] ?? 0) ? 'up' : ($r['occ'] < ($rb['occ'] ?? 0) ? 'down' : '') ?>"><?= e($diffTxt($r['occ'], $rb['occ'] ?? 0, true)) ?></td>
          <td class="right rs-diff <?= $r['amount'] > ($rb['amount'] ?? 0) ? 'up' : ($r['amount'] < ($rb['amount'] ?? 0) ? 'down' : '') ?>"><?= e($diffTxt($r['amount'], $rb['amount'] ?? 0)) ?></td>
        <?php endif ?>
      </tr>
      <?php if ($isTotal): ?></tfoot><?php endif ?>
    <?php endforeach ?>
  </table>
  </div>

  <h3><?= e(RS_UNITS[$unit]) ?> 추이<?= $B ? ' <small class="muted">(A와 B를 같은 순서의 구간끼리 나란히 비교)</small>' : '' ?></h3>
  <div class="table-scroll">
  <table class="table stats-table rs-table">
    <thead>
      <?php if ($B): ?>
      <tr><th colspan="5" class="center">기간 A</th><th colspan="4" class="center rs-b">기간 B</th><th colspan="2" class="center">증감</th></tr>
      <tr><th>구간</th><th class="right">판매</th><th>가동률</th><th class="right">인원</th><th class="right">매출</th>
        <th class="rs-b">구간</th><th class="right rs-b">판매</th><th class="right rs-b">가동률</th><th class="right rs-b">매출</th><th class="right">가동률</th><th class="right">매출</th></tr>
      <?php else: ?>
      <tr><th>구간</th><th class="right">일수</th><th class="right">판매</th><th>가동률</th><th class="right">입실 인원</th><th class="right">1실 평균</th><th class="right">객실 매출</th><th class="right">평균 객실단가</th><th class="right">RevPAR</th>
        <th class="right">비수기 평일</th><th class="right">비수기 주말</th><th class="right">성수기</th><th class="right">할인</th></tr>
      <?php endif ?>
    </thead>
    <tbody>
    <?php if ($B):
        $ak = array_values($A['buckets']); $bk = array_values($B['buckets']);
        for ($i = 0; $i < max(count($ak), count($bk)); $i++): $a = $ak[$i] ?? null; $b = $bk[$i] ?? null; ?>
      <tr class="<?= ($a['sold'] ?? 0) || ($b['sold'] ?? 0) ? '' : 'zero' ?>">
        <td class="nowrap"><?= e($a['label'] ?? '') ?></td><td class="right"><?= $a ? number_format($a['sold']) : '' ?></td>
        <td class="nowrap"><?= $a ? $occBar($a['occ']) . ' ' . $pct($a['occ']) : '' ?></td><td class="right"><?= $a ? number_format($a['guests']) : '' ?></td><td class="right"><?= $a ? number_format($a['amount']) : '' ?></td>
        <td class="nowrap rs-b"><?= e($b['label'] ?? '') ?></td><td class="right rs-b"><?= $b ? number_format($b['sold']) : '' ?></td>
        <td class="right rs-b"><?= $b ? $pct($b['occ']) : '' ?></td><td class="right rs-b"><?= $b ? number_format($b['amount']) : '' ?></td>
        <td class="right rs-diff <?= $a && $b ? ($a['occ'] > $b['occ'] ? 'up' : ($a['occ'] < $b['occ'] ? 'down' : '')) : '' ?>"><?= $a && $b ? e($diffTxt($a['occ'], $b['occ'], true)) : '' ?></td>
        <td class="right rs-diff <?= $a && $b ? ($a['amount'] > $b['amount'] ? 'up' : ($a['amount'] < $b['amount'] ? 'down' : '')) : '' ?>"><?= $a && $b ? e($diffTxt($a['amount'], $b['amount'])) : '' ?></td>
      </tr>
    <?php endfor; else: foreach ($A['buckets'] as $b): ?>
      <tr class="<?= $b['sold'] ? '' : 'zero' ?>">
        <td class="nowrap"><?= e($b['label']) ?></td><td class="right"><?= $b['days'] ?></td><td class="right"><?= number_format($b['sold']) ?></td>
        <td class="nowrap"><?= $occBar($b['occ']) ?> <?= $pct($b['occ']) ?></td><td class="right"><?= number_format($b['guests']) ?></td><td class="right"><?= number_format($b['gpr'], 2) ?></td>
        <td class="right"><?= number_format($b['amount']) ?></td><td class="right"><?= number_format(round($b['adr'])) ?></td><td class="right"><?= number_format(round($b['revpar'])) ?></td>
        <td class="right"><?= $b['weekday'] ?: '-' ?></td><td class="right"><?= $b['weekend'] ?: '-' ?></td><td class="right"><?= $b['peak'] ?: '-' ?></td><td class="right"><?= $b['dc'] ?: '-' ?></td>
      </tr>
    <?php endforeach; endif ?>
    </tbody>
    <tfoot>
      <?php if ($B): ?>
      <tr><th>합계 A</th><th class="right"><?= number_format($T['sold']) ?></th><th><?= $pct($T['occ']) ?></th><th class="right"><?= number_format($T['guests']) ?></th><th class="right"><?= number_format($T['amount']) ?></th>
        <th class="rs-b">합계 B</th><th class="right rs-b"><?= number_format($B['total']['sold']) ?></th><th class="right rs-b"><?= $pct($B['total']['occ']) ?></th><th class="right rs-b"><?= number_format($B['total']['amount']) ?></th>
        <th class="right"><?= e($diffTxt($T['occ'], $B['total']['occ'], true)) ?></th><th class="right"><?= e($diffTxt($T['amount'], $B['total']['amount'])) ?></th></tr>
      <?php else: ?>
      <tr><th>합계</th><th class="right"><?= $T['days'] ?></th><th class="right"><?= number_format($T['sold']) ?></th><th><?= $pct($T['occ']) ?></th><th class="right"><?= number_format($T['guests']) ?></th>
        <th class="right"><?= number_format($T['gpr'], 2) ?></th><th class="right"><?= number_format($T['amount']) ?></th><th class="right"><?= number_format(round($T['adr'])) ?></th><th class="right"><?= number_format(round($T['revpar'])) ?></th>
        <th class="right"><?= $T['weekday'] ?></th><th class="right"><?= $T['weekend'] ?></th><th class="right"><?= $T['peak'] ?></th><th class="right"><?= $T['dc'] ?></th></tr>
      <?php endif ?>
    </tfoot>
  </table>
  </div>
</section>

<div class="grid2">
  <section class="card">
    <h2>객실별 가동률</h2>
    <div class="table-scroll">
    <table class="table">
      <thead><tr><th>객실</th><th class="right">판매(박)</th><th>가동률</th><th class="right">인원</th><th class="right">매출</th><?php if ($B): ?><th class="right">B 가동률</th><th class="right">증감</th><?php endif ?></tr></thead>
      <tbody>
      <?php $rooms = $A['rooms']; uasort($rooms, fn($x, $y) => $y['sold'] <=> $x['sold']);
      foreach ($rooms as $id => $r): $rb = $B['rooms'][$id] ?? null; ?>
        <tr class="<?= $r['active'] ? '' : 'zero' ?>"><td class="rs-room"><?= e($r['name']) ?><?= $r['active'] ? '' : ' <small class="muted">(판매중지)</small>' ?></td>
          <td class="right"><?= number_format($r['sold']) ?></td><td class="nowrap"><?= $occBar($r['occ']) ?> <?= $pct($r['occ']) ?></td>
          <td class="right"><?= number_format($r['guests']) ?></td><td class="right"><?= number_format($r['amount']) ?></td>
          <?php if ($B): ?><td class="right"><?= $pct($rb['occ'] ?? 0) ?></td><td class="right rs-diff <?= $r['occ'] > ($rb['occ'] ?? 0) ? 'up' : ($r['occ'] < ($rb['occ'] ?? 0) ? 'down' : '') ?>"><?= e($diffTxt($r['occ'], $rb['occ'] ?? 0, true)) ?></td><?php endif ?></tr>
      <?php endforeach ?>
      <?php if (!$rooms): ?><tr><td colspan="7" class="center muted">등록된 객실이 없습니다.</td></tr><?php endif ?>
      </tbody>
    </table>
    </div>
  </section>
  <section class="card">
    <h2>요일별 가동률</h2>
    <table class="table">
      <thead><tr><th>요일</th><th class="right">일수</th><th class="right">판매</th><th>가동률</th><th class="right">인원</th><?php if ($B): ?><th class="right">B 가동률</th><?php endif ?></tr></thead>
      <tbody>
      <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $i): $w = $A['weekdays'][$i]; ?>
        <tr><td class="<?= $i === 0 ? 'sun-text' : ($i === 6 ? 'sat-text' : '') ?>"><?= ['일', '월', '화', '수', '목', '금', '토'][$i] ?></td>
          <td class="right"><?= $w['days'] ?></td><td class="right"><?= number_format($w['sold']) ?></td>
          <td class="nowrap"><?= $occBar($w['occ']) ?> <?= $pct($w['occ']) ?></td><td class="right"><?= number_format($w['guests']) ?></td>
          <?php if ($B): ?><td class="right"><?= $pct($B['weekdays'][$i]['occ']) ?></td><?php endif ?></tr>
      <?php endforeach ?>
      </tbody>
    </table>
  </section>
</div>
<p class="muted small">가동률 = 판매 객실(실·박) ÷ (객실 수 <?= $roomCount ?>실 × 일수). 객실 수는 상품관리에서 '판매' 중인 객실 기준입니다.
  평균 객실단가 = 객실 매출 ÷ 판매 객실, RevPAR(객실당 1일 매출) = 객실 매출 ÷ (객실 수 × 일수). 임시저장·반려된 매출보고는 집계하지 않고, 대관 숙박시설은 제외합니다.</p>
<?php layout_footer();
