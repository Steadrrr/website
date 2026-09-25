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
 *   vcheck   : ['checks' => [권종 => ['book' => 장부상 금고 매수, 'actual' => 실제 매수]]]
 */

function items_default(string $type, ?int $teamId = null): array
{
    return match ($type) {
        'facility' => ['team_id' => $teamId, 'facility' => []], // 등록 시설은 폼에서 채움
        'sales'    => ['lines' => [], 'ticket_cash' => 0, 'rent_dc_rule' => null, 'rent_dc_pct' => 0, 'rent_youth' => 0, 'vouchers' => array_fill_keys(voucher_denoms(), 0), 'legacy' => []],
        'rooms'    => ['lines' => [], 'vouchers' => array_fill_keys(voucher_denoms(), 0)],
        'voucher'  => ['vouchers' => array_fill_keys(voucher_denoms(), 0)],
        'daily'    => ['complaints' => []],
        'vcheck'   => ['checks' => []], // 장부 매수는 폼에서 그 날짜 기준으로 계산
        'arwork'   => ['workers' => []],
        'purchase' => ['vendor' => '', 'pay_method' => 'card', 'items' => [purchase_empty_item()]],
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
    if ($journal['type'] === 'arwork') return ar_load($id);
    if ($journal['type'] === 'purchase') return purchase_load($id);
    if ($journal['type'] === 'daily') return ['complaints' => cpl_load($id)];

    if (in_array($journal['type'], SALE_DOC_TYPES, true)) {
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
            'ticket_cash' => (int) (($meta = $q('SELECT * FROM sales_meta WHERE journal_id = ?')[0] ?? [])['ticket_cash'] ?? 0),
            'rent_dc_rule' => $meta['rent_dc_rule'] ?? null,
            'rent_dc_pct'  => (int) ($meta['rent_dc_pct'] ?? 0),
            'rent_youth'   => (int) ($meta['rent_youth'] ?? 0),
            'vouchers'    => $unassigned,
            'legacy'      => $q('SELECT * FROM sales_items WHERE journal_id = ? ORDER BY id'),
        ];
    }

    return match ($journal['type']) {
        'facility' => ['team_id' => $journal['team_id'] ? (int) $journal['team_id'] : null, 'facility' => $q('SELECT * FROM facility_items WHERE journal_id = ? ORDER BY id')],
        'voucher'  => ['vouchers' => $vouchers('in')],
        'vcheck'   => ['checks' => vcheck_load($id)],
        default    => [],
    };
}

