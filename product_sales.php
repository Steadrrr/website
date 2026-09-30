<?php
/**
 * 통계 › 상품판매현황: 상품(또는 분류·프로그램 분야)을 골라 기간의 일별 판매 내역
 *   product_sales.php?item=p:12|g:ticket|g:room|g:rental|g:lodge|g:program|pt:healing&from=&to=[&page=2][&approved=1][&export=xlsx]
 * 화면은 30일씩 쪽을 나누고, 엑셀은 기간 전체를 한 시트에 쭉 내려받는다. 판매가 없는 날도 한 줄씩 나온다.
 * 매출보고(입장권·시설대관·대관 숙박시설·프로그램 판매)와 일일객실판매(객실)의 판매 줄을 날짜별로 더한다.
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/xlsx.php';

$user = require_login();
require_menu($user, 'stat');
$pdo = db();

const PS_PER_PAGE = 30;
const PS_GROUPS = ['ticket' => '입장권', 'room' => '객실', 'rental' => '시설대관', 'lodge' => '대관 숙박시설', 'program' => '프로그램'];

/** 고를 수 있는 항목: 분류 전체 / 상품 하나 / 프로그램 분야 [분류 => [값 => 이름]] */
function ps_items(): array
{
    $out = [];
    foreach (PS_GROUPS as $g => $label) {
        $out[$label]["g:$g"] = "$label 전체";
        if ($g === 'program') {
            foreach (PROGRAM_TYPES as $pt => $pl) $out[$label]["pt:$pt"] = "프로그램 · $pl";
            continue;
        }
        foreach (products_all() as $p) {
            if ($p['grp'] !== $g) continue;
            $out[$label]['p:' . $p['id']] = $p['name'] . ($p['grp'] === 'ticket' && $p['is_free'] ? ' (무료)' : '') . ($p['is_active'] ? '' : ' (판매중지)');
        }
    }
    return $out;
}

$items = ps_items();
$flat = array_merge(...array_values($items));
$item = isset($flat[$_GET['item'] ?? '']) ? $_GET['item'] : 'g:ticket';
[$kind, $key] = explode(':', $item, 2);
$grp = match ($kind) { 'p' => products_all()[(int) $key]['grp'] ?? 'ticket', 'pt' => 'program', default => $key };
$itemLabel = $flat[$item];

