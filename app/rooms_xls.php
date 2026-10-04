<?php
defined('APP_ROOT') || exit;

/*
 * 객실판매관리 작성 화면의 '예약 엑셀로 채우기' — 산림청 통합운영시스템 엑셀(.xls) 두 가지를 제목으로 구분
 *   '입실예정 숙박상품 목록' → 그 날 작성 화면의 입력칸만 채움 (저장은 작성자가)
 *   '퇴실완료 상품 목록'     → 예약마다 묵은 날(입실일 ~ 퇴실 전날)을 계산해, 여러 날짜의 일일객실판매를 미리보기 후 한꺼번에 만들거나 고침
 * 개인정보 보호: 필요한 열(시설/상품·숙박기간·입실·할인적용·결제금액·상태)만 읽고, 고객 이름·아이디·생년월일·휴대폰·연락처·
 * 주문·예약번호 열은 읽지 않는다. 세션에는 객실 이름별 인원·할인·금액만 두고, 올린 파일은 요청이 끝나면 PHP가 지운다(저장하지 않음).
 */
require_once __DIR__ . '/xlsx_read.php';

const ROOMS_FILL_KEY = 'rooms_fill';

/**
 * 예약 엑셀 읽기 → ['kind' => 'checkin'(입실예정) | 'checkout'(퇴실완료), 'stays' => 예약마다 ['room' => 객실명, 'from' => 입실일, 'to' => 퇴실일, 'nights', 'guests', 'dc' => 할인적용, 'amount', 'status']]
 * @throws RuntimeException 읽을 수 없거나 열을 못 찾으면 (오류 문구에 셀 내용은 넣지 않는다)
 */
function rooms_xls_parse(string $path, string $fileName): array
{
    $rows = sheet_read($path, $fileName);
    $title = preg_replace('/\s+/u', '', (string) ($rows[0][0] ?? ''));
    $want = ['room' => ['시설/상품', '시설상품', '상품명', '객실'], 'period' => ['숙박기간'], 'guests' => ['입실', '입실인원'],
             'dc' => ['할인적용', '할인'], 'amount' => ['결제금액(위약금포함)', '결제금액'], 'status' => ['상태', '예약상태']];
    $cols = null;
    $start = 0;
    foreach (array_slice($rows, 0, 10) as $h => $head) {
        $c = [];
        foreach ($head as $i => $v) {
            $v = preg_replace('/\s+/u', '', (string) $v);
            foreach ($want as $k => $names) if (!isset($c[$k]) && in_array($v, $names, true)) { $c[$k] = $i; break; }
        }
        if (isset($c['room'], $c['period'], $c['guests'])) { $cols = $c; $start = $h + 1; break; }
    }
    if (!$cols) throw new RuntimeException("'입실예정 숙박상품 목록' 또는 '퇴실완료 상품 목록' 엑셀이 아닙니다. 머리글에서 시설/상품 · 숙박기간 · 입실 열을 찾지 못했습니다.");
    $out = [];
    foreach (array_slice($rows, $start) as $r) {
        $room = trim((string) ($r[$cols['room']] ?? ''));
        if ($room === '' || !preg_match_all('/\d{4}-\d{2}-\d{2}/', (string) ($r[$cols['period']] ?? ''), $m) || count($m[0]) < 2) continue; // 쪽 번호·빈 줄
        [$from, $to] = [$m[0][0], $m[0][1]];
        if (!valid_date($from) || !valid_date($to) || $to <= $from) continue;
        $out[] = [
            'room' => rooms_xls_name($room),
            'from' => $from, 'to' => $to, 'nights' => (int) round((strtotime($to) - strtotime($from)) / 86400),
            'guests' => (int) round((float) ($r[$cols['guests']] ?? 0)),
            'dc' => isset($cols['dc']) ? trim((string) ($r[$cols['dc']] ?? '')) : '',
            'amount' => isset($cols['amount']) ? (int) round((float) ($r[$cols['amount']] ?? 0)) : 0,
            'status' => isset($cols['status']) ? trim((string) ($r[$cols['status']] ?? '')) : '',
        ];
    }
    if (!$out) throw new RuntimeException('엑셀에 예약이 없습니다.');
    // 제목('퇴실완료 상품 목록')이나 상태('체크아웃')로 퇴실완료 엑셀을 알아본다
    $checkout = str_contains($title, '퇴실') || !array_filter($out, fn($s) => !preg_match('/체크아웃|퇴실/u', $s['status']));
    return ['kind' => $checkout ? 'checkout' : 'checkin', 'stays' => $out];
}

