<?php
/**
 * 설정 › 기간별 가격 (최고관리자)
 *   admin/prices.php          가격표 기간 목록 · 새 기간 만들기
 *   admin/prices.php?id=3     기간 이름·날짜와 상품별 가격 입력
 * 상품관리의 가격은 '현재 가격'이다. 지난 기간에 다른 가격을 썼으면 그 기간을 만들고 상품별 가격을 넣어 두면,
 * 매출보고 일자가 기간 안일 때 그 가격으로 계산된다 (가격표에 없는 상품은 현재 가격).
 */
require dirname(__DIR__) . '/app/bootstrap.php';

$me = require_admin();
$pdo = db();

/** 다른 기간과 날짜가 겹치는가 */
function period_overlap(string $from, string $to, int $exceptId): ?array
{
    foreach (price_periods_all() as $pp) {
        if ((int) $pp['id'] !== $exceptId && $from <= $pp['date_to'] && $to >= $pp['date_from']) return $pp;
    }
    return null;
}

/** 이 기간에 이미 작성된 매출보고 수 */
function period_sales_count(array $pp): int
{
    $st = db()->prepare("SELECT COUNT(*) FROM journals WHERE type = 'sales' AND work_date BETWEEN ? AND ?");
    $st->execute([$pp['date_from'], $pp['date_to']]);
    return (int) $st->fetchColumn();
}

/** 기간 가격을 매길 상품 (자동 상품·무료 입장권 제외) */
function priced_products(): array
{
    return array_filter(products_all(), fn($p) => empty($p['sys_key']) && !($p['grp'] === 'ticket' && $p['is_free']) && isset(PRICE_COLS[$p['grp']]));
}

/** 상품 하나를 현재 가격으로 기간 가격표에 넣는다 */
function period_item_save(int $periodId, array $p, array $vals): void
{
    $cols = array_keys(PRICE_COLS[$p['grp']]);
    $row = [];
    foreach ($cols as $c) $row[$c] = $vals[$c] ?? (int) $p[$c];
    $sql = 'INSERT INTO price_period_items (period_id, product_id, ' . implode(', ', $cols) . ') VALUES (?, ?' . str_repeat(', ?', count($cols)) . ')
            ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(fn($c) => "$c = VALUES($c)", $cols));
    db()->prepare($sql)->execute([$periodId, (int) $p['id'], ...array_values($row)]);
}

if (is_post()) {
    csrf_verify();
    $id = (int) post('id');
    $action = post('action');

    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM price_periods WHERE id = ?')->execute([$id]);
        flash('기간 가격표를 삭제했습니다. 이 기간의 매출보고는 앞으로 현재 가격으로 계산됩니다 (이미 저장된 금액은 그대로).', 'success');
        redirect('admin/prices.php');
    }

    // 기간 이름·날짜 (새로 만들기 / 수정)
    $name = mb_substr(post('name'), 0, 50);
    $from = post('date_from');
    $to = post('date_to');
    $back = $id ? "admin/prices.php?id=$id" : 'admin/prices.php';
    if ($name === '' || !valid_date($from) || !valid_date($to) || $from > $to) {
        flash('가격표 이름과 기간(시작일 ≤ 종료일)을 확인하세요.', 'error');
        redirect($back);
    }
    if ($o = period_overlap($from, $to, $id)) {
        flash("기간이 '" . price_period_label($o) . "'와 겹칩니다. 한 날짜에는 가격표가 하나만 적용됩니다.", 'error');
        redirect($back);
    }
    $note = mb_substr(post('note'), 0, 200) ?: null;
    $pdo->beginTransaction();
    if ($id) {
        $pdo->prepare('UPDATE price_periods SET name = ?, date_from = ?, date_to = ?, note = ? WHERE id = ?')->execute([$name, $from, $to, $note, $id]);
    } else {
        $pdo->prepare('INSERT INTO price_periods (name, date_from, date_to, note) VALUES (?, ?, ?, ?)')->execute([$name, $from, $to, $note]);
        $id = (int) $pdo->lastInsertId();
        if (post('copy') === '1') foreach (priced_products() as $p) period_item_save($id, $p, []);
    }

    // 상품별 가격: 칸을 비우면 현재 가격, 줄 전체를 비우면 이 상품은 가격표에서 빠짐(현재 가격)
    if (post('target') === 'items') {
        foreach (priced_products() as $p) {
            $in = (array) ($_POST['items'][(int) $p['id']] ?? []);
            $vals = [];
            foreach (array_keys(PRICE_COLS[$p['grp']]) as $c) {
                if (trim((string) ($in[$c] ?? '')) !== '') $vals[$c] = to_int($in[$c]);
            }
            if ($vals) period_item_save($id, $p, $vals);
            else $pdo->prepare('DELETE FROM price_period_items WHERE period_id = ? AND product_id = ?')->execute([$id, (int) $p['id']]);
        }
    }
    $pdo->commit();
    flash("기간 가격표 '{$name}'을(를) 저장했습니다." . (post('target') === 'items' ? '' : ' 아래 표에서 상품별 가격을 확인·수정하세요.'), 'success');
    redirect("admin/prices.php?id=$id");
}

