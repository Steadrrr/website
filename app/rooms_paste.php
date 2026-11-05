<?php
defined('APP_ROOT') || exit;

/*
 * 객실판매관리 작성 화면의 '예약 복사해서 붙여넣기' — 산림청 통합운영시스템 예약 목록에서 객실·일정·할인·인원만 복사해 붙여넣는다
 *   예약마다 두 줄:  구름A(2인실) 외1건<TAB>1박2일
 *                   2026-11-06~2026-11-07<TAB>지역주민<TAB>2      (할인 칸은 비어 있을 수 있음, 마지막 숫자 = 입실 인원)
 *   → 그 날 작성 화면의 입력칸만 채움 (저장은 작성자가)
 * 개인정보 보호: 이 네 가지 외의 글은 읽지 않고, 붙여넣은 글은 저장하지 않는다. 세션에는 객실 이름별 일정·인원·할인만 둔다.
 */

const ROOMS_FILL_KEY = 'rooms_fill';
const ROOMS_PASTE_MAX = 20000; // 붙여넣기 글자 수 한도

/**
 * 붙여넣은 예약 목록 → 예약마다 ['room' => 객실명, 'from' => 입실일, 'to' => 퇴실일, 'nights', 'guests', 'dc' => 할인적용]
 * 날짜 줄 앞의 줄이 객실 줄. 한 줄에 객실·날짜를 함께 붙여넣어도 읽는다.
 */
function rooms_paste_parse(string $text): array
{
    $out = [];
    $room = null;
    foreach (preg_split('/\R/u', mb_substr($text, 0, ROOMS_PASTE_MAX)) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (!preg_match('/(\d{4}-\d{2}-\d{2})\s*~\s*(\d{4}-\d{2}-\d{2})/u', $line, $m, PREG_OFFSET_CAPTURE)) {
            $room = $line; // 객실 줄 (다음 날짜 줄과 짝)
            continue;
        }
        $before = trim(substr($line, 0, $m[0][1]));
        if ($before !== '') $room = $before;
        [$from, $to] = [$m[1][0], $m[2][0]];
        $rest = trim(substr($line, $m[0][1] + strlen($m[0][0])));
        $name = $room !== null ? rooms_paste_name($room) : '';
        $room = null;
        if ($name === '' || !valid_date($from) || !valid_date($to) || $to <= $from) continue;
        $guests = preg_match('/(\d+)\s*명?\s*$/u', $rest, $g) ? (int) $g[1] : 0;
        $dc = trim(preg_replace(['/(\d+)\s*명?\s*$/u', '/\s+/u'], ['', ' '], $rest));
        $out[] = ['room' => $name, 'from' => $from, 'to' => $to, 'nights' => (int) round((strtotime($to) - strtotime($from)) / 86400),
                  'guests' => $guests, 'dc' => in_array($dc, ['-', '없음'], true) ? '' : mb_substr($dc, 0, 30)];
    }
    return $out;
}

/** "구름A(2인실) 외1건\t1박2일" → "구름A" */
function rooms_paste_name(string $s): string
{
    $s = preg_split('/\t/u', $s)[0];
    $s = preg_replace(['/\s*\d+\s*박\s*\d+\s*일\s*$/u', '/\s*외\s*\d+\s*건\s*$/u'], '', trim($s));
    return trim(preg_replace('/\s*[(（].*$/u', '', $s));
}

/** 할인적용 문구 → 할인사유 키 (모르면 null) */
function rooms_paste_dc_reason(string $dc): ?string
{
    foreach ([['다자녀', 'multichild'], ['지역', 'local'], ['군민', 'local'], ['장애', 'disabled'], ['유공', 'disabled'], ['단체', 'group'], ['업무', 'work']] as [$kw, $k]) {
        if (str_contains($dc, $kw)) return $k;
    }
    return null;
}

/** 붙여넣은 객실명 → 사이트 객실 (공백·괄호 무시) */
function rooms_paste_product(string $name, array $products): ?array
{
    $norm = fn(string $s) => preg_replace('/[\s()（）]/u', '', $s);
    foreach ($products as $p) if ($p['grp'] === 'room' && $norm($p['name']) === $norm($name)) return $p;
    return null;
}

