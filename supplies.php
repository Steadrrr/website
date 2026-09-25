<?php
/**
 * 객실관리 › 소모품관리
 *   supplies.php[?date=2026-10-05]      일별 수불 입력 (품목마다 입고·출고·비고 → 재고 자동)
 *   supplies.php?view=month&ym=2026-10  월간 수불부 (전월이월·입고·출고·월말재고)
 *   supplies.php?item=3&ym=2026-10      품목별 수불대장 (일자별 입고·출고·재고)
 *   supplies.php?view=items             품목 목록 (사진·품명·규격·단위·적정재고·현재재고)
 *   supplies.php?edit=3 | ?edit=new     품목 등록·수정 (사진은 저해상도로 줄여서 저장)
 * 객실관리 메뉴 권한이 있으면 누구나 입력. 품목 삭제는 공무직 이상 (수불 기록이 있으면 '사용' 해제).
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
require_menu($user, 'room');
$pdo = db();
$canDelete = !empty($user['is_admin']) || (int) $user['rank_level'] >= RANK_WORKER;

$date = valid_date($_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
$ym = preg_match('/^\d{4}-\d{2}$/', $_GET['ym'] ?? '') && valid_date(($_GET['ym'] ?? '') . '-01') ? $_GET['ym'] : date('Y-m');
$view = $_GET['view'] ?? 'day';

if (is_post()) {
    csrf_verify();
    $target = post('target');

    // ── 일별 수불 저장 ──
    if ($target === 'day') {
        $d = post('date');
        if (!valid_date($d)) abort(400, '잘못된 날짜입니다.');
        $items = supplies_all();
        $old = supply_moves_on($d);
        $rows = [];
        $errors = [];
        foreach ((array) ($_POST['m'] ?? []) as $sid => $m) {
            $sid = (int) $sid;
            if (!isset($items[$sid]) || !is_array($m)) continue;
            $in = min(999999, to_int($m['in'] ?? 0));
            $out = min(999999, to_int($m['out'] ?? 0));
            $note = mb_substr(trim((string) ($m['note'] ?? '')), 0, 200);
            $o = $old[$sid] ?? null;
            if ($o && (int) $o['in_qty'] === $in && (int) $o['out_qty'] === $out && (string) $o['note'] === $note) continue; // 그대로
            if (!$o && !$in && !$out && $note === '') continue;
            if ($neg = supply_negative_after($sid, $d, $in, $out)) {
                $errors[] = "{$items[$sid]['name']}: 출고가 재고보다 많습니다" . ($neg !== $d ? " ({$neg} 재고가 음수가 됨)" : '') . '. 입고를 먼저 입력하세요.';
                continue;
            }
            $rows[$sid] = [$in, $out, $note];
        }
        if ($errors) {
            foreach ($errors as $m) flash($m, 'error');
            flash('위 품목을 빼고 저장하지 않았습니다. 고친 뒤 다시 저장하세요.', 'error');
            $_SESSION['supply_post'] = $_POST['m'] ?? []; // 입력값 되살리기
            redirect('supplies.php?date=' . $d);
        }
        $pdo->beginTransaction();
        foreach ($rows as $sid => [$in, $out, $note]) {
            if (!$in && !$out && $note === '') {
                $pdo->prepare('DELETE FROM supply_moves WHERE supply_id = ? AND move_date = ?')->execute([$sid, $d]);
            } else {
                $pdo->prepare('INSERT INTO supply_moves (supply_id, move_date, in_qty, out_qty, note, user_id) VALUES (?, ?, ?, ?, ?, ?)
                               ON DUPLICATE KEY UPDATE in_qty = VALUES(in_qty), out_qty = VALUES(out_qty), note = VALUES(note), user_id = VALUES(user_id)')
                    ->execute([$sid, $d, $in, $out, $note !== '' ? $note : null, $user['id']]);
            }
        }
        $pdo->commit();
        flash($rows ? date('n월 j일', strtotime($d)) . ' 수불 ' . count($rows) . '개 품목을 저장했습니다.' : '바뀐 내용이 없습니다.', $rows ? 'success' : 'info');
        redirect('supplies.php?date=' . $d);
    }

    // ── 품목 삭제 ──
    if ($target === 'item_delete') {
        if (!$canDelete) abort(403, '품목 삭제는 공무직 이상이 합니다.');
        $s = supply_find((int) post('id')) ?? abort(404, '품목을 찾을 수 없습니다.');
        $st = $pdo->prepare('SELECT COUNT(*) FROM supply_moves WHERE supply_id = ?');
        $st->execute([$s['id']]);
        if ((int) $st->fetchColumn()) {
            flash("'{$s['name']}'은(는) 수불 기록이 있어 삭제할 수 없습니다. 대신 '사용'을 해제하세요.", 'error');
            redirect('supplies.php?edit=' . $s['id']);
        }
        if ($s['photo'] && str_starts_with($s['photo'], 'uploads/supply/')) @unlink(APP_ROOT . '/' . $s['photo']);
        $pdo->prepare('DELETE FROM supplies WHERE id = ?')->execute([$s['id']]);
        flash("'{$s['name']}' 품목을 삭제했습니다.", 'success');
        redirect('supplies.php?view=items');
    }

    // ── 품목 등록·수정 ──
    if ($target === 'item') {
        $id = (int) post('id');
        $s = $id ? (supply_find($id) ?? abort(404, '품목을 찾을 수 없습니다.')) : null;
        $name = mb_substr(post('name'), 0, 100);
        $errors = [];
        if ($name === '') $errors[] = '품명을 입력하세요.';
        $initQty = $s ? 0 : to_int(post('init_qty'));
        $initDate = post('init_date');
        if ($initQty && !valid_date($initDate)) $errors[] = '기초재고 날짜를 확인하세요.';
        $photo = $s['photo'] ?? null;
        $newPhoto = null;
        if (!$errors) {
            $f = $_FILES['photo'] ?? null;
            [$newPhoto, $upErr] = $f ? store_uploaded_image((string) $f['name'], (string) $f['tmp_name'], (int) $f['error'], 'supply') : [null, null];
            if ($upErr) $errors[] = $upErr;
        }
        if ($errors) {
            foreach ($errors as $m) flash($m, 'error');
            redirect('supplies.php?edit=' . ($id ?: 'new'));
        }
        if ($newPhoto) {
            image_downscale(APP_ROOT . '/' . $newPhoto, SUPPLY_PHOTO_MAX);
            if ($photo && str_starts_with($photo, 'uploads/supply/')) @unlink(APP_ROOT . '/' . $photo);
            $photo = $newPhoto;
        } elseif (post('remove_photo') === '1' && $photo) {
            if (str_starts_with($photo, 'uploads/supply/')) @unlink(APP_ROOT . '/' . $photo);
            $photo = null;
        }
        $row = [
            'name' => $name, 'spec' => mb_substr(post('spec'), 0, 200) ?: null, 'category' => mb_substr(post('category'), 0, 50) ?: null,
            'unit' => mb_substr(post('unit'), 0, 20) ?: '개', 'safety_stock' => to_int(post('safety_stock')), 'photo' => $photo,
            'memo' => post('memo') ?: null, 'sort_order' => (int) post('sort_order', '0'), 'is_active' => $s ? (post('is_active') === '1' ? 1 : 0) : 1,
        ];
        $pdo->beginTransaction();
        if ($s) {
            $pdo->prepare('UPDATE supplies SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($row))) . ' WHERE id = ?')->execute([...array_values($row), $id]);
        } else {
            $pdo->prepare('INSERT INTO supplies (' . implode(', ', array_keys($row)) . ', created_by) VALUES (' . str_repeat('?, ', count($row)) . '?)')->execute([...array_values($row), $user['id']]);
            $id = (int) $pdo->lastInsertId();
            if ($initQty) {
                $pdo->prepare("INSERT INTO supply_moves (supply_id, move_date, in_qty, out_qty, note, user_id) VALUES (?, ?, ?, 0, '기초재고', ?)")->execute([$id, $initDate, $initQty, $user['id']]);
            }
        }
        $pdo->commit();
        flash("'{$name}' 품목을 " . ($s ? '저장' : '등록') . '했습니다.' . ($initQty ? " (기초재고 {$initQty}{$row['unit']} · {$initDate})" : ''), 'success');
        redirect('supplies.php?view=items');
    }
    abort(400, '잘못된 요청입니다.');
}

$tabs = ['day' => '일별 수불', 'month' => '월간 수불부', 'items' => '품목 관리'];
$tab = isset($_GET['item']) ? 'month' : (isset($_GET['edit']) ? 'items' : (isset($tabs[$view]) ? $view : 'day'));
layout_header('소모품관리', 'supplies');
$nav = function () use ($tab, $tabs, $date, $ym) { ?>
<div class="card-head supply-card-head">
  <h1>소모품관리</h1>
  <div class="tabs no-print">
    <?php foreach ($tabs as $k => $label): ?>
      <a href="<?= e(url('supplies.php?' . ($k === 'day' ? "date=$date" : ($k === 'month' ? "view=month&ym=$ym" : 'view=items')))) ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= e($label) ?></a>
    <?php endforeach ?>
  </div>
</div>
<?php };

/* ═════════════ 품목 등록·수정 ═════════════ */
if (isset($_GET['edit'])) {
    $s = $_GET['edit'] === 'new' ? null : (supply_find((int) $_GET['edit']) ?? abort(404, '품목을 찾을 수 없습니다.'));
    $hasPhoto = $s && $s['photo'] && is_file(APP_ROOT . '/' . $s['photo']);
    $cats = array_values(array_unique(array_filter(array_column(supplies_all(), 'category'))));
    echo '<div class="card">';
    $nav();
    echo '</div>';
    ?>
<form method="post" enctype="multipart/form-data" class="card narrow lost-form">
  <?= csrf_field() ?><input type="hidden" name="target" value="item"><input type="hidden" name="id" value="<?= (int) ($s['id'] ?? 0) ?>">
  <div class="card-head">
    <h2><?= $s ? '품목 수정' : '품목 등록' ?></h2>
    <a class="btn ghost" href="<?= e(url('supplies.php?view=items')) ?>">취소</a>
  </div>
  <div class="lost-form-grid">
    <div class="lost-photo-pick">
      <div class="lost-frame lost-frame-lg">
        <img id="supplyPreview" src="<?= $hasPhoto ? e(url($s['photo'])) : '' ?>" alt="" <?= $hasPhoto ? '' : 'hidden' ?>>
        <?php if (!$hasPhoto): ?><span class="lost-nophoto" data-nophoto>사진을 골라 주세요</span><?php endif ?>
      </div>
      <label class="btn small">📷 사진 <?= $hasPhoto ? '바꾸기' : '선택' ?>
        <input type="file" name="photo" accept="image/*" data-resize="<?= SUPPLY_PHOTO_MAX ?>" data-quality="0.7" data-preview="#supplyPreview" hidden
               onchange="var n=document.querySelector('[data-nophoto]'); if(n) n.hidden=true">
      </label>
      <?php if ($hasPhoto): ?><label class="inline-check small"><input type="checkbox" name="remove_photo" value="1"> 사진 삭제</label><?php endif ?>
      <p class="muted tiny-text">사진은 긴 변 <?= SUPPLY_PHOTO_MAX ?>px로 줄여서 올라갑니다.</p>
    </div>
    <div>
      <label>품명<input name="name" value="<?= e($s['name'] ?? '') ?>" maxlength="100" placeholder="예: 샴푸" required></label>
      <label>규격 · 스펙<input name="spec" value="<?= e($s['spec'] ?? '') ?>" maxlength="200" placeholder="예: 500ml 펌프형, ○○사"></label>
      <div class="row">
        <label>분류<input name="category" value="<?= e($s['category'] ?? '') ?>" maxlength="50" list="supplyCats" placeholder="예: 욕실용품"></label>
        <datalist id="supplyCats"><?php foreach ($cats as $c): ?><option><?= e($c) ?></option><?php endforeach ?></datalist>
        <label>단위<input name="unit" value="<?= e($s['unit'] ?? '개') ?>" maxlength="20" list="supplyUnits"></label>
        <datalist id="supplyUnits"><option>개</option><option>병</option><option>롤</option><option>장</option><option>박스</option><option>세트</option><option>kg</option></datalist>
        <label>적정재고<input name="safety_stock" value="<?= (int) ($s['safety_stock'] ?? 0) ?: '' ?>" inputmode="numeric" class="num" placeholder="0"></label>
      </div>
      <?php if (!$s): ?>
        <div class="row">
          <label>기초재고 <small class="muted">(지금 있는 수량, 선택)</small><input name="init_qty" inputmode="numeric" class="num" placeholder="0"></label>
          <label>기초재고 날짜<input type="date" name="init_date" value="<?= e(date('Y-m-d')) ?>"></label>
        </div>
      <?php endif ?>
      <label>메모<textarea name="memo" rows="3" placeholder="예: 구입처, 발주 단위, 보관 위치"><?= e($s['memo'] ?? '') ?></textarea></label>
      <div class="row">
        <label>순서<input name="sort_order" value="<?= (int) ($s['sort_order'] ?? 0) ?>" inputmode="numeric" class="num tiny"></label>
        <?php if ($s): ?><label class="inline-check"><input type="checkbox" name="is_active" value="1" <?= $s['is_active'] ? 'checked' : '' ?>> 사용 <small class="muted">(해제하면 일별 수불 입력에서 빠짐)</small></label><?php endif ?>
      </div>
    </div>
  </div>
  <div class="actions">
    <button class="btn primary"><?= $s ? '저장' : '등록' ?></button>
  </div>
</form>
<?php if ($s && $canDelete): ?>
<form method="post" class="card narrow no-print" onsubmit="return confirm('이 품목을 삭제할까요?')">
  <?= csrf_field() ?><input type="hidden" name="target" value="item_delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
  <button class="btn small ghost danger">품목 삭제</button> <small class="muted">수불 기록이 있는 품목은 삭제할 수 없고 '사용'을 해제합니다.</small>
</form>
<?php endif ?>
<?php layout_footer(); exit; }

