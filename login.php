<?php
require __DIR__ . '/app/bootstrap.php';

if (current_user()) redirect('index.php'); // 자동 로그인 쿠키가 있으면 여기서 바로 로그인된다

$error = '';
$savedId = saved_login_id();
if (is_post()) {
    csrf_verify();
    $result = attempt_login(post('username'), post('password'));
    if (is_array($result)) {
        save_login_id(post('save_id') === '1' ? $result['username'] : null); // ID 저장
        if (post('auto_login') === '1') remember_issue((int) $result['id']); // 자동 로그인 (이 기기, 30일)
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
$checked = fn(string $k, bool $default) => (is_post() ? post($k) === '1' : $default) ? 'checked' : '';

layout_header('로그인');
?>
<div class="card narrow">
  <h1>로그인</h1>
  <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif ?>
  <form method="post" id="loginForm">
    <?= csrf_field() ?>
    <label>아이디<input name="username" value="<?= e(is_post() ? post('username') : $savedId) ?>" required <?= $savedId && !is_post() ? '' : 'autofocus' ?> autocomplete="username"></label>
    <label>비밀번호<input type="password" name="password" required <?= $savedId && !is_post() ? 'autofocus' : '' ?> autocomplete="current-password"></label>
    <div class="login-opts">
      <label class="inline-check"><input type="checkbox" name="save_id" value="1" <?= $checked('save_id', $savedId !== '') ?>> ID 저장</label>
      <label class="inline-check"><input type="checkbox" name="save_pw" value="1" data-save-pw> 암호 저장</label>
      <label class="inline-check"><input type="checkbox" name="auto_login" value="1" data-auto-login <?= $checked('auto_login', false) ?>> 자동 로그인</label>
    </div>
    <p class="muted tiny-text login-note" data-auto-note hidden>자동 로그인: 이 기기에서 <?= REMEMBER_DAYS ?>일 동안 로그인이 유지됩니다. 로그아웃하면 해제됩니다. <b>여러 사람이 쓰는 PC에서는 켜지 마세요.</b></p>
    <p class="muted tiny-text login-note" data-pw-note hidden>암호 저장: 비밀번호는 이 사이트가 아니라 <b>브라우저(크롬·엣지 등)의 비밀번호 관리자</b>에 안전하게 저장되고, 다음에 자동으로 채워집니다.</p>
    <button class="btn primary block">로그인</button>
  </form>
  <p class="center muted">계정이 없으신가요? <a href="<?= e(url('register.php')) ?>">회원가입</a></p>
</div>
<script>
(function () {
  const form = document.getElementById('loginForm');
  const pw = form.querySelector('[data-save-pw]');
  const auto = form.querySelector('[data-auto-login]');
  const KEY = 'forestlog.savePw';
  let on = false;
  try { on = localStorage.getItem(KEY) === '1'; } catch (e) {}
  pw.checked = on;
  const notes = () => {
    form.querySelector('[data-pw-note]').hidden = !pw.checked;
    form.querySelector('[data-auto-note]').hidden = !auto.checked;
  };
  pw.addEventListener('change', () => { try { localStorage.setItem(KEY, pw.checked ? '1' : '0'); } catch (e) {} notes(); });
  auto.addEventListener('change', notes);
  notes();
  // 암호 저장을 켜 두었으면 브라우저 비밀번호 관리자에서 불러와 채운다 (지원하는 브라우저만)
  if (on && window.PasswordCredential && navigator.credentials) {
    navigator.credentials.get({ password: true, mediation: 'optional' }).then((c) => {
      if (!c || !c.password) return;
      if (!form.username.value || form.username.value === c.id) { form.username.value = c.id; form.password.value = c.password; }
    }).catch(() => {});
  }
  // 로그인할 때 암호 저장이 켜져 있으면 브라우저에 저장 (지원하지 않는 브라우저는 브라우저가 직접 저장할지 물어본다)
  form.addEventListener('submit', (ev) => {
    if (!pw.checked || !window.PasswordCredential || !navigator.credentials || form.dataset.stored) return;
    ev.preventDefault();
    form.dataset.stored = '1';
    let cred = null;
    try { cred = new PasswordCredential({ id: form.username.value, password: form.password.value, name: form.username.value }); } catch (e) {}
    (cred ? navigator.credentials.store(cred) : Promise.resolve()).catch(() => {}).finally(() => form.submit());
  });
})();
</script>
<?php layout_footer();
