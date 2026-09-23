<?php
/**
 * 일지 작성/수정: write.php?type=daily&date=2026-09-22  또는  write.php?id=12
 * 상신된 일지는 모든 직원이 수정할 수 있고, 수정하면 이력이 남고 결재가 처음부터 다시 진행된다.
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
$pdo = db();

$id = (int) ($_GET['id'] ?? 0);
$journal = null;

if ($id) {
    $journal = journal_find($id) ?? abort(404, '일지를 찾을 수 없습니다.');
    if (!can_edit_journal($journal, $user)) abort(403, '임시저장 문서는 작성자만 수정할 수 있습니다.');
    $type = $journal['type'];
    $workDate = $journal['work_date'];
    $teamId = $journal['team_id'] ? (int) $journal['team_id'] : null;
    $payload = items_load($journal);
} else {
    $type = $_GET['type'] ?? 'daily';
    if ($type === 'attendance') redirect('attendance.php');
    if (!isset(JOURNAL_TYPES[$type])) abort(404, '알 수 없는 일지 종류입니다.');
    $workDate = valid_date($_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
    // 시설점검은 관리팀별로 작성
    $teamId = null;
    if ($type === 'facility') {
        $teamId = (int) ($_GET['team'] ?? 0);
        if (!isset(teams_all()[$teamId])) $teamId = (int) array_key_first(teams_all());
    }
    $payload = items_default($type, $teamId ?: null);
}

$revision = $journal && is_revision_edit($journal); // 상신된 적 있는 일지 수정 → 이력 + 결재 초기화
$errors = [];

if (is_post()) {
    csrf_verify();
    $workDate = post('work_date');
    $weather  = mb_substr(post('weather'), 0, 30);
    $content  = post('content');
    $remarks  = post('remarks');
    $submit   = $revision || post('action') === 'submit';
    $reason   = mb_substr(post('edit_reason'), 0, 500);
    if ($revision) {
        $before = journal_snapshot($journal);
        $prevApproval = approval_summary($journal);
    }

    if (!valid_date($workDate)) $errors[] = '일자를 확인하세요.';
    if ($type === 'daily' && $content === '') $errors[] = '업무내용을 입력하세요.';

    [$payload, $itemErrors] = items_parse($type, valid_date($workDate) ? $workDate : date('Y-m-d'), $id);
    $errors = [...$errors, ...$itemErrors];

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $teamId = $payload['team_id'] ?? null;
            if ($id) {
                $pdo->prepare('UPDATE journals SET work_date = ?, team_id = ?, weather = ?, content = ?, remarks = ? WHERE id = ?')
                    ->execute([$workDate, $teamId, $weather ?: null, $content, $remarks, $id]);
            } else {
                $pdo->prepare("INSERT INTO journals (type, team_id, work_date, author_id, weather, content, remarks, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft')")
                    ->execute([$type, $teamId, $workDate, $user['id'], $weather ?: null, $content, $remarks]);
                $id = (int) $pdo->lastInsertId();
            }
            items_save($id, $type, $payload);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        foreach ($payload['warnings'] ?? [] as $w) flash('확인 필요 · ' . $w, 'error');
        if ($revision) {
            $fresh = journal_find($id);
            $changes = snapshot_diff($before, journal_snapshot($fresh));
            if (!$changes && $reason === '') {
                flash('바뀐 내용이 없어 결재 상태를 그대로 두었습니다.', 'info');
                redirect('view.php?id=' . $id);
            }
            revision_record($journal, $user, $changes, $prevApproval, $reason);
            journal_submit($fresh, (int) $user['rank_level']); // 결재선은 수정한 사람 기준으로 처음부터
            flash('수정했습니다. 결재 상태가 초기화되어 처음부터 다시 결재가 진행됩니다.', 'success');
        } elseif ($submit) {
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
<form method="post" class="card" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="card-head">
    <h1><?= e(JOURNAL_TYPES[$type]) ?> <?= $journal ? '수정' : '작성' ?></h1>
    <span class="muted small">결재선: 작성(<?= e(rank_name($user['rank_level'])) ?>)<?php foreach ($line as $r): ?> → <?= e(rank_name($r)) ?><?php endforeach ?><?= $line ? '' : ' (결재 생략)' ?></span>
  </div>
  <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach ?>

  <?php if ($revision): ?>
    <div class="flash flash-warn">
      <b>수정 시 결재 상태가 초기화됩니다.</b> 저장하면 지금까지의 결재(<?= e(approval_summary($journal)) ?>)가 취소되고
      수정한 사람(<?= e($user['name']) ?> <?= e(rank_name($user['rank_level'])) ?>) 기준으로 처음부터 다시 결재가 올라갑니다.
      수정 전·후 내용은 <b>수정 이력</b>에 남습니다.
      <?php if ((int) $journal['author_id'] !== (int) $user['id']): ?><br>작성자: <?= e($journal['author_name']) ?><?php endif ?>
    </div>
  <?php endif ?>

  <div class="row">
    <?php if ($type === 'facility'): ?>
      <label>관리팀
        <?php if ($journal): ?>
          <input type="hidden" name="team_id" value="<?= (int) $teamId ?>"><input value="<?= e(team_name($teamId)) ?>" disabled>
        <?php else: ?>
          <select name="team_id" onchange="location.href='?type=facility&date=' + this.form.work_date.value + '&team=' + this.value">
            <?php foreach (teams_all() as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $t['id'] === (int) ($payload['team_id'] ?? 0) ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach ?>
          </select>
        <?php endif ?>
      </label>
    <?php endif ?>
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
    <?php if ($revision): ?>
      <input name="edit_reason" value="<?= e(post('edit_reason')) ?>" placeholder="수정 사유 (선택)" class="reason-input" maxlength="500">
      <button class="btn primary" name="action" value="submit" onclick="return confirm('결재 상태가 초기화되고 처음부터 다시 결재를 받습니다. 저장할까요?')">
        수정 저장 · <?= $line ? '결재 다시 올리기' : '결재완료' ?></button>
    <?php else: ?>
      <button class="btn" name="action" value="save">임시저장</button>
      <button class="btn primary" name="action" value="submit"><?= $line ? '결재 올리기' : '저장(결재완료)' ?></button>
    <?php endif ?>
  </div>
</form>
<?php layout_footer();
