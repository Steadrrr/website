<?php
defined('APP_ROOT') || exit;

/*
 * 매표 판매 엑셀(산림청 통합운영시스템 '상품판매현황' 등) 읽기 — 설정 › 매출 가져오기(여러 날)와
 * 매출보고 작성 화면의 '매표 엑셀로 채우기'(하루)가 함께 쓴다.
 * 엑셀의 예약자·판매자 등 개인정보는 읽지 않고, 날짜·상품명·상품구분·결제수단·단가·수량·금액만 합계로 묶는다.
 */
require_once __DIR__ . '/xlsx_read.php';

/** 엑셀 머리글에서 열 찾기 */
function imp_columns(array $head): array
{
    // 머리글 이름 → 항목 (공백·"(원)" 같은 단위는 빼고 비교). 산림청 통합운영시스템: 사용일자·상품구분·상품명·결제수단·단가(원)·수량·금액(원)
    $want = ['date' => ['날짜', '일자', '판매일', '사용일자', '이용일자', '판매일자'], 'name' => ['상품명', '상품', '권종'], 'cat' => ['상품구분', '구분'],
             'pay' => ['지불방법', '결제', '결제방법', '결제수단', '지불'], 'price' => ['단가'], 'qty' => ['수량', '매수'], 'amount' => ['판매금액', '판매액', '합계금액']];
    $cols = [];
    $gold = null; // '금액': 판매금액 열이 따로 있으면 단가, 없으면 판매금액
    foreach ($head as $i => $h) {
        $h = preg_replace('/\s+|\((원|개|매|명)\)/u', '', (string) $h);
        if ($h === '금액' && $gold === null) { $gold = $i; continue; }
        foreach ($want as $k => $names) if (!isset($cols[$k]) && in_array($h, $names, true)) { $cols[$k] = $i; break; }
    }
    if ($gold !== null) {
        if (isset($cols['amount'])) $cols['price'] ??= $gold;
        else $cols['amount'] = $gold;
    }
    return $cols;
}

/** 가져올 곳 선택지: 'p:상품id' (입장권) / 'g:분야' (프로그램 판매) / '' (가져오지 않음) */
function imp_targets(): array
{
    $t = [];
    foreach (products_all() as $p) if ($p['grp'] === 'ticket' && empty($p['sys_key'])) $t['p:' . $p['id']] = '입장권 · ' . $p['name'] . ($p['is_free'] ? ' (무료)' : ' (' . number_format((int) $p['price']) . '원)') . ($p['is_active'] ? '' : ' · 판매중지');
    foreach (PROGRAM_TYPES as $k => $label) $t['g:' . $k] = '프로그램 판매 · ' . $label;
    return $t;
}

/** 상품명으로 가져올 곳 짐작: 전에 고른 곳(imp_map_remember) → 같은 이름의 입장권 → 이름에 분야가 들어 있으면 프로그램 → 없음 */
function imp_guess(string $name, array $targets): string
{
    $saved = imp_map_saved();
    if (array_key_exists($name, $saved) && ($saved[$name] === '' || isset($targets[$saved[$name]]))) return $saved[$name];
    $norm = fn(string $s) => preg_replace('/[\s()（）]/u', '', $s);
    foreach (products_all() as $p) if ($p['grp'] === 'ticket' && empty($p['sys_key']) && $norm($p['name']) === $norm($name)) return 'p:' . $p['id'];
    foreach ([['숲해설(용문산)', 'guide2'], ['용문산', 'guide2'], ['숲해설', 'guide'], ['유아숲(직영)', 'kidsdirect'], ['유아숲', 'kidsforest'], ['산림치유', 'healing']] as [$kw, $pt]) {
        if (str_contains($norm($name), $norm($kw)) && isset($targets["g:$pt"])) return "g:$pt";
    }
    return '';
}

/** 전에 고른 가져올 곳 [엑셀 상품명 => 'p:id' | 'g:분야' | ''(가져오지 않음)] */
function imp_map_saved(): array
{
    $m = json_decode((string) setting('sales_xls_map', '[]'), true);
    return is_array($m) ? $m : [];
}

