<?php
/** 일지 작성/수정: write.php?type=daily&date=2026-09-22  또는  write.php?id=12 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
$pdo = db();

$id = (int) ($_GET['id'] ?? 0);
$journal = null;

if ($id) {
    $journal = journal_find($id) ?? abort(404, '일지를 찾을 수 없습니다.');
    if ((int) $journal['author_id'] !== (int) $user['id']) abort(403, '본인이 작성한 일지만 수정할 수 있습니다.');
    if (!in_array($journal['status'], ['draft', 'rejected'], true)) abort(403, '결재중이거나 결재완료된 일지는 수정할 수 없습니다.');
    $type = $journal['type'];
    $workDate = $journal['work_date'];
    $payload = items_load($journal);
} else {
    $type = $_GET['type'] ?? 'daily';
    if (!isset(JOURNAL_TYPES[$type])) abort(404, '알 수 없는 일지 종류입니다.');
    $workDate = valid_date($_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
    $payload = items_default($type);
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

    [$payload, $itemErrors] = items_parse($type, valid_date($workDate) ? $workDate : date('Y-m-d'), $id);
    $errors = [...$errors, ...$itemErrors];

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
            items_save($id, $type, $payload);
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

$v = fn(string $k) => e(is_post() ? post($k) : ($journal[$k] ?? ''));
$line = approval_line_for((int) $user['rank_level']);

layout_header(JOURNAL_TYPES[$type] . ($journal ? ' 수정' : ' 작성'), $type === 'voucher' ? 'voucher' : $type);
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
    <?php if ($type !== 'voucher'): ?>
    <label>날씨<input name="weather" value="<?= $v('weather') ?>" placeholder="맑음 / 18℃" list="weathers"></label>
    <datalist id="weathers"><option>맑음</option><option>구름많음</option><option>흐림</option><option>비</option><option>눈</option></datalist>
    <?php endif ?>
  </div>

  <?php if ($type === 'daily'): ?>
    <label>업무내용<textarea name="content" rows="10" required placeholder="- 09:00 입장객 안내&#10;- 10:30 산책로 순찰"><?= $v('content') ?></textarea></label>
    <label>특이사항<textarea name="remarks" rows="4" placeholder="민원, 사고, 인수인계 사항 등"><?= $v('remarks') ?></textarea></label>
  <?php else: ?>
    <?php items_form($type, $payload, $workDate, $journal) ?>
    <?php if ($type === 'sales'): ?>
      <label>메모<textarea name="content" rows="3" placeholder="환불, 정산 차이, 상품권 환급 객실 등"><?= $v('content') ?></textarea></label>
    <?php elseif ($type === 'voucher'): ?>
      <label>적요<textarea name="content" rows="3" placeholder="구입처, 구입일, 기초재고 등록 등"><?= $v('content') ?></textarea></label>
    <?php else: ?>
      <label>특이사항<textarea name="remarks" rows="4"><?= $v('remarks') ?></textarea></label>
    <?php endif ?>
  <?php endif ?>

  <div class="actions">
    <a class="btn ghost" href="<?= e(url($journal ? 'view.php?id=' . $journal['id'] : ($type === 'voucher' ? 'voucher.php' : "journal.php?type=$type"))) ?>">취소</a>
    <button class="btn" name="action" value="save">임시저장</button>
    <button class="btn primary" name="action" value="submit"><?= $line ? '결재 올리기' : '저장(결재완료)' ?></button>
  </div>
</form>
<?php layout_footer();