/* ═════════════ 품목 목록 ═════════════ */
if ($tab === 'items') {
    $items = supplies_all();
    $stock = supply_stock_now();
    ?>
<section class="card">
  <?php $nav() ?>
  <div class="actions no-margin no-print"><a class="btn primary" href="<?= e(url('supplies.php?edit=new')) ?>">+ 품목 등록</a></div>
  <?php if (!$items): ?>
    <p class="muted">등록된 소모품이 없습니다. '+ 품목 등록'으로 사진·품명·규격을 등록하세요.</p>
  <?php else: ?>
  <div class="table-scroll">
  <table class="table supply-table">
    <thead><tr><th>사진</th><th>품명</th><th>규격 · 스펙</th><th>분류</th><th>단위</th><th class="right">적정재고</th><th class="right">현재재고</th><th>사용</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($items as $sid => $s): $n = $stock[$sid] ?? 0; $low = $s['safety_stock'] && $n < $s['safety_stock']; ?>
      <tr class="<?= $s['is_active'] ? '' : 'inactive' ?>">
        <td><?= supply_photo($s) ?></td><td><b><?= e($s['name']) ?></b><?= $s['memo'] ? '<br><small class="muted">' . e(mb_strimwidth($s['memo'], 0, 40, '…')) . '</small>' : '' ?></td>
        <td><?= e($s['spec'] ?? '') ?></td><td><?= e($s['category'] ?? '') ?></td><td><?= e($s['unit']) ?></td>
        <td class="right"><?= $s['safety_stock'] ? number_format($s['safety_stock']) : '-' ?></td>
        <td class="right"><b class="<?= $low ? 'warn' : '' ?>"><?= number_format($n) ?></b><?= $low ? ' <span class="badge st-rejected">부족</span>' : '' ?></td>
        <td><?= $s['is_active'] ? '✔' : '<span class="muted">중지</span>' ?></td>
        <td class="nowrap"><a class="btn small ghost" href="<?= e(url("supplies.php?item=$sid&ym=$ym")) ?>">수불대장</a> <a class="btn small" href="<?= e(url("supplies.php?edit=$sid")) ?>">수정</a></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
  <?php endif ?>
</section>
<?php layout_footer(); exit; }

