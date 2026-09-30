<?php
/**
 * 설정 › 매출 가져오기 (최고관리자): 매표 프로그램에서 내려받은 판매 엑셀을 날짜별 매출보고로 한꺼번에 등록
 *   엑셀 열: 날짜 · 상품명 · 지불방법(현금/카드) · 금액(단가) · 수량 · 판매금액  (첫 행 머리글, 한 행 = 판매 1건)
 *   1) 올리기 → 2) 상품명마다 가져올 곳(입장권 상품 / 프로그램 판매 / 가져오지 않음) 확인 → 3) 미리보기 → 4) 가져오기
 * 이미 매출보고가 있는 날은 건너뛴다. 입장권 단가·금액은 엑셀 값 그대로, 현금 판매액은 입장권 현금으로.
 * 그 날 프로그램 운영보고가 있는 분야는 (매출보고 직접 입력과 같이) 운영보고 값을 쓴다.
 */
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/sales_xls.php';

$user = require_admin();
$pdo = db();
const IMP_KEY = 'sales_import';
$imp = $_SESSION[IMP_KEY] ?? null;

/** 한 날의 매출보고 내용 만들기 (가져오기·미리보기 공용) */
function imp_day_lines(string $date, array $byName, array $map): array
{
    $products = products_all();
    $lines = [];
    $cash = 0;
    $prog = [];
    $tickets = []; // pid|단가 => 줄
    foreach ($byName as $name => $groups) {
        $to = $map[$name] ?? '';
        if ($to === '') continue;
        foreach ($groups as $g) { // ['pay' => , 'price' => , 'qty' => , 'amount' => , 'rows' => ]
            if (str_starts_with($to, 'p:')) {
                $p = $products[(int) substr($to, 2)] ?? null;
                if (!$p) continue;
                $k = $p['id'] . '|' . $g['price'];
                $tickets[$k] ??= ['product_id' => (int) $p['id'], 'grp' => 'ticket', 'name' => $p['name'], 'is_free' => (int) $p['is_free'],
                    'rate' => null, 'season' => null, 'discounted' => 0, 'unit_price' => (int) $g['price'], 'qty' => 0, 'guests' => 0, 'amount' => 0];
                $tickets[$k]['qty'] += $g['qty'];
                $tickets[$k]['amount'] += $g['amount'];
                if ($g['pay'] === '현금') $cash += $g['amount'];
            } else {
                $pt = substr($to, 2);
                $prog[$pt] ??= ['sessions' => 0, 'paid' => 0, 'free' => 0, 'amount' => 0];
                $prog[$pt]['sessions'] += $g['rows'];
                $prog[$pt][$g['amount'] > 0 ? 'paid' : 'free'] += $g['qty'];
                $prog[$pt]['amount'] += $g['amount'];
            }
        }
    }
    // 쉬자파크숙박(입실·퇴실)은 일일객실판매에서 자동 (매출보고 작성과 같게)
    $roomGuests = stay_guests($date);
    foreach (stay_products() as $key => $p) {
        $qty = $key === 'stay_in' ? $roomGuests : stay_out_guests($date);
        if ($qty > 0) $lines[] = ['product_id' => (int) $p['id'], 'grp' => 'ticket', 'name' => $p['name'], 'is_free' => 1, 'rate' => null, 'season' => null,
            'discounted' => 0, 'unit_price' => 0, 'qty' => $qty, 'guests' => 0, 'amount' => 0];
    }
    $lines = [...$lines, ...array_values($tickets)];
    $progAuto = program_day_summary($date);
    $usedReport = [];
    foreach (PROGRAM_TYPES as $pt => $_) {
        if (isset($progAuto[$pt])) {
            $a = $progAuto[$pt];
            $lines[] = program_sale_line($pt, $a['sessions'], $a['paid'], $a['free'], $a['amount'], true);
            if (isset($prog[$pt])) $usedReport[] = $pt;
        } elseif (isset($prog[$pt])) {
            $lines[] = program_sale_line($pt, $prog[$pt]['sessions'], $prog[$pt]['paid'], $prog[$pt]['free'], $prog[$pt]['amount'], false);
        }
    }
    return [$lines, $cash, $usedReport, (bool) ($tickets || $prog)];
}