/** "별A(4인실)외 0건" → "별A" */
function rooms_xls_name(string $s): string
{
    $s = preg_replace('/\s*외\s*\d+\s*건\s*$/u', '', $s);
    return trim(preg_replace('/\s*[(（].*$/u', '', $s));
}

/** 취소·환불된 예약 */
function rooms_xls_cancelled(string $status): bool
{
    return (bool) preg_match('/취소|환불|반환/u', $status);
}

/** 할인적용 문구 → 할인사유 키 (모르면 null) */
function rooms_xls_dc_reason(string $dc): ?string
{
    foreach ([['다자녀', 'multichild'], ['지역', 'local'], ['군민', 'local'], ['장애', 'disabled'], ['유공', 'disabled'], ['단체', 'group']] as [$kw, $k]) {
        if (str_contains($dc, $kw)) return $k;
    }
    return null;
}

/** 엑셀 객실명 → 사이트 객실 id (공백·괄호 무시) */
function rooms_xls_product(string $name, array $products): ?array
{
    $norm = fn(string $s) => preg_replace('/[\s()（）]/u', '', $s);
    foreach ($products as $p) if ($p['grp'] === 'room' && $norm($p['name']) === $norm($name)) return $p;
    return null;
}

/** 객실 판매 줄 하나 (items_parse 의 일일객실판매 줄과 같은 모양) */
function rooms_xls_line(array $p, string $date, int $guests, string $dcText, array $vouchers, int $nights = 1): array
{
    $rate = rate_for_date($date);
    $reason = $dcText !== '' ? rooms_xls_dc_reason($dcText) : null;
    $dc = $dcText !== '';
    $unit = room_price($p, $rate, $dc, $reason);
    $season = $rate === 'peak' ? season_for('room', $date) : null;
    return ['product_id' => (int) $p['id'], 'grp' => 'room', 'name' => $p['name'], 'is_free' => 0, 'rate' => $rate, 'season' => $season['name'] ?? null,
        'discounted' => (int) ($dc && $unit !== room_price($p, $rate)), 'dc_reason' => $reason, 'unit_price' => $unit, 'qty' => 1, 'guests' => max(0, $guests),
        'amount' => $unit, 'refund_expected' => room_refund($p, $rate), 'vouchers' => $vouchers ?: array_fill_keys(voucher_denoms(), 0),
        'stay_nights' => $nights > 1 ? min($nights, 30) : 0]; // 숙박기간 '2박3일' → 연박 2박 (1박2일은 연박 아님)
}

/**
 * 그 날 묵는 예약으로 객실 입력칸을 채운다 (입실인원·요금구분·할인·할인사유). 지역상품권 환급·메모는 그대로.
 * @return array{0: array, 1: array} [$payload, $report]
 */