/** 고른 가져올 곳을 기억 (다음 파일에서 같은 상품명은 자동으로) */
function imp_map_remember(array $map): void
{
    $saved = imp_map_saved();
    foreach ($map as $name => $to) if ((string) $name !== '') $saved[(string) $name] = (string) $to;
    setting_set('sales_xls_map', json_encode($saved, JSON_UNESCAPED_UNICODE));
}

/** 시설대관 줄 (상품명·구분에 '대관'): 매표 엑셀에서는 대관 행사 입장 인원(행사참석, 보통 0원)이라 다른 상품처럼 가져올 곳을 골라 넣는다.
 *  대관 요금(대관 시간·건수)은 엑셀에 없으므로 매출보고의 시설대관 칸에 직접 입력 — 화면에 안내만 한다. */
function imp_is_rental(string $name, string $cat = ''): bool
{
    return str_contains($name, '대관') || str_contains($cat, '대관');
}

/**
 * 판매 엑셀 읽기
 * @return array{agg: array, names: array, bad: array, rows: int}  agg = 날짜 => 상품명 => [['pay','price','qty','amount','rows'], ...]
 * @throws RuntimeException 읽을 수 없거나 열을 못 찾으면
 */
function imp_parse(string $path, string $fileName): array
{
    $rows = sheet_read($path, $fileName);
    // 머리글 줄 찾기: 위쪽 10줄 안에서 날짜·상품명·수량·금액 열이 모두 있는 줄 (제목·출력일시 줄이 위에 있어도 됨)
    $start = 1;
    $cols = imp_columns($rows[0] ?? []);
    $missing = array_diff(['date', 'name', 'qty', 'amount'], array_keys($cols));
    for ($h = 1; $missing && $h < min(10, count($rows)); $h++) {
        $try = imp_columns($rows[$h]);
        if (!array_diff(['date', 'name', 'qty', 'amount'], array_keys($try))) { $cols = $try; $missing = []; $start = $h + 1; }
    }
    // 머리글 없이 첫 줄부터 판매 내역이면 기본 열 순서(날짜·상품명·지불방법·금액·수량·판매금액)로 읽는다
    if ($missing && count($rows[0] ?? []) >= 6 && sheet_date($rows[0][0] ?? null)) {
        $cols = ['date' => 0, 'name' => 1, 'pay' => 2, 'price' => 3, 'qty' => 4, 'amount' => 5];
        $missing = [];
        $start = 0;
    }
    if ($missing) {
        throw new RuntimeException('엑셀 위쪽(머리글)에서 ' . implode(', ', array_map(fn($k) => ['date' => '날짜', 'name' => '상품명', 'qty' => '수량', 'amount' => '판매금액'][$k], $missing)) . ' 열을 찾지 못했습니다. 머리글이 없으면 A~F열이 날짜·상품명·지불방법·금액·수량·판매금액 순서여야 합니다.');
    }
    $agg = [];    // 날짜 => 상품명 => 지불|단가 => 합계
    $names = [];  // 상품명 => 합계
    $bad = [];
    $dataRows = 0;
    foreach (array_slice($rows, $start) as $i => $r) {
        if (array_filter($r, fn($v) => is_string($v) && in_array(trim($v), ['합계', '소계', '총계', '총합계'], true))) continue; // 합계 줄
        $date = sheet_date($r[$cols['date']] ?? null);
        $name = trim((string) ($r[$cols['name']] ?? ''));
        $qty = (int) round((float) ($r[$cols['qty']] ?? 0));
        $amt = (int) round((float) ($r[$cols['amount']] ?? 0));
        if (!$date && $name === '' && !$qty && !$amt) continue; // 빈 줄
        if (!$date || $name === '') { if (count($bad) < 20) $bad[] = ($i + $start + 1) . '행'; continue; }
        $dataRows++;
        $cat = isset($cols['cat']) ? trim((string) ($r[$cols['cat']] ?? '')) : '';
        $pay = isset($cols['pay']) && str_contains((string) ($r[$cols['pay']] ?? ''), '현금') ? '현금' : (isset($cols['pay']) ? '카드' : '카드');
        $price = isset($cols['price']) ? (int) round((float) ($r[$cols['price']] ?? 0)) : ($qty ? intdiv($amt, $qty) : 0);
        $k = "$pay|$price";
        $agg[$date][$name][$k] ??= ['pay' => $pay, 'price' => $price, 'qty' => 0, 'amount' => 0, 'rows' => 0];
        $agg[$date][$name][$k]['qty'] += $qty;
        $agg[$date][$name][$k]['amount'] += $amt;
        $agg[$date][$name][$k]['rows']++;
        $names[$name] ??= ['rows' => 0, 'qty' => 0, 'amount' => 0, 'cash' => 0, 'prices' => [], 'cats' => []];
        if ($cat !== '') $names[$name]['cats'][$cat] = true;
        $names[$name]['rows']++;
        $names[$name]['qty'] += $qty;
        $names[$name]['amount'] += $amt;
        if ($pay === '현금') $names[$name]['cash'] += $amt;
        $names[$name]['prices'][$price] = true;
    }
    if (!$agg) throw new RuntimeException('가져올 판매 내역이 없습니다. 파일을 확인하세요.');
    ksort($agg);
    uasort($names, fn($a, $b) => $b['amount'] <=> $a['amount'] ?: $b['qty'] <=> $a['qty']);
    return ['agg' => array_map(fn($d) => array_map('array_values', $d), $agg), 'names' => $names, 'bad' => $bad, 'rows' => $dataRows];
}

