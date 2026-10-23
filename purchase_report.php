<?php
/**
 * 시설관리 › 물품구매 › 보고서 — purchase_report.php?from=YYYY-MM-DD&to=YYYY-MM-DD[&vendor=구매처&pay=card|credit&st=approved|paid]
 *   기간·구매처(·결제방법·상태)로 골라 구매 내역을 한 페이지 보고서로: 요약, 구매처별 합계, 건별 내역(품목·수량)
 *   인쇄는 A4 세로 한 장 (내용이 많으면 자동으로 줄임). 임시저장·반려는 넣지 않는다.
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
require_menu($user, 'fac');
if (!can_purchase($user)) abort(403, '물품구매는 공무직 이상만 사용할 수 있습니다.');
$pdo = db();

$from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = valid_date($_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-t');
if ($to < $from) [$from, $to] = [$to, $from];
$vendor = mb_substr(trim((string) ($_GET['vendor'] ?? '')), 0, 100);
$pay = (string) ($_GET['pay'] ?? '');
if (!isset(PURCHASE_PAY[$pay])) $pay = '';
$stLabels = ['' => '결재중 + 결재완료', 'approved' => '결재완료', 'paid' => '지출완료'];
$stF = (string) ($_GET['st'] ?? '');
if (!isset($stLabels[$stF])) $stF = '';

// 구매처 목록 (임시저장·반려 제외한 구매가 있는 곳)
$vendors = $pdo->query("SELECT m.vendor, COUNT(*) AS n FROM purchase_meta m JOIN journals j ON j.id = m.journal_id
                         WHERE j.type = 'purchase' AND j.status IN ('pending', 'approved') AND m.vendor <> '' GROUP BY m.vendor ORDER BY m.vendor")->fetchAll();

$sql = "SELECT j.id, j.work_date, j.status, m.vendor, m.pay_method, m.total, m.paid_at,
               (SELECT GROUP_CONCAT(CONCAT(i.name, ' ', i.qty) ORDER BY i.sort_no SEPARATOR ', ') FROM purchase_items i WHERE i.journal_id = j.id) AS items
          FROM journals j JOIN purchase_meta m ON m.journal_id = j.id
         WHERE j.type = 'purchase' AND j.work_date BETWEEN ? AND ?";
$args = [$from, $to];
$sql .= match ($stF) {
    'approved' => " AND j.status = 'approved'",
    'paid'     => " AND j.status = 'approved' AND m.paid_at IS NOT NULL",
    default    => " AND j.status IN ('pending', 'approved')",
};
if ($vendor !== '') { $sql .= ' AND m.vendor = ?'; $args[] = $vendor; }
if ($pay !== '') { $sql .= ' AND m.pay_method = ?'; $args[] = $pay; }
$st = $pdo->prepare($sql . ' ORDER BY j.work_date, m.vendor, j.id');
$st->execute($args);
$rows = $st->fetchAll();

$sum = fn(callable $cond) => array_sum(array_map(fn($r) => $cond($r) ? (int) $r['total'] : 0, $rows));
$total = $sum(fn($r) => true);
$byVendor = [];
foreach ($rows as $r) {
    $v = &$byVendor[$r['vendor']];
    $v ??= ['n' => 0, 'card' => 0, 'credit' => 0];
    $v['n']++;
    $v[$r['pay_method']] += (int) $r['total'];
    unset($v);
}
uasort($byVendor, fn($a, $b) => ($b['card'] + $b['credit']) <=> ($a['card'] + $a['credit']));

$q = fn(array $p) => 'purchase_report.php?' . http_build_query(array_filter($p + ['from' => $from, 'to' => $to, 'vendor' => $vendor, 'pay' => $pay, 'st' => $stF], fn($v) => $v !== ''));
$period = fn(string $a, string $b) => url($q(['from' => $a, 'to' => $b]));
$m0 = new DateTimeImmutable('first day of this month');
$y = (int) date('Y');
$dateText = fn(string $d) => date('Y. n. j.', strtotime($d));
$cond = array_filter([$vendor !== '' ? "구매처 {$vendor}" : '구매처 전체', $pay ? '결제 ' . PURCHASE_PAY[$pay] : '', $stLabels[$stF]]);

layout_header('물품구매 보고서', 'purchase');
?>
<section class="card no-print">
  <form method="get" class="pc-report-form">
    <div class="row">
      <label>시작일<input type="date" name="from" value="<?= e($from) ?>" required></label>
      <label>종료일<input type="date" name="to" value="<?= e($to) ?>" required></label>
      <label>구매처<select name="vendor">
        <option value="">전체</option>
        <?php foreach ($vendors as $v): ?><option value="<?= e($v['vendor']) ?>" <?= $v['vendor'] === $vendor ? 'selected' : '' ?>><?= e($v['vendor']) ?> (<?= (int) $v['n'] ?>건)</option><?php endforeach ?>
      </select></label>
      <label>결제<select name="pay"><option value="">전체</option><?php foreach (PURCHASE_PAY as $k => $l): ?><option value="<?= $k ?>" <?= $pay === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select></label>
      <label>상태<select name="st"><?php foreach ($stLabels as $k => $l): ?><option value="<?= $k ?>" <?= $stF === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach ?></select></label>
      <button class="btn primary">조회</button>
    </div>
    <div class="actions no-margin">
      <span class="muted small">기간 바로 고르기</span>
      <a class="btn small ghost" href="<?= e($period($m0->format('Y-m-d'), $m0->format('Y-m-t'))) ?>">이번 달</a>
      <a class="btn small ghost" href="<?= e($period($m0->modify('-1 month')->format('Y-m-d'), $m0->modify('-1 month')->format('Y-m-t'))) ?>">지난 달</a>
      <a class="btn small ghost" href="<?= e($period("$y-01-01", "$y-06-30")) ?>">상반기</a>
      <a class="btn small ghost" href="<?= e($period("$y-07-01", "$y-12-31")) ?>">하반기</a>
      <a class="btn small ghost" href="<?= e($period("$y-01-01", "$y-12-31")) ?>">올해</a>
      <a class="btn small ghost" href="<?= e($period(($y - 1) . '-01-01', ($y - 1) . '-12-31')) ?>">작년</a>
    </div>
  </form>
</section>

<section class="card payout pc-report" data-payout>
  <div class="payout-head">
    <h1>물품구매 보고서</h1>
    <div class="payout-meta">
      <span><b><?= e($dateText($from)) ?> ~ <?= e($dateText($to)) ?></b></span>
      <span class="muted small"><?= e(implode(' · ', $cond)) ?></span>
      <span class="muted small">출력 <?= e(date('Y. n. j.')) ?> · <?= e($user['name']) ?></span>
    </div>
  </div>

  <div class="kpis k4">
    <div class="kpi total"><span>총 구매금액</span><b><?= e(won($total)) ?></b><small class="muted"><?= count($rows) ?>건</small></div>
    <div class="kpi"><span>카드</span><b><?= e(won($sum(fn($r) => $r['pay_method'] === 'card'))) ?></b></div>
    <div class="kpi"><span>외상</span><b><?= e(won($sum(fn($r) => $r['pay_method'] === 'credit'))) ?></b></div>
    <div class="kpi"><span>지출완료 / 미완료</span><b><?= e(won($sum(fn($r) => (bool) $r['paid_at']))) ?></b><small class="muted">미완료 <?= number_format($sum(fn($r) => !$r['paid_at'])) ?>원</small></div>
  </div>

  <?php if (!$rows): ?>
    <p class="muted">조건에 맞는 구매 내역이 없습니다.</p>
  <?php else: ?>
  <?php if ($vendor === '' && count($byVendor) > 1): ?>
  <h3>구매처별 합계</h3>
  <div class="table-scroll">
  <table class="table payout-table">
    <thead><tr><th>구매처</th><th class="right">건수</th><th class="right">카드</th><th class="right">외상</th><th class="right">합계</th><th class="right">비율</th></tr></thead>
    <tbody>
    <?php foreach ($byVendor as $name => $v): $amt = $v['card'] + $v['credit']; ?>
      <tr><td><?= e($name) ?></td><td class="right"><?= $v['n'] ?></td><td class="right"><?= $v['card'] ? number_format($v['card']) : '' ?></td>
        <td class="right"><?= $v['credit'] ? number_format($v['credit']) : '' ?></td><td class="right"><b><?= number_format($amt) ?></b></td>
        <td class="right"><?= $total ? number_format($amt * 100 / $total, 1) : 0 ?>%</td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
  <?php endif ?>

  <h3>구매 내역</h3>
  <div class="table-scroll">
  <table class="table payout-table">
    <thead><tr><th class="center">No</th><th>구매일</th><th>구매처</th><th>품목 · 수량</th><th>결제</th><th class="right">금액(원)</th><th>지출</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
      <tr><td class="center"><?= $i + 1 ?></td><td class="nowrap"><?= e(date('n/j', strtotime($r['work_date']))) ?> (<?= weekday_ko($r['work_date']) ?>)</td>
        <td><?= e($r['vendor']) ?></td><td class="pc-items"><?= e((string) $r['items']) ?></td><td><?= e(PURCHASE_PAY[$r['pay_method']] ?? '') ?></td>
        <td class="right"><?= number_format((int) $r['total']) ?></td>
        <td class="nowrap small"><?= $r['paid_at'] ? e(date('n/j', strtotime($r['paid_at']))) : ($r['status'] === 'approved' ? '대기' : '결재중') ?></td></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th colspan="5">합계 <?= count($rows) ?>건</th><th class="right"><?= number_format($total) ?></th><th></th></tr></tfoot>
  </table>
  </div>
  <?php endif ?>

  <div class="actions no-print">
    <a class="btn ghost" href="<?= e(url('purchase.php?ym=' . substr($from, 0, 7))) ?>">물품구매로</a>
    <button type="button" class="btn primary" onclick="window.print()">인쇄</button>
  </div>
  <p class="muted small no-print">임시저장·반려된 구매는 넣지 않습니다. 인쇄는 A4 세로 한 장에 맞춰 나옵니다 (내용이 많으면 글씨가 작아집니다).</p>
</section>
<script>
(function () {
  // 인쇄: A4 세로 한 장 — 넘치면 줄여서 맞춘다
  const box = document.querySelector('[data-payout]');
  window.addEventListener('beforeprint', () => {
    document.body.classList.add('payout-printmode');
    box.style.zoom = '';
    // A4 세로 인쇄 영역(여백 제외 대략 700×1000px) 너비로 놓고 높이를 재야 줄바꿈까지 맞게 계산된다
    const W = 700, max = 960;
    box.style.width = W + 'px';
    let z = 1;
    for (let i = 0; i < 3; i++) { // 줄이면 줄바꿈이 달라지므로 다시 재서 맞춘다
      const h = box.getBoundingClientRect().height;
      if (h <= max || z <= 0.4) break;
      z = Math.max(0.4, z * max / h);
      box.style.zoom = String(z);
      box.style.width = (W / z) + 'px'; // 줄인 만큼 넓혀서 종이 너비를 채운다
    }
  });
  window.addEventListener('afterprint', () => { document.body.classList.remove('payout-printmode'); box.style.zoom = ''; box.style.width = ''; });
})();
</script>
<?php layout_footer();