function rooms_fill_apply(array $payload, array $fill, string $workDate): array
{
    $products = products_at($workDate);
    $rep = ['rooms' => [], 'warn' => [], 'cancel' => 0, 'unknown' => [], 'guests' => 0];
    $old = [];
    foreach ($payload['lines'] as $l) if ($l['grp'] === 'room') $old[(int) $l['product_id']] = $l;
    $lines = [];
    foreach ($fill['stays'] as $s) {
        $p = rooms_xls_product($s['room'], $products);
        if (!$p) { $rep['unknown'][$s['room']] = true; continue; }
        $pid = (int) $p['id'];
        if (isset($lines[$pid])) { $rep['warn'][] = "{$p['name']}: 같은 날 예약이 2건이라 첫 예약만 넣었습니다."; continue; }
        $line = rooms_xls_line($p, $workDate, $s['guests'], $s['dc'], $old[$pid]['vouchers'] ?? [], $s['nights']);
        $unit = $line['unit_price'];
        if ($s['dc'] !== '' && !$line['dc_reason']) $rep['warn'][] = "{$p['name']}: 할인적용 '{$s['dc']}'에 맞는 할인사유가 없어 직접 골라야 합니다.";
        $max = (int) $p['max_people'];
        if ($s['guests'] <= 0) $rep['warn'][] = "{$p['name']}: 입실 인원이 비어 있습니다. 입실인원을 입력하세요.";
        elseif ($max > 0 && $s['guests'] > $max) $rep['warn'][] = "{$p['name']}: 입실 {$s['guests']}명이 최대인원({$max}명)을 넘습니다.";
        $perNight = $s['nights'] > 0 ? intdiv($s['amount'], $s['nights']) : $s['amount'];
        if ($s['amount'] && $perNight !== $unit) $rep['warn'][] = "{$p['name']}: 엑셀 결제금액 " . number_format($s['amount']) . '원' . ($s['nights'] > 1 ? " ({$s['nights']}박)" : '') . ' ≠ 사이트 금액 ' . number_format($unit) . '원 — 요금구분·할인을 확인하세요.';
        if ($s['nights'] > 1) $rep['warn'][] = "{$p['name']}: {$s['nights']}박 예약(" . date('n.j', strtotime($s['from'])) . '~' . date('n.j', strtotime($s['to'])) . ") — 연박 {$s['nights']}박으로 골랐습니다. 다음 날 객실판매에도 이 객실을 입력하세요.";
        $lines[$pid] = $line;
        $rep['rooms'][] = ['name' => $p['name'], 'guests' => $s['guests'], 'dc' => $s['dc'], 'nights' => $s['nights']];
        $rep['guests'] += $s['guests'];
    }
    $rep['cancel'] = $fill['cancel'];
    // 엑셀에 없는 객실이라도 상품권 환급을 이미 입력했으면 그대로 둔다
    foreach ($old as $pid => $l) if (!isset($lines[$pid]) && array_sum($l['vouchers'] ?? []) > 0) {
        $lines[$pid] = $l;
        $rep['warn'][] = "{$l['name']}: 엑셀에 없지만 입력된 지역상품권 환급이 있어 그대로 두었습니다.";
    }
    $payload['lines'] = array_values($lines);
    return [$payload, $rep];
}

/** 객실판매관리 작성 화면 위쪽: 엑셀 올리기 칸 (+ 채운 결과) */
function rooms_fill_panel(string $workDate, ?array $journal, ?array $rep): void
{
    $fill = $_SESSION[ROOMS_FILL_KEY] ?? null;
    ?>
<section class="card sales-fill no-print">
  <div class="card-head">
    <h2>예약 엑셀로 채우기 <small class="muted">산림청 통합운영시스템 '입실예정 숙박상품 목록' · '퇴실완료 상품 목록' 엑셀(.xls)</small></h2>
  </div>
  <form method="post" action="<?= e(url('rooms_fill.php')) ?>" enctype="multipart/form-data" data-sales-fill>
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($journal['id'] ?? 0) ?>">
    <input type="hidden" name="date" value="<?= e($workDate) ?>">
    <input type="file" name="file" accept=".xls,.xlsx" required>
    <button class="btn">올려서 채우기</button>
  </form>
  <p class="muted small"><b>입실예정 목록</b>을 올리면 그 날 예약된 객실의 <b>입실인원·요금구분·할인(할인사유)</b>이 아래 칸에 채워집니다. 확인한 뒤 저장(결재 올리기)해야 반영됩니다. 지역상품권 환급은 직접 입력하세요.<br>
    <b>퇴실완료 목록</b>을 올리면(파일 종류는 자동으로 알아봄) 예약마다 묵은 날(입실일 ~ 퇴실 전날)을 계산해 <b>지난 날짜들의 일일객실판매</b>를 미리보기로 보여 주고, '반영하기'를 누르면 한꺼번에 만들거나 고칩니다.<br>
    <b>개인정보는 남지 않습니다</b> — 고객 이름·아이디·생년월일·전화번호·주문번호 열은 읽지 않고, 올린 파일도 저장하지 않습니다. 취소된 예약은 빠집니다.</p>
  <?php if ($rep && $fill): ?>
  <div class="flash flash-success"><b><?= e(date('Y.n.j', strtotime($fill['date']))) ?></b> 입실 예약 <?= count($rep['rooms']) ?>실 · <?= number_format($rep['guests']) ?>명으로 채웠습니다. <b>아직 저장되지 않았습니다</b> — 아래 내용을 확인하고 저장하세요.</div>
  <ul class="small sales-fill-list">
    <li>객실: <?= e(implode(' / ', array_map(fn($r) => $r['name'] . ' ' . $r['guests'] . '명' . (($r['nights'] ?? 1) > 1 ? ' 연박 ' . $r['nights'] . '박' : '') . ($r['dc'] !== '' ? ' (' . $r['dc'] . ')' : ''), $rep['rooms']))) ?></li>
    <?php if ($rep['cancel']): ?><li>취소된 예약 <?= (int) $rep['cancel'] ?>건은 뺐습니다.</li><?php endif ?>
    <?php if ($fill['others']): ?><li>이 날 묵지 않는 예약 <?= (int) $fill['others'] ?>건(다른 날짜)은 넣지 않았습니다.</li><?php endif ?>
    <?php if ($rep['unknown']): ?><li class="warn">사이트에 없는 객실: <?= e(implode(', ', array_keys($rep['unknown']))) ?> — 설정 › 상품·요금의 객실 이름을 엑셀과 같게 맞춰 주세요.</li><?php endif ?>
    <?php foreach ($rep['warn'] as $w): ?><li class="warn"><?= e($w) ?></li><?php endforeach ?>
  </ul>
  <?php endif ?>
</section>
<script>
document.querySelector('[data-sales-fill]')?.addEventListener('submit', (ev) => {
  const d = document.querySelector('form.card input[name=work_date]');
  if (d && d.value) ev.target.querySelector('input[name=date]').value = d.value;
});
</script>
    <?php
}

