<?php
defined('APP_ROOT') || exit;

/**
 * 시설관리 › 물품구매 (공무직 이상, journals type purchase)
 *  - 재고관리와 별개인 구매 증빙: 구매일(= 문서 일자)·구매처·품목(수량·단가)·결제방법(카드/외상)·검수사진·영수증사진
 *  - 공무직이 결재 요청 → 주무관 결재(1단계) → 주무관이 지출 완료 처리
 *  - 금액 집계는 결재중·결재완료 문서 (임시저장·반려 제외)
 */
const PURCHASE_PAY = ['card' => '카드', 'credit' => '외상'];
const PURCHASE_PHOTO_MAX = 1200; // 영수증 글씨가 보이도록 조금 크게

function purchase_empty_item(): array
{
    return ['name' => '', 'spec' => '', 'qty' => 1, 'unit_price' => 0, 'amount' => 0];
}

function purchase_meta(int $journalId): ?array
{
    $st = db()->prepare('SELECT m.*, u.name AS paid_by_name FROM purchase_meta m LEFT JOIN users u ON u.id = m.paid_by WHERE m.journal_id = ?');
    $st->execute([$journalId]);
    return $st->fetch() ?: null;
}

function purchase_is_paid(int $journalId): bool
{
    return !empty(purchase_meta($journalId)['paid_at']);
}

function purchase_load(int $journalId): array
{
    $st = db()->prepare('SELECT * FROM purchase_items WHERE journal_id = ? ORDER BY sort_no, id');
    $st->execute([$journalId]);
    $m = purchase_meta($journalId);
    return ['vendor' => $m['vendor'] ?? '', 'pay_method' => $m['pay_method'] ?? 'card', 'items' => $st->fetchAll()];
}

