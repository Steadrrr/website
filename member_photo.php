<?php
/** 회원 개인 사진 올리기/바꾸기/삭제: member_photo.php (본인)  또는  ?id=5 (회원관리 권한자) */
require __DIR__ . '/app/bootstrap.php';

$me = require_login();
$id = (int) ($_GET['id'] ?? $me['id']);
if ($id !== (int) $me['id'] && !can_manage_users($me)) abort(403, '본인 사진만 바꿀 수 있습니다.');
$st = db()->prepare('SELECT * FROM users WHERE id = ?');
$st->execute([$id]);
$u = $st->fetch() ?: abort(404, '회원을 찾을 수 없습니다.');
$self = $id === (int) $me['id'];
$back = $self ? 'mypage.php' : 'admin/users.php';

if (is_post()) {
    csrf_verify();
    $old = $u['photo'];
    if (post('action') === 'delete') {
        db()->prepare('UPDATE users SET photo = NULL WHERE id = ?')->execute([$id]);
        flash('사진을 삭제했습니다.', 'success');
    } else {
        $f = $_FILES['photo'] ?? null;
        [$path, $error] = $f ? store_uploaded_image((string) $f['name'], (string) $f['tmp_name'], (int) $f['error'], 'members') : [null, '사진을 선택하세요.'];
        if ($error || !$path) {
            flash($error ?: '사진을 선택하세요.', 'error');
            redirect('member_photo.php' . ($self ? '' : "?id=$id"));
        }
        db()->prepare('UPDATE users SET photo = ? WHERE id = ?')->execute([$path, $id]);
        flash('사진을 저장했습니다.', 'success');
    }
    // 이전 사진 파일 정리 (uploads/members/ 안의 것만)
    if ($old && str_starts_with($old, 'uploads/members/')) @unlink(APP_ROOT . '/' . $old);
    redirect($back);
}

layout_header('사진 등록', $self ? '' : 'admin');
?>
<form method="post" enctype="multipart/form-data" class="card narrow center-card">
  <?= csrf_field() ?>
  <h1><?= $self ? '내 사진' : e($u['name']) . '님 사진' ?></h1>
  <div class="photo-preview"><?= avatar($u, 'avatar avatar-xl') ?></div>
  <p class="muted small">조직도와 대시보드에 표시됩니다. 얼굴이 잘 보이는 사진을 올려 주세요. (큰 사진은 자동으로 줄여서 올립니다)</p>
  <input type="file" name="photo" accept="image/*" data-resize="800" required>
  <div class="actions">
    <a class="btn ghost" href="<?= e(url($back)) ?>">취소</a>
    <?php if ($u['photo']): ?><button class="btn danger" name="action" value="delete" formnovalidate onclick="return confirm('사진을 삭제할까요?')">사진 삭제</button><?php endif ?>
    <button class="btn primary" name="action" value="upload">저장</button>
  </div>
</form>
<?php layout_footer();