/* ───────────── 퇴실완료 엑셀: 여러 날짜의 일일객실판매를 한꺼번에 ───────────── */

const ROOMS_OUT_KEY = 'rooms_out';

/**
 * 퇴실완료 예약을 묵은 날짜별로 나눠, 날짜마다 새로 만들지 / 어떤 객실을 추가·수정할지 계산 (저장하지 않음)
 * 이미 있는 일일객실판매는 엑셀의 객실만 추가·수정하고, 엑셀에 없는 객실·지역상품권 환급·메모는 그대로 둔다.
 * @return array<string, array{journal: ?array, editable: bool, rooms: array, unknown: array, warn: array, changed: int}>
 */
function rooms_out_plan(array $stays, array $user): array
{
    $byDate = [];
    foreach ($stays as $s) {
        for ($d = $s['from']; $d < $s['to']; $d = date('Y-m-d', strtotime("$d +1 day"))) $byDate[$d][] = $s;
    }
    ksort($byDate);
    $plan = [];
    foreach ($byDate as $date => $list) {
        $st = db()->prepare("SELECT id FROM journals WHERE type = 'rooms' AND work_date = ?");
        $st->execute([$date]);
        $j = ($jid = (int) $st->fetchColumn()) ? journal_find($jid) : null;
        $old = [];
        if ($j) foreach (items_load($j)['lines'] as $l) if ($l['grp'] === 'room') $old[(int) $l['product_id']] = $l;
        $products = products_at($date);
        $item = ['journal' => $j, 'editable' => !$j || can_edit_journal($j, $user), 'rooms' => [], 'unknown' => [], 'warn' => [], 'changed' => 0];
        foreach ($list as $s) {
            $p = rooms_xls_product($s['room'], $products);
            if (!$p) { $item['unknown'][$s['room']] = true; continue; }
            $pid = (int) $p['id'];
            if (isset($item['rooms'][$pid])) { $item['warn'][] = "{$p['name']}: 같은 날 예약이 2건이라 첫 예약만 넣습니다."; continue; }
            $line = rooms_xls_line($p, $date, $s['guests'], $s['dc'], $old[$pid]['vouchers'] ?? [], $s['nights']);
            $o = $old[$pid] ?? null;
            $how = !$o ? 'add' : (((int) $o['guests'] !== $line['guests'] || (int) $o['discounted'] !== $line['discounted'] || ($o['dc_reason'] ?? null) !== $line['dc_reason'] || $o['rate'] !== $line['rate'] || (int) ($o['stay_nights'] ?? 0) !== $line['stay_nights']) ? 'change' : 'same');
            if ($how !== 'same') $item['changed']++;
            if ($s['dc'] !== '' && !$line['dc_reason']) $item['warn'][] = "{$p['name']}: 할인적용 '{$s['dc']}'에 맞는 할인사유가 없어 할인 없이 넣습니다 — 저장 후 직접 고르세요.";
            if ($line['guests'] <= 0) $item['warn'][] = "{$p['name']}: 입실 인원이 비어 있습니다.";
            $perNight = $s['nights'] > 0 ? intdiv($s['amount'], $s['nights']) : $s['amount'];
            if ($s['amount'] && $perNight !== $line['unit_price']) $item['warn'][] = "{$p['name']}: 엑셀 결제금액 " . number_format($s['amount']) . '원' . ($s['nights'] > 1 ? " ({$s['nights']}박)" : '') . ' ≠ 사이트 금액 ' . number_format($line['unit_price']) . '원';
            if ($s['dc'] !== '' && !$line['dc_reason']) { $line['discounted'] = 0; $line['unit_price'] = $line['amount'] = room_price($p, $line['rate']); }
            $item['rooms'][$pid] = ['line' => $line, 'how' => $how, 'old' => $o, 'nights' => $s['nights'], 'dc' => $s['dc']];
        }
        $plan[$date] = $item;
    }
    return $plan;
}

