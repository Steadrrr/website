<?php
/**
 * 시설관리 › 물품구매 (공무직 이상)
 *   purchase.php?ym=2026-10[&f=wait]   월 단위 일별(구매처별) 목록, 월간·연간 구매금액, 월별 그래프
 *   POST target=pay|unpay              주무관 이상: 결재완료된 구매의 지출 완료 / 취소
 * 구매 1건 = 결재 문서 1건 (journals type purchase, write.php 에서 작성)
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
if (!can_purchase($user)) abort(403, '물품구매는 공무직 이상만 사용할 수 있습니다.');
$pdo = db();

if (is_post()) {
    csrf_verify();
    if (!can_purchase_pay($user)) abort(403, '지출 처리는 주무관 이상이 합니다.');
    $j = journal_find((int) post('journal_id'));
    if (!$j || $j['type'] !== 'purchase') abort(404, '구매 문서를 찾을 수 없습니다.');
    if ($j['status'] !== 'approved') {
        flash('결재가 완료된 구매만 지출 처리할 수 있습니다.', 'error');
    } elseif (post('target') === 'pay') {
        $date = valid_date(post('paid_at')) ? post('paid_at') : date('Y-m-d');
        $note = mb_substr(post('paid_note'), 0, 200);
        $pdo->prepare('UPDATE purchase_meta SET paid_at = ?, paid_by = ?, paid_note = ? WHERE journal_id = ?')->execute([$date, $user['id'], $note !== '' ? $note : null, $j['id']]);
        journal_log((int) $j['id'], $user, '지출 완료', trim("지출일 $date " . $note));
        flash('지출 완료로 처리했습니다.', 'success');
    } elseif (post('target') === 'unpay') {
        $pdo->prepare('UPDATE purchase_meta SET paid_at = NULL, paid_by = NULL, paid_note = NULL WHERE journal_id = ?')->execute([$j['id']]);
        journal_log((int) $j['id'], $user, '지출 취소', '');
        flash('지출 완료를 취소했습니다.', 'success');
    }
    redirect('view.php?id=' . $j['id']);
}

$ym = $_GET['ym'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ym) || !valid_date("$ym-01")) $ym = date('Y-m');
$first = new DateTimeImmutable("$ym-01");
$from = $first->format('Y-m-d');
$to = $first->modify('last day of this month')->format('Y-m-d');
$year = (int) $first->format('Y');
$filters = ['' => '전체', 'pending' => '결재중', 'wait' => '지출대기', 'paid' => '지출완료', 'card' => '카드', 'credit' => '외상'];
$f = (string) ($_GET['f'] ?? '');
if (!isset($filters[$f])) $f = '';
const PC_COUNTED = "j.status IN ('pending', 'approved')"; // 금액 집계 대상

// 연간 월별 (카드·외상)
$st = $pdo->prepare('SELECT MONTH(j.work_date) AS m, m.pay_method, SUM(m.total) AS amt FROM journals j JOIN purchase_meta m ON m.journal_id = j.id
                      WHERE j.type = \'purchase\' AND ' . PC_COUNTED . ' AND YEAR(j.work_date) = ? GROUP BY MONTH(j.work_date), m.pay_method');
$st->execute([$year]);
$byMonth = ['card' => array_fill(1, 12, 0), 'credit' => array_fill(1, 12, 0)];
foreach ($st as $r) $byMonth[$r['pay_method']][(int) $r['m']] = (int) $r['amt'];
$yearTotal = array_sum($byMonth['card']) + array_sum($byMonth['credit']);
$mNo = (int) $first->format('n');
$monthTotal = $byMonth['card'][$mNo] + $byMonth['credit'][$mNo];

// 이 달 목록 (남의 임시저장 제외)
$st = $pdo->prepare("SELECT j.id, j.type, j.work_date, j.status, j.revision, j.author_id, j.submitted_at, u.name AS author_name,
                            m.vendor, m.pay_method, m.total, m.paid_at,
                            (SELECT GROUP_CONCAT(i.name ORDER BY i.sort_no SEPARATOR ', ') FROM purchase_items i WHERE i.journal_id = j.id) AS items,
                            (SELECT COUNT(*) FROM purchase_items i WHERE i.journal_id = j.id) AS item_count,
                            (SELECT COUNT(*) FROM photos p WHERE p.owner_type = 'purchase_check' AND p.owner_id = j.id) AS check_n,
                            (SELECT COUNT(*) FROM photos p WHERE p.owner_type = 'purchase_receipt' AND p.owner_id = j.id) AS receipt_n
                       FROM journals j JOIN users u ON u.id = j.author_id LEFT JOIN purchase_meta m ON m.journal_id = j.id
                      WHERE j.type = 'purchase' AND j.work_date BETWEEN ? AND ? AND (j.status <> 'draft' OR j.author_id = ?)
                      ORDER BY j.work_date, m.vendor, j.id");
$st->execute([$from, $to, $user['id']]);
$all = $st->fetchAll();
$counted = fn($r) => in_array($r['status'], ['pending', 'approved'], true);
$sumBy = fn(callable $cond) => array_sum(array_map(fn($r) => $cond($r) ? (int) $r['total'] : 0, $all));
$paidAmt = $sumBy(fn($r) => $r['status'] === 'approved' && $r['paid_at']);
$waitRows = array_filter($all, fn($r) => $r['status'] === 'approved' && !$r['paid_at']);
$waitAmt = $sumBy(fn($r) => $r['status'] === 'approved' && !$r['paid_at']);
$pendAmt = $sumBy(fn($r) => $r['status'] === 'pending');
$rows = array_filter($all, fn($r) => match ($f) {
    'pending' => $r['status'] === 'pending',
    'wait'    => $r['status'] === 'approved' && !$r['paid_at'],
    'paid'    => (bool) $r['paid_at'],
    'card', 'credit' => $r['pay_method'] === $f,
    default   => true,
});
$byDate = [];
foreach ($rows as $r) $byDate[$r['work_date']][] = $r;
$q = fn(array $p) => 'purchase.php?' . http_build_query(array_filter(['ym' => $ym, 'f' => $f] + $p, fn($v) => $v !== ''));

layout_header('물품구매', 'purchase');
?>
<div class="card">
  <div class="card-head">
    <h1>물품구매 <small class="muted">구매 증빙 (재고관리와 별개)</small></h1>
    <div class="actions no-margin no-print">
      <button class="btn ghost" onclick="window.print()">인쇄</button>
      <a class="btn primary" href="<?= e(url('write.php?type=purchase&date=' . (substr(date('Y-m-d'), 0, 7) === $ym ? date('Y-m-d') : $from))) ?>">+ 물품구매 등록</a>
    </div>
  </div>
  <div class="kpis k4">
    <div class="kpi total"><span><?= $mNo ?>월 총 구매금액</span><b><?= e(won($monthTotal)) ?></b><small class="muted">카드 <?= number_format($byMonth['card'][$mNo]) ?> · 외상 <?= number_format($byMonth['credit'][$mNo]) ?></small></div>
    <div class="kpi"><span><?= $year ?>년 구매금액</span><b><?= e(won($yearTotal)) ?></b><small class="muted">카드 <?= number_format(array_sum($byMonth['card'])) ?> · 외상 <?= number_format(array_sum($byMonth['credit'])) ?></small></div>
    <div class="kpi"><span><?= $mNo ?>월 지출완료</span><b><?= e(won($paidAmt)) ?></b><small class="muted">결재중 <?= number_format($pendAmt) ?>원</small></div>
    <div class="kpi <?= $waitRows ? 'warn-kpi' : '' ?>"><span>지출대기 (결재완료)</span><b><?= count($waitRows) ?>건</b><small class="muted"><?= number_format($waitAmt) ?>원<?= $waitRows && can_purchase_pay($user) ? ' · 주무관 지출 처리' : '' ?></small></div>
  </div>
  <p class="muted small">금액은 결재중·결재완료된 구매의 합계입니다 (임시저장·반려 제외). 공무직이 등록·결재 요청 → 주무관 결재 → 주무관이 지출 완료.</p>
</div>

<?php stat_chart('pcChart', "{$year}년 월별 구매금액", array_map(fn($m) => "{$m}월", range(1, 12)), [
    ['label' => '카드', 'data' => $byMonth['card'], 'color' => '#2f7d4f'],
    ['label' => '외상', 'data' => $byMonth['credit'], 'color' => '#c9a227'],
    ['label' => '합계', 'type' => 'line', 'data' => array_map(fn($m) => $byMonth['card'][$m] + $byMonth['credit'][$m], range(1, 12)), 'color' => '#4a7fb5'],
], '원', '결재중·결재완료') ?>

<section class="card">
  <div class="cal-nav no-print">
    <a class="btn" href="<?= e(url($q(['ym' => $first->modify('-1 month')->format('Y-m')]))) ?>">‹ 이전달</a>
    <strong><?= e($first->format('Y년 n월')) ?></strong>
    <a class="btn" href="<?= e(url($q(['ym' => $first->modify('+1 month')->format('Y-m')]))) ?>">다음달 ›</a>
    <a class="btn ghost" href="<?= e(url('purchase.php')) ?>">이번 달</a>
    <div class="tabs pc-filter">
      <?php foreach ($filters as $k => $label): ?><a href="<?= e(url($q(['f' => $k]))) ?>" class="<?= $f === $k ? 'on' : '' ?>"><?= e($label) ?></a><?php endforeach ?>
    </div>
  </div>
  <?php if (!$byDate): ?>
    <p class="muted">이 달 구매 내역이 없습니다.</p>
  <?php else: ?>
  <div class="table-scroll">
  <table class="table pc-list">
    <thead><tr><th>일자</th><th>구매처</th><th>품목</th><th>결제</th><th class="right">금액(원)</th><th>사진</th><th>결재</th><th>지출</th><th>작성</th></tr></thead>
    <tbody>
    <?php foreach ($byDate as $date => $list): $dayAmt = array_sum(array_map(fn($r) => $counted($r) ? (int) $r['total'] : 0, $list));
        foreach ($list as $i => $r): ?>
      <tr class="clickable <?= $i === 0 ? 'pc-day-first' : '' ?> <?= $counted($r) ? '' : 'inactive' ?>" onclick="location.href='<?= e(url('view.php?id=' . $r['id'])) ?>'">
        <?php if ($i === 0): ?><td rowspan="<?= count($list) ?>" class="nowrap pc-day"><b><?= e(date('n/j', strtotime($date))) ?></b> (<?= weekday_ko($date) ?>)<br><small class="muted"><?= number_format($dayAmt) ?>원</small></td><?php endif ?>
        <td><b><?= e($r['vendor'] ?? '') ?></b></td>
        <td class="pc-items"><?= e(mb_strimwidth((string) $r['items'], 0, 40, '…')) ?><?= $r['item_count'] > 1 ? ' <small class="muted">(' . (int) $r['item_count'] . '품목)</small>' : '' ?></td>
        <td><?= e(PURCHASE_PAY[$r['pay_method']] ?? '') ?></td>
        <td class="right"><b><?= number_format((int) $r['total']) ?></b></td>
        <td class="nowrap small"><span title="검수사진">🔍<?= (int) $r['check_n'] ?></span> <span title="영수증사진">🧾<?= (int) $r['receipt_n'] ?></span></td>
        <td><?= journal_badges($r) ?></td>
        <td><?= purchase_pay_badge($r, $r['status']) ?></td>
        <td class="small"><?= e($r['author_name']) ?></td>
      </tr>
    <?php endforeach; endforeach ?>
    </tbody>
    <tfoot><tr><th colspan="4"><?= $filters[$f] ?> <?= count($rows) ?>건</th><th class="right"><?= number_format(array_sum(array_map(fn($r) => $counted($r) ? (int) $r['total'] : 0, $rows))) ?></th><th colspan="4" class="small muted">반려·임시저장은 합계에서 제외</th></tr></tfoot>
  </table>
  </div>
  <?php endif ?>
</section>
<?php layout_footer();