/* ═════════════ 품목별 수불대장 ═════════════ */
if (isset($_GET['item'])) {
    $s = supply_find((int) $_GET['item']) ?? abort(404, '품목을 찾을 수 없습니다.');
    $first = new DateTimeImmutable("$ym-01");
    $from = $first->format('Y-m-d');
    $to = $first->modify('last day of this month')->format('Y-m-d');
    $carry = supply_stock_before($from)[(int) $s['id']] ?? 0;
    $st = $pdo->prepare('SELECT m.*, u.name AS user_name FROM supply_moves m LEFT JOIN users u ON u.id = m.user_id WHERE m.supply_id = ? AND m.move_date BETWEEN ? AND ? ORDER BY m.move_date');
    $st->execute([$s['id'], $from, $to]);
    $moves = $st->fetchAll();
    $q = fn(string $m) => "supplies.php?item={$s['id']}&ym=$m";
    ?>
<section class="card">
  <?php $nav() ?>
  <div class="supply-head">
    <?= supply_photo($s, 'supply-photo') ?>
    <div><h2><?= e($s['name']) ?> <small class="muted">수불대장</small></h2>
      <p class="muted"><?= e(implode(' · ', array_filter([$s['spec'], $s['category'], '단위 ' . $s['unit'], $s['safety_stock'] ? '적정재고 ' . number_format($s['safety_stock']) : null]))) ?></p></div>
  </div>
  <div class="cal-nav no-print">
    <a class="btn" href="<?= e(url($q($first->modify('-1 month')->format('Y-m')))) ?>">‹ 이전달</a>
    <strong><?= e($first->format('Y년 n월')) ?></strong>
    <a class="btn" href="<?= e(url($q($first->modify('+1 month')->format('Y-m')))) ?>">다음달 ›</a>
    <button type="button" class="btn ghost" onclick="window.print()">인쇄</button>
  </div>
  <table class="table supply-ledger">
    <thead><tr><th>일자</th><th class="right">입고</th><th class="right">출고</th><th class="right">재고</th><th>비고</th><th>입력</th></tr></thead>
    <tbody>
      <tr class="sys-row"><td>전월이월</td><td></td><td></td><td class="right"><b><?= number_format($carry) ?></b></td><td></td><td></td></tr>
      <?php $bal = $carry; $tin = $tout = 0; foreach ($moves as $m): $bal += $m['in_qty'] - $m['out_qty']; $tin += $m['in_qty']; $tout += $m['out_qty']; ?>
        <tr class="clickable" onclick="location.href='<?= e(url('supplies.php?date=' . $m['move_date'])) ?>'">
          <td class="nowrap"><?= e($m['move_date']) ?> (<?= weekday_ko($m['move_date']) ?>)</td>
          <td class="right"><?= $m['in_qty'] ? number_format($m['in_qty']) : '' ?></td><td class="right"><?= $m['out_qty'] ? number_format($m['out_qty']) : '' ?></td>
          <td class="right"><b class="<?= $s['safety_stock'] && $bal < $s['safety_stock'] ? 'warn' : '' ?>"><?= number_format($bal) ?></b></td>
          <td><?= e($m['note'] ?? '') ?></td><td class="small muted"><?= e($m['user_name'] ?? '') ?></td></tr>
      <?php endforeach ?>
      <?php if (!$moves): ?><tr><td colspan="6" class="muted center">이 달 수불 기록이 없습니다.</td></tr><?php endif ?>
    </tbody>
    <tfoot><tr><th>합계</th><th class="right"><?= number_format($tin) ?></th><th class="right"><?= number_format($tout) ?></th><th class="right"><?= number_format($bal) ?></th><th colspan="2">월말재고 (<?= e($s['unit']) ?>)</th></tr></tfoot>
  </table>
</section>
<?php layout_footer(); exit; }

