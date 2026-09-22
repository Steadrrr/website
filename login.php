<?php
require __DIR__ . '/app/bootstrap.php';

if (current_user()) redirect('index.php');

$error = '';
if (is_post()) {
    csrf_verify();
    $result = attempt_login(post('username'), post('password'));
    if (is_array($result)) {
        $next = $_SESSION['after_login'] ?? '';
        unset($_SESSION['after_login']);
        // 같은 사이트 내부 경로로만 이동
        if ($next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//')) {
            header('Location: ' . $next);
            exit;
        }
        redirect('index.php');
    }
    $error = $result;
}

layout_header('로그인');
?>
<div class="card narrow">
  <h1>로그인</h1>
  <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>아이디<input name="username" value="<?= e(post('username')) ?>" required autofocus autocomplete="username"></label>
    <label>비밀번호<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn primary block">로그인</button>
  </form>
  <p class="center muted">계정이 없으신가요? <a href="<?= e(url('register.php')) ?>">회원가입</a></p>
</div>
<?php layout_footer();
