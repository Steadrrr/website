<?php
/**
 * 설정 › 매출 가져오기 › 객실판매 (최고관리자): 월별 '객실 투숙현황' 엑셀로 날짜별 일일객실판매를 한꺼번에 등록
 *   엑셀: 시트 이름 '1월'~'12월', B1 = 연도, C1 = 월, '객실명' 머리글 줄에 날짜(1~31) 열, 그 아래 객실마다 한 줄 —
 *   날짜 칸의 숫자 = 그 날 입실 인원 (비어 있으면 판매 안 함). 아래쪽 합계·평균 표는 읽지 않는다.
 *   1) 올리기 → 2) 엑셀 객실명마다 사이트 객실 확인 → 3) 미리보기 → 4) 가져오기
 * 요금구분은 날짜로 자동(성수기 기간 → 성수기, 금·토·공휴일 전날 → 비수기 주말, 그 외 비수기 평일), 금액은 그 날의 사이트 객실 요금(할인 없음).
 * 공휴일은 사이트 일정표의 '공휴일'과 엑셀의 '공휴일' 시트(B열 날짜·C열 이름, E열 매년 같은 날 MMDD)를 함께 쓴다.
 * 이미 일일객실판매가 있는 날은 건너뛴다. 저장하면 평소처럼 그 날 매출보고의 쉬자파크숙박(입실·퇴실)이 맞춰진다.
 */
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/xlsx_read.php';

$user = require_admin();
$pdo = db();
const IMR_KEY = 'rooms_import';
$imp = $_SESSION[IMR_KEY] ?? null;

/** 사이트 객실 상품 (판매중지 포함) */
function imr_rooms(): array
{
    return array_filter(products_all(), fn($p) => $p['grp'] === 'room');
}

/** 엑셀 객실명 → 같은 이름의 사이트 객실 id (공백·괄호 무시) */
function imr_guess(string $name): int
{
    $norm = fn(string $s) => preg_replace('/[\s()（）]/u', '', $s);
    foreach (imr_rooms() as $p) if ($norm($p['name']) === $norm($name)) return (int) $p['id'];
    return 0;
}

/** 월별 시트 읽기 → [날짜 => [엑셀 객실명 => 입실인원]], [객실명 => [nights, guests, max]], 경고 */
function imr_parse(array $sheets): array
{
    $agg = [];
    $names = [];
    $warn = [];
    foreach ($sheets as $sheetName => $rows) {
        if (!preg_match('/^(\d{1,2})월$/u', trim($sheetName), $mm)) continue;
        $first = $rows[0] ?? [];
        $year = is_numeric($first[1] ?? null) && $first[1] >= 2000 && $first[1] <= 2100 ? (int) $first[1] : (int) date('Y');
        $month = is_numeric($first[2] ?? null) && $first[2] >= 1 && $first[2] <= 12 ? (int) $first[2] : (int) $mm[1];
        // 머리글 줄: B열이 '객실명'
        $head = null;
        foreach ($rows as $ri => $r) { if ($ri > 15) break; if (trim((string) ($r[1] ?? '')) === '객실명') { $head = $ri; break; } }
        if ($head === null) { $warn[] = "$sheetName: '객실명' 머리글을 찾지 못해 건너뜀"; continue; }
        $dayCols = [];
        foreach ($rows[$head] as $ci => $v) if ($ci >= 3 && is_numeric($v) && $v >= 1 && $v <= 31 && floor((float) $v) == $v) $dayCols[$ci] = (int) $v;
        // 객실 줄: 머리글 두 줄 아래부터 객실명이 비는 줄 전까지
        for ($ri = $head + 1; $ri < $head + 200; $ri++) {
            $r = $rows[$ri] ?? null;
            if ($ri === $head + 1 && $r !== null && !is_numeric($r[2] ?? null)) continue; // 평일·주말·성수기 줄
            $name = trim((string) ($r[1] ?? ''));
            if ($r === null || $name === '' || !is_numeric($r[2] ?? null)) break; // 합계 표 시작
            $max = (int) $r[2];
            $names[$name] ??= ['nights' => 0, 'guests' => 0, 'max' => $max];
            foreach ($dayCols as $ci => $day) {
                $v = $r[$ci] ?? null;
                if ($v === null || $v === '') continue;
                if (!is_numeric($v) || $v <= 0) { if (count($warn) < 20) $warn[] = "$sheetName {$day}일 {$name}: '" . $v . "' — 숫자가 아니라 건너뜀"; continue; }
                if (!checkdate($month, $day, $year)) continue;
                $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                $agg[$date][$name] = (int) round((float) $v);
                $names[$name]['nights']++;
                $names[$name]['guests'] += (int) round((float) $v);
                if ($max && $v > $max && count($warn) < 20) $warn[] = "$date {$name}: 입실 {$v}명이 최대인원({$max}명)보다 많음";
            }
        }
    }
    ksort($agg);
    return [$agg, $names, $warn];
}

