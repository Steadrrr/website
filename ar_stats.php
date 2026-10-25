<?php
/**
 * 객실관리 › AR사용현황 (공무직 이상) — AR사용관리에서 입력한 사용보고 내역을 조회
 *   ar_stats.php?ym=2026-10                월간: 날짜별 사용 내역 표 + 아르바이트별 합계
 *   ar_stats.php?unit=cal&ym=2026-10       달력: 날짜마다 사용한 아르바이트 이름
 *   ar_stats.php?unit=year&y=2026          연간: 월별 계획·사용횟수·시간 + 아르바이트별 합계
 *   &export=xlsx                           지금 보는 기간을 엑셀로
 * 사용횟수는 AR사용관리와 같이 결재중·결재완료 사용보고의 줄 수 (1명 하루 = 1회, 임시저장·반려 제외).
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/xlsx.php';

$user = require_login();
require_menu($user, 'room');
if (!can_ar($user)) abort(403, 'AR사용현황은 공무직 이상만 사용할 수 있습니다.');

$unit = in_array($_GET['unit'] ?? '', ['year', 'cal'], true) ? $_GET['unit'] : 'month';
$ym = preg_match('/^\d{4}-\d{2}$/', $_GET['ym'] ?? '') && valid_date(($_GET['ym'] ?? '') . '-01') ? $_GET['ym'] : date('Y-m');
$y = preg_match('/^\d{4}$/', $_GET['y'] ?? '') && (int) $_GET['y'] >= 2000 && (int) $_GET['y'] <= 2100 ? (int) $_GET['y'] : (int) substr($ym, 0, 4);
if ($unit === 'year') {
    $from = "$y-01-01";
    $to = "$y-12-31";
} else {
    [$from, $to] = ar_month_range("$ym-01");
}

$st = db()->prepare("SELECT w.*, j.status, j.id AS jid FROM ar_workers w JOIN journals j ON j.id = w.journal_id
                      WHERE j.type = 'arwork' AND j.status IN ('pending', 'approved') AND w.work_date BETWEEN ? AND ?
                      ORDER BY w.work_date, w.start_time, w.sort_no, w.id");
$st->execute([$from, $to]);
$rows = $st->fetchAll();
$plans = ar_plans($from, $to);

// 합계: 아르바이트별, 월별(연간), 날짜별(달력)
$byName = $byMonth = $byDay = [];
foreach ($rows as $w) {
    $n = &$byName[$w['name']];
    $n ??= ['days' => 0, 'minutes' => 0, 'last' => ''];
    $n['days']++;
    $n['minutes'] += (int) $w['minutes'];
    $n['last'] = $w['work_date'];
    unset($n);
    $m = &$byMonth[(int) substr($w['work_date'], 5, 2)];
    $m ??= ['used' => 0, 'approved' => 0, 'minutes' => 0, 'days' => [], 'names' => []];
    $m['used']++;
    if ($w['status'] === 'approved') $m['approved']++;
    $m['minutes'] += (int) $w['minutes'];
    $m['days'][$w['work_date']] = true;
    $m['names'][$w['name']] = true;
    unset($m);
    $byDay[$w['work_date']][] = $w;
}
uksort($byName, fn($a, $b) => [$byName[$b]['days'], $a] <=> [$byName[$a]['days'], $b]);
$planByMonth = [];
foreach ($plans as $d => $p) $planByMonth[(int) substr($d, 5, 2)] = ($planByMonth[(int) substr($d, 5, 2)] ?? 0) + (int) $p['people'];
$used = count($rows);
$approved = count(array_filter($rows, fn($w) => $w['status'] === 'approved'));
$minutes = array_sum(array_column($rows, 'minutes'));
$planned = array_sum($planByMonth);
$title = ($unit === 'year' ? "{$y}년" : date('Y년 n월', strtotime($from))) . ' AR 사용현황';
$hm = fn($t) => substr((string) $t, 0, 5);
$hours = fn(int $min) => round($min / 60, 1); // 엑셀은 시간(소수)으로

if (($_GET['export'] ?? '') === 'xlsx') {
    $sub = '출력 ' . date('Y-m-d H:i') . ' · ' . $user['name'] . ' · 결재중·결재완료 사용보고 (1명 하루 = 1회)';
    $detail = ['name' => '사용내역', 'title' => $title . ' · 사용내역', 'subtitle' => $sub,
        'header' => ['사용일', '요일', '성명', '시작', '종료', '휴게(분)', '근무시간', '업무내용', '결재'],
        'rows' => array_map(fn($w) => [$w['work_date'], weekday_ko($w['work_date']), $w['name'], $hm($w['start_time']), $hm($w['end_time']),
            (int) $w['break_min'], $hours((int) $w['minutes']), (string) $w['task'], JOURNAL_STATUS[$w['status']] ?? $w['status']], $rows),
        'footer' => [['합계', '', "{$used}회", '', '', '', $hours($minutes), '', '']], 'widths' => [12, 6, 12, 8, 8, 9, 10, 40, 10]];
    $names = ['name' => '아르바이트별', 'title' => $title . ' · 아르바이트별', 'subtitle' => $sub,
        'header' => ['성명', '사용일수(회)', '근무시간', '마지막 사용일'],
        'rows' => array_map(fn($k, $v) => [$k, $v['days'], $hours($v['minutes']), $v['last']], array_keys($byName), $byName),
        'footer' => [['합계', $used, $hours($minutes), '']], 'widths' => [14, 12, 10, 14]];
    if ($unit === 'year') {
        $quota = ar_quota($y);
        $monthly = ['name' => '월별', 'title' => $title . ' · 월별', 'subtitle' => $sub . ($quota ? " · 사용가능 {$quota}회 · 잔여 " . ($quota - $used) . '회' : ''),
            'header' => ['월', '계획(회)', '사용(회)', '결재완료(회)', '사용일수', '아르바이트(명)', '근무시간'],
            'rows' => array_map(fn($m) => ["{$m}월", $planByMonth[$m] ?? 0, $byMonth[$m]['used'] ?? 0, $byMonth[$m]['approved'] ?? 0,
                count($byMonth[$m]['days'] ?? []), count($byMonth[$m]['names'] ?? []), $hours($byMonth[$m]['minutes'] ?? 0)], range(1, 12)),
            'footer' => [['합계', $planned, $used, $approved, count($byDay), count($byName), $hours($minutes)]], 'widths' => [8, 10, 10, 12, 10, 14, 10]];
        $sheets = [$monthly, $names, $detail];
    } else {
        $sheets = [$detail, $names];
    }
    xlsx_send('AR사용현황_' . ($unit === 'year' ? $y : $ym) . '.xlsx', $sheets);
}

layout_header('AR사용현황', 'ar_stats');
$q = fn(array $p) => url('ar_stats.php?' . http_build_query($p));
$prev = date('Y-m', strtotime("$from -1 month"));
$next = date('Y-m', strtotime("$from +1 month"));
$monthYm = $unit === 'year' ? ($y === (int) date('Y') ? date('Y-m') : "$y-01") : $ym;
$here = $unit === 'year' ? ['unit' => 'year', 'y' => $y] : ($unit === 'cal' ? ['unit' => 'cal', 'ym' => $ym] : ['ym' => $ym]);
?>
<section class="card">
  <div class="card-head">
    <h1>AR사용현황 <small class="muted"><?= ['month' => '월간', 'year' => '연간', 'cal' => '달력'][$unit] ?></small></h1>
    <div class="actions no-margin no-print">
      <a class="btn ghost" href="<?= e(url('ar.php?ym=' . $monthYm)) ?>">AR사용관리 ›</a>
      <a class="btn" href="<?= e($q($here + ['export' => 'xlsx'])) ?>">엑셀 다운로드</a>
      <button class="btn" type="button" onclick="window.print()">인쇄</button>
    </div>
  </div>
  <div class="cpl-nav no-print">
    <div class="stat-units">
      <a class="<?= $unit === 'month' ? 'on' : '' ?>" href="<?= e($q(['ym' => $monthYm])) ?>">월간</a>
      <a class="<?= $unit === 'year' ? 'on' : '' ?>" href="<?= e($q(['unit' => 'year', 'y' => $y])) ?>">연간</a>
      <a class="<?= $unit === 'cal' ? 'on' : '' ?>" href="<?= e($q(['unit' => 'cal', 'ym' => $monthYm])) ?>">달력</a>
    </div>
    <?php if ($unit === 'year'): ?>
      <a class="btn small" href="<?= e($q(['unit' => 'year', 'y' => $y - 1])) ?>">‹ 이전해</a>
      <b><?= $y ?>년</b>
      <a class="btn small" href="<?= e($q(['unit' => 'year', 'y' => $y + 1])) ?>">다음해 ›</a>
    <?php else: $u = $unit === 'cal' ? ['unit' => 'cal'] : []; ?>
      <a class="btn small" href="<?= e($q($u + ['ym' => $prev])) ?>">‹ 이전달</a>
      <b><?= e(date('Y년 n월', strtotime($from))) ?></b>
      <a class="btn small" href="<?= e($q($u + ['ym' => $next])) ?>">다음달 ›</a>
      <form method="get" class="inline"><?php if ($u): ?><input type="hidden" name="unit" value="cal"><?php endif ?><input type="month" name="ym" value="<?= e($ym) ?>" onchange="this.form.submit()"></form>
    <?php endif ?>
  </div>

  <?php if ($unit === 'year') { $quota = ar_quota($y); $remain = $quota - $used; } ?>
  <div class="kpis <?= $unit === 'year' ? 'k4' : 'k3' ?>">
    <div class="kpi"><span>계획</span><b><?= number_format($planned) ?>회</b></div>
    <div class="kpi"><span>사용</span><b><?= number_format($used) ?>회</b><small class="muted">결재완료 <?= number_format($approved) ?>회</small></div>
    <div class="kpi"><span>근무시간</span><b><?= e(ar_hm($minutes)) ?></b><small class="muted"><?= count($byDay) ?>일 · <?= count($byName) ?>명</small></div>
    <?php if ($unit === 'year'): ?>
      <div class="kpi total"><span>잔여 (가능 − 사용)</span><b class="<?= $remain < 0 ? 'warn' : '' ?>"><?= $quota ? number_format($remain) . '회' : '-' ?></b>
        <small class="muted"><?= $quota ? '사용가능 ' . number_format($quota) . '회' : '사용가능횟수는 AR사용관리에서 입력' ?></small></div>
    <?php endif ?>
  </div>
  <p class="muted small">결재중·결재완료된 AR 사용보고의 내용입니다 (1명 하루 = 1회, 임시저장·반려 제외). 입력·수정은 AR사용관리에서 합니다.</p>
</section>

<?php if ($unit === 'year'): ?>
<section class="card">
  <h2>월별 <small class="muted">월을 누르면 그 달 내역</small></h2>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>월</th><th class="right">계획</th><th class="right">사용</th><th class="right">결재완료</th><th class="right">사용일수</th><th class="right">아르바이트</th><th class="right">근무시간</th></tr></thead>
    <tbody>
    <?php foreach (range(1, 12) as $m): $b = $byMonth[$m] ?? null; ?>
      <tr><td><a href="<?= e($q(['ym' => sprintf('%04d-%02d', $y, $m)])) ?>"><?= $m ?>월</a></td>
        <td class="right"><?= ($planByMonth[$m] ?? 0) ?: '' ?></td><td class="right"><b><?= $b ? $b['used'] : '' ?></b></td>
        <td class="right"><?= $b ? $b['approved'] : '' ?></td><td class="right"><?= $b ? count($b['days']) . '일' : '' ?></td>
        <td class="right"><?= $b ? count($b['names']) . '명' : '' ?></td><td class="right"><?= $b ? e(ar_hm($b['minutes'])) : '' ?></td></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th>합계</th><th class="right"><?= $planned ?></th><th class="right"><?= $used ?></th><th class="right"><?= $approved ?></th>
      <th class="right"><?= count($byDay) ?>일</th><th class="right"><?= count($byName) ?>명</th><th class="right"><?= e(ar_hm($minutes)) ?></th></tr></tfoot>
  </table>
  </div>
</section>

<?php elseif ($unit === 'cal'): $first = new DateTimeImmutable($from); $last = new DateTimeImmutable($to); ?>
<section class="card">
  <div class="calendar ar-calendar ar-stat-cal">
    <?php foreach (['일', '월', '화', '수', '목', '금', '토'] as $i => $w): ?>
      <div class="cal-h <?= $i === 0 ? 'sun' : ($i === 6 ? 'sat' : '') ?>"><?= $w ?></div>
    <?php endforeach ?>
    <?php for ($i = 0, $pad = (int) $first->format('w'); $i < $pad; $i++): ?><div class="cal-d empty"></div><?php endfor ?>
    <?php for ($d = $first; $d <= $last; $d = $d->modify('+1 day')):
        $date = $d->format('Y-m-d');
        $w = (int) $d->format('w');
        $day = $byDay[$date] ?? [];
        $pp = (int) ($plans[$date]['people'] ?? 0);
        $cls = ['cal-d'];
        if ($w === 0) $cls[] = 'sun';
        if ($w === 6) $cls[] = 'sat';
        if ($date === date('Y-m-d')) $cls[] = 'today';
    ?>
      <div class="<?= implode(' ', $cls) ?>">
        <a class="num" href="<?= e(url("ar.php?ym=$ym&date=$date")) ?>"><?= $d->format('j') ?></a>
        <?php if ($day || $pp): ?><span class="small muted"><?= $day ? '사용 ' . count($day) . '명' : '' ?><?= $pp ? ($day ? ' · ' : '') . "계획 {$pp}" : '' ?></span><?php endif ?>
        <?php foreach ($day as $x): ?><span class="ar-who" title="<?= e($hm($x['start_time']) . '~' . $hm($x['end_time']) . ($x['task'] ? ' · ' . $x['task'] : '')) ?>"><?= e($x['name']) ?></span><?php endforeach ?>
      </div>
    <?php endfor ?>
  </div>
  <p class="muted small no-print">이름에 마우스를 올리면 사용시간·업무가 보입니다. 날짜(숫자)를 누르면 AR사용관리의 그 날로 갑니다.</p>
</section>

<?php else: ?>
<section class="card">
  <h2>사용 내역 <small class="muted"><?= $used ?>회</small></h2>
  <?php if (!$rows): ?><p class="muted">이 달 사용 내역이 없습니다.</p><?php else: ?>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>사용일</th><th>성명</th><th>시간</th><th class="right">휴게</th><th class="right">근무시간</th><th>업무내용</th><th>결재</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $w): ?>
      <tr><td class="nowrap"><?= e(date('n/j', strtotime($w['work_date']))) ?> (<?= weekday_ko($w['work_date']) ?>)</td><td class="nowrap"><b><?= e($w['name']) ?></b></td>
        <td class="nowrap"><?= e($hm($w['start_time'])) ?>~<?= e($hm($w['end_time'])) ?></td><td class="right nowrap"><?= (int) $w['break_min'] ? (int) $w['break_min'] . '분' : '' ?></td>
        <td class="right nowrap"><?= e(ar_hm((int) $w['minutes'])) ?></td><td><?= e((string) $w['task']) ?></td>
        <td class="nowrap"><a class="chip st-<?= e($w['status']) ?>" href="<?= e(url('view.php?id=' . (int) $w['jid'])) ?>"><?= e(JOURNAL_STATUS[$w['status']] ?? '') ?></a></td></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th colspan="4">합계 <?= $used ?>회</th><th class="right"><?= e(ar_hm($minutes)) ?></th><th colspan="2"></th></tr></tfoot>
  </table>
  </div>
  <?php endif ?>
</section>
<?php endif ?>

<?php if ($unit !== 'cal' && $byName): ?>
<section class="card">
  <h2>아르바이트별 <small class="muted"><?= count($byName) ?>명</small></h2>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>성명</th><th class="right">사용일수</th><th class="right">근무시간</th><th>마지막 사용일</th></tr></thead>
    <tbody>
    <?php foreach ($byName as $name => $v): ?>
      <tr><td><b><?= e($name) ?></b></td><td class="right"><?= $v['days'] ?>회</td><td class="right"><?= e(ar_hm($v['minutes'])) ?></td><td><?= e(date('n/j', strtotime($v['last']))) ?></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif ?>
<?php layout_footer();
