<?php
defined('APP_ROOT') || exit;

/*
 * 일지 종류별 세부 항목 처리 (불러오기 / 입력값 검사 / 저장 / 입력폼 / 보기)
 *
 * payload 형태
 *   facility : ['facility' => [[facility, result, note], ...]]
 *   sales    : ['lines' => [판매내역...], 'vouchers' => [권종 => 출고매수], 'legacy' => [구버전 항목]]
 *   voucher  : ['vouchers' => [권종 => 입고매수]]
 */

function items_default(string $type): array
{
    return match ($type) {
        'facility' => ['facility' => array_map(
            fn($f) => ['facility' => $f, 'result' => '정상', 'note' => ''],
            config('facilities', [])
        )],
        'sales'    => ['lines' => [], 'vouchers' => array_fill_keys(voucher_denoms(), 0), 'legacy' => []],
        'voucher'  => ['vouchers' => array_fill_keys(voucher_denoms(), 0)],
        default    => [],
    };
}

function items_load(array $journal): array
{
    $id = (int) $journal['id'];
    $q = function (string $sql) use ($id): array {
        $st = db()->prepare($sql);
        $st->execute([$id]);
        return $st->fetchAll();
    };
    $vouchers = function (string $dir) use ($q): array {
        $v = array_fill_keys(voucher_denoms(), 0);
        foreach ($q("SELECT denom, SUM(qty) AS qty FROM voucher_moves WHERE journal_id = ? AND direction = '$dir' GROUP BY denom") as $r) {
            $v[(int) $r['denom']] = (int) $r['qty'];
        }
        return $v;
    };

    return match ($journal['type']) {
        'facility' => ['facility' => $q('SELECT * FROM facility_items WHERE journal_id = ? ORDER BY id')],
        'sales'    => [
            'lines'    => $q('SELECT * FROM sales_lines WHERE journal_id = ? ORDER BY grp DESC, id'),
            'vouchers' => $vouchers('out'),
            'legacy'   => $q('SELECT * FROM sales_items WHERE journal_id = ? ORDER BY id'),
        ],
        'voucher'  => ['vouchers' => $vouchers('in')],
        default    => [],
    };
}

/** @return array{0: array, 1: string[]} [payload, 오류메시지] */
function items_parse(string $type, string $workDate, int $journalId): array
{
    $errors = [];
    $payload = items_default($type);

    if ($type === 'facility') {
        $results = config('facility_results', ['정상']);
        $rows = [];
        foreach ((array) ($_POST['fac'] ?? []) as $row) {
            $item = [
                'facility' => mb_substr(trim((string) ($row['facility'] ?? '')), 0, 100),
                'result'   => in_array($row['result'] ?? '', $results, true) ? $row['result'] : $results[0],
                'note'     => mb_substr(trim((string) ($row['note'] ?? '')), 0, 500),
            ];
            if ($item['facility'] !== '') $rows[] = $item;
        }
        if (!$rows) $errors[] = '점검 항목을 1개 이상 입력하세요.';
        $payload['facility'] = $rows;
    }

    if ($type === 'sales') {
        $products = products_all();
        $lines = [];

        foreach ((array) ($_POST['ticket'] ?? []) as $pid => $row) {
            $p = $products[(int) $pid] ?? null;
            $qty = to_int($row['qty'] ?? 0);
            if (!$p || $p['grp'] !== 'ticket' || $qty === 0) continue;
            $unit = product_unit_price($p);
            $lines[] = [
                'product_id' => (int) $p['id'], 'grp' => 'ticket', 'name' => $p['name'], 'is_free' => (int) $p['is_free'],
                'rate' => null, 'discounted' => 0, 'unit_price' => $unit, 'qty' => $qty, 'guests' => 0, 'amount' => $unit * $qty,
            ];
        }

        foreach ((array) ($_POST['room'] ?? []) as $pid => $row) {
            $p = $products[(int) $pid] ?? null;
            $qty = to_int($row['qty'] ?? 0);
            if (!$p || $p['grp'] !== 'room' || $qty === 0) continue;
            $rate = isset(RATE_TYPES[$row['rate'] ?? '']) ? $row['rate'] : rate_for_date($workDate);
            $dc = !empty($row['dc']);
            $guests = to_int($row['guests'] ?? 0);
            $max = (int) $p['max_people'] * $qty;
            if ($guests < 1) {
                $errors[] = "{$p['name']}: 입실인원을 입력하세요.";
            } elseif ($max > 0 && $guests > $max) {
                $errors[] = "{$p['name']}: 입실인원 {$guests}명이 최대인원({$max}명)을 넘습니다.";
            }
            $unit = product_unit_price($p, $rate, $dc);
            $lines[] = [
                'product_id' => (int) $p['id'], 'grp' => 'room', 'name' => $p['name'], 'is_free' => 0,
                'rate' => $rate, 'discounted' => (int) ($dc && $unit !== product_unit_price($p, $rate)),
                'unit_price' => $unit, 'qty' => $qty, 'guests' => $guests, 'amount' => $unit * $qty,
            ];
        }
        $payload['lines'] = $lines;

        foreach (voucher_denoms() as $d) {
            $payload['vouchers'][$d] = to_int($_POST['voucher_out'][$d] ?? 0);
        }

        // 매출보고는 하루 1건 (중복 집계 방지)
        if (valid_date($workDate)) {
            $st = db()->prepare("SELECT id FROM journals WHERE type = 'sales' AND work_date = ? AND id <> ?");
            $st->execute([$workDate, $journalId]);
            if ($dup = $st->fetchColumn()) {
                $errors[] = "해당 날짜의 매출보고가 이미 있습니다. (문서번호 $dup)";
            }
        }
        if (!$lines) $errors[] = '판매 수량을 1개 이상 입력하세요.';
    }

    if ($type === 'voucher') {
        foreach (voucher_denoms() as $d) {
            $payload['vouchers'][$d] = to_int($_POST['voucher_in'][$d] ?? 0);
        }
        if (array_sum($payload['vouchers']) === 0) $errors[] = '입고 매수를 입력하세요.';
    }

    return [$payload, $errors];
}

