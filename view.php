<?php
/** 일지 상세 + 결재 처리 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
$id = (int) ($_GET['id'] ?? 0);
$journal = journal_find($id) ?? abort(404, '일지를 찾을 수 없습니다.');

$isAuthor = (int) $journal['author_id'] === (int) $user['id'];
if ($journal['status'] === 'draft' && !$isAuthor) abort(403, '임시저장 문서는 작성자만 볼 수 있습니다.');
$editable = $isAuthor && in_array($journal['status'], ['draft', 'rejected'], true);

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
            case 'delete':
                if (!$editable && !$user['is_admin']) abort(403, '삭제 권한이 없습니다.');
                db()->prepare('DELETE FROM journals WHERE id = ?')->execute([$id]);
                flash('삭제했습니다.', 'success');
                redirect($journal['type'] === 'voucher' ? 'voucher.php' : 'journal.php?type=' . $journal['type'] . '&date=' . $journal['work_date']);
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        redirect('view.php?id=' . $id);
    }
}

$approvals = journal_approvals($id);
$step = approvable_step($journal, $user);

$delegatable = can_delegate($user, $step);

layout_header(JOURNAL_TYPES[$journal['type']], $journal['type'] === 'voucher' ? 'voucher' : $journal['type']);
?>
<article class="card doc">
  <div class="doc-head">
    <div>
      <h1><?= e(JOURNAL_TYPES[$journal['type']]) ?></h1>
      <p class="muted">
        <?= e($journal['work_date']) ?> (<?= weekday_ko($journal['work_date']) ?>)
        <?php if ($journal['weather']): ?> · <?= e($journal['weather']) ?><?php endif ?>
        · 문서번호 <?= (int) $journal['id'] ?> · <?= status_badge($journal['status']) ?>
      </p>
    </div>
    <?php render_approval_box($journal, $approvals) ?>
  </div>

  <?php items_view($journal) ?>

  <?php if ($journal['content']): ?>
    <h3><?= ['daily' => '업무내용', 'voucher' => '적요'][$journal['type']] ?? '메모' ?></h3>
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

<div class="actions no-print">
  <a class="btn ghost" href="<?= e(url('journal.php?type=' . $journal['type'] . '&date=' . $journal['work_date'])) ?>">목록</a>
  <button class="btn ghost" onclick="window.print()">인쇄</button>
  <?php if ($editable): ?>
    <a class="btn" href="<?= e(url('write.php?id=' . $id)) ?>">수정</a>
  <?php endif ?>
  <?php if ($editable || $user['is_admin']): ?>
    <form method="post" onsubmit="return confirm('삭제하시겠습니까?')">
      <?= csrf_field() ?><button class="btn danger" name="action" value="delete">삭제</button>
    </form>
  <?php endif ?>
  <?php if ($editable): ?>
    <form method="post">
      <?= csrf_field() ?><button class="btn primary" name="action" value="submit"><?= $journal['status'] === 'rejected' ? '재상신' : '결재 올리기' ?></button>
    </form>
  <?php endif ?>
</div>
<?php layout_footer();
