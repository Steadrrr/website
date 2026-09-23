<?php
/** 설정 › 상품·요금: 입장권·객실 상품, 가격, 할인율, 상품권 환급액, 최대인원, 기간요금 (최고관리자) */
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

    // ── 기간요금 저장/삭제 ──
    if (post('target') === 'season') {
        $grp = post('grp');
        if ($action === 'delete') {
            $pdo->prepare('DELETE FROM seasons WHERE id = ?')->execute([$id]);
            flash('기간요금을 삭제했습니다.', 'success');
            redirect('admin/products.php#seasons');
        }
        if (!isset(PRODUCT_GROUPS[$grp])) abort(400, '잘못된 값입니다.');
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
        'max_people'    => $grp === 'room' ? to_int(post('max_people')) : 0,
        'sort_order'    => (int) post('sort_order', '0'),
        'is_active'     => post('is_active') === '1' ? 1 : 0,
    ];
    if ($data['is_free']) $data['price'] = 0;

    if ($name === '') {
        flash('상품명을 입력하세요.', 'error');
    } elseif ($grp === 'room' && $data['max_people'] < 1) {
        flash("{$name}: 최대인원을 입력하세요.", 'error');
    } else {
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
        $pdo->commit();
        flash("{$name} 저장했습니다. (이미 작성된 매출보고의 금액은 바뀌지 않습니다)", 'success');
    }
    redirect('admin/products.php#' . $grp);
}

$byGroup = ['ticket' => [], 'room' => []];
foreach (products_all() as $p) $byGroup[$p['grp']][] = $p;
$nextSort = fn(array $rows) => $rows ? max(array_column($rows, 'sort_order')) + 10 : 10;
$ticketSeasons = array_filter(seasons_all(), fn($s) => $s['grp'] === 'ticket');
$roomSeasons = array_filter(seasons_all(), fn($s) => $s['grp'] === 'room' && $s['is_active']);

/** 상품 행 하나 (기존 상품 수정 또는 신규 추가) */
function product_row(string $grp, ?array $p, int $sort, array $ticketSeasons): void
{
    $fid = 'p' . ($p['id'] ?? 'new-' . $grp);
    $val = fn(string $k, mixed $d = '') => e($p[$k] ?? $d);
    $money = fn(string $k) => e(isset($p[$k]) && $p[$k] ? number_format((int) $p[$k]) : '');
    ?>
  <tr class="<?= $p ? ($p['is_active'] ? '' : 'inactive') : 'new-row' ?>">
    <td><input form="<?= $fid ?>" name="sort_order" value="<?= $val('sort_order', $sort) ?>" class="num tiny" inputmode="numeric"></td>
    <td><input form="<?= $fid ?>" name="name" value="<?= $val('name') ?>" placeholder="<?= $p ? '' : ($grp === 'ticket' ? '새 입장권 (예: 어른)' : '새 객실 (예: 숲속의집 101호)') ?>" required></td>
    <?php if ($grp === 'ticket'): ?>
      <td><select form="<?= $fid ?>" name="is_free">
        <option value="0">유료</option><option value="1" <?= !empty($p['is_free']) ? 'selected' : '' ?>>무료</option>
      </select></td>
      <td><input form="<?= $fid ?>" name="price" value="<?= $money('price') ?>" class="num" inputmode="numeric" data-money placeholder="0"></td>
      <?php foreach ($ticketSeasons as $sid => $s):
          $sp = $p ? (season_prices()[$sid][(int) $p['id']] ?? null) : null; ?>
        <td><input form="<?= $fid ?>" name="season_price[<?= $sid ?>]" value="<?= $sp !== null ? e(number_format($sp)) : '' ?>" class="num" inputmode="numeric" data-money placeholder="정상가"></td>
      <?php endforeach ?>
    <?php else: ?>
      <td><input form="<?= $fid ?>" name="max_people" value="<?= $val('max_people') ?>" class="num tiny" inputmode="numeric" required></td>
      <?php foreach (['weekday' => 'price', 'weekend' => 'price_weekend', 'peak' => 'price_peak'] as $rate => $col): ?>
        <td><input form="<?= $fid ?>" name="<?= $col ?>" value="<?= $money($col) ?>" class="num" inputmode="numeric" data-money placeholder="0">
          <?php if ($p && room_dc_pct($rate) > 0): ?><small class="muted dc-preview">할인 <?= number_format(room_price($p, $rate, true)) ?></small><?php endif ?></td>
      <?php endforeach ?>
      <td><input form="<?= $fid ?>" name="refund_amount" value="<?= $money('refund_amount') ?>" class="num" inputmode="numeric" data-money placeholder="0"></td>
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
      <?php foreach (PRODUCT_GROUPS as $k => $label): ?><option value="<?= $k ?>" <?= ($s['grp'] ?? 'ticket') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
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
    요금은 <b>비수기 평일 · 비수기 주말 · 성수기</b> 3가지입니다. 매출보고에서
    <?= $roomSeasons ? e(implode(', ', array_map('season_label', $roomSeasons))) . '은 성수기, ' : '' ?>그 외 금·토요일은 비수기 주말, 나머지는 비수기 평일 요금이 자동 선택됩니다.<br>
    <b>상품권 환급액</b>은 객실 1실당 환급해 주는 지역상품권 금액입니다. 매출보고에서 입력한 환급액이 이 금액과 다르면 알려 줍니다.
  </p>
  <form method="post" class="dc-form">
    <?= csrf_field() ?><input type="hidden" name="target" value="room_dc">
    <b>할인율 (할인 체크 시 일괄 적용)</b>
    <?php foreach (RATE_TYPES as $rate => $label): ?>
      <label><?= e($label) ?> <input name="dc_<?= $rate ?>" value="<?= room_dc_pct($rate) ?>" class="num tiny" inputmode="numeric"> %</label>
    <?php endforeach ?>
    <button class="btn small primary">할인율 저장</button>
  </form>
  <div class="table-scroll">
  <table class="table product-table">
    <thead>
      <tr><th rowspan="2">순서</th><th rowspan="2">객실명</th><th rowspan="2">최대인원</th><th colspan="3" class="center">요금(원)</th><th rowspan="2">상품권<br>환급액(원)</th><th rowspan="2">판매</th><th rowspan="2"></th></tr>
      <tr><?php foreach (RATE_TYPES as $rate => $label): ?><th><?= e($label) ?><br><small><?= room_dc_pct($rate) ?>% 할인</small></th><?php endforeach ?></tr>
    </thead>
    <tbody>
      <?php foreach ($byGroup['room'] as $p) product_row('room', $p, 0, []) ?>
      <?php product_row('room', null, $nextSort($byGroup['room']), []) ?>
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
· 매출보고에 한 번이라도 쓰인 상품은 삭제 대신 '판매' 체크를 해제하세요.</p>
<?php layout_footer();