/** 엑셀 '공휴일' 시트 → ['2026-02-16' => '구정', ...] (B열 날짜·C열 이름, E열 'MMDD' 는 $years 의 해마다) */
function imr_holidays(array $rows, array $years): array
{
    $out = [];
    foreach ($rows as $r) {
        if (($d = sheet_date($r[1] ?? null)) && in_array((int) substr($d, 0, 4), $years, true)) $out[$d] = trim((string) ($r[2] ?? '공휴일')) ?: '공휴일';
        $md = trim((string) ($r[4] ?? ''));
        if (preg_match('/^\d{3,4}$/', $md)) {
            $md = str_pad($md, 4, '0', STR_PAD_LEFT);
            foreach ($years as $y) if (checkdate((int) substr($md, 0, 2), (int) substr($md, 2), $y)) $out[sprintf('%d-%s-%s', $y, substr($md, 0, 2), substr($md, 2))] ??= '공휴일';
        }
    }
    ksort($out);
    return $out;
}

/** 한 날의 객실 판매 줄 */
function imr_day_lines(string $date, array $byName, array $map, array $holidays = []): array
{
    $products = products_at($date);
    $rate = rate_for_date($date, $holidays); // 공휴일 전날은 주말 요금
    $season = $rate === 'peak' ? (season_for('room', $date)['name'] ?? null) : null;
    $lines = [];
    foreach ($byName as $name => $guests) {
        $p = $products[(int) ($map[$name] ?? 0)] ?? null;
        if (!$p || $p['grp'] !== 'room') continue;
        $unit = room_price($p, $rate);
        $lines[] = ['product_id' => (int) $p['id'], 'grp' => 'room', 'name' => $p['name'], 'is_free' => 0, 'rate' => $rate, 'season' => $season,
            'discounted' => 0, 'unit_price' => $unit, 'qty' => 1, 'guests' => $guests, 'amount' => $unit, 'refund_expected' => null, 'vouchers' => []];
    }
    return $lines;
}