/* ───────────── 매출보고 작성 화면: 매표 엑셀로 채우기 (하루) ───────────── */

const SALES_FILL_KEY = 'sales_fill';

/**
 * 올린 엑셀의 그 날 판매를 매출보고 입력값에 채운다 (저장은 작성자가 확인 후 직접).
 * 입장권·프로그램 판매·입장권 현금만 바꾸고 시설대관·대관 숙박·메모 등은 그대로.
 * 엑셀의 '시설대관(행사참석)' 줄도 다른 상품처럼 가져올 곳(예: 무료 입장권)을 고르면 들어간다 (대관 요금은 시설대관 칸에 직접).
 * @return array{0: array, 1: array} [$payload, $report]
 */
function sales_fill_apply(array $payload, array $fill, string $workDate): array
{
    $targets = imp_targets();
    $products = products_at($workDate);
    $rep = ['tickets' => [], 'progs' => [], 'rental' => [], 'skip' => [], 'warn' => [], 'cash' => 0, 'map' => []];
    $qty = $prog = [];
    foreach ($fill['byName'] as $name => $groups) {
        $cat = implode(', ', $fill['cats'][$name] ?? []);
        $q = array_sum(array_column($groups, 'qty'));
        $a = array_sum(array_column($groups, 'amount'));
        $row = ['name' => $name, 'cat' => $cat, 'qty' => $q, 'amount' => $a];
        if (imp_is_rental($name, $cat)) $rep['rental'][] = $row; // 안내용 (가져올 곳은 아래에서 다른 상품과 같이)
        $to = imp_guess($name, $targets);
        $rep['map'][$name] = $to;
        if (str_starts_with($to, 'p:') && ($p = $products[(int) substr($to, 2)] ?? null)) {
            $pid = (int) $p['id'];
            $qty[$pid] = ($qty[$pid] ?? 0) + $q;
            $unit = ticket_price($p, $workDate)[0];
            foreach ($groups as $g) {
                if ($g['pay'] === '현금') $rep['cash'] += $g['amount'];
                if ($g['qty'] && $g['price'] !== ($p['is_free'] ? 0 : $unit)) $rep['warn'][$name] = "{$name}: 엑셀 단가 " . number_format($g['price']) . "원 ≠ 사이트 단가 " . number_format($p['is_free'] ? 0 : $unit) . '원 (매출보고 금액은 사이트 단가 × 매수로 계산)';
            }
            $rep['tickets'][] = $row + ['to' => $p['name']];
        } elseif (str_starts_with($to, 'g:') && isset(PROGRAM_TYPES[substr($to, 2)])) {
            $pt = substr($to, 2);
            $prog[$pt] ??= ['sessions' => 0, 'paid' => 0, 'free' => 0, 'amount' => 0];
            foreach ($groups as $g) {
                $prog[$pt]['sessions'] += $g['rows'];
                $prog[$pt][$g['amount'] > 0 ? 'paid' : 'free'] += $g['qty'];
                $prog[$pt]['amount'] += $g['amount'];
            }
            $rep['progs'][] = $row + ['to' => PROGRAM_TYPES[$pt]];
        } else {
            $rep['skip'][] = $row;
        }
    }
    // 입장권(쉬자파크숙박 자동 줄 제외)·직접 입력 프로그램 판매를 엑셀 값으로 바꾼다
    $lines = array_values(array_filter($payload['lines'], fn($l) => !($l['grp'] === 'ticket' && empty($products[(int) $l['product_id']]['sys_key'] ?? null)) && $l['grp'] !== 'program'));
    foreach ($qty as $pid => $q) {
        $p = $products[$pid];
        [$unit, $season] = ticket_price($p, $workDate);
        $lines[] = ['product_id' => $pid, 'grp' => 'ticket', 'name' => $p['name'], 'is_free' => (int) $p['is_free'], 'rate' => null, 'season' => $season,
            'discounted' => 0, 'unit_price' => $unit, 'qty' => $q, 'guests' => 0, 'amount' => $unit * $q];
    }
    $auto = program_day_summary($workDate);
    foreach ($prog as $pt => $v) {
        $lines[] = program_sale_line($pt, $v['sessions'], $v['paid'], $v['free'], $v['amount'], false);
        if (isset($auto[$pt])) $rep['warn'][] = PROGRAM_TYPES[$pt] . ': 그 날 운영보고가 있어 운영보고 값(유료 ' . $auto[$pt]['paid'] . '·무료 ' . $auto[$pt]['free'] . '명·' . number_format($auto[$pt]['amount']) . '원)을 씁니다.';
    }
    $payload['lines'] = $lines;
    $payload['ticket_cash'] = $rep['cash'];
    return [$payload, $rep];
}

