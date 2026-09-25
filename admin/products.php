<?php
/** 설정 › 상품·요금: 입장권·객실·시설대관·대관 숙박시설 상품, 가격, 할인율, 상품권 환급액, 최대인원, 기간요금 (최고관리자) */
require dirname(__DIR__) . '/app/bootstrap.php';

$me = require_admin();
$pdo = db();

/** 'MM-DD' 검사 (02-29 허용) */
function valid_md(string $md): bool
{
    return (bool) preg_match('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', $md) && checkdate((int) substr($md, 0, 2), (int) substr($md, 3), 2024);
}

if (is_post()) {
    csrf_verify();
    $id = (int) post('id');
    $action = post('action');

    // ── 객실 할인율 (요금구분별 일괄 적용) ──
    if (post('target') === 'room_dc') {
        foreach (array_keys(RATE_TYPES) as $rate) {
            setting_set('room_dc_' . $rate, (string) max(0, min(100, (int) post('dc_' . $rate))));
        }
        flash('객실 할인율을 저장했습니다. (이미 작성된 매출보고의 금액은 바뀌지 않습니다)', 'success');
        redirect('admin/products.php#room');
    }

    // ── 객실 분류 추가·수정·삭제 ──
    if (post('target') === 'room_type') {
        $name = mb_substr(post('name'), 0, 50);
        $vals = [];
        foreach (ROOM_TYPE_COLS as $c) $vals[$c] = to_int(post($c));
        if ($action === 'delete') {
            $st = $pdo->prepare('SELECT COUNT(*) FROM products WHERE room_type_id = ?');
            $st->execute([$id]);
            if ((int) $st->fetchColumn()) flash('이 분류로 지정된 객실이 있어 삭제할 수 없습니다. 객실의 분류를 먼저 바꾸세요.', 'error');
            else { $pdo->prepare('DELETE FROM room_types WHERE id = ?')->execute([$id]); flash('객실 분류를 삭제했습니다.', 'success'); }
        } elseif ($name === '') {
            flash('분류 이름을 입력하세요.', 'error');
        } elseif ($vals['max_people'] < 1 || $vals['base_people'] > $vals['max_people']) {
            flash("{$name}: 최대인원을 입력하고, 기준인원은 최대인원 이하로 입력하세요.", 'error');
        } else {
            $cols = ['name' => $name, 'sort_order' => (int) post('sort_order', '0')] + $vals;
            if ($id) {
                $pdo->prepare('UPDATE room_types SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($cols))) . ' WHERE id = ?')->execute([...array_values($cols), $id]);
                $n = room_type_apply($id);
                flash("객실 분류 '{$name}'을(를) 저장하고 이 분류 객실 {$n}실에 인원·요금·환급액을 적용했습니다. (이미 작성된 매출보고의 금액은 바뀌지 않습니다)", 'success');
            } else {
                $pdo->prepare('INSERT INTO room_types (' . implode(', ', array_keys($cols)) . ') VALUES (?' . str_repeat(', ?', count($cols) - 1) . ')')->execute(array_values($cols));
                flash("객실 분류 '{$name}'을(를) 추가했습니다. 객실 표에서 객실마다 분류를 고르세요.", 'success');
            }
        }
        redirect('admin/products.php#room-types');
    }

    // ── 기간요금 저장/삭제 ──
    if (post('target') === 'season') {
        $grp = post('grp');
        if ($action === 'delete') {
            $pdo->prepare('DELETE FROM seasons WHERE id = ?')->execute([$id]);
            flash('기간요금을 삭제했습니다.', 'success');
            redirect('admin/products.php#seasons');
        }
        if (!isset(SEASON_GROUPS[$grp])) abort(400, '잘못된 값입니다.');
        $name = mb_substr(post('name'), 0, 50);
        $start = post('start_md');
        $end = post('end_md');
        if ($name === '' || !valid_md($start) || !valid_md($end)) {
            flash('기간 이름과 기간(월-일, 예: 11-01)을 확인하세요.', 'error');
        } else {
            $row = [$grp, $name, $start, $end, (int) post('sort_order', '0'), post('is_active') === '1' ? 1 : 0];
            if ($id) {
                $pdo->prepare('UPDATE seasons SET grp = ?, name = ?, start_md = ?, end_md = ?, sort_order = ?, is_active = ? WHERE id = ?')
                    ->execute([...$row, $id]);
            } else {
                $pdo->prepare('INSERT INTO seasons (grp, name, start_md, end_md, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)')->execute($row);
            }
            flash("기간요금 '{$name}'을(를) 저장했습니다." . ($grp === 'ticket' ? ' 입장권 표에서 상품별 기간 가격을 입력하세요.' : ''), 'success');
        }
        redirect('admin/products.php#seasons');
    }

    // ── 상품 저장/삭제 ──
    $grp = post('grp');
    if (!isset(PRODUCT_GROUPS[$grp])) abort(400, '잘못된 값입니다.');
    if ($id && !empty(products_all()[$id]['sys_key'])) abort(400, '쉬자파크숙박(입실·퇴실)은 자동 상품이라 수정·삭제할 수 없습니다.');

    if ($action === 'delete') {
        $st = $pdo->prepare('SELECT COUNT(*) FROM sales_lines WHERE product_id = ?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            flash('이미 매출보고에 사용된 상품은 삭제할 수 없습니다. 대신 "판매"를 해제(판매중지)하세요.', 'error');
        } else {
            $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
            flash('삭제했습니다.', 'success');
        }
        redirect('admin/products.php#' . $grp);
    }

    $name = mb_substr(post('name'), 0, 100);
    $data = [
        'name'          => $name,
        'is_free'       => $grp === 'ticket' && post('is_free') === '1' ? 1 : 0,
        'price'         => to_int(post('price')),
        'price_weekend' => $grp === 'room' ? to_int(post('price_weekend')) : 0,
        'price_peak'    => $grp === 'room' ? to_int(post('price_peak')) : 0,
        'refund_amount' => $grp === 'room' ? to_int(post('refund_amount')) : 0,
        'refund_weekend' => $grp === 'room' ? to_int(post('refund_weekend')) : 0,
        'refund_peak'   => $grp === 'room' ? to_int(post('refund_peak')) : 0,
        'price_2h'      => $grp === 'rental' ? to_int(post('price_2h')) : 0,
        'price_4h'      => $grp === 'rental' ? to_int(post('price_4h')) : 0,
        'price_day'     => $grp === 'rental' ? to_int(post('price_day')) : 0,
        'price_night'   => $grp === 'rental' ? to_int(post('price_night')) : 0,
        'max_people'    => $grp === 'room' ? to_int(post('max_people')) : 0,
        'room_type_id'  => $grp === 'room' && isset(room_types_all()[(int) post('room_type_id')]) ? (int) post('room_type_id') : null,
        'sort_order'    => (int) post('sort_order', '0'),
        'is_active'     => post('is_active') === '1' ? 1 : 0,
    ];
    if ($data['is_free']) $data['price'] = 0;

    if ($name === '') {
        flash('상품명을 입력하세요.', 'error');
    } elseif ($grp === 'room' && !$data['room_type_id']) {
        flash("{$name}: 객실 분류를 고르세요. 인원·요금·환급액은 분류에서 정해집니다.", 'error');
    } else {
        if ($grp === 'room') foreach (ROOM_TYPE_COLS as $c) unset($data[$c]); // 분류 값으로 덮어씀 (아래 room_type_apply)
        $cols = array_keys($data);
        $pdo->beginTransaction();
        if ($id) {
            $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
            $pdo->prepare("UPDATE products SET $set WHERE id = ? AND grp = ?")->execute([...array_values($data), $id, $grp]);
        } else {
            $pdo->prepare('INSERT INTO products (grp, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')')
                ->execute([$grp, ...array_values($data)]);
            $id = (int) $pdo->lastInsertId();
        }
        // 입장권 기간 가격 (비우면 정상가 적용)
        if ($grp === 'ticket') {
            foreach ((array) ($_POST['season_price'] ?? []) as $sid => $val) {
                $pdo->prepare('DELETE FROM season_prices WHERE season_id = ? AND product_id = ?')->execute([(int) $sid, $id]);
                if (!$data['is_free'] && trim((string) $val) !== '' && isset(seasons_all()[(int) $sid])) {
                    $pdo->prepare('INSERT INTO season_prices (season_id, product_id, price) VALUES (?, ?, ?)')->execute([(int) $sid, $id, to_int($val)]);
                }
            }
        }
        if ($grp === 'room') room_type_apply((int) $data['room_type_id'], $id);
        $pdo->commit();
        flash("{$name} 저장했습니다." . ($grp === 'room' ? " (분류 '" . room_type_name((int) $data['room_type_id']) . "'의 인원·요금·환급액 적용)" : '') . ' (이미 작성된 매출보고의 금액은 바뀌지 않습니다)', 'success');
    }
    redirect('admin/products.php#' . $grp);
}

$byGroup = array_fill_keys(array_keys(PRODUCT_GROUPS), []);
foreach (products_all() as $p) $byGroup[$p['grp']][] = $p;
$nextSort = fn(array $rows) => $rows ? max(array_column($rows, 'sort_order')) + 10 : 10;
$ticketSeasons = array_filter(seasons_all(), fn($s) => $s['grp'] === 'ticket');
$roomSeasons = array_filter(seasons_all(), fn($s) => $s['grp'] === 'room' && $s['is_active']);

/** 상품 행 하나 (기존 상품 수정 또는 신규 추가) */
function product_row(string $grp, ?array $p, int $sort, array $ticketSeasons): void
{
    $fid = 'p' . ($p['id'] ?? 'new-' . $grp);
    if ($p && !empty($p['sys_key'])): // 쉬자파크숙박 입실·퇴실: 수정·삭제 불가 ?>
  <tr class="sys-row">
    <td class="center muted">🔒</td>
    <td><b><?= e($p['name']) ?></b></td>
    <td><span class="badge">무료</span></td>
    <td colspan="<?= 1 + count($ticketSeasons) ?>" class="small muted"><?= $p['sys_key'] === 'stay_in' ? '매출보고의 객실 입실인원 합계가 자동으로 들어갑니다' : '전날 매출보고의 입실인원 합계가 자동으로 들어갑니다' ?> (수정·삭제 불가)</td>
    <td class="center">✔</td><td></td>
  </tr>
    <?php return; endif;
    $val = fn(string $k, mixed $d = '') => e($p[$k] ?? $d);
    $money = fn(string $k) => e(isset($p[$k]) && $p[$k] ? number_format((int) $p[$k]) : '');
    ?>
  <tr class="<?= $p ? ($p['is_active'] ? '' : 'inactive') : 'new-row' ?>">
    <td><input form="<?= $fid ?>" name="sort_order" value="<?= $val('sort_order', $sort) ?>" class="num tiny" inputmode="numeric"></td>
    <td><input form="<?= $fid ?>" name="name" value="<?= $val('name') ?>" placeholder="<?= $p ? '' : ['ticket' => '새 입장권 (예: 어른)', 'room' => '새 객실 (예: 숲속의집 101호)', 'rental' => '새 대관시설 (예: 세미나실)', 'lodge' => '새 대관 숙박시설 (예: 연수동)'][$grp] ?>" required></td>
    <?php if ($grp === 'rental'): ?>
      <?php foreach ([...array_column(RENT_TIMES, 1), 'price_night'] as $col): ?>
        <td><input form="<?= $fid ?>" name="<?= $col ?>" value="<?= $money($col) ?>" class="num" inputmode="numeric" data-money placeholder="0"></td>
      <?php endforeach ?>
    <?php elseif ($grp === 'lodge'): ?>
      <td><input form="<?= $fid ?>" name="price" value="<?= $money('price') ?>" class="num" inputmode="numeric" data-money placeholder="0"></td>
    <?php elseif ($grp === 'ticket'): ?>
      <td><select form="<?= $fid ?>" name="is_free">
        <option value="0">유료</option><option value="1" <?= !empty($p['is_free']) ? 'selected' : '' ?>>무료</option>
      </select></td>
      <td><input form="<?= $fid ?>" name="price" value="<?= $money('price') ?>" class="num" inputmode="numeric" data-money placeholder="0"></td>
      <?php foreach ($ticketSeasons as $sid => $s):
          $sp = $p ? (season_prices()[$sid][(int) $p['id']] ?? null) : null; ?>
        <td><input form="<?= $fid ?>" name="season_price[<?= $sid ?>]" value="<?= $sp !== null ? e(number_format($sp)) : '' ?>" class="num" inputmode="numeric" data-money placeholder="정상가"></td>
      <?php endforeach ?>
    <?php else: ?>
      <td><select form="<?= $fid ?>" name="room_type_id" required>
        <option value="">- 분류 선택 -</option>
        <?php foreach (room_types_all() as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) ($p['room_type_id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach ?>
      </select><?= $p && !$p['room_type_id'] ? '<br><small class="warn">분류를 지정하세요</small>' : '' ?></td>
      <?php if ($p): // 인원·요금·환급액은 분류에서 (읽기 전용) ?>
        <td class="center small"><?= (int) ($p['base_people'] ?? 0) ?> / <?= (int) $p['max_people'] ?></td>
        <?php foreach (['weekday' => 'price', 'weekend' => 'price_weekend', 'peak' => 'price_peak'] as $rate => $col): ?>
          <td class="right"><?= number_format((int) $p[$col]) ?><?php if (room_dc_pct($rate) > 0): ?><br><small class="muted dc-preview">할인 <?= number_format(room_price($p, $rate, true)) ?></small><?php endif ?></td>
        <?php endforeach ?>
        <?php foreach (['refund_amount', 'refund_weekend', 'refund_peak'] as $col): ?><td class="right refund-cell"><?= number_format((int) $p[$col]) ?></td><?php endforeach ?>
      <?php else: ?>
        <td colspan="7" class="small muted">인원·요금·환급액은 고른 분류의 값이 들어갑니다.</td>
      <?php endif ?>
    <?php endif ?>
    <td class="center"><input form="<?= $fid ?>" type="checkbox" name="is_active" value="1" <?= !$p || $p['is_active'] ? 'checked' : '' ?>></td>
    <td class="nowrap">
      <form method="post" id="<?= $fid ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) ($p['id'] ?? 0) ?>">
        <input type="hidden" name="grp" value="<?= $grp ?>">
        <button class="btn small primary" name="action" value="save"><?= $p ? '저장' : '추가' ?></button>
        <?php if ($p): ?>
          <button class="btn small ghost danger" name="action" value="delete" formnovalidate onclick="return confirm('삭제할까요?')">삭제</button>
        <?php endif ?>
      </form>
    </td>
  </tr>
    <?php
}

/** 기간요금 행 */
function season_row(?array $s, int $sort): void
{
    $fid = 's' . ($s['id'] ?? 'new');
    ?>
  <tr class="<?= $s ? ($s['is_active'] ? '' : 'inactive') : 'new-row' ?>">
    <td><input form="<?= $fid ?>" name="sort_order" value="<?= e($s['sort_order'] ?? $sort) ?>" class="num tiny" inputmode="numeric"></td>
    <td><select form="<?= $fid ?>" name="grp">
      <?php foreach (SEASON_GROUPS as $k => $label): ?><option value="<?= $k ?>" <?= ($s['grp'] ?? 'ticket') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
    </select></td>
    <td><input form="<?= $fid ?>" name="name" value="<?= e($s['name'] ?? '') ?>" placeholder="<?= $s ? '' : '새 기간 (예: 동절기)' ?>" required></td>
    <td><input form="<?= $fid ?>" name="start_md" value="<?= e($s['start_md'] ?? '') ?>" class="md" placeholder="11-01" pattern="\d{2}-\d{2}" required></td>
    <td><input form="<?= $fid ?>" name="end_md" value="<?= e($s['end_md'] ?? '') ?>" class="md" placeholder="02-29" pattern="\d{2}-\d{2}" required></td>
    <td class="small muted"><?= $s ? ($s['grp'] === 'ticket' ? '입장권 표의 기간 가격 적용' : '성수기 요금 자동 선택') : '' ?></td>
    <td class="center"><input form="<?= $fid ?>" type="checkbox" name="is_active" value="1" <?= !$s || $s['is_active'] ? 'checked' : '' ?>></td>
    <td class="nowrap">
      <form method="post" id="<?= $fid ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="target" value="season">
        <input type="hidden" name="id" value="<?= (int) ($s['id'] ?? 0) ?>">
        <button class="btn small primary" name="action" value="save"><?= $s ? '저장' : '추가' ?></button>
        <?php if ($s): ?>
          <button class="btn small ghost danger" name="action" value="delete" formnovalidate onclick="return confirm('기간요금을 삭제할까요? 입장권의 기간 가격도 함께 지워집니다.')">삭제</button>
        <?php endif ?>
      </form>
    </td>
  </tr>
    <?php
}

layout_header('상품·요금', 'settings');
settings_nav('products');
?>
<section class="card" id="ticket">
  <h1>상품관리 · 입장권</h1>
  <p class="muted small">매출보고 작성 화면에 이 순서대로 표시되고, 단가가 자동으로 들어갑니다. 무료 상품은 대시보드에서 '무료'로 집계됩니다.
    <?php if ($ticketSeasons): ?><br>기간 가격 칸을 비워두면 그 기간에도 정상가가 적용됩니다.<?php endif ?></p>
  <div class="table-scroll">
  <table class="table product-table">
    <thead><tr><th>순서</th><th>상품명</th><th>유료/무료</th><th>가격(원)</th>
      <?php foreach ($ticketSeasons as $s): ?><th class="season-col"><?= e($s['name']) ?> 가격<br><small><?= e(str_replace('-', '/', $s['start_md']) . '~' . str_replace('-', '/', $s['end_md'])) ?><?= $s['is_active'] ? '' : ' 사용안함' ?></small></th><?php endforeach ?>
      <th>판매</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($byGroup['ticket'] as $p) product_row('ticket', $p, 0, $ticketSeasons) ?>
      <?php product_row('ticket', null, $nextSort($byGroup['ticket']), $ticketSeasons) ?>
    </tbody>
  </table>
  </div>
</section>

<section class="card" id="room">
  <h1>상품관리 · 객실</h1>
  <p class="muted small">
    객실의 <b>기준인원 · 최대인원 · 요금 · 상품권 환급액</b>은 <b>객실 분류</b>에서 정합니다. 분류를 저장하면 그 분류의 모든 객실에 덮어씁니다.<br>
    요금은 <b>비수기 평일 · 비수기 주말 · 성수기</b> 3가지입니다. 매출보고에서
    <?= $roomSeasons ? e(implode(', ', array_map('season_label', $roomSeasons))) . '은 성수기, ' : '' ?>그 외 금·토요일은 비수기 주말, 나머지는 비수기 평일 요금이 자동 선택됩니다.
    <b>상품권 환급액</b>은 1실당 환급해 주는 지역상품권 금액이며, 매출보고에서 입력한 환급액이 기준과 다르면 알려 줍니다.
  </p>
  <form method="post" class="dc-form">
    <?= csrf_field() ?><input type="hidden" name="target" value="room_dc">
    <b>할인율 (할인 체크 시 일괄 적용)</b>
    <?php foreach (RATE_TYPES as $rate => $label): ?>
      <label><?= e($label) ?> <input name="dc_<?= $rate ?>" value="<?= room_dc_pct($rate) ?>" class="num tiny" inputmode="numeric"> %</label>
    <?php endforeach ?>
    <button class="btn small primary">할인율 저장</button>
  </form>

  <h2 id="room-types">객실 분류 <small class="muted">인원 · 요금 · 환급액</small></h2>
  <div class="table-scroll">
  <table class="table product-table room-type-table">
    <thead>
      <tr><th rowspan="2">순서</th><th rowspan="2">분류</th><th rowspan="2">기준인원</th><th rowspan="2">최대인원</th><th colspan="3" class="center">요금(원)</th><th colspan="3" class="center refund-head">상품권 환급액(원)</th><th rowspan="2">객실</th><th rowspan="2"></th></tr>
      <tr><?php foreach (RATE_TYPES as $rate => $label): ?><th><?= e($label) ?><br><small><?= room_dc_pct($rate) ?>% 할인</small></th><?php endforeach ?>
        <?php foreach (RATE_TYPES as $label): ?><th class="refund-head"><?= e($label) ?></th><?php endforeach ?></tr>
    </thead>
    <tbody>
    <?php $typeCount = array_count_values(array_map(fn($p) => (int) $p['room_type_id'], $byGroup['room']));
    foreach ([...room_types_all(), null] as $t): $fid = 'rt' . ($t['id'] ?? 'new');
        $tm = fn(string $c) => $t && $t[$c] ? e(number_format((int) $t[$c])) : ''; ?>
      <tr class="<?= $t ? '' : 'new-row' ?>">
        <td><input form="<?= $fid ?>" name="sort_order" value="<?= e($t['sort_order'] ?? (count(room_types_all()) + 1) * 10) ?>" class="num tiny" inputmode="numeric"></td>
        <td><input form="<?= $fid ?>" name="name" value="<?= e($t['name'] ?? '') ?>" placeholder="<?= $t ? '' : '새 분류 (예: 6인실)' ?>" maxlength="50" required class="short-name"></td>
        <td><input form="<?= $fid ?>" name="base_people" value="<?= e($t['base_people'] ?? '') ?>" class="num tiny" inputmode="numeric" placeholder="0"></td>
        <td><input form="<?= $fid ?>" name="max_people" value="<?= e($t['max_people'] ?? '') ?>" class="num tiny" inputmode="numeric" required></td>
        <?php foreach (['price', 'price_weekend', 'price_peak'] as $c): ?>
          <td><input form="<?= $fid ?>" name="<?= $c ?>" value="<?= $tm($c) ?>" class="num" inputmode="numeric" data-money placeholder="0"></td>
        <?php endforeach ?>
        <?php foreach (['refund_amount', 'refund_weekend', 'refund_peak'] as $c): ?>
          <td class="refund-cell"><input form="<?= $fid ?>" name="<?= $c ?>" value="<?= $tm($c) ?>" class="num" inputmode="numeric" data-money placeholder="0"></td>
        <?php endforeach ?>
        <td class="small nowrap"><?= $t ? (int) ($typeCount[(int) $t['id']] ?? 0) . '실' : '' ?></td>
        <td class="nowrap"><form method="post" id="<?= $fid ?>">
          <?= csrf_field() ?><input type="hidden" name="target" value="room_type"><input type="hidden" name="id" value="<?= (int) ($t['id'] ?? 0) ?>">
          <button class="btn small primary" name="action" value="save" <?= $t && ($typeCount[(int) $t['id']] ?? 0) ? 'onclick="return confirm(\'이 분류의 객실 ' . (int) $typeCount[(int) $t['id']] . '실에 인원·요금·환급액을 덮어씁니다. 저장할까요?\')"' : '' ?>><?= $t ? '저장' : '추가' ?></button>
          <?php if ($t): ?><button class="btn small ghost danger" name="action" value="delete" formnovalidate onclick="return confirm('이 분류를 삭제할까요?')">삭제</button><?php endif ?>
        </form></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
  <p class="muted small">객실이 지정된 분류는 삭제할 수 없습니다. 분류별로 <b>객실이용통계</b>를 볼 수 있습니다.</p>

  <h2>객실</h2>
  <p class="muted small">객실마다 분류만 고르면 인원·요금·환급액은 분류의 값이 들어갑니다 (아래 숫자는 보기용).</p>
  <div class="table-scroll">
  <table class="table product-table">
    <thead>
      <tr><th rowspan="2">순서</th><th rowspan="2">객실명</th><th rowspan="2">분류</th><th rowspan="2">기준/최대</th><th colspan="3" class="center">요금(원)</th><th colspan="3" class="center refund-head">상품권 환급액(원)</th><th rowspan="2">판매</th><th rowspan="2"></th></tr>
      <tr><?php foreach (RATE_TYPES as $rate => $label): ?><th><?= e($label) ?></th><?php endforeach ?>
        <?php foreach (RATE_TYPES as $label): ?><th class="refund-head"><?= e($label) ?></th><?php endforeach ?></tr>
    </thead>
    <tbody>
      <?php foreach ($byGroup['room'] as $p) product_row('room', $p, 0, []) ?>
      <?php product_row('room', null, $nextSort($byGroup['room']), []) ?>
    </tbody>
  </table>
  </div>
</section>

<section class="card" id="rental">
  <h1>상품관리 · 시설대관</h1>
  <p class="muted small">대관 시간(<?= e(implode(' · ', array_column(RENT_TIMES, 0))) ?>)에 따라 요금이 정해지고,
    <?= e(RENT_NIGHT_LABEL) ?>에 사용하면 <b>야간 추가요금</b>이 더해집니다. 매출보고에서 시설마다 대관 시간과 야간 사용 여부를 고릅니다.</p>
  <div class="table-scroll">
  <table class="table product-table">
    <thead>
      <tr><th rowspan="2">순서</th><th rowspan="2">시설명</th><th colspan="3" class="center">대관 요금(원)</th><th rowspan="2">야간 추가요금(원)<br><small>18~21시</small></th><th rowspan="2">판매</th><th rowspan="2"></th></tr>
      <tr><?php foreach (RENT_TIMES as [$label]): ?><th><?= e($label) ?></th><?php endforeach ?></tr>
    </thead>
    <tbody>
      <?php foreach ($byGroup['rental'] as $p) product_row('rental', $p, 0, []) ?>
      <?php product_row('rental', null, $nextSort($byGroup['rental']), []) ?>
    </tbody>
  </table>
  </div>
</section>

<section class="card" id="lodge">
  <h1>상품관리 · 대관 숙박시설</h1>
  <p class="muted small">정액제로 운영하는 대관용 숙박시설입니다. 매출보고에서 실 수를 입력하고, 할인은 시설대관과 합친 금액에 <b>통합 할인</b>
    (5실 이상 10% · 9실 이상 20% · 초등·청소년 20명 이상 30%)으로 적용합니다.</p>
  <div class="table-scroll">
  <table class="table product-table">
    <thead><tr><th>순서</th><th>시설명</th><th>정액 요금(원)</th><th>판매</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($byGroup['lodge'] as $p) product_row('lodge', $p, 0, []) ?>
      <?php product_row('lodge', null, $nextSort($byGroup['lodge']), []) ?>
    </tbody>
  </table>
  </div>
</section>

<section class="card" id="seasons">
  <h1>기간요금</h1>
  <p class="muted small">
    · <b>입장권</b> 기간(예: 동절기 11-01 ~ 02-29): 기간 중에는 위 입장권 표의 '기간 가격'이 자동 적용됩니다.<br>
    · <b>객실</b> 기간(예: 성수기 07-01 ~ 08-31): 기간 중에는 객실의 '성수기' 요금이 자동 선택됩니다.<br>
    · 기간은 월-일로 입력하며 해를 넘겨도 됩니다(11-01 ~ 02-29). 기간이 겹치면 순서가 빠른 것이 적용됩니다.
  </p>
  <div class="table-scroll">
  <table class="table product-table">
    <thead><tr><th>순서</th><th>대상</th><th>이름</th><th>시작(월-일)</th><th>종료(월-일)</th><th>적용 방식</th><th>사용</th><th></th></tr></thead>
    <tbody>
      <?php foreach (seasons_all() as $s) season_row($s, 0) ?>
      <?php season_row(null, seasons_all() ? max(array_column(seasons_all(), 'sort_order')) + 10 : 10) ?>
    </tbody>
  </table>
  </div>
</section>
<p class="muted small">· 상품 가격을 바꿔도 이미 작성된 매출보고의 금액은 바뀌지 않습니다(작성 당시 가격으로 저장).<br>
· 여기 가격은 <b>현재 가격</b>입니다. 지난 기간에 다른 가격을 썼다면 <a href="<?= e(url('admin/prices.php')) ?>">기간별 가격</a>에서 그 기간의 가격표를 만드세요.<br>
· 매출보고에 한 번이라도 쓰인 상품은 삭제 대신 '판매' 체크를 해제하세요.</p>
<?php layout_footer();