/* ───────────── 처리 ───────────── */
if (is_post()) {
    csrf_verify();
    $act = post('act');
    if ($act === 'reset') {
        unset($_SESSION[IMR_KEY]);
        redirect('admin/import_rooms.php');
    }
    if ($act === 'upload') {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) { flash('파일을 올리지 못했습니다. 다시 골라 주세요.', 'error'); redirect('admin/import_rooms.php'); }
        try {
            $sheets = xlsx_sheets($f['tmp_name'], 0, fn($n) => (bool) preg_match('/^(\d{1,2}월|공휴일)$/u', trim($n)));
        } catch (Throwable $e) {
            flash($e->getMessage(), 'error');
            redirect('admin/import_rooms.php');
        }
        [$agg, $names, $warn] = imr_parse($sheets);
        $years = array_values(array_unique(array_map(fn($d) => (int) substr($d, 0, 4), array_keys($agg))));
        $holidays = isset($sheets['공휴일']) ? imr_holidays($sheets['공휴일'], $years) : [];
        if (!$agg) { flash("가져올 객실 판매가 없습니다. 시트 이름이 '1월'~'12월'이고, '객실명' 머리글 아래에 객실마다 날짜별 입실 인원이 있는지 확인하세요.", 'error'); redirect('admin/import_rooms.php'); }
        $_SESSION[IMR_KEY] = ['file' => (string) $f['name'], 'sheets' => count($sheets), 'agg' => $agg, 'names' => $names, 'warn' => $warn,
            'map' => array_combine(array_keys($names), array_map('imr_guess', array_keys($names))), 'status' => 'approved', 'holidays' => $holidays];
        redirect('admin/import_rooms.php');
    }
    if (!$imp) redirect('admin/import_rooms.php');
    $rooms = imr_rooms();
    foreach (array_keys($imp['names']) as $i => $n) {
        $v = (int) ($_POST['map'][$i] ?? 0);
        $imp['map'][$n] = isset($rooms[$v]) ? $v : 0;
    }
    $imp['status'] = post('status') === 'draft' ? 'draft' : 'approved';
    $_SESSION[IMR_KEY] = $imp;
    if ($act === 'run') {
        $existing = array_flip($pdo->query("SELECT work_date FROM journals WHERE type = 'rooms'")->fetchAll(PDO::FETCH_COLUMN));
        $made = $skipped = $empty = 0;
        $ins = $pdo->prepare("INSERT INTO journals (type, team_id, work_date, author_id, weather, content, remarks, status) VALUES ('rooms', NULL, ?, ?, NULL, '', ?, 'draft')");
        $note = '엑셀 가져오기: ' . $imp['file'];
        $pdo->beginTransaction();
        try {
            foreach ($imp['agg'] as $date => $byName) {
                if (isset($existing[$date])) { $skipped++; continue; }
                $lines = imr_day_lines($date, $byName, $imp['map'], $imp['holidays'] ?? []);
                if (!$lines) { $empty++; continue; }
                $ins->execute([$date, $user['id'], $note]);
                $id = (int) $pdo->lastInsertId();
                items_save($id, 'rooms', ['lines' => $lines, 'vouchers' => []]); // 쉬자파크숙박(입실·퇴실)도 맞춰진다
                if ($imp['status'] === 'approved') {
                    $pdo->prepare("UPDATE journals SET status = 'approved', submitted_at = NOW(), completed_at = NOW() WHERE id = ?")->execute([$id]);
                }
                journal_log($id, $user, '엑셀 가져오기', $imp['file'] . ($imp['status'] === 'approved' ? ' · 결재완료로 등록' : ' · 임시저장'));
                $made++;
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        unset($_SESSION[IMR_KEY]);
        flash("일일객실판매 {$made}건을 가져왔습니다." . ($skipped ? " 이미 일일객실판매가 있던 {$skipped}일은 건너뛰었습니다." : '') . ($empty ? " 가져올 객실이 없던 {$empty}일은 만들지 않았습니다." : ''), 'success');
        redirect('journal.php?type=rooms&ym=' . substr((string) array_key_first($imp['agg']), 0, 7));
    }
    redirect('admin/import_rooms.php' . ($act === 'preview' ? '#preview' : ''));
}

layout_header('객실판매 가져오기', 'settings');
settings_nav('import');
?>
<div class="tabs team-tabs no-print">
  <a href="<?= e(url('admin/import_sales.php')) ?>">매출보고 (입장권·프로그램)</a>
  <a href="<?= e(url('admin/import_rooms.php')) ?>" class="on">일일객실판매 (객실)</a>
</div>
<?php if (!$imp): ?>
<section class="card">
  <h1>객실판매 가져오기 <small class="muted">월별 객실 투숙현황 엑셀 → 날짜별 일일객실판매</small></h1>
  <ul class="small">
    <li>시트 이름이 <b>1월 ~ 12월</b>이고, 각 시트의 B1에 연도, C1에 월, <b>'객실명'</b> 머리글 줄에 날짜(1~31) 열이 있는 엑셀(.xlsx)입니다.</li>
    <li>객실마다 한 줄, 날짜 칸의 숫자를 그 날 <b>입실 인원</b>으로 읽습니다 (비어 있으면 판매 안 함). 아래쪽 합계·판매율·평균 표는 읽지 않습니다.</li>
    <li>올린 뒤 엑셀 객실명마다 <b>사이트 객실</b>을 확인하고, 미리보기를 본 다음 가져옵니다. 올리기만 해서는 저장되지 않습니다.</li>
    <li>요금구분은 날짜로 자동(성수기 기간 → 성수기, 금·토·<b>공휴일 전날</b> → 비수기 주말, 그 외 평일), 금액은 그 날의 객실 요금입니다 (할인·지역상품권 환급은 없음).
      공휴일은 사이트 일정표의 공휴일과 엑셀의 <b>'공휴일' 시트</b>를 함께 씁니다.
      <b>이미 일일객실판매가 있는 날은 건너뜁니다.</b></li>
  </ul>
  <form method="post" enctype="multipart/form-data" class="actions" style="justify-content:flex-start">
    <?= csrf_field() ?><input type="hidden" name="act" value="upload">
    <input type="file" name="file" accept=".xlsx" required>
    <button class="btn primary">올리기</button>
  </form>
</section>
<?php layout_footer(); exit; endif;

$rooms = imr_rooms();
$dates = array_keys($imp['agg']);
$existing = [];
$st = $pdo->prepare("SELECT work_date, id FROM journals WHERE type = 'rooms' AND work_date BETWEEN ? AND ?");
$st->execute([reset($dates), end($dates)]);
foreach ($st as $r) $existing[$r['work_date']] = $r['id'];
$sum = ['days' => 0, 'skip' => 0, 'empty' => 0, 'nights' => 0, 'guests' => 0, 'amount' => 0, 'weekday' => 0, 'weekend' => 0, 'peak' => 0, 'eve' => 0];
$eves = []; // 공휴일 전날이라 주말 요금이 된 날
$byMonth = [];
foreach ($imp['agg'] as $date => $byName) {
    if (isset($existing[$date])) { $sum['skip']++; continue; }
    $lines = imr_day_lines($date, $byName, $imp['map'], $imp['holidays'] ?? []);
    if (!$lines) { $sum['empty']++; continue; }
    $sum['days']++;
    if ($lines[0]['rate'] === 'weekend' && !in_array((int) date('w', strtotime($date)), [5, 6], true)) { $eves[] = $date; $sum['eve'] += count($lines); }
    $mk = substr($date, 0, 7);
    $byMonth[$mk] ??= ['days' => 0, 'nights' => 0, 'guests' => 0, 'amount' => 0];
    $byMonth[$mk]['days']++;
    foreach ($lines as $l) {
        $sum['nights']++; $sum['guests'] += $l['guests']; $sum['amount'] += $l['amount']; $sum[$l['rate']]++;
        $byMonth[$mk]['nights']++; $byMonth[$mk]['guests'] += $l['guests']; $byMonth[$mk]['amount'] += $l['amount'];
    }
}
$unmapped = array_keys(array_filter($imp['map'], fn($v) => !$v));
?>
<section class="card">
  <div class="card-head">
    <h1>객실판매 가져오기 <small class="muted"><?= e($imp['file']) ?></small></h1>
    <form method="post"><?= csrf_field() ?><button class="btn ghost" name="act" value="reset">다른 파일 올리기</button></form>
  </div>
  <div class="kpis k4">
    <div class="kpi"><span>판매 (실·박)</span><b><?= number_format(array_sum(array_column($imp['names'], 'nights'))) ?>박</b><small class="muted"><?= count($imp['names']) ?>개 객실 · 입실 <?= number_format(array_sum(array_column($imp['names'], 'guests'))) ?>명</small></div>
    <div class="kpi"><span>기간</span><b><?= e(date('Y.n.j', strtotime(reset($dates)))) ?> ~ <?= e(date('n.j', strtotime(end($dates)))) ?></b><small class="muted">판매가 있는 날 <?= count($dates) ?>일</small></div>
    <div class="kpi total"><span>새로 만들 일일객실판매</span><b><?= $sum['days'] ?>일</b><small class="muted"><?= $sum['skip'] ? '이미 있는 ' . $sum['skip'] . '일 건너뜀' : '이미 있는 날 없음' ?></small></div>
    <div class="kpi"><span>객실 금액 (사이트 요금)</span><b><?= e(won($sum['amount'])) ?></b><small class="muted">평일 <?= $sum['weekday'] ?> · 주말 <?= $sum['weekend'] ?> · 성수기 <?= $sum['peak'] ?>박</small></div>
  </div>
  <?php if ($imp['warn']): ?><div class="flash flash-warn"><?= e(implode(' / ', $imp['warn'])) ?></div><?php endif ?>
</section>

<form method="post" class="card">
  <?= csrf_field() ?>
  <h2>1. 엑셀 객실명마다 사이트 객실 <small class="muted">같은 이름은 자동으로 골라 두었습니다</small></h2>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>엑셀 객실명</th><th class="right">최대인원</th><th class="right">판매(박)</th><th class="right">입실 인원</th><th>사이트 객실</th></tr></thead>
    <tbody>
    <?php $i = 0; foreach ($imp['names'] as $name => $n): $cur = (int) ($imp['map'][$name] ?? 0); ?>
      <tr class="<?= $cur ? '' : 'inactive' ?>">
        <td><b><?= e($name) ?></b></td><td class="right"><?= (int) $n['max'] ?>인</td><td class="right"><?= number_format($n['nights']) ?></td><td class="right"><?= number_format($n['guests']) ?></td>
        <td><select name="map[<?= $i ?>]">
          <option value="0">가져오지 않음</option>
          <?php foreach ($rooms as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $cur === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?> (최대 <?= (int) $p['max_people'] ?>인<?= $p['is_active'] ? '' : ' · 판매중지' ?>)</option><?php endforeach ?>
        </select></td>
      </tr>
    <?php $i++; endforeach ?>
    </tbody>
  </table>
  </div>
  <p class="muted small">알맞은 객실이 없으면 설정 › 상품·요금에서 먼저 객실을 만든 뒤 이 화면을 새로고침하세요.</p>

  <h2>2. 등록 상태</h2>
  <div class="imp-status">
    <label><input type="radio" name="status" value="approved" <?= $imp['status'] === 'approved' ? 'checked' : '' ?>> <span><b>결재완료</b>로 등록 (지난 자료 — 결재를 다시 받지 않음, 통계에 바로 반영)</span></label>
    <label><input type="radio" name="status" value="draft" <?= $imp['status'] === 'draft' ? 'checked' : '' ?>> <span><b>임시저장</b>으로 등록 (하나씩 열어 확인하고 결재 올리기)</span></label>
  </div>
  <div class="actions"><button class="btn" name="act" value="preview">사이트 객실 저장 · 미리보기 다시 계산</button></div>

  <h2 id="preview">3. 미리보기 <small class="muted">아직 저장되지 않았습니다</small></h2>
  <ul class="small">
    <li>새로 만들 일일객실판매 <b><?= $sum['days'] ?>일</b> · <?= number_format($sum['nights']) ?>박 · 입실 <?= number_format($sum['guests']) ?>명 · <?= e(won($sum['amount'])) ?><?= $sum['skip'] ? ' · 이미 있어 건너뛸 날 <b>' . $sum['skip'] . '일</b>' : '' ?></li>
    <?php if ($unmapped): ?><li class="warn">가져오지 않을 객실: <?= e(implode(', ', $unmapped)) ?></li><?php endif ?>
    <li>공휴일 전날은 주말 요금: <?= $eves ? count($eves) . '일 ' . $sum['eve'] . '박 (' . e(implode(', ', array_map(fn($d) => date('n/j', strtotime($d)) . '(' . weekday_ko($d) . ')', $eves))) . ')' : '해당 없음' ?>
      <small class="muted">— 공휴일은 사이트 일정표의 공휴일<?= !empty($imp['holidays']) ? '과 엑셀 공휴일 시트(' . count($imp['holidays']) . '일)' : '' ?></small></li>
    <li>저장하면 그 날 매출보고(있으면)의 쉬자파크숙박(입실)과 다음 날 (퇴실)이 입실 인원으로 맞춰집니다.</li>
  </ul>
  <?php if ($byMonth): ?>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>월</th><th class="right">일수</th><th class="right">판매(박)</th><th class="right">입실 인원</th><th class="right">금액</th></tr></thead>
    <tbody><?php foreach ($byMonth as $mk => $v): ?><tr><td><?= e(date('Y년 n월', strtotime("$mk-01"))) ?></td><td class="right"><?= $v['days'] ?></td><td class="right"><?= number_format($v['nights']) ?></td><td class="right"><?= number_format($v['guests']) ?></td><td class="right"><?= number_format($v['amount']) ?></td></tr><?php endforeach ?></tbody>
    <tfoot><tr><th>합계</th><th class="right"><?= $sum['days'] ?></th><th class="right"><?= number_format($sum['nights']) ?></th><th class="right"><?= number_format($sum['guests']) ?></th><th class="right"><?= number_format($sum['amount']) ?></th></tr></tfoot>
  </table>
  </div>
  <?php endif ?>
  <div class="actions">
    <button class="btn primary" name="act" value="run" <?= $sum['days'] ? '' : 'disabled' ?>
      onclick="return confirm('지금 고른 사이트 객실·등록 상태로 일일객실판매를 만듭니다 (미리보기 <?= $sum['days'] ?>일, 이미 있는 날은 건너뜀). 가져올까요?')">가져오기 (<?= $sum['days'] ?>일)</button>
  </div>
</form>
<?php layout_footer();
