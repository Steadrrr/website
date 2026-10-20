<?php
/**
 * 일일객실판매 › 지역상품권 수불부 (지급 대장) — rooms_payout.php?id=일일객실판매 문서
 *   그 날 상품권을 줄 객실과 권종별 지급 매수·금액, 총 지급 매수·금액. 지급자 = 로그인한 사람(자동), 수령자 = 화면에서 서명.
 *   A4 세로 한 장으로 인쇄 (내용이 많으면 자동으로 줄임). 서명은 저장하지 않고 인쇄에만 쓴다.
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
require_menu($user, 'room');
$journal = journal_find((int) ($_GET['id'] ?? 0)) ?? abort(404, '일일객실판매를 찾을 수 없습니다.');
if ($journal['type'] !== 'rooms') abort(404, '일일객실판매 문서가 아닙니다.');

$payload = items_load($journal);
$denoms = voucher_denoms();
$rows = [];
foreach ($payload['lines'] as $l) {
    if ($l['grp'] !== 'room' || !array_sum($l['vouchers'] ?? [])) continue;
    $rows[] = ['name' => $l['name'], 'qty' => $l['vouchers']];
}
if (array_sum($payload['vouchers'] ?? [])) $rows[] = ['name' => '객실 미지정 (이전 자료)', 'qty' => $payload['vouchers']];
$total = array_fill_keys($denoms, 0);
foreach ($rows as $r) foreach ($denoms as $d) $total[$d] += (int) ($r['qty'][$d] ?? 0);
$date = $journal['work_date'];

layout_header('지역상품권 수불부', 'rooms');
?>
<section class="card payout" data-payout>
  <div class="payout-head">
    <h1>지역상품권 지급 수불부</h1>
    <div class="payout-meta">
      <span><b><?= e(date('Y년 n월 j일', strtotime($date))) ?> (<?= e(weekday_ko($date)) ?>)</b></span>
      <span class="muted small">일일객실판매 문서 <?= (int) $journal['id'] ?> · <?= journal_badges($journal) ?></span>
    </div>
  </div>

  <table class="table payout-sign">
    <tr>
      <th>지급자</th><td><b><?= e($user['name']) ?></b> <small class="muted"><?= e(rank_name($user['rank_level'])) ?></small></td>
      <th>수령자</th>
      <td class="sign-cell">
        <input class="sign-name" placeholder="수령자 이름" aria-label="수령자 이름">
        <div class="sign-pad"><canvas data-sign width="520" height="120" aria-label="수령자 서명"></canvas><span class="sign-hint no-print">여기에 서명</span></div>
        <button type="button" class="btn small ghost no-print" data-sign-clear>서명 지우기</button>
      </td>
    </tr>
  </table>

  <?php if (!$rows): ?>
    <p class="muted">이 날 일일객실판매에 입력된 지역상품권 환급(지급)이 없습니다.</p>
  <?php else: ?>
  <table class="table payout-table">
    <thead><tr><th class="center">No</th><th>객실명</th><?php foreach ($denoms as $d): ?><th class="right"><?= e(denom_label($d)) ?></th><?php endforeach ?><th class="right">지급수량</th><th class="right">지급금액</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $i => $r): ?>
      <tr><td class="center"><?= $i + 1 ?></td><td><?= e($r['name']) ?></td>
        <?php foreach ($denoms as $d): ?><td class="right"><?= ($r['qty'][$d] ?? 0) ? number_format($r['qty'][$d]) : '' ?></td><?php endforeach ?>
        <td class="right"><?= number_format(array_sum($r['qty'])) ?>매</td><td class="right"><?= e(won(voucher_amount($r['qty']))) ?></td></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th></th><th>합계 <small class="muted"><?= count($rows) ?>실</small></th>
      <?php foreach ($denoms as $d): ?><th class="right"><?= number_format($total[$d]) ?></th><?php endforeach ?>
      <th class="right"><?= number_format(array_sum($total)) ?>매</th><th class="right"><?= e(won(voucher_amount($total))) ?></th></tr></tfoot>
  </table>
  <p class="payout-total">총 지급수량 <b><?= number_format(array_sum($total)) ?>매</b> · 총 지급금액 <b><?= e(won(voucher_amount($total))) ?></b>
    <small class="muted">(<?= e(implode(' · ', array_map(fn($d) => denom_label($d) . ' ' . number_format($total[$d]) . '매', array_filter($denoms, fn($d) => $total[$d] > 0)))) ?>)</small></p>
  <?php endif ?>

  <div class="actions no-print">
    <a class="btn ghost" href="<?= e(url('view.php?id=' . (int) $journal['id'])) ?>">일일객실판매로</a>
    <button type="button" class="btn primary" onclick="window.print()">인쇄</button>
  </div>
  <p class="muted small no-print">서명은 저장되지 않습니다. 서명한 뒤 인쇄하세요. 인쇄는 A4 세로 한 장에 맞춰 나옵니다.</p>
</section>
<script>
(function () {
  // 수령자 서명 (마우스·손가락·펜)
  const cv = document.querySelector('[data-sign]');
  const ctx = cv.getContext('2d');
  const hint = document.querySelector('.sign-hint');
  ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#111';
  let drawing = false;
  const pos = (e) => { const r = cv.getBoundingClientRect(); return [(e.clientX - r.left) * cv.width / r.width, (e.clientY - r.top) * cv.height / r.height]; };
  cv.addEventListener('pointerdown', (e) => { drawing = true; cv.setPointerCapture(e.pointerId); const [x, y] = pos(e); ctx.beginPath(); ctx.moveTo(x, y); if (hint) hint.hidden = true; e.preventDefault(); });
  cv.addEventListener('pointermove', (e) => { if (!drawing) return; const [x, y] = pos(e); ctx.lineTo(x, y); ctx.stroke(); e.preventDefault(); });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach((t) => cv.addEventListener(t, () => { drawing = false; }));
  document.querySelector('[data-sign-clear]').addEventListener('click', () => { ctx.clearRect(0, 0, cv.width, cv.height); if (hint) hint.hidden = false; });

  // 인쇄: A4 세로 한 장 — 넘치면 줄여서 맞춘다
  const box = document.querySelector('[data-payout]');
  window.addEventListener('beforeprint', () => {
    document.body.classList.add('payout-printmode');
    box.style.zoom = '';
    const max = 1000; // A4 세로 인쇄 가능 높이(px, 여백 제외 대략)
    const h = box.scrollHeight;
    if (h > max) box.style.zoom = String(Math.max(0.4, max / h));
  });
  window.addEventListener('afterprint', () => { document.body.classList.remove('payout-printmode'); box.style.zoom = ''; });
})();
</script>
<?php layout_footer();