/* ═════════════ 월간 수불부 ═════════════ */
if ($tab === 'month') {
    $first = new DateTimeImmutable("$ym-01");
    $from = $first->format('Y-m-d');
    $to = $first->modify('last day of this month')->format('Y-m-d');
    $carry = supply_stock_before($from);
    $st = $pdo->prepare('SELECT supply_id, SUM(in_qty) AS i, SUM(out_qty) AS o FROM supply_moves WHERE move_date BETWEEN ? AND ? GROUP BY supply_id');
    $st->execute([$from, $to]);
    $mon = [];
    foreach ($st as $r) $mon[(int) $r['supply_id']] = [(int) $r['i'], (int) $r['o']];
    $items = array_filter(supplies_all(), fn($s) => $s['is_active'] || isset($mon[(int) $s['id']]) || ($carry[(int) $s['id']] ?? 0));
    ?>
<section class="card">
  <?php $nav() ?>
  <div class="cal-nav no-print">
    <a class="btn" href="<?= e(url('supplies.php?view=month&ym=' . $first->modify('-1 month')->format('Y-m'))) ?>">‹ 이전달</a>
    <strong><?= e($first->format('Y년 n월')) ?> 수불부</strong>
    <a class="btn" href="<?= e(url('supplies.php?view=month&ym=' . $first->modify('+1 month')->format('Y-m'))) ?>">다음달 ›</a>
    <button type="button" class="btn ghost" onclick="window.print()">인쇄</button>
  </div>
  <div class="table-scroll">
  <table class="table supply-table">
    <thead><tr><th>사진</th><th>품명 · 규격</th><th>단위</th><th class="right">전월이월</th><th class="right">입고</th><th class="right">출고</th><th class="right">월말재고</th><th class="right">적정재고</th><th class="no-print"></th></tr></thead>
    <tbody>
    <?php foreach ($items as $sid => $s): [$i, $o] = $mon[$sid] ?? [0, 0]; $c = $carry[$sid] ?? 0; $end = $c + $i - $o; $low = $s['safety_stock'] && $end < $s['safety_stock']; ?>
      <tr><td><?= supply_photo($s) ?></td><td><b><?= e($s['name']) ?></b><?= $s['spec'] ? '<br><small class="muted">' . e($s['spec']) . '</small>' : '' ?></td><td><?= e($s['unit']) ?></td>
        <td class="right"><?= number_format($c) ?></td><td class="right"><?= $i ? number_format($i) : '' ?></td><td class="right"><?= $o ? number_format($o) : '' ?></td>
        <td class="right"><b class="<?= $low ? 'warn' : '' ?>"><?= number_format($end) ?></b><?= $low ? ' <span class="badge st-rejected">부족</span>' : '' ?></td>
        <td class="right"><?= $s['safety_stock'] ? number_format($s['safety_stock']) : '-' ?></td>
        <td class="no-print"><a class="btn small ghost" href="<?= e(url("supplies.php?item=$sid&ym=$ym")) ?>">수불대장</a></td></tr>
    <?php endforeach ?>
    <?php if (!$items): ?><tr><td colspan="9" class="muted center">등록된 소모품이 없습니다.</td></tr><?php endif ?>
    </tbody>
  </table>
  </div>
</section>
<?php layout_footer(); exit; }