$period = null;
if (isset($_GET['id'])) {
    $period = price_periods_all()[(int) $_GET['id']] ?? null;
    if (!$period) abort(404, '가격표를 찾을 수 없습니다.');
}

layout_header('기간별 가격', 'settings');
settings_nav('prices');

if (!$period):
    $periods = price_periods_all();
    $year = date('Y');
    ?>
<section class="card">
  <h1>기간별 가격</h1>
  <p class="muted small">
    · <b>상품·요금</b>에 있는 가격은 <b>현재 가격</b>입니다. 지난 기간에 다른 가격을 썼다면 여기서 그 기간의 가격표를 만드세요.<br>
    · 매출보고 <b>일자가 가격표 기간 안</b>이면 그 가격으로 단가·금액·상품권 환급 기준액이 계산됩니다. 가격표에 없는 상품과 기간 밖의 날짜는 현재 가격입니다.<br>
    · 예) 현재 가격이 2026-10-14부터라면 '2026년 이전 요금' 2026-01-01 ~ 2026-10-13 가격표를 만들고, 달라진 상품 가격만 고치면 됩니다.<br>
    · 입장권의 기간요금(동절기 등)은 그대로 적용되고, 객실 할인율·대관 할인율은 기간과 관계없이 같습니다.
  </p>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>가격표</th><th>기간</th><th class="right">상품 수</th><th class="right">이 기간 매출보고</th><th>메모</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($periods as $pp): ?>
      <tr>
        <td><a href="<?= e(url('admin/prices.php?id=' . $pp['id'])) ?>"><b><?= e($pp['name']) ?></b></a></td>
        <td class="nowrap"><?= e($pp['date_from']) ?> ~ <?= e($pp['date_to']) ?></td>
        <td class="right"><?= count(price_period_items()[(int) $pp['id']] ?? []) ?></td>
        <td class="right"><?= number_format(period_sales_count($pp)) ?>건</td>
        <td class="small"><?= e($pp['note'] ?? '') ?></td>
        <td class="right"><a class="btn small" href="<?= e(url('admin/prices.php?id=' . $pp['id'])) ?>">가격 입력·수정</a></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$periods): ?><tr><td colspan="6" class="center muted">기간별 가격표가 없습니다. 모든 날짜에 현재 가격이 적용됩니다.</td></tr><?php endif ?>
    </tbody>
  </table>
  </div>
</section>

<section class="card">
  <h2>새 기간 가격표</h2>
  <form method="post" class="row price-period-form">
    <?= csrf_field() ?>
    <label>이름<input name="name" required maxlength="50" placeholder="예: 2026년 이전 요금" value="<?= e($year) ?>년 이전 요금"></label>
    <label>시작일<input type="date" name="date_from" required value="<?= e("$year-01-01") ?>"></label>
    <label>종료일<input type="date" name="date_to" required></label>
    <label>메모<input name="note" maxlength="200" placeholder="(선택)"></label>
    <label class="inline-check"><input type="checkbox" name="copy" value="1" checked> 현재 가격으로 채워 두기 (달라진 것만 고치면 됨)</label>
    <button class="btn primary">만들기</button>
  </form>
</section>
<?php
    layout_footer();
    exit;
endif;