/** 트랜잭션 안에서 호출 */
function items_save(int $id, string $type, array $payload): void
{
    $pdo = db();

    if ($type === 'facility') {
        $pdo->prepare('DELETE FROM facility_items WHERE journal_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO facility_items (journal_id, facility, result, note) VALUES (?, ?, ?, ?)');
        foreach ($payload['facility'] as $it) {
            $ins->execute([$id, $it['facility'], $it['result'], $it['note']]);
        }
    }

    if ($type === 'sales') {
        $pdo->prepare('DELETE FROM sales_lines WHERE journal_id = ?')->execute([$id]);
        $ins = $pdo->prepare(
            'INSERT INTO sales_lines (journal_id, product_id, grp, name, is_free, rate, discounted, unit_price, qty, guests, amount)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($payload['lines'] as $l) {
            $ins->execute([$id, $l['product_id'], $l['grp'], $l['name'], $l['is_free'], $l['rate'],
                $l['discounted'], $l['unit_price'], $l['qty'], $l['guests'], $l['amount']]);
        }
    }

    if ($type === 'sales' || $type === 'voucher') {
        $dir = $type === 'sales' ? 'out' : 'in';
        $pdo->prepare('DELETE FROM voucher_moves WHERE journal_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO voucher_moves (journal_id, direction, denom, qty) VALUES (?, ?, ?, ?)');
        foreach ($payload['vouchers'] as $denom => $qty) {
            if ($qty > 0) $ins->execute([$id, $dir, $denom, $qty]);
        }
    }
}

/* ───────────────────────── 입력 폼 ───────────────────────── */