/* ───────────── 처리 ───────────── */
if (is_post()) {
    csrf_verify();
    $act = post('act');
    if ($act === 'reset') {
        unset($_SESSION[IMP_KEY]);
        redirect('admin/import_sales.php');
    }
    if ($act === 'upload') {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) { flash('파일을 올리지 못했습니다. 다시 골라 주세요.', 'error'); redirect('admin/import_sales.php'); }
        try {
            ['agg' => $agg, 'names' => $names, 'bad' => $bad, 'rows' => $dataRows] = imp_parse($f['tmp_name'], (string) $f['name']);
        } catch (Throwable $e) {
            flash($e->getMessage(), 'error');
            redirect('admin/import_sales.php');
        }
        $targets = imp_targets();
        $_SESSION[IMP_KEY] = ['file' => (string) $f['name'], 'rows' => $dataRows, 'bad' => $bad, 'agg' => $agg,
            'names' => $names, 'map' => array_combine(array_keys($names), array_map(fn($n) => imp_guess($n, $targets), array_keys($names))), 'status' => 'approved'];
        redirect('admin/import_sales.php');
    }
    if (!$imp) redirect('admin/import_sales.php');
    // 가져올 곳·등록 상태 저장 (미리보기 / 가져오기 공통)
    $targets = imp_targets();
    foreach (array_keys($imp['names']) as $i => $n) {
        $v = (string) ($_POST['map'][$i] ?? '');
        $imp['map'][$n] = isset($targets[$v]) ? $v : '';
    }
    $imp['status'] = post('status') === 'draft' ? 'draft' : 'approved';
    $_SESSION[IMP_KEY] = $imp;
    if ($act === 'run') {
        imp_map_remember($imp['map']); // 매출보고 작성 화면의 '매표 엑셀로 채우기'도 같은 가져올 곳을 쓴다
        $existing = array_flip($pdo->query("SELECT work_date FROM journals WHERE type = 'sales'")->fetchAll(PDO::FETCH_COLUMN));
        $made = $skipped = $empty = 0;
        $ins = $pdo->prepare("INSERT INTO journals (type, team_id, work_date, author_id, weather, content, remarks, status) VALUES ('sales', NULL, ?, ?, NULL, '', ?, 'draft')");
        $note = '엑셀 가져오기: ' . $imp['file'];
        $pdo->beginTransaction();
        try {
            foreach ($imp['agg'] as $date => $byName) {
                if (isset($existing[$date])) { $skipped++; continue; }
                [$lines, $cash, , $has] = imp_day_lines($date, $byName, $imp['map']);
                if (!$has) { $empty++; continue; }
                $ins->execute([$date, $user['id'], $note]);
                $id = (int) $pdo->lastInsertId();
                items_save($id, 'sales', ['lines' => $lines, 'ticket_cash' => $cash, 'rent_dc_rule' => null, 'rent_dc_pct' => 0, 'rent_youth' => 0, 'vouchers' => []]);
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
        unset($_SESSION[IMP_KEY]);
        flash("매출보고 {$made}건을 가져왔습니다." . ($skipped ? " 이미 매출보고가 있던 {$skipped}일은 건너뛰었습니다." : '') . ($empty ? " 가져올 상품이 없던 {$empty}일은 만들지 않았습니다." : ''), 'success');
        redirect('journal.php?type=sales&ym=' . substr((string) array_key_last($imp['agg']), 0, 7));
    }
    redirect('admin/import_sales.php' . ($act === 'preview' ? '#preview' : ''));
}

layout_header('매출 가져오기', 'settings');
settings_nav('import');
?>
<div class="tabs team-tabs no-print">
  <a href="<?= e(url('admin/import_sales.php')) ?>" class="on">매출보고 (입장권·프로그램)</a>
  <a href="<?= e(url('admin/import_rooms.php')) ?>">일일객실판매 (객실)</a>
</div>
<?php

if (!$imp): ?>
<section class="card">
  <h1>매출 가져오기 <small class="muted">엑셀 → 날짜별 매출보고</small></h1>
  <p>매표 프로그램에서 내려받은 <b>판매 내역 엑셀</b>을 올리면 날짜별로 묶어 <b>매출보고</b>를 한꺼번에 만듭니다.</p>
  <ul class="small">
    <li><b>산림청 통합운영시스템의 '상품판매현황' 엑셀(.xls)을 그대로</b> 올리면 됩니다 (제목 줄·합계 줄·쪽 번호는 알아서 건너뜀).</li>
    <li>다른 엑셀(.xlsx/.xls/.csv)도 머리글에 <b>날짜(사용일자) · 상품명 · 지불방법(결제수단) · 단가 · 수량 · 금액</b> 열이 있으면 됩니다 (한 줄 = 판매 1건).
      머리글이 없으면 A~F열을 날짜·상품명·지불방법·단가·수량·판매금액 순서로 읽습니다.</li>
    <li>올린 뒤 상품명마다 <b>가져올 곳</b>(입장권 상품 / 프로그램 판매 / 가져오지 않음)을 확인하고, 미리보기를 본 다음 가져옵니다. 올리기만 해서는 아무것도 저장되지 않습니다.</li>
    <li><b>이미 매출보고가 있는 날은 건너뜁니다</b> (덮어쓰지 않음). 객실·시설대관은 가져오지 않습니다.</li>
  </ul>
  <form method="post" enctype="multipart/form-data" class="actions" style="justify-content:flex-start">
    <?= csrf_field() ?><input type="hidden" name="act" value="upload">
    <input type="file" name="file" accept=".xlsx,.xls,.csv" required>
    <button class="btn primary">올리기</button>
  </form>
</section>
<?php layout_footer(); exit; endif;

$targets = imp_targets();
$dates = array_keys($imp['agg']);
$existing = [];
$st = $pdo->prepare("SELECT work_date, id, status FROM journals WHERE type = 'sales' AND work_date BETWEEN ? AND ?");
$st->execute([reset($dates), end($dates)]);
foreach ($st as $r) $existing[$r['work_date']] = $r;
// 미리보기 계산
$sum = ['days' => 0, 'skip' => 0, 'empty' => 0, 'ticket_qty' => 0, 'ticket_amt' => 0, 'cash' => 0, 'prog_people' => 0, 'prog_amt' => 0, 'report' => 0];
$byTarget = [];
$sample = [];
foreach ($imp['agg'] as $date => $byName) {
    if (isset($existing[$date])) { $sum['skip']++; continue; }
    [$lines, $cash, $usedReport, $has] = imp_day_lines($date, $byName, $imp['map']);
    if (!$has) { $sum['empty']++; continue; }
    $sum['days']++;
    $sum['cash'] += $cash;
    if ($usedReport) $sum['report']++;
    $dayAmt = 0;
    foreach ($lines as $l) {
        $key = $l['grp'] === 'program' ? '프로그램 · ' . $l['name'] . (!empty($l['auto']) ? ' (운영보고)' : '') : $l['name'];
        $byTarget[$key] ??= ['qty' => 0, 'free' => 0, 'amount' => 0];
        $byTarget[$key]['qty'] += $l['qty'];
        $byTarget[$key]['free'] += $l['guests'];
        $byTarget[$key]['amount'] += $l['amount'];
        $dayAmt += $l['amount'];
        if ($l['grp'] === 'ticket') { $sum['ticket_qty'] += $l['qty']; $sum['ticket_amt'] += $l['amount']; }
        else { $sum['prog_people'] += $l['qty'] + $l['guests']; $sum['prog_amt'] += $l['amount']; }
    }
    if (count($sample) < 5) $sample[$date] = $dayAmt;
}
$unmapped = array_filter($imp['map'], fn($v) => $v === '');
?>
<section class="card">
  <div class="card-head">
    <h1>매출 가져오기 <small class="muted"><?= e($imp['file']) ?></small></h1>
    <form method="post"><?= csrf_field() ?><button class="btn ghost" name="act" value="reset">다른 파일 올리기</button></form>
  </div>
  <div class="kpis k4">
    <div class="kpi"><span>판매 건수</span><b><?= number_format($imp['rows']) ?>건</b><small class="muted"><?= count($imp['names']) ?>개 상품명</small></div>
    <div class="kpi"><span>기간</span><b><?= e(date('Y.n.j', strtotime(reset($dates)))) ?> ~ <?= e(date('n.j', strtotime(end($dates)))) ?></b><small class="muted">판매가 있는 날 <?= count($dates) ?>일</small></div>
    <div class="kpi total"><span>새로 만들 매출보고</span><b><?= $sum['days'] ?>일</b><small class="muted"><?= $sum['skip'] ? '이미 있는 ' . $sum['skip'] . '일 건너뜀' : '이미 있는 날 없음' ?></small></div>
    <div class="kpi"><span>입장권 금액</span><b><?= e(won($sum['ticket_amt'])) ?></b><small class="muted">현금 <?= number_format($sum['cash']) ?> · 카드 <?= number_format($sum['ticket_amt'] - $sum['cash']) ?></small></div>
  </div>
  <?php if ($imp['bad']): ?><div class="flash flash-warn">날짜나 상품명이 비어 가져올 수 없는 줄: <?= e(implode(', ', $imp['bad'])) ?><?= count($imp['bad']) >= 20 ? ' 등' : '' ?></div><?php endif ?>
</section>

<form method="post" class="card">
  <?= csrf_field() ?>
  <h2>1. 상품명마다 가져올 곳 <small class="muted">같은 이름의 입장권은 자동으로 골라 두었습니다</small></h2>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>엑셀 상품명</th><th class="right">건수</th><th class="right">수량</th><th class="right">판매금액</th><th>단가</th><th>가져올 곳</th></tr></thead>
    <tbody>
    <?php $i = 0; foreach ($imp['names'] as $name => $n): $cur = $imp['map'][$name] ?? ''; ?>
      <tr class="<?= $cur === '' ? 'inactive' : '' ?>">
        <td><b><?= e($name) ?></b><?= !empty($n['cats']) ? '<br><small class="muted">' . e(implode(', ', array_keys($n['cats']))) . '</small>' : '' ?></td><td class="right"><?= number_format($n['rows']) ?></td><td class="right"><?= number_format($n['qty']) ?></td>
        <td class="right"><?= number_format($n['amount']) ?><?= $n['cash'] ? '<br><small class="muted">현금 ' . number_format($n['cash']) . '</small>' : '' ?></td>
        <td class="small"><?= e(implode(' · ', array_map('number_format', array_keys($n['prices'])))) ?></td>
        <td><select name="map[<?= $i ?>]">
          <option value="">가져오지 않음</option>
          <?php foreach ($targets as $k => $label): ?><option value="<?= e($k) ?>" <?= $cur === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
        </select></td>
      </tr>
    <?php $i++; endforeach ?>
    </tbody>
  </table>
  </div>
  <p class="muted small">
    <b>입장권</b>: 엑셀의 단가·금액 그대로 들어가고, 지불방법이 현금인 금액은 매출보고의 '입장권 현금'이 됩니다. 알맞은 입장권이 없으면 설정 › 상품·요금에서 먼저 만든 뒤 이 화면을 새로고침하세요.<br>
    <b>프로그램 판매</b>: 금액이 있는 수량은 유료, 0원인 수량은 무료 인원, 판매 건수는 회차로 들어갑니다. 그 날 그 분야의 <b>프로그램 운영보고</b>가 있으면 운영보고 값을 씁니다.
  </p>

  <h2>2. 등록 상태</h2>
  <div class="imp-status">
    <label><input type="radio" name="status" value="approved" <?= $imp['status'] === 'approved' ? 'checked' : '' ?>> <span><b>결재완료</b>로 등록 (지난 자료 — 결재를 다시 받지 않음, 통계에 바로 반영)</span></label>
    <label><input type="radio" name="status" value="draft" <?= $imp['status'] === 'draft' ? 'checked' : '' ?>> <span><b>임시저장</b>으로 등록 (하나씩 열어 확인하고 결재 올리기)</span></label>
  </div>
  <div class="actions"><button class="btn" name="act" value="preview">가져올 곳 저장 · 미리보기 다시 계산</button></div>

  <h2 id="preview">3. 미리보기 <small class="muted">아직 저장되지 않았습니다</small></h2>
  <ul class="small">
    <li>새로 만들 매출보고 <b><?= $sum['days'] ?>일</b><?= $sum['skip'] ? ' · 이미 매출보고가 있어 건너뛸 날 <b>' . $sum['skip'] . '일</b>' : '' ?><?= $sum['empty'] ? ' · 가져올 상품이 없어 만들지 않을 날 ' . $sum['empty'] . '일' : '' ?></li>
    <li>입장권 <?= number_format($sum['ticket_qty']) ?>매 · <?= e(won($sum['ticket_amt'])) ?> (현금 <?= number_format($sum['cash']) ?>원) · 프로그램 <?= number_format($sum['prog_people']) ?>명 · <?= e(won($sum['prog_amt'])) ?></li>
    <?php if ($sum['report']): ?><li><?= $sum['report'] ?>일은 프로그램 운영보고가 있어 그 분야는 운영보고 값을 씁니다.</li><?php endif ?>
    <?php if ($unmapped): ?><li class="warn">가져오지 않을 상품명: <?= e(implode(', ', array_keys($unmapped))) ?></li><?php endif ?>
  </ul>
  <?php if ($byTarget): ?>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>매출보고에 들어갈 항목</th><th class="right">수량(유료)</th><th class="right">무료 인원</th><th class="right">금액</th></tr></thead>
    <tbody><?php foreach ($byTarget as $k => $v): ?><tr><td><?= e($k) ?></td><td class="right"><?= number_format($v['qty']) ?></td><td class="right"><?= $v['free'] ? number_format($v['free']) : '' ?></td><td class="right"><?= number_format($v['amount']) ?></td></tr><?php endforeach ?></tbody>
  </table>
  </div>
  <?php endif ?>
  <?php if ($sum['skip']): ?>
    <details class="small"><summary>건너뛸 날 (이미 매출보고가 있음) <?= $sum['skip'] ?>일</summary>
      <?= implode(', ', array_map(fn($d) => '<a href="' . e(url('view.php?id=' . $existing[$d]['id'])) . '">' . e(date('n/j', strtotime($d))) . '</a>', array_values(array_filter($dates, fn($d) => isset($existing[$d]))))) ?>
    </details>
  <?php endif ?>
  <div class="actions">
    <button class="btn primary" name="act" value="run" <?= $sum['days'] ? '' : 'disabled' ?>
      onclick="return confirm('지금 고른 가져올 곳·등록 상태로 매출보고를 만듭니다 (미리보기 <?= $sum['days'] ?>일, 이미 있는 날은 건너뜀). 가져올까요?')">가져오기 (<?= $sum['days'] ?>일)</button>
  </div>
</form>
<?php layout_footer();