/** 객실 판매 줄 하나 (items_parse 의 일일객실판매 줄과 같은 모양) */
function rooms_paste_line(array $p, string $date, int $guests, string $dcText, array $vouchers, int $nights = 1): array
{
    $rate = rate_for_date($date);
    $reason = $dcText !== '' ? rooms_paste_dc_reason($dcText) : null;
    $dc = $dcText !== '';
    $unit = room_price($p, $rate, $dc, $reason);
    $season = $rate === 'peak' ? season_for('room', $date) : null;
    return ['product_id' => (int) $p['id'], 'grp' => 'room', 'name' => $p['name'], 'is_free' => 0, 'rate' => $rate, 'season' => $season['name'] ?? null,
        'discounted' => (int) ($dc && $unit !== room_price($p, $rate)), 'dc_reason' => $reason, 'unit_price' => $unit, 'qty' => 1, 'guests' => max(0, $guests),
        'amount' => $unit, 'refund_expected' => room_refund_stay($p, $date, $rate, $dc && $unit !== room_price($p, $rate) && !room_dc_refundable($rate, $reason), $nights > 1 ? min($nights, 30) : 0), 'vouchers' => $vouchers ?: array_fill_keys(voucher_denoms(), 0),
        'stay_nights' => $nights > 1 ? min($nights, 30) : 0]; // 숙박기간 '2박3일' → 연박 2박 (1박2일은 연박 아님)
}

/**
 * 그 날 묵는 예약으로 객실 입력칸을 채운다 (입실인원·요금구분·할인·할인사유). 지역상품권 환급·메모는 그대로.
 * @return array{0: array, 1: array} [$payload, $report]
 */
function rooms_fill_apply(array $payload, array $fill, string $workDate): array
{
    $products = products_at($workDate);
    $rep = ['rooms' => [], 'warn' => [], 'unknown' => [], 'guests' => 0];
    $old = [];
    foreach ($payload['lines'] as $l) if ($l['grp'] === 'room') $old[(int) $l['product_id']] = $l;
    $lines = [];
    foreach ($fill['stays'] as $s) {
        $p = rooms_paste_product($s['room'], $products);
        if (!$p) { $rep['unknown'][$s['room']] = true; continue; }
        $pid = (int) $p['id'];
        if (isset($lines[$pid])) { $rep['warn'][] = "{$p['name']}: 같은 날 예약이 2건이라 첫 예약만 넣었습니다."; continue; }
        $line = rooms_paste_line($p, $workDate, $s['guests'], $s['dc'], $old[$pid]['vouchers'] ?? [], $s['nights']);
        if ($s['dc'] !== '' && !$line['dc_reason']) $rep['warn'][] = "{$p['name']}: 할인적용 '{$s['dc']}'에 맞는 할인사유가 없어 직접 골라야 합니다.";
        $max = (int) $p['max_people'];
        if ($s['guests'] <= 0) $rep['warn'][] = "{$p['name']}: 입실 인원이 비어 있습니다. 입실인원을 입력하세요.";
        elseif ($max > 0 && $s['guests'] > $max) $rep['warn'][] = "{$p['name']}: 입실 {$s['guests']}명이 최대인원({$max}명)을 넘습니다.";
        if ($s['nights'] > 1) $rep['warn'][] = "{$p['name']}: {$s['nights']}박 예약(" . date('n.j', strtotime($s['from'])) . '~' . date('n.j', strtotime($s['to'])) . ") — 연박 {$s['nights']}박으로 골랐습니다. 다음 날 객실판매에도 이 객실을 입력하세요.";
        $lines[$pid] = $line;
        $rep['rooms'][] = ['name' => $p['name'], 'guests' => $s['guests'], 'dc' => $s['dc'], 'nights' => $s['nights']];
        $rep['guests'] += $s['guests'];
    }
    // 붙여넣은 목록에 없는 객실이라도 상품권 환급을 이미 입력했으면 그대로 둔다
    foreach ($old as $pid => $l) if (!isset($lines[$pid]) && array_sum($l['vouchers'] ?? []) > 0) {
        $lines[$pid] = $l;
        $rep['warn'][] = "{$l['name']}: 붙여넣은 목록에 없지만 입력된 지역상품권 환급이 있어 그대로 두었습니다.";
    }
    $payload['lines'] = array_values($lines);
    return [$payload, $rep];
}