function items_form(string $type, array $payload, string $workDate, ?array $journal): void
{
    if ($type === 'facility') {
        $rows = $payload['facility'];
        $rows[] = ['facility' => '', 'result' => '정상', 'note' => ''];
        $rows[] = ['facility' => '', 'result' => '정상', 'note' => ''];
        ?>
<div class="table-scroll">
<table class="table">
  <thead><tr><th>시설</th><th>점검결과</th><th>내용 / 조치사항</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $i => $it): ?>
    <tr>
      <td><input name="fac[<?= $i ?>][facility]" value="<?= e($it['facility']) ?>" placeholder="시설명 추가"></td>
      <td><select name="fac[<?= $i ?>][result]">
        <?php foreach (config('facility_results', ['정상']) as $r): ?>
          <option <?= $it['result'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
        <?php endforeach ?>
      </select></td>
      <td><input name="fac[<?= $i ?>][note]" value="<?= e($it['note']) ?>"></td>
    </tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>
        <?php
        return;
    }

    if ($type === 'voucher') {
        voucher_qty_table('voucher_in', '입고 매수', $payload['vouchers'], voucher_stock());
        return;
    }

    if ($type !== 'sales') return;

    $byProduct = [];
    foreach ($payload['lines'] as $l) $byProduct[(int) $l['product_id']] = $l;
    $ids = array_keys($byProduct);
    $tickets = products_for_form('ticket', $ids);
    $rooms = products_for_form('room', $ids);
    $defaultRate = rate_for_date($workDate);

    if (!$tickets && !$rooms): ?>
  <div class="flash flash-error">등록된 판매 상품이 없습니다. 관리자에게 <b>상품관리</b>에서 입장권·객실을 등록해 달라고 요청하세요.</div>
    <?php endif ?>

<script>window.PEAK_SEASONS = <?= json_encode(config('peak_seasons', [['07-15', '08-24']])) ?>;</script>
<div data-sales-form>
  <?php if ($tickets): ?>
  <h3>입장권 판매</h3>
  <div class="table-scroll">
  <table class="table" data-ticket-table>
    <thead><tr><th>상품</th><th>구분</th><th class="right">단가</th><th>수량(매)</th><th class="right">금액</th></tr></thead>
    <tbody>
    <?php foreach ($tickets as $pid => $p): $l = $byProduct[$pid] ?? null; ?>
      <tr data-price="<?= product_unit_price($p) ?>" data-free="<?= (int) $p['is_free'] ?>">
        <td><?= e($p['name']) ?><?= $p['is_active'] ? '' : ' <small class="muted">(판매중지)</small>' ?></td>
        <td><?= $p['is_free'] ? '<span class="badge">무료</span>' : '유료' ?></td>
        <td class="right"><?= number_format(product_unit_price($p)) ?></td>
        <td><input name="ticket[<?= $pid ?>][qty]" value="<?= e($l['qty'] ?? '') ?>" inputmode="numeric" class="num short" data-money data-qty></td>
        <td class="right" data-line-amount>0</td>
      </tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th colspan="3">입장권 합계 <small class="muted" data-ticket-breakdown></small></th><th class="right" data-ticket-qty>0</th><th class="right" data-ticket-amount>0</th></tr></tfoot>
  </table>
  </div>
  <?php endif ?>

  <?php if ($rooms): ?>
  <h3>객실 판매</h3>
  <p class="muted small">요금구분은 날짜에 따라 자동 선택됩니다(금·토, 성수기 = 주말·성수기). 할인 대상이면 '할인'에 체크하세요.</p>
  <div class="table-scroll">
  <table class="table" data-room-table>
    <thead><tr><th>객실</th><th>요금구분</th><th>할인</th><th class="right">단가</th><th>객실수</th><th>입실인원</th><th class="right">금액</th></tr></thead>
    <tbody>
    <?php foreach ($rooms as $pid => $p): $l = $byProduct[$pid] ?? null; $rate = $l['rate'] ?? $defaultRate; ?>
      <tr data-weekday="<?= (int) $p['price'] ?>" data-weekend="<?= (int) $p['price_weekend'] ?>"
          data-dc-weekday="<?= (int) $p['dc_weekday'] ?>" data-dc-weekend="<?= (int) $p['dc_weekend'] ?>" data-max="<?= (int) $p['max_people'] ?>">
        <td><?= e($p['name']) ?> <small class="muted">최대 <?= (int) $p['max_people'] ?>인</small><?= $p['is_active'] ? '' : ' <small class="muted">(판매중지)</small>' ?></td>
        <td><select name="room[<?= $pid ?>][rate]" data-rate>
          <?php foreach (RATE_TYPES as $k => $label): ?><option value="<?= $k ?>" <?= $rate === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
        </select></td>
        <td class="center"><input type="checkbox" name="room[<?= $pid ?>][dc]" value="1" data-dc <?= !empty($l['discounted']) ? 'checked' : '' ?>></td>
        <td class="right" data-unit>0</td>
        <td><input name="room[<?= $pid ?>][qty]" value="<?= e($l['qty'] ?? '') ?>" inputmode="numeric" class="num short" data-money data-qty></td>
        <td><input name="room[<?= $pid ?>][guests]" value="<?= e(($l['guests'] ?? 0) ?: '') ?>" inputmode="numeric" class="num short" data-money data-guests></td>
        <td class="right" data-line-amount>0</td>
      </tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th colspan="4">객실 합계</th><th class="right" data-room-qty>0</th><th class="right" data-room-guests>0</th><th class="right" data-room-amount>0</th></tr></tfoot>
  </table>
  </div>

  <h3>지역상품권 환급(출고)</h3>
  <?php
    // 수정 중인 보고서의 기존 출고분은 이미 재고에서 빠져 있으므로 되돌려서 보여준다
    $stock = voucher_stock();
    if ($journal && $journal['status'] !== 'draft') {
        foreach (items_load($journal)['vouchers'] as $d => $q) $stock[$d] += $q;
    }
    voucher_qty_table('voucher_out', '출고 매수', $payload['vouchers'], $stock);
  ?>
  <?php endif ?>

  <div class="grand">매출 합계 <b data-grand>0원</b></div>
</div>
    <?php
}

