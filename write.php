<?php
/** 일지 작성/수정: write.php?type=daily&date=2026-09-22  또는  write.php?id=12 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
$pdo = db();

$id = (int) ($_GET['id'] ?? 0);
$journal = null;
$salesItems = [];
$facilityItems = [];

if ($id) {
    $journal = journal_find($id) ?? abort(404, '일지를 찾을 수 없습니다.');
    if ((int) $journal['author_id'] !== (int) $user['id']) abort(403, '본인이 작성한 일지만 수정할 수 있습니다.');
    if (!in_array($journal['status'], ['draft', 'rejected'], true)) abort(403, '결재중이거나 결재완료된 일지는 수정할 수 없습니다.');
    $type = $journal['type'];
    $workDate = $journal['work_date'];

    $st = $pdo->prepare('SELECT * FROM sales_items WHERE journal_id = ? ORDER BY id');
    $st->execute([$id]);
    $salesItems = $st->fetchAll();
    $st = $pdo->prepare('SELECT * FROM facility_items WHERE journal_id = ? ORDER BY id');
    $st->execute([$id]);
    $facilityItems = $st->fetchAll();
} else {
    $type = $_GET['type'] ?? 'daily';
    if (!isset(JOURNAL_TYPES[$type])) abort(404, '알 수 없는 일지 종류입니다.');
    $workDate = valid_date($_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
}

$errors = [];

if (is_post()) {
    csrf_verify();
    $workDate = post('work_date');
    $weather  = mb_substr(post('weather'), 0, 30);
    $content  = post('content');
    $remarks  = post('remarks');
    $submit   = post('action') === 'submit';

    if (!valid_date($workDate)) $errors[] = '일자를 확인하세요.';
    if ($type === 'daily' && $content === '') $errors[] = '업무내용을 입력하세요.';

    // 항목 파싱
    $salesItems = [];
    if ($type === 'sales') {
        foreach ((array) ($_POST['sales'] ?? []) as $row) {
            $item = [
                'category' => mb_substr(trim((string) ($row['category'] ?? '')), 0, 50),
                'qty'      => to_int($row['qty'] ?? 0),
                'card'     => to_int($row['card'] ?? 0),
                'cash'     => to_int($row['cash'] ?? 0),
                'transfer' => to_int($row['transfer'] ?? 0),
            ];
            if ($item['category'] !== '') $salesItems[] = $item;
        }
        // 매출보고는 하루 1건 (중복 집계 방지)
        if (valid_date($workDate)) {
            $st = $pdo->prepare("SELECT id FROM journals WHERE type = 'sales' AND work_date = ? AND id <> ?");
            $st->execute([$workDate, $id]);
            if ($dup = $st->fetchColumn()) {
                $errors[] = "해당 날짜의 매출보고가 이미 있습니다. (문서번호 $dup)";
            }
        }
    }

    $facilityItems = [];
    if ($type === 'facility') {
        $results = config('facility_results', ['정상']);
        foreach ((array) ($_POST['fac'] ?? []) as $row) {
            $item = [
                'facility' => mb_substr(trim((string) ($row['facility'] ?? '')), 0, 100),
                'result'   => in_array($row['result'] ?? '', $results, true) ? $row['result'] : $results[0],
                'note'     => mb_substr(trim((string) ($row['note'] ?? '')), 0, 500),
            ];
            if ($item['facility'] !== '') $facilityItems[] = $item;
        }
        if (!$facilityItems) $errors[] = '점검 항목을 1개 이상 입력하세요.';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare('UPDATE journals SET work_date = ?, weather = ?, content = ?, remarks = ? WHERE id = ?')
                    ->execute([$workDate, $weather ?: null, $content, $remarks, $id]);
            } else {
                $pdo->prepare("INSERT INTO journals (type, work_date, author_id, weather, content, remarks, status) VALUES (?, ?, ?, ?, ?, ?, 'draft')")
                    ->execute([$type, $workDate, $user['id'], $weather ?: null, $content, $remarks]);
                $id = (int) $pdo->lastInsertId();
            }

            $pdo->prepare('DELETE FROM sales_items WHERE journal_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO sales_items (journal_id, category, qty, card, cash, transfer) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($salesItems as $it) {
                $ins->execute([$id, $it['category'], $it['qty'], $it['card'], $it['cash'], $it['transfer']]);
            }

            $pdo->prepare('DELETE FROM facility_items WHERE journal_id = ?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO facility_items (journal_id, facility, result, note) VALUES (?, ?, ?, ?)');
            foreach ($facilityItems as $it) {
                $ins->execute([$id, $it['facility'], $it['result'], $it['note']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        if ($submit) {
            journal_submit(journal_find($id));
            flash('결재를 올렸습니다.', 'success');
        } else {
            flash('임시저장했습니다. 결재 올리기를 눌러야 결재가 진행됩니다.', 'info');
        }
        redirect('view.php?id=' . $id);
    }
}

// 신규 작성 시 기본 행 채우기
if ($type === 'sales') {
    $have = array_column($salesItems, 'category');
    foreach (config('sales_categories', []) as $cat) {
        if (!in_array($cat, $have, true)) $salesItems[] = ['category' => $cat, 'qty' => 0, 'card' => 0, 'cash' => 0, 'transfer' => 0];
    }
}
if ($type === 'facility') {
    if (!$facilityItems) {
        foreach (config('facilities', []) as $f) $facilityItems[] = ['facility' => $f, 'result' => '정상', 'note' => ''];
    }
    $facilityItems[] = ['facility' => '', 'result' => '정상', 'note' => ''];
    $facilityItems[] = ['facility' => '', 'result' => '정상', 'note' => ''];
}

$v = fn(string $k) => e(is_post() ? post($k) : ($journal[$k] ?? ''));
$line = approval_line_for((int) $user['rank_level']);

layout_header(JOURNAL_TYPES[$type] . ($journal ? ' 수정' : ' 작성'), $type);
?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <div class="card-head">
    <h1><?= e(JOURNAL_TYPES[$type]) ?> <?= $journal ? '수정' : '작성' ?></h1>
    <span class="muted small">결재선: 작성(<?= e(rank_name($user['rank_level'])) ?>)<?php foreach ($line as $r): ?> → <?= e(rank_name($r)) ?><?php endforeach ?><?= $line ? '' : ' (결재 생략)' ?></span>
  </div>
  <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach ?>

  <div class="row">
    <label>일자<input type="date" name="work_date" value="<?= e($workDate) ?>" required></label>
    <label>날씨<input name="weather" value="<?= $v('weather') ?>" placeholder="맑음 / 18℃" list="weathers"></label>
    <datalist id="weathers"><option>맑음</option><option>구름많음</option><option>흐림</option><option>비</option><option>눈</option></datalist>
  </div>

  <?php if ($type === 'daily'): ?>
    <label>업무내용<textarea name="content" rows="10" required placeholder="- 09:00 입장객 안내&#10;- 10:30 산책로 순찰"><?= $v('content') ?></textarea></label>
    <label>특이사항<textarea name="remarks" rows="4" placeholder="민원, 사고, 인수인계 사항 등"><?= $v('remarks') ?></textarea></label>

  <?php elseif ($type === 'sales'): ?>
    <div class="table-scroll">
    <table class="table sales-input" data-sales>
      <thead><tr><th>구분</th><th>건수</th><th>카드</th><th>현금</th><th>계좌이체</th><th class="right">소계</th></tr></thead>
      <tbody>
      <?php foreach ($salesItems as $i => $it): ?>
        <tr>
          <td><input type="hidden" name="sales[<?= $i ?>][category]" value="<?= e($it['category']) ?>"><?= e($it['category']) ?></td>
          <td><input name="sales[<?= $i ?>][qty]" value="<?= e($it['qty'] ?: '') ?>" inputmode="numeric" class="num" data-money></td>
          <?php foreach (['card', 'cash', 'transfer'] as $col): ?>
            <td><input name="sales[<?= $i ?>][<?= $col ?>]" value="<?= e($it[$col] ? number_format((int) $it[$col]) : '') ?>" inputmode="numeric" class="num" data-money data-amount></td>
          <?php endforeach ?>
          <td class="right" data-subtotal>0</td>
        </tr>
      <?php endforeach ?>
      </tbody>
      <tfoot><tr><th>합계</th><th data-sum="qty"></th><th data-sum="card"></th><th data-sum="cash"></th><th data-sum="transfer"></th><th class="right nowrap" data-grand>0</th></tr></tfoot>
    </table>
    </div>
    <label>메모<textarea name="content" rows="3" placeholder="환불, 할인, 정산 차이 등"><?= $v('content') ?></textarea></label>

  <?php else: ?>
    <div class="table-scroll">
    <table class="table">
      <thead><tr><th>시설</th><th>점검결과</th><th>내용 / 조치사항</th></tr></thead>
      <tbody>
      <?php foreach ($facilityItems as $i => $it): ?>
        <tr>
          <td><input name="fac[<?= $i ?>][facility]" value="<?= e($it['facility']) ?>" placeholder="시설명 추가"></td>
          <td><select name="fac[<?= $i ?>][result]">
            <?php foreach (config('facility_results', ['정상']) as $r): ?>
              <option <?= $it['result'] === $r ? 'selected' : '' ?>><?= e($r) ?></option>
            <?php endforeach ?>
          </select></td>
          <td><input name="fac[<?= $i ?>][note]" value="<?= e($it['note']) ?>"></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
    </div>
    <label>특이사항<textarea name="remarks" rows="4"><?= $v('remarks') ?></textarea></label>
  <?php endif ?>

  <div class="actions">
    <a class="btn ghost" href="<?= e(url($journal ? 'view.php?id=' . $journal['id'] : "journal.php?type=$type")) ?>">취소</a>
    <button class="btn" name="action" value="save">임시저장</button>
    <button class="btn primary" name="action" value="submit"><?= $line ? '결재 올리기' : '저장(결재완료)' ?></button>
  </div>
</form>
<?php layout_footer();