/** 매출보고 작성 화면 위쪽: 엑셀 올리기 칸 (+ 채운 결과) */
function sales_fill_panel(string $workDate, ?array $journal, ?array $rep): void
{
    $fill = $_SESSION[SALES_FILL_KEY] ?? null;
    $back = $journal ? 'write.php?id=' . (int) $journal['id'] : 'write.php?type=sales&date=' . $workDate;
    $row = fn(array $r) => '<b>' . e($r['name']) . '</b>' . ($r['cat'] !== '' ? ' <small class="muted">' . e($r['cat']) . '</small>' : '') . ' ' . number_format($r['qty']) . '매 · ' . number_format($r['amount']) . '원';
    ?>
<section class="card sales-fill no-print">
  <div class="card-head">
    <h2>매표 엑셀로 채우기 <small class="muted">산림청 통합운영시스템 '상품판매현황' 엑셀(.xls)</small></h2>
  </div>
  <form method="post" action="<?= e(url('sales_fill.php')) ?>" enctype="multipart/form-data" class="actions" style="justify-content:flex-start" data-sales-fill>
    <?= csrf_field() ?><input type="hidden" name="act" value="upload"><input type="hidden" name="id" value="<?= (int) ($journal['id'] ?? 0) ?>">
    <input type="hidden" name="date" value="<?= e($workDate) ?>">
    <input type="file" name="file" accept=".xls,.xlsx,.csv" required>
    <button class="btn">올려서 채우기</button>
  </form>
  <p class="muted small">그 날의 판매 내역 엑셀을 올리면 <b>입장권</b>과 <b>프로그램 판매</b>, 입장권 <b>현금</b>이 아래 칸에 채워집니다. 확인한 뒤 저장(결재 올리기)해야 반영됩니다.<br>
    엑셀의 <b>'시설대관'(행사참석)</b>은 대관 행사로 들어온 <b>입장 인원</b>이라, 가져올 곳(예: 무료 입장권)을 고르면 입장권으로 들어갑니다. <b>대관 요금</b>(대관 시간·건수)은 엑셀에 없으니 아래 시설대관 칸에 직접 입력하세요. 엑셀의 예약자·판매자 정보는 저장하지 않습니다.</p>
  <?php if ($rep && $fill): ?>
  <div class="flash flash-success"><b><?= e(date('Y.n.j', strtotime($fill['date']))) ?></b> 판매 내역(<?= e($fill['file']) ?>)으로 채웠습니다. <b>아직 저장되지 않았습니다</b> — 아래 내용을 확인하고 저장하세요.
    <?php if ($fill['others']): ?><br>엑셀의 다른 날짜(<?= e(implode(', ', array_map(fn($d) => date('n.j', strtotime($d)), $fill['others']))) ?>)는 쓰지 않았습니다.<?php endif ?></div>
  <ul class="small sales-fill-list">
    <?php if ($rep['tickets']): ?><li>입장권: <?= implode(' / ', array_map(fn($r) => $row($r) . ' → ' . e($r['to']), $rep['tickets'])) ?> · 현금 <?= number_format($rep['cash']) ?>원</li><?php endif ?>
    <?php if ($rep['progs']): ?><li>프로그램 판매: <?= implode(' / ', array_map(fn($r) => $row($r) . ' → ' . e($r['to']), $rep['progs'])) ?></li><?php endif ?>
    <?php if ($rep['rental']): ?><li>시설대관(행사 입장 인원): <?= implode(' / ', array_map(fn($r) => $row($r) . (($rep['map'][$r['name']] ?? '') === '' ? ' → <b class="warn">가져올 곳을 고르세요</b>' : ''), $rep['rental'])) ?> — 대관 요금은 시설대관 칸에 직접 입력하세요.</li><?php endif ?>
    <?php foreach ($rep['warn'] as $w): ?><li class="warn"><?= e($w) ?></li><?php endforeach ?>
  </ul>
  <?php if ($rep['skip'] || $rep['tickets'] || $rep['progs']): $targets = imp_targets(); ?>
  <details <?= $rep['skip'] ? 'open' : '' ?>>
    <summary class="small"><?= $rep['skip'] ? '<b class="warn">입력하지 않은 상품 ' . count($rep['skip']) . '개</b> — 가져올 곳을 고르면 다음부터 자동으로 들어갑니다' : '엑셀 상품명마다 가져올 곳 바꾸기' ?></summary>
    <form method="post" action="<?= e(url('sales_fill.php')) ?>">
      <?= csrf_field() ?><input type="hidden" name="act" value="map"><input type="hidden" name="back" value="<?= e($back) ?>">
      <table class="table compact">
        <thead><tr><th>엑셀 상품명</th><th class="right">매수 · 금액</th><th>가져올 곳</th></tr></thead>
        <tbody>
        <?php $i = 0; foreach ([...$rep['skip'], ...$rep['tickets'], ...$rep['progs']] as $r): $cur = $rep['map'][$r['name']] ?? ''; ?>
          <tr class="<?= $cur === '' ? 'inactive' : '' ?>"><td><b><?= e($r['name']) ?></b><?= $r['cat'] !== '' ? ' <small class="muted">' . e($r['cat']) . '</small>' : '' ?><input type="hidden" name="name[<?= $i ?>]" value="<?= e($r['name']) ?>"></td>
            <td class="right"><?= number_format($r['qty']) ?>매 · <?= number_format($r['amount']) ?>원</td>
            <td><select name="map[<?= $i ?>]"><option value="">가져오지 않음</option>
              <?php foreach ($targets as $k => $label): ?><option value="<?= e($k) ?>" <?= $cur === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></td></tr>
        <?php $i++; endforeach ?>
        </tbody>
      </table>
      <div class="actions"><button class="btn small">가져올 곳 저장 · 다시 채우기</button></div>
    </form>
  </details>
  <?php endif ?>
  <?php endif ?>
</section>
<script>
// 올릴 때 아래 입력칸의 일자를 함께 보낸다 (엑셀에 여러 날짜가 있으면 이 날짜를 씀)
document.querySelector('[data-sales-fill]')?.addEventListener('submit', (ev) => {
  const d = document.querySelector('form.card input[name=work_date]');
  if (d && d.value) ev.target.querySelector('input[name=date]').value = d.value;
});
</script>
    <?php
}
