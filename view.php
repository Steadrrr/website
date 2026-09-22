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
                if (!approvable_step($journal, $user)) abort(403, '결재 권한이 없습니다.');
                $comment = mb_substr(post('comment'), 0, 500);
                if ($action === 'reject' && $comment === '') {
                    flash('반려 사유를 입력하세요.', 'error');
                    redirect('view.php?id=' . $id);
                }
                journal_decide($journal, $user, $action === 'approve', $comment);
                flash($action === 'approve' ? '승인했습니다.' : '반려했습니다.', 'success');
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
                redirect('journal.php?type=' . $journal['type'] . '&date=' . $journal['work_date']);
        }
    } catch (RuntimeException $e) {
        flash($e->getMessage(), 'error');
        redirect('view.php?id=' . $id);
    }
}

$approvals = journal_approvals($id);
$step = approvable_step($journal, $user);

$sales = $facilities = [];
if ($journal['type'] === 'sales') {
    $st = db()->prepare('SELECT * FROM sales_items WHERE journal_id = ? ORDER BY id');
    $st->execute([$id]);
    $sales = $st->fetchAll();
} elseif ($journal['type'] === 'facility') {
    $st = db()->prepare('SELECT * FROM facility_items WHERE journal_id = ? ORDER BY id');
    $st->execute([$id]);
    $facilities = $st->fetchAll();
}

layout_header(JOURNAL_TYPES[$journal['type']], $journal['type']);
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

  <?php if ($journal['type'] === 'sales'):
      $sum = ['qty' => 0, 'card' => 0, 'cash' => 0, 'transfer' => 0]; ?>
    <div class="table-scroll">
    <table class="table">
      <thead><tr><th>구분</th><th class="right">건수</th><th class="right">카드</th><th class="right">현금</th><th class="right">계좌이체</th><th class="right">소계</th></tr></thead>
      <tbody>
      <?php foreach ($sales as $s):
          foreach ($sum as $k => $_) $sum[$k] += (int) $s[$k]; ?>
        <tr>
          <td><?= e($s['category']) ?></td>
          <td class="right"><?= number_format((int) $s['qty']) ?></td>
          <td class="right"><?= number_format((int) $s['card']) ?></td>
          <td class="right"><?= number_format((int) $s['cash']) ?></td>
          <td class="right"><?= number_format((int) $s['transfer']) ?></td>
          <td class="right"><b><?= number_format($s['card'] + $s['cash'] + $s['transfer']) ?></b></td>
        </tr>
      <?php endforeach ?>
      </tbody>
      <tfoot><tr>
        <th>합계</th>
        <th class="right"><?= number_format($sum['qty']) ?></th>
        <th class="right"><?= number_format($sum['card']) ?></th>
        <th class="right"><?= number_format($sum['cash']) ?></th>
        <th class="right"><?= number_format($sum['transfer']) ?></th>
        <th class="right"><?= e(won($sum['card'] + $sum['cash'] + $sum['transfer'])) ?></th>
      </tr></tfoot>
    </table>
    </div>
  <?php elseif ($journal['type'] === 'facility'): ?>
    <div class="table-scroll">
    <table class="table">
      <thead><tr><th>시설</th><th>점검결과</th><th>내용 / 조치사항</th></tr></thead>
      <tbody>
      <?php foreach ($facilities as $f): ?>
        <tr><td><?= e($f['facility']) ?></td><td><span class="result r-<?= e($f['result']) ?>"><?= e($f['result']) ?></span></td><td><?= e($f['note']) ?></td></tr>
      <?php endforeach ?>
      </tbody>
    </table>
    </div>
  <?php endif ?>

  <?php if ($journal['content']): ?>
    <h3><?= $journal['type'] === 'daily' ? '업무내용' : '메모' ?></h3>
    <div class="pre"><?= e($journal['content']) ?></div>
  <?php endif ?>
  <?php if ($journal['remarks']): ?>
    <h3>특이사항</h3>
    <div class="pre"><?= e($journal['remarks']) ?></div>
  <?php endif ?>

  <?php $comments = array_filter($approvals, fn($a) => $a['comment']); if ($comments): ?>
    <h3>결재 의견</h3>
    <ul class="list">
      <?php foreach ($comments as $a): ?>
        <li><span><b><?= e($a['approver_name']) ?></b> (<?= e(rank_name($a['required_rank'])) ?>, <?= $a['status'] === 'rejected' ? '반려' : '승인' ?>)</span><span><?= e($a['comment']) ?></span></li>
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
