<?php
/** 상품관리: 입장권·객실 상품명, 가격, 할인가, 최대인원 (최고관리자·팀장) */
require dirname(__DIR__) . '/app/bootstrap.php';

$me = require_manager();
$pdo = db();

if (is_post()) {
    csrf_verify();
    $id = (int) post('id');
    $grp = post('grp');
    if (!isset(PRODUCT_GROUPS[$grp])) abort(400, '잘못된 값입니다.');

    if (post('action') === 'delete') {
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
        'dc_weekday'    => $grp === 'room' ? to_int(post('dc_weekday')) : 0,
        'dc_weekend'    => $grp === 'room' ? to_int(post('dc_weekend')) : 0,
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
        if ($id) {
            $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
            $pdo->prepare("UPDATE products SET $set WHERE id = ? AND grp = ?")->execute([...array_values($data), $id, $grp]);
        } else {
            $pdo->prepare('INSERT INTO products (grp, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')')
                ->execute([$grp, ...array_values($data)]);
        }
        flash("{$name} 저장했습니다. (이미 작성된 매출보고의 금액은 바뀌지 않습니다)", 'success');
    }
    redirect('admin/products.php#' . $grp);
}

$byGroup = ['ticket' => [], 'room' => []];
foreach (products_all() as $p) $byGroup[$p['grp']][] = $p;
$nextSort = fn(array $rows) => $rows ? max(array_column($rows, 'sort_order')) + 10 : 10;

/** 행 하나 (기존 상품 수정 또는 신규 추가) */
function product_row(string $grp, ?array $p, int $sort): void
{
    $fid = 'p' . ($p['id'] ?? 'new-' . $grp);
    $val = fn(string $k, mixed $d = '') => e($p[$k] ?? $d);
    $money = fn(string $k) => e(isset($p[$k]) && $p[$k] ? number_format((int) $p[$k]) : '');
    ?>
  <tr class="<?= $p ? ($p['is_active'] ? '' : 'inactive') : 'new-row' ?>">
    <td><input form="<?= $fid ?>" name="sort_order" value="<?= $val('sort_order', $sort) ?>" class="num tiny" inputmode="numeric"></td>
    <td><input form="<?= $fid ?>" name="name" value="<?= $val('name') ?>" placeholder="<?= $p ? '' : ($grp === 'ticket' ? '새 입장권 (예: 어른)' : '새 객실 (예: 숲속의집 101호)') ?>" required></td>
    <?php if ($grp === 'ticket'): ?>
      <td><select form="<?= $fid ?>" name="is_free" data-free-select>
        <option value="0">유료</option><option value="1" <?= !empty($p['is_free']) ? 'selected' : '' ?>>무료</option>
      </select></td>
      <td><input form="<?= $fid ?>" name="price" value="<?= $money('price') ?>" class="num" inputmode="numeric" data-money placeholder="0"></td>
    <?php else: ?>
      <td><input form="<?= $fid ?>" name="max_people" value="<?= $val('max_people') ?>" class="num tiny" inputmode="numeric" required></td>
      <td><input form="<?= $fid ?>" name="price" value="<?= $money('price') ?>" class="num" inputmode="numeric" data-money placeholder="0"></td>
      <td><input form="<?= $fid ?>" name="price_weekend" value="<?= $money('price_weekend') ?>" class="num" inputmode="numeric" data-money placeholder="0"></td>
      <td><input form="<?= $fid ?>" name="dc_weekday" value="<?= $money('dc_weekday') ?>" class="num" inputmode="numeric" data-money placeholder="없음"></td>
      <td><input form="<?= $fid ?>" name="dc_weekend" value="<?= $money('dc_weekend') ?>" class="num" inputmode="numeric" data-money placeholder="없음"></td>
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

layout_header('상품관리', 'products');
?>
<section class="card" id="ticket">
  <h1>상품관리 · 입장권</h1>
  <p class="muted small">매출보고 작성 화면에 이 순서대로 표시되고, 단가가 자동으로 들어갑니다. 무료 상품은 대시보드에서 '무료'로 집계됩니다.</p>
  <div class="table-scroll">
  <table class="table product-table">
    <thead><tr><th>순서</th><th>상품명</th><th>유료/무료</th><th>가격(원)</th><th>판매</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($byGroup['ticket'] as $p) product_row('ticket', $p, 0) ?>
      <?php product_row('ticket', null, $nextSort($byGroup['ticket'])) ?>
    </tbody>
  </table>
  </div>
</section>

<section class="card" id="room">
  <h1>상품관리 · 객실</h1>
  <p class="muted small">
    평일/주말·성수기 요금과 할인 요금을 입력하세요. 할인 요금을 비워두면 해당 요금구분에는 할인 체크를 할 수 없습니다.
    매출보고에서 금·토요일과 성수기(<?= e(implode(', ', array_map(fn($s) => str_replace('-', '/', $s[0]) . '~' . str_replace('-', '/', $s[1]), config('peak_seasons', [['07-15', '08-24']])))) ?>)는 주말·성수기 요금이 자동 선택됩니다.
  </p>
  <div class="table-scroll">
  <table class="table product-table">
    <thead>
      <tr><th rowspan="2">순서</th><th rowspan="2">객실명</th><th rowspan="2">최대인원</th><th colspan="2" class="center">정상 요금(원)</th><th colspan="2" class="center">할인 요금(원)</th><th rowspan="2">판매</th><th rowspan="2"></th></tr>
      <tr><th>평일</th><th>주말·성수기</th><th>평일</th><th>주말·성수기</th></tr>
    </thead>
    <tbody>
      <?php foreach ($byGroup['room'] as $p) product_row('room', $p, 0) ?>
      <?php product_row('room', null, $nextSort($byGroup['room'])) ?>
    </tbody>
  </table>
  </div>
</section>
<p class="muted small">· 상품 가격을 바꿔도 이미 작성된 매출보고의 금액은 바뀌지 않습니다(작성 당시 가격으로 저장).<br>
· 매출보고에 한 번이라도 쓰인 상품은 삭제 대신 '판매' 체크를 해제하세요.</p>
<?php layout_footer();
