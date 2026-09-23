<?php
/** 지역상품권 재고·수불부: voucher.php?ym=2026-09 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
$denoms = voucher_denoms();

$ym = $_GET['ym'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ym) || !valid_date("$ym-01")) $ym = date('Y-m');
$first = new DateTimeImmutable("$ym-01");
$last  = $first->modify('last day of this month');

$stockNow = voucher_stock();
$opening  = voucher_stock($first->format('Y-m-d'));

// 이 달의 수불 내역 (문서 단위로 한 줄)
$st = db()->prepare(
    'SELECT j.id, j.type, j.work_date, j.status, j.content, u.name AS author_name, m.direction, m.denom, m.qty
       FROM voucher_moves m
       JOIN journals j ON j.id = m.journal_id
       JOIN users u ON u.id = j.author_id
      WHERE ' . VOUCHER_COUNTED_SQL . ' AND j.work_date BETWEEN ? AND ?
      ORDER BY j.work_date, m.direction, j.id'
);
$st->execute([$first->format('Y-m-d'), $last->format('Y-m-d')]);
$rows = [];
foreach ($st as $r) {
    $key = $r['id'] . $r['direction'];
    $rows[$key] ??= [
        'id' => $r['id'], 'date' => $r['work_date'], 'dir' => $r['direction'], 'author' => $r['author_name'],
        'memo' => $r['type'] === 'voucher' ? $r['content'] : '매출보고 객실 환급', 'qty' => array_fill_keys($denoms, 0),
    ];
    $rows[$key]['qty'][(int) $r['denom']] += (int) $r['qty'];
}

// 결재 대기중인 입고 (아직 재고에 반영 안 됨)
$pending = db()->query(
    "SELECT j.id, j.work_date, j.status, u.name AS author_name,
            (SELECT SUM(m.denom * m.qty) FROM voucher_moves m WHERE m.journal_id = j.id) AS amount
       FROM journals j JOIN users u ON u.id = j.author_id
      WHERE j.type = 'voucher' AND j.status IN ('pending', 'rejected')
      ORDER BY j.work_date, j.id"
)->fetchAll();

layout_header('상품권 재고', 'voucher');
?>
<section class="card">
  <div class="card-head">
    <h1>지역상품권 재고</h1>
    <div class="actions no-margin no-print">
      <button class="btn ghost" onclick="window.print()">인쇄</button>
      <a class="btn primary" href="<?= e(url('write.php?type=voucher')) ?>">+ 입고 등록</a>
    </div>
  </div>
  <div class="kpis k<?= count($denoms) + 1 ?>">
    <?php foreach ($denoms as $d): ?>
      <div class="kpi"><span><?= e(denom_label($d)) ?></span><b><?= number_format($stockNow[$d]) ?>매</b><small class="muted"><?= e(won($d * $stockNow[$d])) ?></small></div>
    <?php endforeach ?>
    <div class="kpi total"><span>재고 금액</span><b><?= e(won(voucher_amount($stockNow))) ?></b></div>
  </div>
  <p class="muted small">입고는 <b>상품권입고</b> 문서가 결재완료되면 반영되고, 출고는 매출보고의 '지역상품권 환급' 입력분이 결재를 올리는 즉시 반영됩니다.</p>
</section>

<?php if ($pending): ?>
<section class="card">
  <h2>결재 대기중인 입고</h2>
  <table class="table">
    <thead><tr><th>일자</th><th>작성자</th><th class="right">금액</th><th>상태</th></tr></thead>
    <tbody>
    <?php foreach ($pending as $p): ?>
      <tr class="clickable" onclick="location.href='<?= e(url('view.php?id=' . $p['id'])) ?>'">
        <td><?= e($p['work_date']) ?></td><td><?= e($p['author_name']) ?></td>
        <td class="right"><?= e(won($p['amount'])) ?></td><td><?= status_badge($p['status']) ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</section>
<?php endif ?>

<section class="card">
  <div class="card-head">
    <h2>수불부</h2>
    <div class="cal-nav">
      <a class="btn" href="<?= e(url('voucher.php?ym=' . $first->modify('-1 month')->format('Y-m'))) ?>">‹</a>
      <strong><?= e($first->format('Y년 n월')) ?></strong>
      <a class="btn" href="<?= e(url('voucher.php?ym=' . $first->modify('+1 month')->format('Y-m'))) ?>">›</a>
    </div>
  </div>
  <div class="table-scroll">
  <table class="table ledger">
    <thead>
      <tr><th rowspan="2">일자</th><th rowspan="2">구분</th><th rowspan="2">적요</th>
        <?php foreach ($denoms as $d): ?><th class="right"><?= e(denom_label($d)) ?></th><?php endforeach ?>
        <th class="right" rowspan="2">금액</th><th class="right" rowspan="2">잔액</th></tr>
      <tr><?php foreach ($denoms as $d): ?><th class="right small">매수</th><?php endforeach ?></tr>
    </thead>
    <tbody>
      <tr class="carry">
        <td><?= e($first->format('m/d')) ?></td><td>이월</td><td>전월 이월</td>
        <?php foreach ($denoms as $d): ?><td class="right"><?= number_format($opening[$d]) ?></td><?php endforeach ?>
        <td></td><td class="right"><?= number_format(voucher_amount($opening)) ?></td>
      </tr>
      <?php
      $balance = $opening;
      $sumIn = $sumOut = array_fill_keys($denoms, 0);
      foreach ($rows as $r):
          $sign = $r['dir'] === 'in' ? 1 : -1;
          foreach ($r['qty'] as $d => $q) {
              $balance[$d] += $sign * $q;
              if ($sign > 0) $sumIn[$d] += $q; else $sumOut[$d] += $q;
          } ?>
        <tr class="clickable dir-<?= $r['dir'] ?>" onclick="location.href='<?= e(url('view.php?id=' . $r['id'])) ?>'">
          <td><?= e(date('m/d', strtotime($r['date']))) ?></td>
          <td><?= $r['dir'] === 'in' ? '입고' : '출고' ?></td>
          <td><?= e(mb_strimwidth((string) $r['memo'], 0, 40, '…')) ?> <small class="muted"><?= e($r['author']) ?></small></td>
          <?php foreach ($denoms as $d): ?><td class="right"><?= $r['qty'][$d] ? ($sign > 0 ? '+' : '−') . number_format($r['qty'][$d]) : '' ?></td><?php endforeach ?>
          <td class="right"><?= ($sign > 0 ? '+' : '−') . number_format(voucher_amount($r['qty'])) ?></td>
          <td class="right"><?= number_format(voucher_amount($balance)) ?></td>
        </tr>
      <?php endforeach ?>
      <?php if (!$rows): ?><tr><td colspan="<?= count($denoms) + 5 ?>" class="center muted">이 달의 수불 내역이 없습니다.</td></tr><?php endif ?>
    </tbody>
    <tfoot>
      <tr><th colspan="3">입고 계</th><?php foreach ($denoms as $d): ?><th class="right"><?= number_format($sumIn[$d]) ?></th><?php endforeach ?><th class="right"><?= number_format(voucher_amount($sumIn)) ?></th><th></th></tr>
      <tr><th colspan="3">출고 계</th><?php foreach ($denoms as $d): ?><th class="right"><?= number_format($sumOut[$d]) ?></th><?php endforeach ?><th class="right"><?= number_format(voucher_amount($sumOut)) ?></th><th></th></tr>
      <tr class="closing"><th colspan="3">월말 재고</th><?php foreach ($denoms as $d): ?><th class="right"><?= number_format($balance[$d]) ?></th><?php endforeach ?><th></th><th class="right"><?= number_format(voucher_amount($balance)) ?></th></tr>
    </tfoot>
  </table>
  </div>
  <p class="muted small">처음 사용할 때는 현재 보유 중인 상품권을 '입고 등록'(적요: 기초재고)으로 한 번 올려 결재받으세요.</p>
</section>
<?php layout_footer();
