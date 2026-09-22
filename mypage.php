<?php
require __DIR__ . '/app/bootstrap.php';

$user = require_login();

if (is_post()) {
    csrf_verify();
    $name  = post('name');
    $phone = post('phone');
    $new   = post('new_password');

    if ($name === '') {
        flash('이름을 입력하세요.', 'error');
    } elseif ($new !== '' && !password_verify(post('password'), $user['password_hash'])) {
        flash('현재 비밀번호가 올바르지 않습니다.', 'error');
    } elseif ($new !== '' && mb_strlen($new) < 8) {
        flash('새 비밀번호는 8자 이상이어야 합니다.', 'error');
    } else {
        db()->prepare('UPDATE users SET name = ?, phone = ? WHERE id = ?')->execute([$name, $phone ?: null, $user['id']]);
        if ($new !== '') {
            db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        }
        flash('저장했습니다.', 'success');
    }
    redirect('mypage.php');
}

layout_header('내 정보');
?>
<form method="post" class="card narrow">
  <?= csrf_field() ?>
  <h1>내 정보</h1>
  <p class="muted">아이디 <b><?= e($user['username']) ?></b> · 직급 <b><?= e(rank_name($user['rank_level'])) ?></b>
    <?= $user['is_admin'] ? ' · 최고관리자' : '' ?></p>
  <label>이름<input name="name" value="<?= e($user['name']) ?>" required></label>
  <label>연락처<input name="phone" value="<?= e($user['phone']) ?>"></label>
  <hr>
  <p class="muted small">비밀번호를 바꿀 때만 입력하세요.</p>
  <label>현재 비밀번호<input type="password" name="password" autocomplete="current-password"></label>
  <label>새 비밀번호<input type="password" name="new_password" minlength="8" autocomplete="new-password"></label>
  <button class="btn primary block">저장</button>
</form>
<?php layout_footer();