/** 권종별 매수 입력표 (입고/출고 공용) */
function voucher_qty_table(string $field, string $label, array $values, array $stock): void
{
    ?>
<div class="table-scroll">
<table class="table" data-voucher-table>
  <thead><tr><th>권종</th><th class="right">현재고</th><th><?= e($label) ?></th><th class="right">금액</th></tr></thead>
  <tbody>
  <?php foreach (voucher_denoms() as $d): ?>
    <tr data-denom="<?= $d ?>" data-stock="<?= (int) ($stock[$d] ?? 0) ?>">
      <td><?= e(denom_label($d)) ?></td>
      <td class="right"><?= number_format($stock[$d] ?? 0) ?>매</td>
      <td><input name="<?= $field ?>[<?= $d ?>]" value="<?= e(($values[$d] ?? 0) ?: '') ?>" inputmode="numeric" class="num short" data-money data-vqty></td>
      <td class="right" data-vamount>0</td>
    </tr>
  <?php endforeach ?>
  </tbody>
  <tfoot><tr><th colspan="3">합계 <small class="warn" data-vwarn></small></th><th class="right" data-vtotal>0</th></tr></tfoot>
</table>
</div>
    <?php
}

/* ───────────────────────── 보기 ───────────────────────── */

function items_view(array $journal): void
{
    $payload = items_load($journal);

    if ($journal['type'] === 'facility'): ?>
<div class="table-scroll">
<table class="table">
  <thead><tr><th>시설</th><th>점검결과</th><th>내용 / 조치사항</th></tr></thead>
  <tbody>
  <?php foreach ($payload['facility'] as $f): ?>
    <tr><td><?= e($f['facility']) ?></td><td><span class="result r-<?= e($f['result']) ?>"><?= e($f['result']) ?></span></td><td><?= e($f['note']) ?></td></tr>
  <?php endforeach ?>
  </tbody>
</table>
</div>
    <?php return; endif;

    if ($journal['type'] === 'voucher') {
        voucher_view_table($payload['vouchers'], '입고');
        if ($journal['status'] !== 'approved') {
            echo '<p class="muted small">결재완료 후 상품권 재고에 반영됩니다.</p>';
        }
        return;
    }

    if ($journal['type'] !== 'sales') return;

    $tickets = array_filter($payload['lines'], fn($l) => $l['grp'] === 'ticket');
    $rooms = array_filter($payload['lines'], fn($l) => $l['grp'] === 'room');
    $sum = fn(array $rows, string $k) => array_sum(array_column($rows, $k));
    $grand = $sum($payload['lines'], 'amount');

    if ($tickets):
        $free = $sum(array_filter($tickets, fn($l) => $l['is_free']), 'qty'); ?>
  <h3>입장권 판매</h3>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>상품</th><th>구분</th><th class="right">단가</th><th class="right">수량</th><th class="right">금액</th></tr></thead>
    <tbody>
    <?php foreach ($tickets as $l): ?>
      <tr><td><?= e($l['name']) ?></td><td><?= $l['is_free'] ? '무료' : '유료' ?></td>
        <td class="right"><?= number_format($l['unit_price']) ?></td><td class="right"><?= number_format($l['qty']) ?></td>
        <td class="right"><?= number_format($l['amount']) ?></td></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th colspan="3">합계 <small class="muted">유료 <?= number_format($sum($tickets, 'qty') - $free) ?> · 무료 <?= number_format($free) ?></small></th>
      <th class="right"><?= number_format($sum($tickets, 'qty')) ?></th><th class="right"><?= e(won($sum($tickets, 'amount'))) ?></th></tr></tfoot>
  </table>
  </div>
    <?php endif;

    if ($rooms): ?>
  <h3>객실 판매</h3>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>객실</th><th>요금구분</th><th class="right">단가</th><th class="right">객실수</th><th class="right">입실인원</th><th class="right">금액</th></tr></thead>
    <tbody>
    <?php foreach ($rooms as $l): ?>
      <tr><td><?= e($l['name']) ?></td>
        <td><?= e(RATE_TYPES[$l['rate']] ?? '') ?><?= $l['discounted'] ? ' <span class="badge st-pending">할인</span>' : '' ?></td>
        <td class="right"><?= number_format($l['unit_price']) ?></td><td class="right"><?= number_format($l['qty']) ?></td>
        <td class="right"><?= number_format($l['guests']) ?></td><td class="right"><?= number_format($l['amount']) ?></td></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th colspan="3">합계</th><th class="right"><?= number_format($sum($rooms, 'qty')) ?></th>
      <th class="right"><?= number_format($sum($rooms, 'guests')) ?></th><th class="right"><?= e(won($sum($rooms, 'amount'))) ?></th></tr></tfoot>
  </table>
  </div>
    <?php endif;

    if (array_sum($payload['vouchers']) > 0) {
        echo '<h3>지역상품권 환급(출고)</h3>';
        voucher_view_table($payload['vouchers'], '출고');
    }

    if ($payload['legacy']):
        $grand += array_sum(array_map(fn($s) => $s['card'] + $s['cash'] + $s['transfer'], $payload['legacy'])); ?>
  <h3>매출 (이전 형식)</h3>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>구분</th><th class="right">건수</th><th class="right">카드</th><th class="right">현금</th><th class="right">계좌이체</th></tr></thead>
    <tbody>
    <?php foreach ($payload['legacy'] as $s): ?>
      <tr><td><?= e($s['category']) ?></td><td class="right"><?= number_format($s['qty']) ?></td><td class="right"><?= number_format($s['card']) ?></td>
        <td class="right"><?= number_format($s['cash']) ?></td><td class="right"><?= number_format($s['transfer']) ?></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
    <?php endif ?>
  <div class="grand">매출 합계 <b><?= e(won($grand)) ?></b></div>
    <?php
}

function voucher_view_table(array $vouchers, string $label): void
{
    ?>
<div class="table-scroll">
<table class="table">
  <thead><tr><th>권종</th><th class="right"><?= e($label) ?> 매수</th><th class="right">금액</th></tr></thead>
  <tbody>
  <?php foreach ($vouchers as $d => $q): if (!$q) continue; ?>
    <tr><td><?= e(denom_label($d)) ?></td><td class="right"><?= number_format($q) ?></td><td class="right"><?= number_format($d * $q) ?></td></tr>
  <?php endforeach ?>
  </tbody>
  <tfoot><tr><th colspan="2">합계</th><th class="right"><?= e(won(voucher_amount($vouchers))) ?></th></tr></tfoot>
</table>
</div>
    <?php
}

/** 매출보고 1건의 매출 합계 SQL (별칭 j) — 달력 등에서 사용 */
const SALES_AMOUNT_SQL = '(SELECT COALESCE(SUM(l.amount), 0) FROM sales_lines l WHERE l.journal_id = j.id)
    + (SELECT COALESCE(SUM(s.card + s.cash + s.transfer), 0) FROM sales_items s WHERE s.journal_id = j.id)';
