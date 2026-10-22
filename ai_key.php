<?php
/**
 * AI 분석 › Claude API 키 넣기·바꾸기·지우기
 *  - 운영 사이트: 최고관리자만
 *  - 체험판: 누구나 '팀장(관리자)'로 들어올 수 있으므로, 운영 사이트 최고관리자의 아이디·비밀번호를 함께 넣어야 저장된다
 *  키는 app/anthropic_key.txt 에 저장 (app 폴더는 웹에서 열 수 없음), 화면에는 앞뒤 몇 글자만 보인다.
 *  저장 전에 Anthropic 에 키가 맞는지 확인한다 (모델 목록 조회 — 비용 없음).
 */
require __DIR__ . '/app/bootstrap.php';

$user = is_demo() ? require_login() : require_admin();
$error = '';

if (is_post()) {
    csrf_verify();
    $error = is_demo() ? (demo_verify_main_admin(post('admin_id'), (string) ($_POST['admin_pw'] ?? '')) ?? '') : '';
    if ($error === '') {
        if (post('action') === 'delete') {
            ai_key_save('') ? flash('API 키를 지웠습니다.', 'success') : flash('키 파일을 지우지 못했습니다.', 'error');
            redirect('ai_key.php');
        }
        $key = preg_replace('/\s+/', '', (string) ($_POST['api_key'] ?? ''));
        $error = ai_key_verify($key) ?? '';
        if ($error === '') {
            if (ai_key_save($key)) {
                flash('API 키를 저장했습니다. 이제 AI 분석을 받을 수 있습니다.', 'success');
                redirect('ai.php');
            }
            $error = '서버에 키 파일을 쓰지 못했습니다 (app 폴더 쓰기 권한 확인 필요).';
        }
    }
}

$now = ai_key();
$fromConfig = trim((string) config('anthropic_api_key', '')) !== '';
layout_header('AI 연결 (API 키)', 'ai');
?>
<div class="card narrow">
  <h1>AI 연결 — Claude API 키</h1>
  <?php $local = is_file(ai_key_file()); ?>
  <p>지금 상태: <?= $now !== '' ? '<b>연결됨</b> <code>' . e(ai_key_masked($now)) . '</code>' . ($fromConfig ? ' <span class="muted">(설정 파일에 있는 키 — 여기서 바꿀 수 없음)</span>' : '') : '<b>연결 안 됨</b>' ?></p>
  <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif ?>
  <?php if (!$fromConfig): ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <label>API 키 <input name="api_key" type="password" placeholder="sk-ant-로 시작하는 키 전체" autocomplete="off" required></label>
    <?php if (is_demo()): ?>
      <p class="muted tiny-text">체험판은 누구나 관리자로 들어올 수 있어서, <b>운영 사이트 최고관리자</b> 계정으로 한 번 더 확인합니다. (5번 틀리면 1시간 잠김)</p>
      <label>운영 사이트 관리자 아이디 <input name="admin_id" autocomplete="off" required></label>
      <label>운영 사이트 관리자 비밀번호 <input name="admin_pw" type="password" autocomplete="new-password" required></label>
    <?php endif ?>
    <button class="btn primary block">키 확인 후 저장</button>
  </form>
  <?php if ($now !== '' && !$local): ?><p class="muted tiny-text">지금 키는 운영 사이트에 저장된 키입니다. 여기서 새 키를 저장하면 체험판은 그 키를 쓰고, 지우기는 이 체험판에 저장한 키만 지웁니다.</p><?php endif ?>
  <?php if ($local): ?>
  <form method="post" style="margin-top:1rem" onsubmit="return confirm('API 키를 지울까요? AI 분석은 규칙 예측만 보이게 됩니다.')">
    <?= csrf_field() ?><input type="hidden" name="action" value="delete">
    <?php if (is_demo()): ?>
      <details><summary class="muted">키 지우기</summary>
        <label>운영 사이트 관리자 아이디 <input name="admin_id" autocomplete="off" required></label>
        <label>운영 사이트 관리자 비밀번호 <input name="admin_pw" type="password" autocomplete="new-password" required></label>
        <button class="btn danger">키 지우기</button>
      </details>
    <?php else: ?>
      <button class="btn danger">키 지우기</button>
    <?php endif ?>
  </form>
  <?php endif ?>
  <?php endif ?>
  <ul class="muted tiny-text">
    <li>키는 서버의 <code>app/anthropic_key.txt</code> 에만 저장되고, 화면에는 앞뒤 몇 글자만 보입니다. 저장소(깃허브)에는 올라가지 않습니다.</li>
    <li>저장하기 전에 Anthropic 에 키가 맞는지 확인합니다 (비용 없음).</li>
    <li>키를 채팅·카톡·메일로 보내지 마세요. 새어 나갔다면 Anthropic 콘솔에서 그 키를 지우고 새로 만드세요.</li>
  </ul>
  <p><a href="<?= e(url('ai.php')) ?>">← AI 분석으로</a></p>
</div>
<?php layout_footer();
