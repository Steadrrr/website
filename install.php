<?php
/**
 * 최초 설치: 테이블 생성 + 최고관리자 계정 생성.
 * 설치가 끝나면 이 파일을 반드시 서버에서 삭제하세요.
 */
require __DIR__ . '/app/bootstrap.php';

try {
    db();
} catch (PDOException $e) {
    abort(500, 'DB 접속 실패: app/config.php 의 DB 정보를 확인하세요. (' . $e->getMessage() . ')');
}

$installed = false;
try {
    $installed = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
} catch (PDOException) {
    // users 테이블이 아직 없음
}
if ($installed) {
    abort(403, '이미 설치되어 있습니다. 보안을 위해 install.php 파일을 삭제하세요.');
}

$errors = [];
if (is_post()) {
    csrf_verify();
    $username = post('username');
    $password = post('password');
    $name     = post('name');
    if (!preg_match('/^[a-zA-Z0-9_]{4,20}$/', $username)) $errors[] = '아이디는 영문/숫자 4~20자';
    if (mb_strlen($password) < 8) $errors[] = '비밀번호는 8자 이상';
    if ($name === '') $errors[] = '이름을 입력하세요';

    if (!$errors) {
        $sql = file_get_contents(APP_ROOT . '/sql/schema.sql');
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
            db()->exec($stmt);
        }
        db()->prepare("INSERT INTO users (username, password_hash, name, rank_level, is_admin, status) VALUES (?, ?, ?, ?, 1, 'active')")
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $name, RANK_LEADER]);
        flash('설치 완료! 지금 install.php 파일을 서버에서 삭제하세요.', 'success');
        redirect('login.php');
    }
}

layout_header('설치');
?>
<div class="card narrow">
  <h1>최초 설치</h1>
  <p class="muted">DB 테이블을 만들고 최고관리자 계정을 생성합니다.</p>
  <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>관리자 아이디<input name="username" value="<?= e(post('username', 'admin')) ?>" required></label>
    <label>관리자 비밀번호<input type="password" name="password" required minlength="8"></label>
    <label>이름<input name="name" value="<?= e(post('name')) ?>" required></label>
    <button class="btn primary block">설치하기</button>
  </form>
</div>
<?php layout_footer();