$items = price_period_items()[(int) $period['id']] ?? [];
$byGroup = [];
foreach (priced_products() as $p) $byGroup[$p['grp']][] = $p;
$salesN = period_sales_count($period);
?>
<section class="card">
  <div class="card-head">
    <h1>기간별 가격 <small class="muted"><?= e(price_period_label($period)) ?></small></h1>
    <div class="actions no-margin"><a class="btn ghost" href="<?= e(url('admin/prices.php')) ?>">‹ 목록</a></div>
  </div>
  <form method="post" id="pf" class="row price-period-form">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $period['id'] ?>">
    <input type="hidden" name="target" value="items">
    <label>이름<input name="name" required maxlength="50" value="<?= e($period['name']) ?>"></label>
    <label>시작일<input type="date" name="date_from" required value="<?= e($period['date_from']) ?>"></label>
    <label>종료일<input type="date" name="date_to" required value="<?= e($period['date_to']) ?>"></label>
    <label>메모<input name="note" maxlength="200" value="<?= e($period['note'] ?? '') ?>"></label>
  </form>
  <p class="muted small">
    · 칸 아래 작은 글씨는 <b>현재 가격</b>입니다. 현재 가격과 다른 칸은 <span class="pp-diff-sample">노란색</span>으로 표시됩니다.<br>
    · 칸을 비우고 저장하면 그 칸은 현재 가격으로 채워집니다. 한 상품의 칸을 <b>모두 비우면</b> 그 상품은 이 기간에도 현재 가격을 씁니다.<br>
    · 이 기간에 이미 작성된 매출보고는 <b><?= number_format($salesN) ?>건</b>입니다. 저장된 보고서의 금액은 자동으로 바뀌지 않으며, 그 보고서를 수정해서 저장하면 이 가격으로 다시 계산됩니다.
  </p>
</section>

<?php foreach (PRODUCT_GROUPS as $grp => $glabel): if (empty($byGroup[$grp])) continue; $cols = PRICE_COLS[$grp]; ?>
<section class="card">
  <h2><?= e($glabel) ?></h2>
  <div class="table-scroll">
  <table class="table product-table pp-table">
    <thead><tr><th>상품</th><?php foreach ($cols as $c => $cl): ?><th class="right"><?= e($cl) ?></th><?php endforeach ?></tr></thead>
    <tbody>
    <?php foreach ($byGroup[$grp] as $p): $it = $items[(int) $p['id']] ?? null; ?>
      <tr class="<?= $p['is_active'] ? '' : 'inactive' ?>">
        <td><b><?= e($p['name']) ?></b><?= $p['is_active'] ? '' : ' <small class="muted">판매중지</small>' ?><?= $it ? '' : '<br><small class="muted">현재 가격 사용</small>' ?></td>
        <?php foreach ($cols as $c => $cl): $cur = (int) $p[$c]; $val = $it ? (int) $it[$c] : null; ?>
          <td class="right <?= $val !== null && $val !== $cur ? 'pp-diff' : '' ?>">
            <input form="pf" name="items[<?= (int) $p['id'] ?>][<?= $c ?>]" value="<?= $val === null ? '' : e(number_format($val)) ?>" class="num" inputmode="numeric" data-cur="<?= $cur ?>">
            <small class="muted">현재 <?= number_format($cur) ?></small>
          </td>
        <?php endforeach ?>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
</section>
<?php endforeach ?>

<div class="actions">
  <button class="btn primary" form="pf">가격표 저장</button>
  <form method="post" class="inline" onsubmit="return confirm('이 기간 가격표를 삭제할까요? 이 기간의 매출보고는 앞으로 현재 가격으로 계산됩니다.')">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $period['id'] ?>"><input type="hidden" name="action" value="delete">
    <button class="btn danger">가격표 삭제</button>
  </form>
</div>
<script>
// 현재 가격과 다른 칸 표시
document.querySelectorAll('.pp-table input[data-cur]').forEach((i) => i.addEventListener('input', () => {
  const v = i.value.replace(/\D/g, '');
  i.closest('td').classList.toggle('pp-diff', v !== '' && parseInt(v, 10) !== parseInt(i.dataset.cur, 10));
}));
</script>
<?php layout_footer();
