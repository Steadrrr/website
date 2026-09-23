<?php
defined('APP_ROOT') || exit;

/*
 * 일지 종류별 세부 항목 처리 (불러오기 / 입력값 검사 / 저장 / 입력폼 / 보기)
 *
 * payload 형태
 *   facility : ['team_id' => 관리팀, 'facility' => [[facility_id, area, facility, result, note], ...]]
 *   sales    : ['lines' => [판매내역... (객실은 'vouchers' => [권종 => 환급매수])], 'ticket_cash' => 입장권 현금,
 *               'vouchers' => [권종 => 객실 미지정 환급매수 (이전 버전 자료)], 'legacy' => [구버전 항목], 'warnings' => [...]]
 *   voucher  : ['vouchers' => [권종 => 입고매수]]
 */

function items_default(string $type, ?int $teamId = null): array
{
    return match ($type) {
        'facility' => ['team_id' => $teamId, 'facility' => []], // 등록 시설은 폼에서 채움
        'sales'    => ['lines' => [], 'ticket_cash' => 0, 'vouchers' => array_fill_keys(voucher_denoms(), 0), 'legacy' => []],
        'voucher'  => ['vouchers' => array_fill_keys(voucher_denoms(), 0)],
        default    => is_program_type($type) ? ['sessions' => [program_empty_session()]] : [],
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

    if (is_program_type($journal['type'])) return program_load($id);

    if ($journal['type'] === 'sales') {
        $lines = $q('SELECT * FROM sales_lines WHERE journal_id = ? ORDER BY grp DESC, id');
        $unassigned = array_fill_keys(voucher_denoms(), 0);
        $byLine = [];
        foreach ($q("SELECT line_id, denom, SUM(qty) AS qty FROM voucher_moves WHERE journal_id = ? AND direction = 'out' GROUP BY line_id, denom") as $r) {
            if ($r['line_id']) $byLine[(int) $r['line_id']][(int) $r['denom']] = (int) $r['qty'];
            else $unassigned[(int) $r['denom']] = (int) $r['qty'];
        }
        foreach ($lines as &$l) {
            $l['vouchers'] = array_replace(array_fill_keys(voucher_denoms(), 0), $byLine[(int) $l['id']] ?? []);
        }
        unset($l);
        return [
            'lines'       => $lines,
            'ticket_cash' => (int) ($q('SELECT ticket_cash FROM sales_meta WHERE journal_id = ?')[0]['ticket_cash'] ?? 0),
            'vouchers'    => $unassigned,
            'legacy'      => $q('SELECT * FROM sales_items WHERE journal_id = ? ORDER BY id'),
        ];
    }

    return match ($journal['type']) {
        'facility' => ['team_id' => $journal['team_id'] ? (int) $journal['team_id'] : null, 'facility' => $q('SELECT * FROM facility_items WHERE journal_id = ? ORDER BY id')],
        'voucher'  => ['vouchers' => $vouchers('in')],
        default    => [],
    };
}

/** @return array{0: array, 1: string[]} [payload, 오류메시지] */
function items_parse(string $type, string $workDate, int $journalId): array
{
    if (is_program_type($type)) return program_parse($type, $workDate, $journalId);

    $errors = [];
    $teamId = (int) ($_POST['team_id'] ?? 0);
    $payload = items_default($type, isset(teams_all()[$teamId]) ? $teamId : null);

    if ($type === 'facility') {
        $results = config('facility_results', ['정상']);
        $registered = facilities_list(null, false);
        $rows = [];
        foreach ((array) ($_POST['fac'] ?? []) as $row) {
            $f = $registered[(int) ($row['facility_id'] ?? 0)] ?? null;
            $item = [
                'facility_id' => $f ? (int) $f['id'] : null,
                'area'     => $f['area'] ?? null,
                'facility' => $f ? $f['name'] : mb_substr(trim((string) ($row['facility'] ?? '')), 0, 100),
                'result'   => in_array($row['result'] ?? '', $results, true) ? $row['result'] : $results[0],
                'note'     => mb_substr(trim((string) ($row['note'] ?? '')), 0, 500),
            ];
            if ($item['facility'] !== '') $rows[] = $item;
        }
        if (!$payload['team_id']) $errors[] = '관리팀을 선택하세요.';
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
            [$unit, $season] = ticket_price($p, $workDate);
            $lines[] = [
                'product_id' => (int) $p['id'], 'grp' => 'ticket', 'name' => $p['name'], 'is_free' => (int) $p['is_free'],
                'rate' => null, 'season' => $season, 'discounted' => 0, 'unit_price' => $unit, 'qty' => $qty, 'guests' => 0, 'amount' => $unit * $qty,
            ];
        }

        // 객실: 객실명마다 한 실이므로 입실인원을 입력하면 판매로 본다
        $payload['warnings'] = [];
        foreach ((array) ($_POST['room'] ?? []) as $pid => $row) {
            $p = $products[(int) $pid] ?? null;
            if (!$p || $p['grp'] !== 'room') continue;
            $guests = to_int($row['guests'] ?? 0);
            $vouchers = [];
            foreach (voucher_denoms() as $d) $vouchers[$d] = to_int($row['v'][$d] ?? 0);
            if ($guests === 0) {
                if (array_sum($vouchers) > 0) $errors[] = "{$p['name']}: 상품권 환급을 입력했지만 입실인원이 없습니다.";
                continue;
            }
            $max = (int) $p['max_people'];
            if ($max > 0 && $guests > $max) {
                $errors[] = "{$p['name']}: 입실인원 {$guests}명이 최대인원({$max}명)을 넘습니다.";
            }
            $rate = isset(RATE_TYPES[$row['rate'] ?? '']) ? $row['rate'] : rate_for_date($workDate);
            $dc = !empty($row['dc']);
            $unit = room_price($p, $rate, $dc);
            $roomSeason = $rate === 'peak' ? season_for('room', $workDate) : null;
            $refund = voucher_amount($vouchers);
            $expected = room_refund($p, $rate);
            if ($refund !== $expected) {
                $payload['warnings'][] = "{$p['name']}: 지역상품권 환급액 " . number_format($refund) . '원이 ' . RATE_TYPES[$rate] . ' 기준 환급액 ' . number_format($expected) . '원과 다릅니다.';
            }
            $lines[] = [
                'product_id' => (int) $p['id'], 'grp' => 'room', 'name' => $p['name'], 'is_free' => 0,
                'rate' => $rate, 'season' => $roomSeason['name'] ?? null, 'discounted' => (int) ($dc && $unit !== room_price($p, $rate)),
                'unit_price' => $unit, 'qty' => 1, 'guests' => $guests, 'amount' => $unit,
                'refund_expected' => $expected, 'vouchers' => $vouchers,
            ];
        }
        // 시설대관: 대관 시간(2시간/4시간/4시간 이상) + 야간 추가, 건수
        foreach ((array) ($_POST['rental'] ?? []) as $pid => $row) {
            $p = $products[(int) $pid] ?? null;
            if (!$p || $p['grp'] !== 'rental') continue;
            $time = isset(RENT_TIMES[$row['time'] ?? '']) ? $row['time'] : null;
            $night = !empty($row['night']);
            $qty = to_int($row['qty'] ?? 0);
            if (!$time && !$night) {
                if ($qty > 0) $errors[] = "{$p['name']}: 대관 시간 또는 야간 사용을 고르세요.";
                continue;
            }
            $qty = max(1, $qty);
            $unit = rental_price($p, $time, $night);
            $lines[] = [
                'product_id' => (int) $p['id'], 'grp' => 'rental', 'name' => $p['name'], 'is_free' => 0, 'rate' => null, 'season' => null,
                'discounted' => 0, 'unit_price' => $unit, 'qty' => $qty, 'guests' => 0, 'amount' => $unit * $qty,
                'rent_time' => $time, 'night' => (int) $night, 'dc_pct' => 0,
            ];
        }

        // 대관 숙박시설: 정액 요금, 할인율(%) 입력, 건수
        foreach ((array) ($_POST['lodge'] ?? []) as $pid => $row) {
            $p = $products[(int) $pid] ?? null;
            if (!$p || $p['grp'] !== 'lodge') continue;
            $qty = to_int($row['qty'] ?? 0);
            $pctRaw = trim((string) ($row['dc'] ?? ''));
            $pct = $pctRaw === '' ? 0 : (int) $pctRaw;
            if ($qty === 0) continue;
            if ($pct < 0 || $pct > 100 || ($pctRaw !== '' && !ctype_digit($pctRaw))) {
                $errors[] = "{$p['name']}: 할인율은 0~100 사이 숫자로 입력하세요.";
                continue;
            }
            $unit = lodge_price($p, $pct);
            $lines[] = [
                'product_id' => (int) $p['id'], 'grp' => 'lodge', 'name' => $p['name'], 'is_free' => 0, 'rate' => null, 'season' => null,
                'discounted' => (int) ($pct > 0), 'unit_price' => $unit, 'qty' => $qty, 'guests' => 0, 'amount' => $unit * $qty,
                'rent_time' => null, 'night' => 0, 'dc_pct' => $pct,
            ];
        }
        $payload['lines'] = $lines;

        // 입장권 현금 수입 (나머지는 카드로 본다)
        $ticketAmount = array_sum(array_map(fn($l) => $l['grp'] === 'ticket' ? $l['amount'] : 0, $lines));
        $payload['ticket_cash'] = to_int($_POST['ticket_cash'] ?? 0);
        if ($payload['ticket_cash'] > $ticketAmount) {
            $errors[] = '입장권 현금 금액이 입장권 판매금액(' . number_format($ticketAmount) . '원)보다 큽니다.';
        }

        // 이전 버전 자료의 객실 미지정 환급분 (있을 때만 화면에 나옴)
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
    if (is_program_type($type)) {
        program_save($id, $payload);
        return;
    }

    if ($type === 'facility') {
        $pdo->prepare('DELETE FROM facility_items WHERE journal_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO facility_items (journal_id, facility_id, area, facility, result, note) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($payload['facility'] as $it) {
            $ins->execute([$id, $it['facility_id'] ?? null, $it['area'] ?? null, $it['facility'], $it['result'], $it['note']]);
        }
    }

    if ($type === 'sales' || $type === 'voucher') {
        $pdo->prepare('DELETE FROM voucher_moves WHERE journal_id = ?')->execute([$id]);
    }
    $moveIns = $pdo->prepare('INSERT INTO voucher_moves (journal_id, line_id, direction, denom, qty) VALUES (?, ?, ?, ?, ?)');

    if ($type === 'sales') {
        $pdo->prepare('DELETE FROM sales_lines WHERE journal_id = ?')->execute([$id]);
        $ins = $pdo->prepare(
            'INSERT INTO sales_lines (journal_id, product_id, grp, name, is_free, rate, season, discounted, unit_price, qty, guests, refund_expected, rent_time, night, dc_pct, amount)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($payload['lines'] as $l) {
            $ins->execute([$id, $l['product_id'], $l['grp'], $l['name'], $l['is_free'], $l['rate'], $l['season'] ?? null,
                $l['discounted'], $l['unit_price'], $l['qty'], $l['guests'], $l['refund_expected'] ?? null,
                $l['rent_time'] ?? null, (int) ($l['night'] ?? 0), (int) ($l['dc_pct'] ?? 0), $l['amount']]);
            $lineId = (int) $pdo->lastInsertId();
            foreach ($l['vouchers'] ?? [] as $denom => $qty) {
                if ($qty > 0) $moveIns->execute([$id, $lineId, 'out', $denom, $qty]);
            }
        }
        $pdo->prepare('INSERT INTO sales_meta (journal_id, ticket_cash) VALUES (?, ?) ON DUPLICATE KEY UPDATE ticket_cash = VALUES(ticket_cash)')
            ->execute([$id, (int) $payload['ticket_cash']]);
    }

    if ($type === 'sales' || $type === 'voucher') {
        foreach ($payload['vouchers'] as $denom => $qty) {
            if ($qty > 0) $moveIns->execute([$id, null, $type === 'sales' ? 'out' : 'in', $denom, $qty]);
        }
    }
}

/* ───────────────────────── 입력 폼 ───────────────────────── */

function items_form(string $type, array $payload, string $workDate, ?array $journal): void
{
    if (is_program_type($type)) {
        program_form($type, $payload, $journal);
        return;
    }

    if ($type === 'facility') {
        facility_form_rows($payload);
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
    $rentals = products_for_form('rental', $ids);
    $lodges = products_for_form('lodge', $ids);
    $defaultRate = rate_for_date($workDate);

    if (!$tickets && !$rooms && !$rentals && !$lodges): ?>
  <div class="flash flash-error">등록된 판매 상품이 없습니다. 관리자에게 <b>상품관리</b>에서 입장권·객실·시설대관을 등록해 달라고 요청하세요.</div>
    <?php endif ?>

<script>window.SEASONS = <?= json_encode(array_values(array_map(
    fn($s) => ['id' => (int) $s['id'], 'grp' => $s['grp'], 'label' => season_label($s), 'start' => $s['start_md'], 'end' => $s['end_md']],
    array_filter(seasons_all(), fn($s) => $s['is_active'])
)), JSON_UNESCAPED_UNICODE) ?>;</script>
<div data-sales-form>
  <?php if ($tickets): ?>
  <?php $ts = season_for('ticket', $workDate); ?>
  <h3>입장권 판매 <small class="season-tag" data-ticket-season><?= $ts ? e(season_label($ts)) . ' 요금 적용' : '' ?></small></h3>
  <div class="table-scroll">
  <table class="table" data-ticket-table>
    <thead><tr><th>상품</th><th>구분</th><th class="right">단가</th><th>수량(매)</th><th class="right">금액</th></tr></thead>
    <tbody>
    <?php foreach ($tickets as $pid => $p):
        $l = $byProduct[$pid] ?? null;
        $sp = [];
        foreach (season_prices() as $sid => $prices) if (isset($prices[$pid])) $sp[$sid] = $prices[$pid]; ?>
      <tr data-base="<?= $p['is_free'] ? 0 : (int) $p['price'] ?>" data-price="<?= ticket_price($p, $workDate)[0] ?>" data-free="<?= (int) $p['is_free'] ?>"
          data-seasons="<?= e(json_encode($sp ?: new stdClass())) ?>">
        <td><?= e($p['name']) ?><?= $p['is_active'] ? '' : ' <small class="muted">(판매중지)</small>' ?></td>
        <td><?= $p['is_free'] ? '<span class="badge">무료</span>' : '유료' ?></td>
        <td class="right" data-unit><?= number_format(ticket_price($p, $workDate)[0]) ?></td>
        <td><input name="ticket[<?= $pid ?>][qty]" value="<?= e($l['qty'] ?? '') ?>" inputmode="numeric" class="num short" data-money data-qty></td>
        <td class="right" data-line-amount>0</td>
      </tr>
    <?php endforeach ?>
    </tbody>
    <tfoot>
      <tr><th colspan="3">입장권 합계 <small class="muted" data-ticket-breakdown></small></th><th class="right" data-ticket-qty>0</th><th class="right" data-ticket-amount>0</th></tr>
      <tr class="pay-row"><td colspan="3" class="right">결제수단 · <b>현금</b></td>
        <td colspan="2"><input name="ticket_cash" value="<?= e(($payload['ticket_cash'] ?? 0) ? number_format($payload['ticket_cash']) : '') ?>" inputmode="numeric" class="num" data-money data-ticket-cash placeholder="0"></td></tr>
      <tr class="pay-row"><td colspan="3" class="right"><b>카드</b> <small class="muted">(합계 − 현금, 자동)</small></td><td colspan="2" class="right" data-ticket-card>0</td></tr>
    </tfoot>
  </table>
  </div>
  <?php endif ?>

  <?php if ($rooms):
    $denoms = voucher_denoms();
    $rateSeason = season_for('room', $workDate);
    // 수정 중인 보고서의 기존 환급분은 이미 재고에서 빠져 있으므로 되돌려서 보여준다
    $stock = voucher_stock();
    if ($journal && $journal['status'] !== 'draft') {
        foreach (journal_vouchers_out((int) $journal['id']) as $d => $q) $stock[$d] += $q;
    }
    $unassigned = array_sum($payload['vouchers'] ?? []) > 0; ?>
  <script>window.ROOM_DC = <?= json_encode(array_map(fn($k) => room_dc_pct($k), array_combine(array_keys(RATE_TYPES), array_keys(RATE_TYPES)))) ?>;</script>
  <h3>객실 판매 · 지역상품권 환급</h3>
  <p class="muted small">
    판매한 객실의 <b>입실인원</b>을 입력하세요. 요금구분은 날짜로 자동 선택됩니다(성수기 기간 → 성수기, 금·토 → 비수기 주말).
    할인 대상이면 '할인'에 체크하세요 (<?= e(implode(', ', array_map(fn($k, $v) => $v . ' ' . room_dc_pct($k) . '%', array_keys(RATE_TYPES), RATE_TYPES))) ?>).
    지역상품권은 환급한 권종별 매수를 입력하며, 객실·요금구분별 기준 환급액과 다르면 붉게 표시되고 저장할 때 알려 드립니다.
  </p>
  <div class="table-scroll">
  <table class="table room-table" data-room-table>
    <thead>
      <tr><th rowspan="2">객실</th><th rowspan="2">요금구분</th><th rowspan="2">할인</th><th rowspan="2" class="right">단가</th><th rowspan="2">입실인원</th><th rowspan="2" class="right">금액</th>
        <th colspan="<?= count($denoms) + 1 ?>" class="center refund-head">지역상품권 환급 (매수)</th></tr>
      <tr><?php foreach ($denoms as $d): ?><th class="refund-head"><?= e(denom_label($d)) ?></th><?php endforeach ?><th class="right refund-head">환급액 / 기준</th></tr>
    </thead>
    <tbody>
    <?php foreach ($rooms as $pid => $p): $l = $byProduct[$pid] ?? null; $rate = $l['rate'] ?? rate_for_date($workDate); ?>
      <tr data-weekday="<?= (int) $p['price'] ?>" data-weekend="<?= (int) $p['price_weekend'] ?>" data-peak="<?= (int) $p['price_peak'] ?>"
          data-max="<?= (int) $p['max_people'] ?>" data-refund-weekday="<?= room_refund($p, 'weekday') ?>" data-refund-weekend="<?= room_refund($p, 'weekend') ?>" data-refund-peak="<?= room_refund($p, 'peak') ?>" data-name="<?= e($p['name']) ?>">
        <td><?= e($p['name']) ?> <small class="muted">최대 <?= (int) $p['max_people'] ?>인</small><?= $p['is_active'] ? '' : ' <small class="muted">(판매중지)</small>' ?></td>
        <td><select name="room[<?= $pid ?>][rate]" data-rate>
          <?php foreach (RATE_TYPES as $k => $label): ?><option value="<?= $k ?>" <?= $rate === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
        </select></td>
        <td class="nowrap"><label class="inline-check dc-check"><input type="checkbox" name="room[<?= $pid ?>][dc]" value="1" data-dc <?= !empty($l['discounted']) ? 'checked' : '' ?>><span data-dc-pct></span></label></td>
        <td class="right" data-unit>0</td>
        <td><input name="room[<?= $pid ?>][guests]" value="<?= e(($l['guests'] ?? 0) ?: '') ?>" inputmode="numeric" class="num tiny" data-money data-guests></td>
        <td class="right" data-line-amount>0</td>
        <?php foreach ($denoms as $d): ?>
          <td class="refund-cell"><input name="room[<?= $pid ?>][v][<?= $d ?>]" value="<?= e(($l['vouchers'][$d] ?? 0) ?: '') ?>" inputmode="numeric" class="num tiny" data-money data-vdenom="<?= $d ?>"></td>
        <?php endforeach ?>
        <td class="right refund-cell nowrap"><b data-refund-amt>0</b><br><small class="muted">기준 <span data-refund-base><?= number_format(room_refund($p, $rate)) ?></span></small></td>
      </tr>
    <?php endforeach ?>
    <?php if ($unassigned): ?>
      <tr class="unassigned"><td colspan="6">객실 미지정 환급 <small class="muted">(이전 버전에서 입력한 자료)</small></td>
        <?php foreach ($denoms as $d): ?>
          <td class="refund-cell"><input name="voucher_out[<?= $d ?>]" value="<?= e(($payload['vouchers'][$d] ?? 0) ?: '') ?>" inputmode="numeric" class="num tiny" data-money data-vdenom="<?= $d ?>"></td>
        <?php endforeach ?>
        <td class="refund-cell"></td></tr>
    <?php endif ?>
    </tbody>
    <tfoot><tr><th colspan="4">객실 합계 <small class="muted" data-room-count></small></th><th class="right" data-room-guests>0</th><th class="right" data-room-amount>0</th>
      <?php foreach ($denoms as $d): ?><th class="right refund-cell" data-vsum="<?= $d ?>">0</th><?php endforeach ?><th class="right refund-cell" data-vsum-amt>0</th></tr></tfoot>
  </table>
  </div>

  <h3>지역상품권 환급 합계 · 재고</h3>
  <div class="table-scroll">
  <table class="table" data-voucher-summary>
    <thead><tr><th>권종</th><th class="right">현재고</th><th class="right">이번 환급(매)</th><th class="right">환급 금액</th><th class="right">남은 재고(매)</th><th class="right">남은 재고 금액</th></tr></thead>
    <tbody>
    <?php foreach ($denoms as $d): ?>
      <tr data-denom="<?= $d ?>" data-stock="<?= (int) ($stock[$d] ?? 0) ?>">
        <td><?= e(denom_label($d)) ?></td><td class="right"><?= number_format($stock[$d] ?? 0) ?></td>
        <td class="right" data-out>0</td><td class="right" data-out-amt>0</td><td class="right" data-left>0</td><td class="right" data-left-amt>0</td>
      </tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th>합계</th><th class="right"><?= number_format(array_sum($stock)) ?></th><th class="right" data-out-total>0</th><th class="right" data-out-amt-total>0</th>
      <th class="right" data-left-total>0</th><th class="right" data-left-amt-total>0</th></tr></tfoot>
  </table>
  </div>
  <?php endif ?>

  <?php if ($rentals): ?>
  <h3>시설대관</h3>
  <p class="muted small">대관한 시설의 <b>대관 시간</b>을 고르고, <?= e(RENT_NIGHT_LABEL) ?>에도 사용했으면 '야간'에 체크하세요 (야간만 사용해도 됩니다).
    같은 시설을 같은 조건으로 여러 번 대관했으면 건수를 입력합니다.</p>
  <div class="table-scroll">
  <table class="table rental-table" data-rental-table>
    <thead><tr><th>시설</th><th>대관 시간</th><th>야간</th><th>건수</th><th class="right">단가</th><th class="right">금액</th></tr></thead>
    <tbody>
    <?php foreach ($rentals as $pid => $p): $l = $byProduct[$pid] ?? null; ?>
      <tr data-prices="<?= e(json_encode(array_map(fn($t) => (int) $p[$t[1]], RENT_TIMES))) ?>" data-night="<?= (int) $p['price_night'] ?>" data-name="<?= e($p['name']) ?>">
        <td><?= e($p['name']) ?><?= $p['is_active'] ? '' : ' <small class="muted">(판매중지)</small>' ?></td>
        <td><select name="rental[<?= $pid ?>][time]" data-rent-time>
          <option value="">선택 안 함</option>
          <?php foreach (RENT_TIMES as $k => [$label, $col]): ?><option value="<?= $k ?>" <?= ($l['rent_time'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?> · <?= number_format((int) $p[$col]) ?></option><?php endforeach ?>
        </select></td>
        <td class="nowrap"><label class="inline-check"><input type="checkbox" name="rental[<?= $pid ?>][night]" value="1" data-rent-night <?= !empty($l['night']) ? 'checked' : '' ?>>
          <small>+<?= number_format((int) $p['price_night']) ?></small></label></td>
        <td><input name="rental[<?= $pid ?>][qty]" value="<?= e($l['qty'] ?? '') ?>" inputmode="numeric" class="num tiny" data-money data-rent-qty placeholder="1"></td>
        <td class="right" data-unit>0</td>
        <td class="right" data-line-amount>0</td>
      </tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th colspan="3">시설대관 합계</th><th class="right" data-rent-count>0건</th><th></th><th class="right" data-rent-amount>0원</th></tr></tfoot>
  </table>
  </div>
  <?php endif ?>

  <?php if ($lodges): ?>
  <h3>대관 숙박시설</h3>
  <p class="muted small">정액 요금입니다. 이용한 시설의 <b>건수</b>를 입력하고, 할인 대상이면 <b>할인율(%)</b>을 입력하세요 (10원 단위 버림).</p>
  <div class="table-scroll">
  <table class="table rental-table" data-lodge-table>
    <thead><tr><th>시설</th><th class="right">정액 요금</th><th>할인율(%)</th><th>건수</th><th class="right">단가</th><th class="right">금액</th></tr></thead>
    <tbody>
    <?php foreach ($lodges as $pid => $p): $l = $byProduct[$pid] ?? null; ?>
      <tr data-price="<?= (int) $p['price'] ?>" data-name="<?= e($p['name']) ?>">
        <td><?= e($p['name']) ?><?= $p['is_active'] ? '' : ' <small class="muted">(판매중지)</small>' ?></td>
        <td class="right"><?= number_format((int) $p['price']) ?></td>
        <td class="nowrap"><input name="lodge[<?= $pid ?>][dc]" value="<?= e(!empty($l['dc_pct']) ? $l['dc_pct'] : '') ?>" inputmode="numeric" class="num tiny" data-lodge-dc placeholder="0" maxlength="3"> %</td>
        <td><input name="lodge[<?= $pid ?>][qty]" value="<?= e($l['qty'] ?? '') ?>" inputmode="numeric" class="num tiny" data-money data-lodge-qty></td>
        <td class="right" data-unit>0</td>
        <td class="right" data-line-amount>0</td>
      </tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th colspan="3">대관 숙박시설 합계</th><th class="right" data-lodge-count>0건</th><th></th><th class="right" data-lodge-amount>0원</th></tr></tfoot>
  </table>
  </div>
  <?php endif ?>

  <div class="grand">매출 합계 <b data-grand>0원</b></div>
</div>
    <?php
}

/** 시설점검 입력표: 관리팀의 등록 시설(구역별) + 직접 입력 행 */
function facility_form_rows(array $payload): void
{
    $normal = normal_result();
    $registered = $payload['team_id'] ? facilities_list($payload['team_id']) : [];
    $byFac = $free = [];
    foreach ($payload['facility'] as $r) {
        if (!empty($r['facility_id'])) $byFac[(int) $r['facility_id']] = $r; else $free[] = $r;
    }
    $rows = [];
    foreach ($registered as $fid => $f) {
        $rows[] = $byFac[$fid] ?? ['facility_id' => $fid, 'area' => $f['area'], 'facility' => $f['name'], 'result' => $normal, 'note' => ''];
        unset($byFac[$fid]);
    }
    $rows = [...$rows, ...array_values($byFac)]; // 지금은 사용안함인 시설의 기존 기록
    $blank = ['facility_id' => null, 'area' => null, 'facility' => '', 'result' => $normal, 'note' => ''];
    $free = [...$free, $blank, $blank];
    if (!$registered) {
        echo '<div class="flash flash-info">이 관리팀에 등록된 세부시설이 없습니다. <a href="' . e(url('facilities.php')) . '">시설물</a> 메뉴에서 등록하면 점검표가 자동으로 만들어집니다. 지금은 아래에 직접 입력할 수 있습니다.</div>';
        $free = [...$free, $blank, $blank];
    }
    $area = false;
    ?>
<p class="muted small">정상이 아닌 항목은 결과를 바꾸고 내용·조치사항을 적어 주세요. 시설별 '이상 이력'에 모입니다.</p>
<div class="table-scroll">
<table class="table facility-check">
  <thead><tr><th>시설</th><th>점검결과</th><th>내용 / 조치사항</th></tr></thead>
  <tbody>
  <?php foreach ([...$rows, ...$free] as $i => $it):
      $isFree = empty($it['facility_id']);
      $rowArea = $isFree ? '기타 (직접 입력)' : $it['area'];
      if ($rowArea !== $area): $area = $rowArea; ?>
        <tr class="area-row"><th colspan="3"><?= e($area) ?></th></tr>
      <?php endif ?>
    <tr>
      <td><?php if ($isFree): ?>
            <input name="fac[<?= $i ?>][facility]" value="<?= e($it['facility']) ?>" placeholder="시설명 직접 입력">
          <?php else: ?>
            <input type="hidden" name="fac[<?= $i ?>][facility_id]" value="<?= (int) $it['facility_id'] ?>"><?= e($it['facility']) ?>
          <?php endif ?></td>
      <td><select name="fac[<?= $i ?>][result]" data-result>
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
    if (is_program_type($journal['type'])) {
        program_view($journal);
        return;
    }
    $payload = items_load($journal);

    if ($journal['type'] === 'facility'):
        $normal = normal_result();
        $issues = count(array_filter($payload['facility'], fn($f) => $f['result'] !== $normal));
        $area = false; ?>
<p><?= $payload['team_id'] ? '<span class="badge">' . e(team_name($payload['team_id'])) . '</span> ' : '' ?>
  점검 <?= count($payload['facility']) ?>개 · <?= $issues ? '<b class="warn">이상 ' . $issues . '건</b>' : '모두 정상' ?></p>
<div class="table-scroll">
<table class="table">
  <thead><tr><th>시설</th><th>점검결과</th><th>내용 / 조치사항</th></tr></thead>
  <tbody>
  <?php foreach ($payload['facility'] as $f):
      if ($f['area'] !== $area): $area = $f['area']; ?>
        <tr class="area-row"><th colspan="3"><?= e($area ?? '기타 (직접 입력)') ?></th></tr>
      <?php endif ?>
    <tr class="<?= $f['result'] !== $normal ? 'issue' : '' ?>">
      <td><?= $f['facility_id'] ? '<a href="' . e(url('facility.php?id=' . $f['facility_id'])) . '">' . e($f['facility']) . '</a>' : e($f['facility']) ?></td>
      <td><span class="result r-<?= e($f['result']) ?>"><?= e($f['result']) ?></span></td><td><?= e($f['note']) ?></td></tr>
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
    $rentals = array_filter($payload['lines'], fn($l) => $l['grp'] === 'rental');
    $lodges = array_filter($payload['lines'], fn($l) => $l['grp'] === 'lodge');
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
      <tr><td><?= e($l['name']) ?><?= $l['season'] ? ' <span class="badge st-pending">' . e($l['season']) . '</span>' : '' ?></td><td><?= $l['is_free'] ? '무료' : '유료' ?></td>
        <td class="right"><?= number_format($l['unit_price']) ?></td><td class="right"><?= number_format($l['qty']) ?></td>
        <td class="right"><?= number_format($l['amount']) ?></td></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th colspan="3">합계 <small class="muted">유료 <?= number_format($sum($tickets, 'qty') - $free) ?> · 무료 <?= number_format($free) ?></small></th>
      <th class="right"><?= number_format($sum($tickets, 'qty')) ?></th><th class="right"><?= e(won($sum($tickets, 'amount'))) ?></th></tr>
      <tr class="pay-row"><td colspan="4" class="right">현금</td><td class="right"><?= e(won($payload['ticket_cash'])) ?></td></tr>
      <tr class="pay-row"><td colspan="4" class="right">카드</td><td class="right"><?= e(won($sum($tickets, 'amount') - $payload['ticket_cash'])) ?></td></tr></tfoot>
  </table>
  </div>
    <?php endif;

    if ($rooms):
        $denoms = voucher_denoms(); ?>
  <h3>객실 판매 · 지역상품권 환급</h3>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>객실</th><th>요금구분</th><th class="right">단가</th><th class="right">입실인원</th><th class="right">금액</th>
      <?php foreach ($denoms as $d): ?><th class="right"><?= e(denom_label($d)) ?></th><?php endforeach ?><th class="right">환급액</th></tr></thead>
    <tbody>
    <?php foreach ($rooms as $l):
        $refund = voucher_amount($l['vouchers']);
        $mismatch = $l['refund_expected'] !== null && $refund !== (int) $l['refund_expected']; ?>
      <tr class="<?= $mismatch ? 'issue' : '' ?>"><td><?= e($l['name']) ?></td>
        <td><?= e(RATE_TYPES[$l['rate']] ?? '') ?><?= $l['season'] ? ' <small class="muted">(' . e($l['season']) . ')</small>' : '' ?><?= $l['discounted'] ? ' <span class="badge st-pending">할인</span>' : '' ?></td>
        <td class="right"><?= number_format($l['unit_price']) ?></td>
        <td class="right"><?= number_format($l['guests']) ?></td><td class="right"><?= number_format($l['amount']) ?></td>
        <?php foreach ($denoms as $d): ?><td class="right"><?= $l['vouchers'][$d] ? number_format($l['vouchers'][$d]) : '' ?></td><?php endforeach ?>
        <td class="right nowrap"><?= number_format($refund) ?><?= $mismatch ? '<br><small class="warn">기준 ' . number_format($l['refund_expected']) . '</small>' : '' ?></td></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th colspan="3">합계 <small class="muted"><?= count($rooms) ?>실</small></th>
      <th class="right"><?= number_format($sum($rooms, 'guests')) ?></th><th class="right"><?= e(won($sum($rooms, 'amount'))) ?></th>
      <?php foreach ($denoms as $d): ?><th class="right"><?= number_format(array_sum(array_map(fn($l) => $l['vouchers'][$d], $rooms))) ?></th><?php endforeach ?>
      <th class="right"><?= e(won(array_sum(array_map(fn($l) => voucher_amount($l['vouchers']), $rooms)))) ?></th></tr></tfoot>
  </table>
  </div>
    <?php endif;

    if ($rentals): ?>
  <h3>시설대관</h3>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>시설</th><th>대관</th><th class="right">단가</th><th class="right">건수</th><th class="right">금액</th></tr></thead>
    <tbody>
    <?php foreach ($rentals as $l): ?>
      <tr><td><?= e($l['name']) ?></td><td><?= e(rental_desc($l['rent_time'], (bool) $l['night'])) ?></td>
        <td class="right"><?= number_format($l['unit_price']) ?></td><td class="right"><?= number_format($l['qty']) ?></td><td class="right"><?= number_format($l['amount']) ?></td></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th colspan="3">합계</th><th class="right"><?= number_format($sum($rentals, 'qty')) ?></th><th class="right"><?= e(won($sum($rentals, 'amount'))) ?></th></tr></tfoot>
  </table>
  </div>
    <?php endif;

    if ($lodges): ?>
  <h3>대관 숙박시설</h3>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>시설</th><th>할인</th><th class="right">단가</th><th class="right">건수</th><th class="right">금액</th></tr></thead>
    <tbody>
    <?php foreach ($lodges as $l): ?>
      <tr><td><?= e($l['name']) ?></td><td><?= $l['dc_pct'] ? '<span class="badge st-pending">' . (int) $l['dc_pct'] . '% 할인</span>' : '-' ?></td>
        <td class="right"><?= number_format($l['unit_price']) ?></td><td class="right"><?= number_format($l['qty']) ?></td><td class="right"><?= number_format($l['amount']) ?></td></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th colspan="3">합계</th><th class="right"><?= number_format($sum($lodges, 'qty')) ?></th><th class="right"><?= e(won($sum($lodges, 'amount'))) ?></th></tr></tfoot>
  </table>
  </div>
    <?php endif;

    $allOut = journal_vouchers_out((int) $journal['id']);
    if (array_sum($allOut) > 0) {
        echo '<h3>지역상품권 환급 합계</h3>';
        voucher_view_table($allOut, '환급');
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

/** 문서 1건의 지역상품권 출고(환급) 권종별 매수 합계 */
function journal_vouchers_out(int $journalId): array
{
    $out = array_fill_keys(voucher_denoms(), 0);
    $st = db()->prepare("SELECT denom, SUM(qty) AS qty FROM voucher_moves WHERE journal_id = ? AND direction = 'out' GROUP BY denom");
    $st->execute([$journalId]);
    foreach ($st as $r) $out[(int) $r['denom']] = (int) $r['qty'];
    return $out;
}

/** 매출보고 1건의 매출 합계 SQL (별칭 j) — 달력 등에서 사용 */
const SALES_AMOUNT_SQL = '(SELECT COALESCE(SUM(l.amount), 0) FROM sales_lines l WHERE l.journal_id = j.id)
    + (SELECT COALESCE(SUM(s.card + s.cash + s.transfer), 0) FROM sales_items s WHERE s.journal_id = j.id)';