/** @return array{0: array, 1: string[]} */
function purchase_parse(int $journalId): array
{
    $errors = [];
    $items = [];
    $vendor = mb_substr(trim(post('vendor')), 0, 100);
    $pay = isset(PURCHASE_PAY[post('pay_method')]) ? post('pay_method') : '';
    if ($vendor === '') $errors[] = '구매처를 입력하세요.';
    if ($pay === '') $errors[] = '결제방법(카드·외상)을 고르세요.';
    foreach ((array) ($_POST['it'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $it = [
            'name'       => mb_substr(trim((string) ($row['name'] ?? '')), 0, 100),
            'spec'       => mb_substr(trim((string) ($row['spec'] ?? '')), 0, 100),
            'qty'        => min(999999, to_int($row['qty'] ?? 0)),
            'unit_price' => min(99999999, to_int($row['unit_price'] ?? 0)),
        ];
        if ($it['name'] === '' && $it['unit_price'] === 0) continue; // 비운 줄
        $no = count($items) + 1;
        if ($it['name'] === '') $errors[] = "{$no}번째 품목: 품목명을 입력하세요.";
        if ($it['qty'] === 0) $errors[] = "{$no}번째 품목({$it['name']}): 수량을 입력하세요.";
        if ($it['unit_price'] === 0) $errors[] = "{$no}번째 품목({$it['name']}): 단가를 입력하세요.";
        $it['amount'] = $it['qty'] * $it['unit_price'];
        $it['sort_no'] = $no;
        $items[] = $it;
    }
    if (!$items) $errors[] = '구매 품목을 1개 이상 입력하세요.';
    // 사진: 이미 있는 것(삭제 체크 제외) + 새로 올리는 것이 1장 이상이어야 결재 요청 가능
    if (post('action') === 'submit' || ($journalId && ($j = journal_find($journalId)) && $j['submitted_at'])) {
        $deleted = array_map('intval', (array) ($_POST['delete_photos'] ?? []));
        foreach (['purchase_check' => ['check_photos', '검수사진'], 'purchase_receipt' => ['receipt_photos', '영수증사진']] as $type => [$field, $label]) {
            $kept = $journalId ? count(array_filter(photos_for($type, $journalId), fn($p) => !in_array((int) $p['id'], $deleted, true))) : 0;
            $new = count(array_filter((array) ($_FILES[$field]['error'] ?? []), fn($e) => (int) $e === UPLOAD_ERR_OK));
            if ($kept + $new === 0) $errors[] = "{$label}을 1장 이상 올리세요.";
        }
    }
    return [['vendor' => $vendor, 'pay_method' => $pay ?: 'card', 'items' => $items ?: [purchase_empty_item()]], $errors];
}

/** 트랜잭션 안에서 호출. 품목·구매처 저장 + 검수·영수증 사진 추가·삭제 */
function purchase_save(int $journalId, array $payload): void
{
    $pdo = db();
    $total = array_sum(array_column($payload['items'], 'amount'));
    $pdo->prepare('INSERT INTO purchase_meta (journal_id, vendor, pay_method, total) VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE vendor = VALUES(vendor), pay_method = VALUES(pay_method), total = VALUES(total)')
        ->execute([$journalId, $payload['vendor'], $payload['pay_method'], $total]);
    $pdo->prepare('DELETE FROM purchase_items WHERE journal_id = ?')->execute([$journalId]);
    $ins = $pdo->prepare('INSERT INTO purchase_items (journal_id, sort_no, name, spec, qty, unit_price, amount) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($payload['items'] as $it) {
        $ins->execute([$journalId, $it['sort_no'], $it['name'], $it['spec'] !== '' ? $it['spec'] : null, $it['qty'], $it['unit_price'], $it['amount']]);
    }
    $del = (array) ($_POST['delete_photos'] ?? []);
    photos_delete('purchase_check', $journalId, $del);
    photos_delete('purchase_receipt', $journalId, $del);
    $uid = (int) (current_user()['id'] ?? 0);
    foreach (photos_save_uploaded('purchase_check', $journalId, $uid, PURCHASE_PHOTO_MAX, 'check_photos') as $err) flash($err, 'error');
    foreach (photos_save_uploaded('purchase_receipt', $journalId, $uid, PURCHASE_PHOTO_MAX, 'receipt_photos') as $err) flash($err, 'error');
}

function purchase_item_row(string $key, array $it): void
{
    $n = fn(string $c) => "it[$key][$c]";
    ?>
  <tr data-pc-row>
    <td class="center" data-pc-no></td>
    <td><input name="<?= $n('name') ?>" value="<?= e($it['name']) ?>" maxlength="100" placeholder="예: LED 전구" class="pc-name"></td>
    <td><input name="<?= $n('spec') ?>" value="<?= e($it['spec'] ?? '') ?>" maxlength="100" placeholder="예: 12W 주광색"></td>
    <td><input name="<?= $n('qty') ?>" value="<?= (int) $it['qty'] ?: '' ?>" inputmode="numeric" class="num tiny" data-pc-qty></td>
    <td><input name="<?= $n('unit_price') ?>" value="<?= (int) $it['unit_price'] ? e(number_format((int) $it['unit_price'])) : '' ?>" inputmode="numeric" class="num" data-money data-pc-price placeholder="0"></td>
    <td class="right nowrap" data-pc-amt>0</td>
    <td><button type="button" class="btn small ghost danger" data-pc-del>삭제</button></td>
  </tr>
    <?php
}

function purchase_form(array $payload, ?array $journal): void
{
    $vendors = array_column(db()->query('SELECT vendor, MAX(journal_id) AS k FROM purchase_meta GROUP BY vendor ORDER BY k DESC LIMIT 50')->fetchAll(), 'vendor');
    $id = $journal ? (int) $journal['id'] : null;
    ?>
<div data-pc-form>
  <div class="row">
    <label>구매처<input name="vendor" value="<?= e($payload['vendor']) ?>" maxlength="100" list="pcVendors" placeholder="예: ○○철물" required></label>
    <datalist id="pcVendors"><?php foreach ($vendors as $v): ?><option><?= e($v) ?></option><?php endforeach ?></datalist>
    <div class="prog-paid">
      <span class="label-text">결제방법</span>
      <?php foreach (PURCHASE_PAY as $k => $label): ?>
        <label class="inline-check"><input type="radio" name="pay_method" value="<?= $k ?>" <?= $payload['pay_method'] === $k ? 'checked' : '' ?>> <?= e($label) ?></label>
      <?php endforeach ?>
    </div>
  </div>
  <h3>구매 품목</h3>
  <div class="table-scroll">
  <table class="table pc-table">
    <thead><tr><th>No</th><th>품목</th><th>규격</th><th>수량</th><th>단가(원)</th><th class="right">금액(원)</th><th></th></tr></thead>
    <tbody data-pc-body><?php foreach (array_values($payload['items']) as $i => $it) purchase_item_row((string) $i, $it + purchase_empty_item()) ?></tbody>
    <tfoot><tr><th colspan="5">합계 <span data-pc-count>0</span>품목</th><th class="right"><b data-pc-total>0</b>원</th><th></th></tr></tfoot>
  </table>
  </div>
  <template id="pcRowTpl"><?php purchase_item_row('__KEY__', purchase_empty_item()) ?></template>
  <button type="button" class="btn" data-pc-add>+ 품목 추가</button>

  <div class="grid2 pc-photos">
    <div><h3>검수사진 <small class="muted">같이 구매한 물품을 모아서 1장 이상</small></h3>
      <?php render_photo_editor('purchase_check', $id, PURCHASE_PHOTO_MAX, 'check_photos', '검수사진 추가') ?></div>
    <div><h3>영수증사진 <small class="muted">외상은 거래명세서</small></h3>
      <?php render_photo_editor('purchase_receipt', $id, PURCHASE_PHOTO_MAX, 'receipt_photos', '영수증사진 추가') ?></div>
  </div>
  <p class="muted small">결재 요청하면 주무관이 결재하고 지출을 완료합니다. 검수사진·영수증사진은 각각 1장 이상 있어야 결재 요청할 수 있습니다.</p>
</div>
<script>
(function () {
  const form = document.querySelector('[data-pc-form]');
  const body = form.querySelector('[data-pc-body]');
  const num = (v) => parseInt(String(v).replace(/\D/g, ''), 10) || 0;
  const fmt = (n) => n.toLocaleString('ko-KR');
  function recalc() {
    let total = 0, count = 0;
    body.querySelectorAll('[data-pc-row]').forEach((tr, i) => {
      tr.querySelector('[data-pc-no]').textContent = i + 1;
      const amt = num(tr.querySelector('[data-pc-qty]').value) * num(tr.querySelector('[data-pc-price]').value);
      tr.querySelector('[data-pc-amt]').textContent = fmt(amt);
      if (tr.querySelector('.pc-name').value.trim() !== '') { count++; total += amt; }
    });
    form.querySelector('[data-pc-count]').textContent = count;
    form.querySelector('[data-pc-total]').textContent = fmt(total);
  }
  let seq = Date.now();
  form.querySelector('[data-pc-add]').addEventListener('click', () => {
    body.insertAdjacentHTML('beforeend', document.getElementById('pcRowTpl').innerHTML.replace(/__KEY__/g, 'n' + seq++));
    recalc();
    body.lastElementChild.querySelector('.pc-name').focus();
  });
  body.addEventListener('click', (ev) => {
    if (!ev.target.matches('[data-pc-del]')) return;
    const tr = ev.target.closest('tr');
    if (body.querySelectorAll('[data-pc-row]').length === 1) tr.querySelectorAll('input').forEach((x) => { x.value = ''; });
    else tr.remove();
    recalc();
  });
  body.addEventListener('input', recalc);
  recalc();
})();
</script>
    <?php
}

/** 지출 상태 배지 */
function purchase_pay_badge(?array $m, string $status): string
{
    if (!empty($m['paid_at'])) return '<span class="badge st-approved">지출완료</span>';
    return $status === 'approved' ? '<span class="badge st-pending">지출대기</span>' : '';
}

function purchase_view(array $journal): void
{
    $id = (int) $journal['id'];
    $p = purchase_load($id);
    $m = purchase_meta($id);
    $total = array_sum(array_column($p['items'], 'amount'));
    $user = current_user();
    ?>
  <div class="kpis k4">
    <div class="kpi"><span>구매처</span><b><?= e($p['vendor']) ?></b></div>
    <div class="kpi"><span>결제방법</span><b><?= e(PURCHASE_PAY[$p['pay_method']] ?? '') ?></b></div>
    <div class="kpi"><span>지출</span><b><?= !empty($m['paid_at']) ? '완료' : ($journal['status'] === 'approved' ? '대기' : '결재 후') ?></b>
      <?php if (!empty($m['paid_at'])): ?><small class="muted"><?= e($m['paid_at']) ?> · <?= e($m['paid_by_name'] ?? '') ?></small><?php endif ?></div>
    <div class="kpi total"><span>구매 금액</span><b><?= e(won($total)) ?></b></div>
  </div>
  <div class="table-scroll">
  <table class="table pc-table">
    <thead><tr><th>No</th><th>품목</th><th>규격</th><th class="right">수량</th><th class="right">단가</th><th class="right">금액</th></tr></thead>
    <tbody>
    <?php foreach ($p['items'] as $it): ?>
      <tr><td class="center"><?= (int) $it['sort_no'] ?></td><td><b><?= e($it['name']) ?></b></td><td><?= e($it['spec'] ?? '') ?></td>
        <td class="right"><?= number_format($it['qty']) ?></td><td class="right"><?= number_format($it['unit_price']) ?></td><td class="right"><?= number_format($it['amount']) ?></td></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th colspan="5">합계 <?= count($p['items']) ?>품목</th><th class="right"><?= number_format($total) ?></th></tr></tfoot>
  </table>
  </div>
  <div class="grid2 pc-photos">
    <div><h3>검수사진 <small class="muted"><?= count($c = photos_for('purchase_check', $id)) ?>장</small></h3><?php $c ? render_gallery($c) : print('<p class="muted small">없음</p>') ?></div>
    <div><h3>영수증사진 <small class="muted"><?= count($r = photos_for('purchase_receipt', $id)) ?>장</small></h3><?php $r ? render_gallery($r) : print('<p class="muted small">없음</p>') ?></div>
  </div>
  <?php if ($journal['status'] === 'approved' && can_purchase_pay($user)): ?>
    <form method="post" action="<?= e(url('purchase.php')) ?>" class="pc-pay no-print">
      <?= csrf_field() ?><input type="hidden" name="journal_id" value="<?= $id ?>">
      <?php if (empty($m['paid_at'])): ?>
        <input type="hidden" name="target" value="pay">
        <b>지출 처리</b>
        <label>지출일<input type="date" name="paid_at" value="<?= e(date('Y-m-d')) ?>" required></label>
        <label>메모<input name="paid_note" maxlength="200" placeholder="예: 법인카드 결제 확인 / 외상 대금 이체"></label>
        <button class="btn primary">지출 완료</button>
      <?php else: ?>
        <input type="hidden" name="target" value="unpay">
        <span>지출 완료 <?= e($m['paid_at']) ?> · <?= e($m['paid_by_name'] ?? '') ?><?= $m['paid_note'] ? ' · ' . e($m['paid_note']) : '' ?></span>
        <button class="btn small ghost danger" onclick="return confirm('지출 완료를 취소할까요?')">지출 취소</button>
      <?php endif ?>
    </form>
  <?php elseif (!empty($m['paid_at'])): ?>
    <p class="muted small">지출 완료: <?= e($m['paid_at']) ?> · <?= e($m['paid_by_name'] ?? '') ?><?= $m['paid_note'] ? ' · ' . e($m['paid_note']) : '' ?></p>
  <?php endif ?>
    <?php
}

/** 수정 이력 비교용 */
function purchase_snapshot(array $journal): array
{
    $id = (int) $journal['id'];
    $p = purchase_load($id);
    return [
        '구매처'   => $p['vendor'],
        '결제방법' => PURCHASE_PAY[$p['pay_method']] ?? '',
        '품목'     => array_map(fn($it) => $it['name'] . ($it['spec'] ? " ({$it['spec']})" : '') . ' ' . number_format($it['qty']) . ' × ' . number_format($it['unit_price']) . ' = ' . number_format($it['amount']) . '원', $p['items']),
        '구매 금액' => number_format(array_sum(array_column($p['items'], 'amount'))) . '원',
        '사진'     => '검수 ' . count(photos_for('purchase_check', $id)) . '장 · 영수증 ' . count(photos_for('purchase_receipt', $id)) . '장',
    ];
}