/* ═════════════ 일별 수불 입력 ═════════════ */
$before = supply_stock_before($date);
$moves = supply_moves_on($date);
$items = array_filter(supplies_all(), fn($s) => $s['is_active'] || isset($moves[(int) $s['id']]));
$retry = $_SESSION['supply_post'] ?? null; // 저장 실패 시 입력값
unset($_SESSION['supply_post']);
$dt = new DateTimeImmutable($date);
?>
<form method="post" class="card" data-supply-day>
  <?= csrf_field() ?><input type="hidden" name="target" value="day"><input type="hidden" name="date" value="<?= e($date) ?>">
  <?php $nav() ?>
  <div class="cal-nav no-print">
    <a class="btn" href="<?= e(url('supplies.php?date=' . $dt->modify('-1 day')->format('Y-m-d'))) ?>">‹ 전날</a>
    <input type="date" value="<?= e($date) ?>" onchange="if (this.value) location.href='?date=' + this.value" class="date-jump">
    <strong><?= e($dt->format('n월 j일')) ?> (<?= weekday_ko($date) ?>)</strong>
    <a class="btn" href="<?= e(url('supplies.php?date=' . $dt->modify('+1 day')->format('Y-m-d'))) ?>">다음날 ›</a>
    <a class="btn ghost" href="<?= e(url('supplies.php')) ?>">오늘</a>
    <button type="button" class="btn ghost" onclick="window.print()">인쇄</button>
  </div>
  <?php if (!$items): ?>
    <p class="muted">등록된 소모품이 없습니다. <a href="<?= e(url('supplies.php?edit=new')) ?>">품목 등록</a>에서 먼저 사진·품명·규격을 등록하세요.</p>
  <?php else: ?>
  <p class="muted small">받은 수량은 <b>입고</b>, 객실에 쓴(나간) 수량은 <b>출고</b>에 입력하면 재고가 자동으로 계산됩니다. 0으로 비우면 그 날 기록이 지워집니다.</p>
  <div class="table-scroll">
  <table class="table supply-table supply-day">
    <thead><tr><th>사진</th><th>품명 · 규격</th><th>단위</th><th class="right">전일재고</th><th>입고</th><th>출고</th><th class="right">재고</th><th>비고</th></tr></thead>
    <tbody>
    <?php foreach ($items as $sid => $s): $m = $moves[$sid] ?? null; $r = $retry[$sid] ?? null;
        $in = $r ? (string) ($r['in'] ?? '') : ($m && $m['in_qty'] ? (string) $m['in_qty'] : '');
        $out = $r ? (string) ($r['out'] ?? '') : ($m && $m['out_qty'] ? (string) $m['out_qty'] : ''); ?>
      <tr data-supply data-before="<?= (int) ($before[$sid] ?? 0) ?>" data-safety="<?= (int) $s['safety_stock'] ?>" class="<?= $s['is_active'] ? '' : 'inactive' ?>">
        <td><?= supply_photo($s) ?></td>
        <td><a href="<?= e(url("supplies.php?item=$sid&ym=" . substr($date, 0, 7))) ?>"><b><?= e($s['name']) ?></b></a><?= $s['spec'] ? '<br><small class="muted">' . e($s['spec']) . '</small>' : '' ?></td>
        <td><?= e($s['unit']) ?></td>
        <td class="right"><?= number_format($before[$sid] ?? 0) ?></td>
        <td><input name="m[<?= $sid ?>][in]" value="<?= e($in) ?>" inputmode="numeric" class="num tiny" placeholder="0" data-in></td>
        <td><input name="m[<?= $sid ?>][out]" value="<?= e($out) ?>" inputmode="numeric" class="num tiny" placeholder="0" data-out></td>
        <td class="right"><b data-bal>0</b> <span class="badge st-rejected" data-low hidden>부족</span></td>
        <td><input name="m[<?= $sid ?>][note]" value="<?= e($r ? (string) ($r['note'] ?? '') : ($m['note'] ?? '')) ?>" maxlength="200" placeholder="예: ○○상사 입고" class="supply-note">
          <?php if ($m && $m['user_name']): ?><small class="muted"><?= e($m['user_name']) ?></small><?php endif ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
  <div class="actions no-print"><button class="btn primary">이 날 수불 저장</button></div>
  <?php endif ?>
</form>
<script>
(function () {
  const f = document.querySelector('[data-supply-day]');
  if (!f) return;
  const num = (v) => parseInt(String(v).replace(/\D/g, ''), 10) || 0;
  function recalc() {
    f.querySelectorAll('[data-supply]').forEach((tr) => {
      const bal = num(tr.dataset.before) + num(tr.querySelector('[data-in]').value) - num(tr.querySelector('[data-out]').value);
      const b = tr.querySelector('[data-bal]');
      b.textContent = bal.toLocaleString('ko-KR');
      b.classList.toggle('warn', bal < 0 || (num(tr.dataset.safety) > 0 && bal < num(tr.dataset.safety)));
      tr.querySelector('[data-low]').hidden = !(num(tr.dataset.safety) > 0 && bal < num(tr.dataset.safety));
    });
  }
  f.addEventListener('input', recalc);
  recalc();
})();
</script>
<?php layout_footer();