$from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = valid_date($_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
if ($to < $from) [$from, $to] = [$to, $from];
if ((strtotime($to) - strtotime($from)) / 86400 > 366 * 3) $from = date('Y-m-d', strtotime("$to -" . (366 * 3) . ' days'));
$approvedOnly = !empty($_GET['approved']);
$statuses = $approvedOnly ? ['approved'] : config('chart_statuses', ['pending', 'approved']);
$statusLabel = $approvedOnly ? '결재완료' : '결재중·결재완료';

// 날짜별 합계
[$cond, $condArgs] = match ($kind) {
    'p'  => ['l.product_id = ?', [(int) $key]],
    'pt' => ["l.grp = 'program' AND l.prog_type = ?", [$key]],
    default => ['l.grp = ?', [$key]],
};
$st = $pdo->prepare("SELECT j.work_date AS d, GROUP_CONCAT(DISTINCT CONCAT(j.id, ':', j.type)) AS docs,
                            SUM(l.qty) AS qty, SUM(l.guests) AS guests, SUM(l.amount) AS amount, SUM(l.sessions) AS sessions,
                            SUM(IF(l.discounted, l.qty, 0)) AS dc, SUM(IF(l.is_free, l.qty, 0)) AS free_qty
                       FROM journals j JOIN sales_lines l ON l.journal_id = j.id
                      WHERE " . SALE_DOC_SQL . " AND $cond AND j.work_date BETWEEN ? AND ?
                        AND j.status IN (" . implode(',', array_fill(0, count($statuses), '?')) . ')
                      GROUP BY j.work_date');
$st->execute([...$condArgs, $from, $to, ...$statuses]);
$byDate = [];
foreach ($st as $r) $byDate[$r['d']] = $r;

// 분류별 칸: [키 => [머리글, 값 함수]]
$num = fn(string $k) => fn(?array $r) => (int) ($r[$k] ?? 0);
$cols = match ($grp) {
    'room'    => ['qty' => ['판매(실)', $num('qty')], 'guests' => ['입실 인원', $num('guests')], 'dc' => ['할인(실)', $num('dc')], 'amount' => ['금액', $num('amount')]],
    'program' => ['sessions' => ['회차', $num('sessions')], 'qty' => ['유료 인원', $num('qty')], 'guests' => ['무료 인원', $num('guests')],
                  'people' => ['인원 합계', fn($r) => (int) ($r['qty'] ?? 0) + (int) ($r['guests'] ?? 0)], 'amount' => ['금액', $num('amount')]],
    'rental'  => ['qty' => ['건수', $num('qty')], 'amount' => ['금액', $num('amount')]],
    'lodge'   => ['qty' => ['실 수', $num('qty')], 'amount' => ['금액', $num('amount')]],
    default   => $kind === 'p'
        ? ['qty' => ['매수', $num('qty')], 'amount' => ['금액', $num('amount')]]
        : ['paid' => ['유료 매수', fn($r) => (int) ($r['qty'] ?? 0) - (int) ($r['free_qty'] ?? 0)], 'free' => ['무료 매수', $num('free_qty')], 'qty' => ['합계 매수', $num('qty')], 'amount' => ['금액', $num('amount')]],
};
$unitCol = match ($grp) { 'room' => 'qty', 'program' => 'people', default => 'qty' };
$hasAvg = $grp !== 'program';

// 모든 날짜 (판매 없는 날 포함)
$days = [];
for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) $days[] = $d;
$total = array_fill_keys(array_keys($cols), 0);
$soldDays = 0;
foreach ($days as $d) {
    $r = $byDate[$d] ?? null;
    foreach ($cols as $k => [, $fn]) $total[$k] += $fn($r);
    if ($r && ((int) $r['qty'] || (int) $r['guests'] || (int) $r['amount'])) $soldDays++;
}
$avg = fn(int $amount, int $qty) => $qty ? (int) round($amount / $qty) : 0;
$wd = fn(string $d) => weekday_ko($d);
$title = "상품판매현황 · $itemLabel (" . date('Y.n.j', strtotime($from)) . ' ~ ' . date('Y.n.j', strtotime($to)) . ')';

if (($_GET['export'] ?? '') === 'xlsx') {
    $head = ['날짜', '요일', ...array_map(fn($c) => $c[0], array_values($cols)), ...($hasAvg ? ['평균 단가'] : [])];
    $rows = array_map(function (string $d) use ($byDate, $cols, $hasAvg, $avg, $wd, $unitCol) {
        $r = $byDate[$d] ?? null;
        $vals = array_map(fn($c) => $c[1]($r), $cols);
        return [$d, $wd($d), ...array_values($vals), ...($hasAvg ? [$avg($vals['amount'], $vals[$unitCol])] : [])];
    }, $days);
    xlsx_send('상품판매현황_' . preg_replace('/[\\\\\/:*?"<>|\s]+/u', '_', $itemLabel) . "_{$from}_{$to}.xlsx", [[
        'name' => '일별 판매', 'title' => config('site_name') . ' ' . $title,
        'subtitle' => "$statusLabel 매출보고·일일객실판매 집계 · 판매한 날 {$soldDays}일 / " . count($days) . '일 · 출력 ' . date('Y-m-d H:i') . ' · ' . $user['name'],
        'header' => $head, 'rows' => $rows,
        'footer' => [['합계', '', ...array_values($total), ...($hasAvg ? [$avg($total['amount'], $total[$unitCol])] : [])]],
        'widths' => [12, 6, ...array_fill(0, count($cols), 12), ...($hasAvg ? [12] : [])],
    ]]);
}

// 쪽 나누기 (30일씩)
$pages = max(1, (int) ceil(count($days) / PS_PER_PAGE));
$page = min($pages, max(1, (int) ($_GET['page'] ?? 1)));
$pageDays = array_slice($days, ($page - 1) * PS_PER_PAGE, PS_PER_PAGE);
$pageTotal = array_fill_keys(array_keys($cols), 0);
foreach ($pageDays as $d) foreach ($cols as $k => [, $fn]) $pageTotal[$k] += $fn($byDate[$d] ?? null);
$q = fn(array $o) => 'product_sales.php?' . http_build_query(array_filter(array_merge(['item' => $item, 'from' => $from, 'to' => $to, 'approved' => $approvedOnly ? 1 : null], $o), fn($v) => $v !== null && $v !== ''));
$quick = [
    '이번 달' => [date('Y-m-01'), date('Y-m-d')],
    '지난 달' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
    '최근 30일' => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')],
    '올해' => [date('Y-01-01'), date('Y-m-d')],
    '작년' => [(date('Y') - 1) . '-01-01', (date('Y') - 1) . '-12-31'],
];
$unitName = $cols[$unitCol][0];

layout_header('상품판매현황', 'product_sales');
?>
<section class="card no-print">
  <div class="card-head">
    <h1>상품판매현황</h1>
    <div class="actions no-margin">
      <a class="btn" href="<?= e(url($q(['export' => 'xlsx']))) ?>">엑셀 다운로드 <small>(기간 전체)</small></a>
      <button class="btn" type="button" onclick="window.print()">인쇄 · PDF</button>
    </div>
  </div>
  <form method="get" class="filters">
    <label>상품
      <select name="item">
        <?php foreach ($items as $glabel => $opts): ?><optgroup label="<?= e($glabel) ?>">
          <?php foreach ($opts as $v => $l): ?><option value="<?= e($v) ?>" <?= $item === $v ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?>
        </optgroup><?php endforeach ?>
      </select>
    </label>
    <label>시작<input type="date" name="from" value="<?= e($from) ?>"></label>
    <label>종료<input type="date" name="to" value="<?= e($to) ?>"></label>
    <label class="inline-check"><input type="checkbox" name="approved" value="1" <?= $approvedOnly ? 'checked' : '' ?>> 결재완료만</label>
    <button class="btn small primary">조회</button>
  </form>
  <div class="stat-units ps-quick">
    <?php foreach ($quick as $ql => [$qf, $qt]): ?><a href="<?= e(url($q(['from' => $qf, 'to' => $qt, 'page' => null]))) ?>" class="<?= $from === $qf && $to === $qt ? 'on' : '' ?>"><?= $ql ?></a><?php endforeach ?>
  </div>
</section>

<section class="card">
  <h2><?= e($title) ?> <small class="muted"><?= e($statusLabel) ?> 집계</small></h2>
  <div class="kpis k4">
    <div class="kpi total"><span>금액 합계</span><b><?= e(won($total['amount'])) ?></b><small class="muted">하루 평균 <?= e(won((int) round($total['amount'] / max(1, count($days))))) ?></small></div>
    <div class="kpi"><span><?= e($unitName) ?> 합계</span><b><?= number_format($total[$unitCol]) ?></b><small class="muted"><?= $hasAvg ? '평균 단가 ' . e(won($avg($total['amount'], $total[$unitCol]))) : '회차 ' . number_format($total['sessions'] ?? 0) . '회' ?></small></div>
    <div class="kpi"><span>판매한 날</span><b><?= $soldDays ?>일</b><small class="muted">기간 <?= count($days) ?>일 중</small></div>
    <div class="kpi"><span>판매한 날 평균</span><b><?= number_format($soldDays ? $total[$unitCol] / $soldDays : 0, 1) ?></b><small class="muted"><?= e($unitName) ?> · <?= e(won($soldDays ? (int) round($total['amount'] / $soldDays) : 0)) ?></small></div>
  </div>

  <?php $pager = function () use ($pages, $page, $q, $days) {
      if ($pages < 2) return;
      echo '<div class="ps-pager no-print">';
      echo $page > 1 ? '<a class="btn small" href="' . e(url($q(['page' => $page - 1]))) . '">‹ 이전 30일</a>' : '<span class="btn small disabled">‹ 이전 30일</span>';
      for ($i = 1; $i <= $pages; $i++) {
          if ($pages > 12 && abs($i - $page) > 3 && $i !== 1 && $i !== $pages) { if (abs($i - $page) === 4) echo '<span class="muted">…</span>'; continue; }
          $d0 = $days[($i - 1) * PS_PER_PAGE];
          echo '<a class="' . ($i === $page ? 'on' : '') . '" href="' . e(url($q(['page' => $i]))) . '" title="' . e($d0) . '~">' . $i . '</a>';
      }
      echo $page < $pages ? '<a class="btn small" href="' . e(url($q(['page' => $page + 1]))) . '">다음 30일 ›</a>' : '<span class="btn small disabled">다음 30일 ›</span>';
      echo ' <span class="muted small">' . $page . ' / ' . $pages . '쪽</span></div>';
  }; $pager(); ?>

  <div class="table-scroll">
  <table class="table stats-table ps-table">
    <thead><tr><th>날짜</th><?php foreach ($cols as [$h]): ?><th class="right"><?= e($h) ?></th><?php endforeach ?><?php if ($hasAvg): ?><th class="right">평균 단가</th><?php endif ?><th class="no-print">문서</th></tr></thead>
    <tbody>
    <?php foreach ($pageDays as $d): $r = $byDate[$d] ?? null; $w = (int) date('w', strtotime($d)); $vals = array_map(fn($c) => $c[1]($r), $cols); ?>
      <tr class="<?= array_filter($vals) ? '' : 'zero' ?>">
        <td class="nowrap <?= $w === 0 ? 'sun-text' : ($w === 6 ? 'sat-text' : '') ?>"><?= e(date('Y.n.j', strtotime($d))) ?> (<?= e($wd($d)) ?>)</td>
        <?php foreach ($vals as $k => $v): ?><td class="right <?= $k === 'amount' ? 'strong' : '' ?>"><?= $v ? number_format($v) : '-' ?></td><?php endforeach ?>
        <?php if ($hasAvg): ?><td class="right"><?= $vals[$unitCol] ? number_format($avg($vals['amount'], $vals[$unitCol])) : '-' ?></td><?php endif ?>
        <td class="no-print small nowrap"><?php foreach ($r ? explode(',', $r['docs']) : [] as $doc): [$jid, $jt] = explode(':', $doc); ?><a href="<?= e(url('view.php?id=' . (int) $jid)) ?>"><?= $jt === 'rooms' ? '객실판매' : '매출보고' ?> ›</a> <?php endforeach ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
    <tfoot>
      <?php if ($pages > 1): ?>
      <tr><th>이 쪽 합계</th><?php foreach ($pageTotal as $v): ?><th class="right"><?= number_format($v) ?></th><?php endforeach ?><?php if ($hasAvg): ?><th class="right"><?= number_format($avg($pageTotal['amount'], $pageTotal[$unitCol])) ?></th><?php endif ?><th class="no-print"></th></tr>
      <?php endif ?>
      <tr><th>기간 합계</th><?php foreach ($total as $v): ?><th class="right"><?= number_format($v) ?></th><?php endforeach ?><?php if ($hasAvg): ?><th class="right"><?= number_format($avg($total['amount'], $total[$unitCol])) ?></th><?php endif ?><th class="no-print"></th></tr>
    </tfoot>
  </table>
  </div>
  <?php $pager(); ?>
  <p class="muted small">매출보고(입장권·시설대관·대관 숙박시설·프로그램 판매)와 일일객실판매(객실)의 <?= e($statusLabel) ?> 문서를 날짜별로 더했습니다. 판매가 없는 날도 한 줄씩 나오며, 화면은 30일씩 나눠 보여 주고 <b>엑셀은 기간 전체</b>가 한 시트에 나옵니다.
    <?= $grp === 'program' ? '프로그램 판매의 유료 인원에는 할인 인원이 포함됩니다.' : '평균 단가 = 금액 ÷ ' . e($unitName) . '.' ?></p>
</section>
<?php layout_footer();
