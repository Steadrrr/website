<?php
require __DIR__ . '/app/bootstrap.php';

if (current_user()) redirect('index.php');

$errors = [];
if (is_post()) {
    csrf_verify();
    $username = post('username');
    $password = post('password');
    $name     = post('name');
    $phone    = post('phone');
    $rank     = (int) post('rank_level', '1');

    if (!preg_match('/^[a-zA-Z0-9_]{4,20}$/', $username)) $errors[] = '아이디는 영문/숫자 4~20자로 입력하세요.';
    if (mb_strlen($password) < 8) $errors[] = '비밀번호는 8자 이상이어야 합니다.';
    if ($password !== post('password2')) $errors[] = '비밀번호 확인이 일치하지 않습니다.';
    if ($name === '' || mb_strlen($name) > 50) $errors[] = '이름을 입력하세요.';
    if (!isset(RANKS[$rank])) $errors[] = '직급을 선택하세요.';

    if (!$errors) {
        $st = db()->prepare('SELECT 1 FROM users WHERE username = ?');
        $st->execute([$username]);
        if ($st->fetchColumn()) $errors[] = '이미 사용 중인 아이디입니다.';
    }

    if (!$errors) {
        // 가입 신청 직급은 참고용. 관리자/팀장이 승인하면서 최종 직급을 확정한다.
        db()->prepare("INSERT INTO users (username, password_hash, name, phone, rank_level, status) VALUES (?, ?, ?, ?, ?, 'pending')")
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $name, $phone ?: null, $rank]);
        flash('가입 신청이 완료되었습니다. 관리자 승인 후 로그인할 수 있습니다.', 'success');
        redirect('login.php');
    }
}

layout_header('회원가입');
?>
<div class="card narrow">
  <h1>회원가입</h1>
  <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach ?>
  <form method="post">
    <?= csrf_field() ?>
    <label>아이디<input name="username" value="<?= e(post('username')) ?>" required pattern="[A-Za-z0-9_]{4,20}" autocomplete="username"></label>
    <label>비밀번호 (8자 이상)<input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
    <label>비밀번호 확인<input type="password" name="password2" required minlength="8" autocomplete="new-password"></label>
    <label>이름<input name="name" value="<?= e(post('name')) ?>" required maxlength="50"></label>
    <label>연락처<input name="phone" value="<?= e(post('phone')) ?>" placeholder="010-0000-0000"></label>
    <label>직급
      <select name="rank_level">
        <?php foreach (RANKS as $v => $label): ?>
          <option value="<?= $v ?>" <?= (int) post('rank_level', '1') === $v ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach ?>
      </select>
    </label>
    <button class="btn primary block">가입 신청</button>
  </form>
  <p class="center muted">가입 후 관리자(팀장)의 승인이 있어야 로그인됩니다.</p>
</div>
<?php layout_footer();
