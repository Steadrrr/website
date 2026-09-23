<?php
/** 일지 상세 + 결재 처리 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
$id = (int) ($_GET['id'] ?? 0);
$journal = journal_find($id) ?? abort(404, '일지를 찾을 수 없습니다.');

$isAuthor = (int) $journal['author_id'] === (int) $user['id'];
if ($journal['status'] === 'draft' && !$isAuthor) abort(403, '임시저장 문서는 작성자만 볼 수 있습니다.');
$editable = $isAuthor && in_array($journal['status'], ['draft', 'rejected'], true);
// 근태: 수정·재상신 없이 취소(삭제) 후 다시 입력
$att = $journal['type'] === 'attendance' ? att_find($id) : null;
if ($att) {
    $editable = false;
    $attWorker = att_user((int) $att['user_id']);
    $canCancel = att_can_cancel($journal, $att, $user);
    $canSeeFile = $attWorker && att_can_view($user, $attWorker);
    $canAttach = $canSeeFile && ($isAuthor || (int) $att['user_id'] === (int) $user['id'] || att_is_manager($user));
}
$listUrl = $att ? 'attendance.php?ym=' . substr($att['start_date'], 0, 7)
    : 'journal.php?type=' . $journal['type'] . '&date=' . $journal['work_date'];

if (is_post()) {
    csrf_verify();
    $action = post('action');
    try {
        switch ($action) {
            case 'approve':
            case 'reject':
            case 'delegate':
                if (!approvable_step($journal, $user)) abort(403, '결재 권한이 없습니다.');
                $comment = mb_substr(post('comment'), 0, 500);
                if ($action === 'reject' && $comment === '') {
                    flash('반려 사유를 입력하세요.', 'error');
                    redirect('view.php?id=' . $id);
                }
                journal_decide($journal, $user, $action, $comment);
                flash(['approve' => '승인했습니다.', 'reject' => '반려했습니다.', 'delegate' => '전결 처리했습니다. (결재완료)'][$action], 'success');
                redirect('approvals.php');
            case 'submit':
                if (!$editable) abort(403, '결재를 올릴 수 없는 상태입니다.');
                journal_submit($journal);
                flash('결재를 올렸습니다.', 'success');
                redirect('view.php?id=' . $id);
            case 'attach':
                if (!$att || !$canAttach) abort(403, '첨부 권한이 없습니다.');
                [$file, $upErr] = att_store_attachment($_FILES['attachment'] ?? []);
                if ($upErr || !$file) {
                    flash($upErr ?: '파일을 고르세요.', 'error');
                } else {
                    if ($att['attachment'] && is_file(APP_ROOT . '/' . $att['attachment'])) @unlink(APP_ROOT . '/' . $att['attachment']);
                    db()->prepare('UPDATE attendance SET attachment = ? WHERE journal_id = ?')->execute([$file, $id]);
                    flash('첨부했습니다.', 'success');
                }
                redirect('view.php?id=' . $id);
            case 'delete':
                if ($att ? !$canCancel : (!$editable && !$user['is_admin'])) abort(403, '삭제 권한이 없습니다.');
                db()->prepare('DELETE FROM journals WHERE id = ?')->execute([$id]);
                if ($att && $att['attachment'] && is_file(APP_ROOT . '/' . $att['attachment'])) @unlink(APP_ROOT . '/' . $att['attachment']);
                flash($att ? '근태를 취소(삭제)했습니다.' : '삭제했습니다.', 'success');
                redirect($journal['type'] === 'voucher' ? 'voucher.php' : $listUrl);
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        redirect('view.php?id=' . $id);
    }
}

$approvals = journal_approvals($id);
$step = approvable_step($journal, $user);

$delegatable = can_delegate($user, $step);
$revisions = journal_revisions($id);
$lastEditor = $revisions[0]['user_name'] ?? '';

layout_header(JOURNAL_TYPES[$journal['type']], $journal['type'] === 'voucher' ? 'voucher' : $journal['type']);
$docTitle = $att ? '근태 신청 · ' . $att['user_name'] . ' ' . att_kind_name($att['kind']) : JOURNAL_TYPES[$journal['type']];
?>
<article class="card doc">
  <div class="doc-head">
    <div>
      <h1><?= e($docTitle) ?></h1>
      <p class="muted">
        <?= e($journal['work_date']) ?> (<?= weekday_ko($journal['work_date']) ?>)
        <?php if ($journal['weather']): ?> · <?= e($journal['weather']) ?><?php endif ?>
        · 문서번호 <?= (int) $journal['id'] ?> · <?= journal_badges($journal) ?>
      </p>
      <?php if ($journal['revision']): ?>
        <p class="muted small">마지막 수정: <?= e($lastEditor) ?> · <?= e(date('Y-m-d H:i', strtotime($journal['last_edited_at']))) ?>
          (상신 후 <?= (int) $journal['revision'] ?>회 수정 · <a href="#revisions">수정 이력</a>)</p>
      <?php endif ?>
    </div>
    <?php render_approval_box($journal, $approvals) ?>
  </div>

  <?php if ($att): [$kLabel, $kColor] = ATT_KINDS[$att['kind']]; ?>
    <table class="table att-box" style="--c: <?= $kColor ?>">
      <tr><th>근무자</th><td><?= e($att['user_name']) ?> <small class="muted"><?= e($attWorker ? member_affiliation($attWorker) : '') ?></small></td></tr>
      <tr><th>구분</th><td><span class="badge ev-badge"><?= e($kLabel) ?></span></td></tr>
      <tr><th>기간</th><td><?= e(att_when($att)) ?>
        <?php if (!att_is_time_kind($att['kind'])): ?><small class="muted">(휴무일·공휴일 제외 근무일 기준)</small><?php endif ?>
        <?php if (in_array($att['kind'], ['early', 'out'], true)): ?><small class="muted">(연차에서 차감)</small><?php endif ?></td></tr>
      <tr><th>입력</th><td><?= e($journal['author_name']) ?> <small class="muted"><?= e(rank_name($journal['author_rank'])) ?> · <?= e(date('Y-m-d H:i', strtotime($journal['created_at']))) ?></small></td></tr>
      <?php if ($canSeeFile): ?>
      <tr><th>첨부</th><td>
        <?php if ($att['attachment']): ?><a href="<?= e(url('attendance.php?file=' . $id)) ?>" target="_blank">📎 첨부 파일 보기</a><?php else: ?><span class="muted">없음</span><?php endif ?>
      </td></tr>
      <?php endif ?>
    </table>
    <?php if ($att['cert_required'] && !$att['attachment']): ?>
      <p class="cert-alert">⚠ 연속 3일 이상 또는 연 6일을 넘는 병가입니다. 의사의 진단서를 첨부해야 합니다.</p>
    <?php endif ?>
    <?php if ($canAttach && ($att['cert_required'] || in_array($att['kind'], ['sick', 'official'], true))): ?>
      <form method="post" enctype="multipart/form-data" class="inline-form no-print">
        <?= csrf_field() ?>
        <label><?= $att['attachment'] ? '첨부 바꾸기' : '진단서 등 첨부' ?> <small class="muted">(사진 또는 PDF)</small>
          <input type="file" name="attachment" accept="image/*,application/pdf" required></label>
        <button class="btn small" name="action" value="attach">올리기</button>
      </form>
    <?php endif ?>
  <?php else: items_view($journal); endif ?>

  <?php if ($journal['content']): ?>
    <h3><?= ['daily' => '업무내용', 'voucher' => '적요', 'attendance' => '사유'][$journal['type']] ?? '메모' ?></h3>
    <div class="pre"><?= e($journal['content']) ?></div>
  <?php endif ?>
  <?php if ($journal['remarks']): ?>
    <h3>특이사항</h3>
    <div class="pre"><?= e($journal['remarks']) ?></div>
  <?php endif ?>

  <?php
  $delegated = in_array('skipped', array_column($approvals, 'status'), true);
  $comments = array_filter($approvals, fn($a) => $a['comment'] && $a['status'] !== 'skipped'); if ($comments): ?>
    <h3>결재 의견</h3>
    <ul class="list">
      <?php foreach ($comments as $a): ?>
        <li><span><b><?= e($a['approver_name']) ?></b> (<?= e(rank_name($a['required_rank'])) ?>, <?= $a['status'] === 'rejected' ? '반려' : ($delegated ? '전결' : '승인') ?>)</span><span><?= e($a['comment']) ?></span></li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>
</article>

<?php if ($step): ?>
<form method="post" class="card no-print">
  <?= csrf_field() ?>
  <h2><?= e(rank_name($step['required_rank'])) ?> 결재</h2>
  <label>의견 (반려 시 필수)<textarea name="comment" rows="2"></textarea></label>
  <div class="actions">
    <button class="btn danger" name="action" value="reject">반려</button>
    <?php if ($delegatable): ?>
      <button class="btn" name="action" value="delegate" onclick="return confirm('팀장 결재 없이 전결로 최종 완료합니다. 진행할까요?')">전결</button>
    <?php endif ?>
    <button class="btn primary" name="action" value="approve">승인</button>
  </div>
</form>
<?php endif ?>

<div id="revisions"><?php render_revisions($revisions) ?></div>

<div class="actions no-print">
  <a class="btn ghost" href="<?= e(url($listUrl)) ?>">목록</a>
  <button class="btn ghost" onclick="window.print()">인쇄</button>
  <?php if (can_edit_journal($journal, $user)): ?>
    <?= edit_button($journal) ?>
  <?php endif ?>
  <?php if ($att ? $canCancel : ($editable || $user['is_admin'])): ?>
    <form method="post" onsubmit="return confirm('<?= $att ? '이 근태를 취소(삭제)할까요?' : '삭제하시겠습니까?' ?>')">
      <?= csrf_field() ?><button class="btn danger" name="action" value="delete"><?= $att ? '근태 취소' : '삭제' ?></button>
    </form>
  <?php endif ?>
  <?php if ($editable): ?>
    <form method="post">
      <?= csrf_field() ?><button class="btn primary" name="action" value="submit"><?= $journal['status'] === 'rejected' ? '재상신' : '결재 올리기' ?></button>
    </form>
  <?php endif ?>
</div>
<?php layout_footer();
