<?php
/** 공지 쓰기/수정/삭제 (최고관리자·팀장·주무관): notice_edit.php  또는  ?id=3 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
if (!can_write_notice($user)) abort(403, '공지는 주무관 이상만 쓸 수 있습니다.');
$pdo = db();

$id = (int) ($_GET['id'] ?? 0);
$n = null;
if ($id) {
    $st = $pdo->prepare('SELECT * FROM notices WHERE id = ?');
    $st->execute([$id]);
    $n = $st->fetch() ?: abort(404, '공지를 찾을 수 없습니다.');
}

if (is_post()) {
    csrf_verify();
    if (post('action') === 'delete' && $n) {
        $pdo->prepare('DELETE FROM notices WHERE id = ?')->execute([$id]);
        flash('공지를 삭제했습니다.', 'success');
        redirect('notices.php');
    }
    $title = mb_substr(post('title'), 0, 200);
    $body = post('body');
    $pinned = post('is_pinned') === '1' ? 1 : 0;
    if ($title === '') {
        flash('제목을 입력하세요.', 'error');
        redirect($id ? "notice_edit.php?id=$id" : 'notice_edit.php');
    }
    if ($n) {
        $pdo->prepare('UPDATE notices SET title = ?, body = ?, is_pinned = ?, updated_at = NOW() WHERE id = ?')->execute([$title, $body, $pinned, $id]);
    } else {
        $pdo->prepare('INSERT INTO notices (title, body, is_pinned, author_id) VALUES (?, ?, ?, ?)')->execute([$title, $body, $pinned, $user['id']]);
        $id = (int) $pdo->lastInsertId();
    }
    flash('공지를 저장했습니다.', 'success');
    redirect('notices.php?id=' . $id);
}

layout_header($n ? '공지 수정' : '공지 쓰기', 'notices');
?>
<form method="post" class="card">
  <?= csrf_field() ?>
  <h1><?= $n ? '공지 수정' : '공지 쓰기' ?></h1>
  <label>제목<input name="title" value="<?= e($n['title'] ?? '') ?>" required maxlength="200"></label>
  <label>내용<textarea name="body" rows="12"><?= e($n['body'] ?? '') ?></textarea></label>
  <label class="inline-check"><input type="checkbox" name="is_pinned" value="1" <?= !empty($n['is_pinned']) ? 'checked' : '' ?>>
    중요 공지 (목록 맨 위 고정, 대시보드에 항상 표시)</label>
  <div class="actions">
    <?php if ($n): ?><button class="btn danger" name="action" value="delete" formnovalidate onclick="return confirm('공지를 삭제할까요?')">삭제</button><?php endif ?>
    <a class="btn ghost" href="<?= e(url($n ? 'notices.php?id=' . $n['id'] : 'notices.php')) ?>">취소</a>
    <button class="btn primary" name="action" value="save">저장</button>
  </div>
</form>
<?php layout_footer();