/**
 * 계획대로 저장. 새 날짜는 만들고(결재 올리기 또는 임시저장), 있는 문서는 객실을 추가·수정한다.
 * 결재를 올린 적 있는 문서를 고치면 작성 화면에서 고칠 때와 같이 수정 이력을 남기고 결재를 처음부터 다시 올린다.
 * @return array{made: int, edited: int, skipped: int}
 */
function rooms_out_apply(array $plan, array $user, bool $submitNew, string $file): array
{
    $pdo = db();
    $res = ['made' => 0, 'edited' => 0, 'skipped' => 0];
    $note = "퇴실완료 엑셀 ($file)";
    foreach ($plan as $date => $item) {
        if (!$item['rooms'] || !$item['changed']) continue;
        if (!$item['editable']) { $res['skipped']++; continue; }
        $pdo->beginTransaction();
        try {
            $j = $item['journal'] ? journal_find((int) $item['journal']['id']) : null; // 계획 뒤에 바뀌었을 수 있어 다시 읽음
            if ($j) {
                $payload = items_load($j);
                $lines = [];
                foreach ($payload['lines'] as $l) if ($l['grp'] === 'room') $lines[(int) $l['product_id']] = $l;
                foreach ($item['rooms'] as $pid => $r) {
                    $r['line']['vouchers'] = $lines[$pid]['vouchers'] ?? $r['line']['vouchers']; // 입력된 상품권 환급은 그대로
                    $lines[$pid] = $r['line'];
                }
                $payload['lines'] = array_values($lines);
                $revision = is_revision_edit($j);
                if ($revision) { $before = journal_snapshot($j); $prev = approval_summary($j); }
                items_save((int) $j['id'], 'rooms', $payload);
                $pdo->commit();
                // 결재 다시 올리기는 작성 화면(write.php)처럼 저장을 마친 뒤에 (journal_submit 이 따로 트랜잭션을 연다)
                if ($revision) {
                    $fresh = journal_find((int) $j['id']);
                    $changes = snapshot_diff($before, journal_snapshot($fresh));
                    if ($changes) {
                        revision_record($j, $user, $changes, $prev, $note);
                        journal_submit($fresh, (int) $user['rank_level']);
                    }
                }
                journal_log((int) $j['id'], $user, $revision ? '수정 (결재 다시 올림)' : '임시저장 수정', $note);
                $res['edited']++;
                continue;
            } else {
                $pdo->prepare("INSERT INTO journals (type, team_id, work_date, author_id, weather, content, remarks, status) VALUES ('rooms', NULL, ?, ?, NULL, '', NULL, 'draft')")
                    ->execute([$date, $user['id']]);
                $id = (int) $pdo->lastInsertId();
                items_save($id, 'rooms', ['lines' => array_values(array_column($item['rooms'], 'line')), 'vouchers' => array_fill_keys(voucher_denoms(), 0)]);
                $pdo->commit();
                if ($submitNew) journal_submit(journal_find($id), (int) $user['rank_level']);
                journal_log($id, $user, $submitNew ? '작성 · 결재 올리기' : '작성 (임시저장)', $note);
                $res['made']++;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
    return $res;
}