/** 객실판매관리 작성 화면 위쪽: '예약 복사해서 붙여넣기' 버튼과 붙여넣기 창 (+ 채운 결과) */
function rooms_fill_panel(string $workDate, ?array $journal, ?array $rep): void
{
    $fill = $_SESSION[ROOMS_FILL_KEY] ?? null;
    ?>
<section class="card sales-fill no-print">
  <div class="card-head">
    <h2>예약으로 채우기 <small class="muted">산림청 통합운영시스템 예약 목록의 객실 · 일정 · 할인 · 인원</small></h2>
    <button type="button" class="btn" data-paste-open>예약 복사해서 붙여넣기</button>
  </div>
  <p class="muted small">예약 목록에서 <b>객실 · 숙박기간 · 할인 · 인원</b>만 복사해 붙여넣으면 그 날 묵는 객실의 <b>입실인원·요금구분·할인(할인사유)·연박</b>이 아래 칸에 채워집니다.
    확인한 뒤 저장(결재 올리기)해야 반영됩니다. 지역상품권 환급은 직접 입력하세요.</p>
  <?php if ($rep && $fill): ?>
  <div class="flash flash-success"><b><?= e(date('Y.n.j', strtotime($fill['date']))) ?></b> 예약 <?= count($rep['rooms']) ?>실 · <?= number_format($rep['guests']) ?>명으로 채웠습니다. <b>아직 저장되지 않았습니다</b> — 아래 내용을 확인하고 저장하세요.</div>
  <ul class="small sales-fill-list">
    <li>객실: <?= e(implode(' / ', array_map(fn($r) => $r['name'] . ' ' . $r['guests'] . '명' . (($r['nights'] ?? 1) > 1 ? ' 연박 ' . $r['nights'] . '박' : '') . ($r['dc'] !== '' ? ' (' . $r['dc'] . ')' : ''), $rep['rooms']))) ?></li>
    <?php if ($fill['others']): ?><li>이 날 묵지 않는 예약 <?= (int) $fill['others'] ?>건(다른 날짜)은 넣지 않았습니다.</li><?php endif ?>
    <?php if ($rep['unknown']): ?><li class="warn">사이트에 없는 객실: <?= e(implode(', ', array_keys($rep['unknown']))) ?> — 설정 › 상품·요금의 객실 이름을 예약 목록과 같게 맞춰 주세요.</li><?php endif ?>
    <?php foreach ($rep['warn'] as $w): ?><li class="warn"><?= e($w) ?></li><?php endforeach ?>
  </ul>
  <?php endif ?>
</section>
<dialog class="daily-dialog paste-dialog" data-paste-dialog>
  <form method="post" action="<?= e(url('rooms_fill.php')) ?>" data-paste-form>
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($journal['id'] ?? 0) ?>">
    <input type="hidden" name="date" value="<?= e($workDate) ?>">
    <h3>예약 복사해서 붙여넣기</h3>
    <p class="small">예약 목록에서 <b>객실 · 숙박기간 · 할인 · 인원</b> 칸만 복사해 아래에 붙여넣으세요 (Ctrl+V). 예약마다 두 줄입니다.<br>
      <span class="warn"><b>고객 이름 · 전화번호 · 생년월일 · 예약번호는 붙여넣지 마세요.</b></span> 붙여넣은 글은 저장하지 않습니다.</p>
    <textarea name="text" rows="12" maxlength="<?= ROOMS_PASTE_MAX ?>" required spellcheck="false" placeholder="구름A(2인실) 외1건&#9;1박2일&#10;2026-11-06~2026-11-07&#9;지역주민&#9;2&#10;구름B(2인실) 외1건&#9;1박2일&#10;2026-11-06~2026-11-07&#9;&#9;2"></textarea>
    <p class="small muted" data-paste-count></p>
    <div class="actions"><button type="button" class="btn ghost" data-paste-cancel>취소</button><button class="btn primary">채우기</button></div>
  </form>
</dialog>
<script>
(function () {
  const dlg = document.querySelector('[data-paste-dialog]');
  if (!dlg) return;
  const form = dlg.querySelector('form');
  const ta = form.querySelector('textarea');
  const count = form.querySelector('[data-paste-count]');
  document.querySelector('[data-paste-open]')?.addEventListener('click', () => { dlg.showModal(); ta.focus(); });
  form.querySelector('[data-paste-cancel]').addEventListener('click', () => dlg.close());
  // 붙여넣은 예약 수 (날짜 줄 수) 미리 보기
  ta.addEventListener('input', () => {
    const n = (ta.value.match(/\d{4}-\d{2}-\d{2}\s*~\s*\d{4}-\d{2}-\d{2}/g) || []).length;
    count.textContent = ta.value.trim() ? (n ? `예약 ${n}건을 찾았습니다.` : '예약 날짜(2026-11-06~2026-11-07 모양)를 찾지 못했습니다.') : '';
  });
  // 작성 화면의 일자를 바꿨으면 그 날짜로 채운다
  form.addEventListener('submit', () => {
    const d = document.querySelector('form.card input[name=work_date]');
    if (d && d.value) form.querySelector('input[name=date]').value = d.value;
  });
})();
</script>
    <?php
}