/** @return array{0: array, 1: string[]} [payload, 오류메시지] */
function items_parse(string $type, string $workDate, int $journalId): array
{
    if (is_program_type($type)) return program_parse($type, $workDate, $journalId);
    if ($type === 'arwork') return ar_parse($workDate, $journalId);
    if ($type === 'purchase') return purchase_parse($journalId);
    if ($type === 'daily') {
        [$rows, $errors] = cpl_parse($workDate);
        return [['complaints' => $rows], $errors];
    }

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

    if ($type === 'rooms') {
        // 일일객실판매: 객실 판매와 지역상품권 환급 (매출보고와 따로 결재)
        $products = products_at($workDate); // 기간별 가격표가 있으면 그 날짜의 가격
        $lines = [];
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
        $payload['lines'] = $lines;
        // 이전 버전 자료의 객실 미지정 환급분 (있을 때만 화면에 나옴)
        foreach (voucher_denoms() as $d) $payload['vouchers'][$d] = to_int($_POST['voucher_out'][$d] ?? 0);
        // 일일객실판매는 하루 1건
        if (valid_date($workDate)) {
            $st = db()->prepare("SELECT id FROM journals WHERE type = 'rooms' AND work_date = ? AND id <> ?");
            $st->execute([$workDate, $journalId]);
            if ($dup = $st->fetchColumn()) $errors[] = "해당 날짜의 일일객실판매가 이미 있습니다. (문서번호 $dup)";
        }
        if (!$lines && !array_sum($payload['vouchers'])) $errors[] = '판매한 객실의 입실인원을 1개 이상 입력하세요.';
    }

    if ($type === 'sales') {
        $products = products_at($workDate); // 기간별 가격표가 있으면 그 날짜의 가격
        $lines = [];

        foreach ((array) ($_POST['ticket'] ?? []) as $pid => $row) {
            $p = $products[(int) $pid] ?? null;
            $qty = to_int($row['qty'] ?? 0);
            if (!$p || $p['grp'] !== 'ticket' || !empty($p['sys_key']) || $qty === 0) continue; // 쉬자파크숙박은 아래에서 자동
            [$unit, $season] = ticket_price($p, $workDate);
            $lines[] = [
                'product_id' => (int) $p['id'], 'grp' => 'ticket', 'name' => $p['name'], 'is_free' => (int) $p['is_free'],
                'rate' => null, 'season' => $season, 'discounted' => 0, 'unit_price' => $unit, 'qty' => $qty, 'guests' => 0, 'amount' => $unit * $qty,
            ];
        }

        $payload['warnings'] = [];
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

        // 대관 숙박시설: 정액 요금 × 실 수
        foreach ((array) ($_POST['lodge'] ?? []) as $pid => $row) {
            $p = $products[(int) $pid] ?? null;
            if (!$p || $p['grp'] !== 'lodge') continue;
            $qty = to_int($row['qty'] ?? 0);
            if ($qty === 0) continue;
            $lines[] = [
                'product_id' => (int) $p['id'], 'grp' => 'lodge', 'name' => $p['name'], 'is_free' => 0, 'rate' => null, 'season' => null,
                'discounted' => 0, 'unit_price' => (int) $p['price'], 'qty' => $qty, 'guests' => 0, 'amount' => (int) $p['price'] * $qty,
                'rent_time' => null, 'night' => 0, 'dc_pct' => 0,
            ];
        }

        // 시설대관 + 대관 숙박시설 통합 할인: '초등·청소년 20명 이상' 체크 → 30%,
        // 아니면 대관 숙박시설 실 수로 자동 (9실 이상 20%, 5실 이상 10%)
        $rentLines = array_filter($lines, fn($l) => in_array($l['grp'], ['rental', 'lodge'], true));
        $lodgeRooms = array_sum(array_map(fn($l) => $l['grp'] === 'lodge' ? $l['qty'] : 0, $lines));
        $rule = !empty($_POST['rent_youth']) ? 'youth20' : rent_dc_auto($lodgeRooms);
        if (!$rentLines) $rule = null;
        $pct = $rule ? RENT_DC_RULES[$rule][1] : 0;
        if ($pct) {
            foreach ($lines as &$l) {
                if (!in_array($l['grp'], ['rental', 'lodge'], true)) continue;
                $l['amount'] = dc_amount($l['unit_price'] * $l['qty'], $pct); // 줄마다 할인 (10원 단위 버림)
                $l['discounted'] = 1;
                $l['dc_pct'] = $pct;
            }
            unset($l);
        }
        $payload['rent_dc_rule'] = $rule;
        $payload['rent_dc_pct'] = $pct;
        $payload['rent_youth'] = (int) ($rule === 'youth20'); // 체크 여부
        // 프로그램 판매: 그 날 프로그램 운영보고가 있으면 그 합계(자동), 없으면 직접 입력한 값
        $progAuto = valid_date($workDate) ? program_day_summary($workDate) : [];
        foreach (PROGRAM_TYPES as $pt => $plabel) {
            if (isset($progAuto[$pt])) {
                $a = $progAuto[$pt];
                $lines[] = program_sale_line($pt, $a['sessions'], $a['paid'], $a['free'], $a['amount'], true);
                continue;
            }
            $row = (array) ($_POST['prog'][$pt] ?? []);
            [$ses, $paid, $free, $amt] = [to_int($row['sessions'] ?? 0), to_int($row['paid'] ?? 0), to_int($row['free'] ?? 0), to_int($row['amount'] ?? 0)];
            if ($ses || $paid || $free || $amt) $lines[] = program_sale_line($pt, $ses, $paid, $free, $amt, false);
        }

        // 쉬자파크숙박 입실 = 그 날 일일객실판매의 입실인원 합계, 퇴실 = 전날 입실인원 합계 (수정 불가, 무료 입장권으로 집계)
        $roomGuests = valid_date($workDate) ? stay_guests($workDate) : 0;
        $stayLines = [];
        foreach (stay_products() as $key => $p) {
            $qty = $key === 'stay_in' ? $roomGuests : (valid_date($workDate) ? stay_out_guests($workDate) : 0);
            if ($qty > 0) $stayLines[] = [
                'product_id' => (int) $p['id'], 'grp' => 'ticket', 'name' => $p['name'], 'is_free' => 1,
                'rate' => null, 'season' => null, 'discounted' => 0, 'unit_price' => 0, 'qty' => $qty, 'guests' => 0, 'amount' => 0,
            ];
        }
        $lines = [...$stayLines, ...$lines];
        $payload['lines'] = $lines;

        // 입장권 현금 수입 (나머지는 카드로 본다)
        $ticketAmount = array_sum(array_map(fn($l) => $l['grp'] === 'ticket' ? $l['amount'] : 0, $lines));
        $payload['ticket_cash'] = to_int($_POST['ticket_cash'] ?? 0);
        if ($payload['ticket_cash'] > $ticketAmount) {
            $errors[] = '입장권 현금 금액이 입장권 판매금액(' . number_format($ticketAmount) . '원)보다 큽니다.';
        }

        // 지역상품권 환급은 일일객실판매에서 입력한다
        $payload['vouchers'] = array_fill_keys(voucher_denoms(), 0);

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

    if ($type === 'vcheck') {
        $book = vcheck_book($workDate, $journalId);
        $diff = false;
        foreach (voucher_denoms() as $d) {
            $raw = trim((string) ($_POST['vc_actual'][$d] ?? ''));
            if ($raw === '') { $errors[] = denom_label($d) . ' 실제 매수를 입력하세요 (없으면 0).'; $raw = '0'; }
            $payload['checks'][$d] = ['book' => $book[$d], 'actual' => to_int($raw)];
            if ($payload['checks'][$d]['actual'] !== $book[$d]) $diff = true;
        }
        if ($diff && trim(post('remarks')) === '') $errors[] = '장부와 실제 매수가 다릅니다. 차이 사유를 입력하세요.';
    }

    return [$payload, $errors];
}

/** 트랜잭션 안에서 호출 */
function items_save(int $id, string $type, array $payload): void
{
    $pdo = db();
    if (is_program_type($type)) {
        program_save($id, $payload);
        // 그 날 매출보고의 '프로그램 판매'를 이 운영보고 값으로 맞춘다
        $st = $pdo->prepare('SELECT work_date FROM journals WHERE id = ?');
        $st->execute([$id]);
        sales_sync_programs((string) $st->fetchColumn(), PROGRAM_TYPES[$type] . " 운영보고 저장 (문서 $id)");
        return;
    }
    if ($type === 'daily') {
        $st = $pdo->prepare('SELECT work_date FROM journals WHERE id = ?');
        $st->execute([$id]);
        cpl_save($id, (string) $st->fetchColumn(), $payload['complaints'] ?? []);
        return;
    }

    if ($type === 'arwork') {
        ar_save($id, $payload);
        return;
    }
    if ($type === 'purchase') {
        purchase_save($id, $payload);
        return;
    }

    if ($type === 'vcheck') {
        $pdo->prepare('DELETE FROM voucher_checks WHERE journal_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO voucher_checks (journal_id, denom, book_qty, actual_qty) VALUES (?, ?, ?, ?)');
        foreach ($payload['checks'] as $d => $c) $ins->execute([$id, $d, $c['book'], $c['actual']]);
        return;
    }

    if ($type === 'facility') {
        $pdo->prepare('DELETE FROM facility_items WHERE journal_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO facility_items (journal_id, facility_id, area, facility, result, note) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($payload['facility'] as $it) {
            $ins->execute([$id, $it['facility_id'] ?? null, $it['area'] ?? null, $it['facility'], $it['result'], $it['note']]);
        }
    }

    if (in_array($type, ['sales', 'rooms', 'voucher'], true)) {
        $pdo->prepare('DELETE FROM voucher_moves WHERE journal_id = ?')->execute([$id]);
    }
    $moveIns = $pdo->prepare('INSERT INTO voucher_moves (journal_id, line_id, direction, denom, qty) VALUES (?, ?, ?, ?, ?)');

    if (in_array($type, SALE_DOC_TYPES, true)) {
        $pdo->prepare('DELETE FROM sales_lines WHERE journal_id = ?')->execute([$id]);
        $ins = $pdo->prepare(
            'INSERT INTO sales_lines (journal_id, product_id, grp, name, is_free, rate, season, discounted, unit_price, qty, guests, refund_expected, rent_time, night, dc_pct, prog_type, sessions, auto, amount)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($payload['lines'] as $l) {
            $ins->execute([$id, $l['product_id'], $l['grp'], $l['name'], $l['is_free'], $l['rate'], $l['season'] ?? null,
                $l['discounted'], $l['unit_price'], $l['qty'], $l['guests'], $l['refund_expected'] ?? null,
                $l['rent_time'] ?? null, (int) ($l['night'] ?? 0), (int) ($l['dc_pct'] ?? 0), $l['prog_type'] ?? null, (int) ($l['sessions'] ?? 0), (int) ($l['auto'] ?? 0), $l['amount']]);
            $lineId = (int) $pdo->lastInsertId();
            foreach ($l['vouchers'] ?? [] as $denom => $qty) {
                if ($qty > 0) $moveIns->execute([$id, $lineId, 'out', $denom, $qty]);
            }
        }
        if ($type === 'sales') {
            $pdo->prepare('INSERT INTO sales_meta (journal_id, ticket_cash, rent_dc_rule, rent_dc_pct, rent_youth) VALUES (?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE ticket_cash = VALUES(ticket_cash), rent_dc_rule = VALUES(rent_dc_rule), rent_dc_pct = VALUES(rent_dc_pct), rent_youth = VALUES(rent_youth)')
                ->execute([$id, (int) $payload['ticket_cash'], $payload['rent_dc_rule'] ?? null, (int) ($payload['rent_dc_pct'] ?? 0), (int) ($payload['rent_youth'] ?? 0)]);
        } else {
            // 객실 입실인원이 바뀌면 그 날 매출보고의 '쉬자파크숙박(입실)'과 다음 날의 '(퇴실)'을 맞춘다
            $st = $pdo->prepare('SELECT work_date FROM journals WHERE id = ?');
            $st->execute([$id]);
            stay_sync_rooms((string) $st->fetchColumn());
        }
    }

    if (in_array($type, ['sales', 'rooms', 'voucher'], true)) {
        foreach ($payload['vouchers'] ?? [] as $denom => $qty) {
            if ($qty > 0) $moveIns->execute([$id, null, $type === 'voucher' ? 'in' : 'out', $denom, $qty]);
        }
    }
}

/* ───────────────────────── 입력 폼 ───────────────────────── */

function items_form(string $type, array $payload, string $workDate, ?array $journal): void
{
    if (is_program_type($type)) {
        program_form($type, $payload, $workDate, $journal);
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

    if ($type === 'arwork') {
        ar_form($payload, $workDate, $journal);
        return;
    }
    if ($type === 'purchase') {
        purchase_form($payload, $journal);
        return;
    }
    if ($type === 'vcheck') {
        vcheck_form($payload, $workDate, $journal);
        return;
    }

    if (!in_array($type, SALE_DOC_TYPES, true)) return;

    // 매출보고 = 입장권·시설대관, 일일객실판매 = 객실·지역상품권 환급
    $byProduct = [];
    foreach ($payload['lines'] as $l) $byProduct[(int) $l['product_id']] = $l;
    $ids = array_keys($byProduct);
    $isRooms = $type === 'rooms';
    $tickets = $isRooms ? [] : products_for_form('ticket', $ids, $workDate);
    $rooms = $isRooms ? products_for_form('room', $ids, $workDate) : [];
    $rentals = $isRooms ? [] : products_for_form('rental', $ids, $workDate);
    $lodges = $isRooms ? [] : products_for_form('lodge', $ids, $workDate);
    $defaultRate = rate_for_date($workDate);

    if (!$tickets && !$rooms && !$rentals && !$lodges): ?>
  <div class="flash flash-error">등록된 판매 상품이 없습니다. 관리자에게 <b>상품관리</b>에서 <?= $isRooms ? '객실' : '입장권·시설대관' ?>을 등록해 달라고 요청하세요.</div>
    <?php endif ?>
    <?php if (!$isRooms): ?><p class="muted small">객실 판매와 지역상품권 환급은 <a href="<?= e(url('journal.php?type=rooms&date=' . $workDate)) ?>">운영관리 › 객실판매관리</a>에서 따로 입력·결재합니다.</p><?php endif ?>

<script>window.STAY_API = <?= json_encode(url('api/stay.php')) ?>;</script>
<script>window.SEASONS = <?= json_encode(array_values(array_map(
    fn($s) => ['id' => (int) $s['id'], 'grp' => $s['grp'], 'label' => season_label($s), 'start' => $s['start_md'], 'end' => $s['end_md']],
    array_filter(seasons_all(), fn($s) => $s['is_active'])
)), JSON_UNESCAPED_UNICODE) ?>;</script>
<?php $pp = price_period_for($workDate); ?>
<script>window.PRICE_PERIODS = <?= json_encode(array_values(array_map(
    fn($x) => ['id' => (int) $x['id'], 'label' => price_period_label($x), 'from' => $x['date_from'], 'to' => $x['date_to']], price_periods_all()
)), JSON_UNESCAPED_UNICODE) ?>; window.PRICE_PERIOD_NOW = <?= (int) ($pp['id'] ?? 0) ?>; window.SALES_RELOAD = <?= json_encode($journal ? null : url('write.php?type=' . $type . '&date=')) ?>;</script>
<div class="flash price-period-note" data-price-period<?= $pp ? '' : ' hidden' ?>>📅 이 날짜는 <b>기간별 가격표 '<?= e($pp ? price_period_label($pp) : '') ?>'</b>의 가격으로 계산됩니다. (가격표에 없는 상품은 현재 가격)</div>
<div class="flash flash-warn" data-price-period-changed hidden></div>
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
        <?php if (!empty($p['sys_key'])): // 쉬자파크숙박 입실·퇴실: 수량 자동, 수정 불가 ?>
        <td><?= e($p['name']) ?> <span class="badge auto-badge" title="<?= $p['sys_key'] === 'stay_in' ? '그 날 일일객실판매의 입실인원 합계' : '전날 일일객실판매의 입실인원 합계' ?>">자동</span>
          <br><small class="muted"><?= $p['sys_key'] === 'stay_in' ? '객실판매 입실인원 합계' : '전날 입실인원 합계' ?></small></td>
        <td><span class="badge">무료</span></td>
        <td class="right" data-unit>0</td>
        <td><input value="<?= e($p['sys_key'] === 'stay_in' ? stay_guests($workDate) : stay_out_guests($workDate)) ?>" class="num short" data-qty data-stay="<?= $p['sys_key'] === 'stay_in' ? 'in' : 'out' ?>" readonly tabindex="-1" title="자동 계산 (수정 불가)"></td>
        <?php else: ?>
        <td><?= e($p['name']) ?><?= $p['is_active'] ? '' : ' <small class="muted">(판매중지)</small>' ?></td>
        <td><?= $p['is_free'] ? '<span class="badge">무료</span>' : '유료' ?></td>
        <td class="right" data-unit><?= number_format(ticket_price($p, $workDate)[0]) ?></td>
        <td><input name="ticket[<?= $pid ?>][qty]" value="<?= e($l['qty'] ?? '') ?>" inputmode="numeric" class="num short" data-money data-qty></td>
        <?php endif ?>
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
        <td><?= e($p['name']) ?> <small class="muted"><?= $p['base_people'] ?? 0 ? '기준 ' . (int) $p['base_people'] . '·' : '' ?>최대 <?= (int) $p['max_people'] ?>인</small><?= $p['is_active'] ? '' : ' <small class="muted">(판매중지)</small>' ?></td>
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

  <?php if ($rentals || $lodges):
      $rule = $payload['rent_dc_rule'] ?? null; ?>
  <div class="rent-block" data-rent-block>
  <h3>시설대관 · 대관 숙박시설</h3>
  <p class="muted small">시설대관은 대관 시간을 고르고 <?= e(RENT_NIGHT_LABEL) ?>에도 썼으면 '야간'에 체크하세요 (야간만 사용해도 됩니다).
    대관 숙박시설은 이용한 <b>실 수</b>를 입력합니다. 할인은 아래에서 <b>시설대관 + 대관 숙박시설 합계</b>에 한 번에 적용합니다.</p>
  <?php if ($rentals): ?>
  <div class="table-scroll">
  <table class="table rental-table" data-rental-table>
    <thead><tr><th>시설대관</th><th>대관 시간</th><th>야간</th><th>건수</th><th class="right">단가</th><th class="right">금액</th></tr></thead>
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
  </table>
  </div>
  <?php endif ?>

  <?php if ($lodges): ?>
  <div class="table-scroll">
  <table class="table rental-table" data-lodge-table>
    <thead><tr><th>대관 숙박시설</th><th class="right">정액 요금</th><th>실 수</th><th class="right">금액</th></tr></thead>
    <tbody>
    <?php foreach ($lodges as $pid => $p): $l = $byProduct[$pid] ?? null; ?>
      <tr data-price="<?= (int) $p['price'] ?>" data-name="<?= e($p['name']) ?>">
        <td><?= e($p['name']) ?><?= $p['is_active'] ? '' : ' <small class="muted">(판매중지)</small>' ?></td>
        <td class="right"><?= number_format((int) $p['price']) ?></td>
        <td><input name="lodge[<?= $pid ?>][qty]" value="<?= e($l['qty'] ?? '') ?>" inputmode="numeric" class="num tiny" data-money data-lodge-qty></td>
        <td class="right" data-line-amount>0</td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
  <?php endif ?>

  <div class="rent-summary">
    <div class="rent-row"><span>소계 <small class="muted">(시설대관 <span data-rent-count>0건</span> · 대관 숙박 <span data-lodge-count>0실</span>)</small></span><b data-rent-gross>0원</b></div>
    <div class="rent-row rent-dc">
      <span>통합 할인 <b data-rent-dc-label>없음</b></span>
      <label class="inline-check"><input type="checkbox" name="rent_youth" value="1" data-rent-youth <?= $rule === 'youth20' ? 'checked' : '' ?>> 초등·청소년 20명 이상 (30% 할인)</label>
      <b class="warn" data-rent-dc-amount></b>
    </div>
    <p class="muted tiny-text">대관 숙박시설 5실 이상 10%, 9실 이상 20%는 실 수에 맞춰 자동 적용됩니다. 초등·청소년 20명 이상이면 체크하세요 (30%, 다른 할인 대신 적용).</p>
    <div class="rent-row rent-total"><span>시설대관 · 대관 숙박시설 합계</span><b data-rent-net>0원</b></div>
  </div>
  </div>
  <?php endif ?>

  <?php if (!$isRooms):
      $progAuto = program_day_summary($workDate);
      $progLines = [];
      foreach ($payload['lines'] as $l) if ($l['grp'] === 'program') $progLines[$l['prog_type']] = $l; ?>
  <h3>프로그램 판매</h3>
  <p class="muted small">그 날 <b>프로그램 운영보고</b>가 있는 분야는 운영보고의 회차·인원·금액이 <b>자동</b>으로 들어갑니다 (운영보고를 고치면 여기도 바뀝니다). 유료 인원에는 할인 인원이 포함됩니다.
    운영보고가 없는 분야는 직접 입력하세요. 일자를 바꾸면 저장할 때 그 날짜의 운영보고로 다시 채워집니다.</p>
  <div class="table-scroll">
  <table class="table program-sale-table" data-program-table>
    <thead><tr><th>분야</th><th>회차</th><th>유료 인원</th><th>무료 인원</th><th class="right">금액</th><th>입력</th></tr></thead>
    <tbody>
    <?php foreach (PROGRAM_TYPES as $pt => $plabel): $a = $progAuto[$pt] ?? null; $l = $progLines[$pt] ?? null; ?>
      <?php if ($a): ?>
      <tr class="auto" data-amount="<?= $a['amount'] ?>">
        <td><?= e($plabel) ?></td><td class="num-cell"><?= number_format($a['sessions']) ?>회</td><td class="num-cell"><?= number_format($a['paid']) ?>명</td><td class="num-cell"><?= number_format($a['free']) ?>명</td>
        <td class="right" data-line-amount><?= number_format($a['amount']) ?></td>
        <td><span class="badge auto-badge">자동</span> <a class="small" href="<?= e(url('view.php?id=' . $a['journal_id'])) ?>" target="_blank">운영보고 ›</a></td>
      </tr>
      <?php else: ?>
      <tr>
        <td><?= e($plabel) ?></td>
        <td><input name="prog[<?= $pt ?>][sessions]" value="<?= e(($l && !$l['auto'] ? (int) $l['sessions'] : 0) ?: '') ?>" inputmode="numeric" class="num tiny" data-money placeholder="0"></td>
        <td><input name="prog[<?= $pt ?>][paid]" value="<?= e(($l && !$l['auto'] ? (int) $l['qty'] : 0) ?: '') ?>" inputmode="numeric" class="num tiny" data-money placeholder="0"></td>
        <td><input name="prog[<?= $pt ?>][free]" value="<?= e(($l && !$l['auto'] ? (int) $l['guests'] : 0) ?: '') ?>" inputmode="numeric" class="num tiny" data-money placeholder="0"></td>
        <td><input name="prog[<?= $pt ?>][amount]" value="<?= e(($l && !$l['auto'] ? number_format((int) $l['amount']) : '') ?: '') ?>" inputmode="numeric" class="num" data-money data-prog-amount placeholder="0"></td>
        <td class="small muted">직접 입력</td>
      </tr>
      <?php endif ?>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th colspan="4">프로그램 합계</th><th class="right" data-program-amount>0원</th><th></th></tr></tfoot>
  </table>
  </div>
  <?php endif ?>

  <div class="grand"><?= $isRooms ? '객실 매출 합계' : '매출 합계' ?> <b data-grand>0원</b></div>
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
    if ($journal['type'] === 'daily') {
        cpl_view(cpl_load((int) $journal['id']));
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

    if ($journal['type'] === 'arwork') {
        ar_view($journal);
        return;
    }
    if ($journal['type'] === 'purchase') {
        purchase_view($journal);
        return;
    }
    if ($journal['type'] === 'vcheck') {
        vcheck_view($journal, $payload['checks']);
        return;
    }

    if ($journal['type'] === 'voucher') {
        voucher_view_table($payload['vouchers'], '입고');
        if ($journal['status'] !== 'approved') {
            echo '<p class="muted small">결재완료 후 상품권 재고에 반영됩니다.</p>';
        }
        return;
    }

    if (!in_array($journal['type'], SALE_DOC_TYPES, true)) return;

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

    if ($rentals || $lodges):
        $rgross = array_sum(array_map(fn($l) => $l['unit_price'] * $l['qty'], [...$rentals, ...$lodges]));
        $rnet = $sum([...$rentals, ...$lodges], 'amount');
        $rule = $payload['rent_dc_rule'] ?? null; ?>
  <div class="rent-block">
  <h3>시설대관 · 대관 숙박시설</h3>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>구분</th><th>시설</th><th>대관</th><th class="right">단가</th><th class="right">건수·실</th><th class="right">금액</th></tr></thead>
    <tbody>
    <?php foreach ($rentals as $l): ?>
      <tr><td>시설대관</td><td><?= e($l['name']) ?></td><td><?= e(rental_desc($l['rent_time'], (bool) $l['night'])) ?></td>
        <td class="right"><?= number_format($l['unit_price']) ?></td><td class="right"><?= number_format($l['qty']) ?>건</td><td class="right"><?= number_format($l['unit_price'] * $l['qty']) ?></td></tr>
    <?php endforeach ?>
    <?php foreach ($lodges as $l): ?>
      <tr><td>대관 숙박</td><td><?= e($l['name']) ?><?= !$rule && $l['dc_pct'] ? ' <span class="badge st-pending">' . (int) $l['dc_pct'] . '% 할인</span>' : '' ?></td><td>정액</td>
        <td class="right"><?= number_format($l['unit_price']) ?></td><td class="right"><?= number_format($l['qty']) ?>실</td><td class="right"><?= number_format($l['unit_price'] * $l['qty']) ?></td></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot>
      <tr><th colspan="5">소계</th><th class="right"><?= number_format($rgross) ?></th></tr>
      <?php if ($rgross !== $rnet): ?>
        <tr class="pay-row"><td colspan="5" class="right">통합 할인<?= $rule ? ' · ' . e(RENT_DC_RULES[$rule][0] ?? $rule) . ' ' . (int) $payload['rent_dc_pct'] . '%' : '' ?>
 </td><td class="right warn">− <?= number_format($rgross - $rnet) ?></td></tr>
      <?php endif ?>
      <tr><th colspan="5">합계</th><th class="right"><?= e(won($rnet)) ?></th></tr>
    </tfoot>
  </table>
  </div>
  </div>
    <?php endif;

    $programs = array_filter($payload['lines'], fn($l) => $l['grp'] === 'program');
    if ($programs): ?>
  <h3>프로그램 판매</h3>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>분야</th><th class="right">회차</th><th class="right">유료 인원</th><th class="right">무료 인원</th><th class="right">금액</th><th>입력</th></tr></thead>
    <tbody>
    <?php foreach ($programs as $l): ?>
      <tr><td><?= e($l['name']) ?></td><td class="right"><?= number_format($l['sessions']) ?>회</td><td class="right"><?= number_format($l['qty']) ?>명</td><td class="right"><?= number_format($l['guests']) ?>명</td>
        <td class="right"><?= number_format($l['amount']) ?></td><td class="small muted"><?= $l['auto'] ? '운영보고 자동' : '직접 입력' ?></td></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th>합계</th><th class="right"><?= number_format($sum($programs, 'sessions')) ?>회</th><th class="right"><?= number_format($sum($programs, 'qty')) ?>명</th><th class="right"><?= number_format($sum($programs, 'guests')) ?>명</th><th class="right"><?= e(won($sum($programs, 'amount'))) ?></th><th></th></tr></tfoot>
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
  <div class="grand"><?= $journal['type'] === 'rooms' ? '객실 매출 합계' : '매출 합계' ?> <b><?= e(won($grand)) ?></b></div>
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
