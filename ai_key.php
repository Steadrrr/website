<?php
/**
 * AI 분석 › Claude API 키 넣기·바꾸기·지우기
 *  - 운영 사이트: 최고관리자만
 *  - 체험판: 누구나 '팀장(관리자)'로 들어올 수 있으므로, 운영 사이트 최고관리자의 아이디·비밀번호를 함께 넣어야 저장된다
 *  키는 app/anthropic_key.txt 에 저장 (app 폴더는 웹에서 열 수 없음), 화면에는 앞뒤 몇 글자만 보인다.
 *  저장 전에 Anthropic 에 키가 맞는지 확인한다 (모델 목록 조회 — 비용 없음).
 *  운영 사이트에는 아래에 '구글 AI 키'(먼저 쓰고, 안 되면 Claude) — app/ai_gemini.php
 */
require __DIR__ . '/app/bootstrap.php';

$user = is_demo() ? require_login() : require_admin();
$error = '';

if (is_post()) {
    csrf_verify();
    $error = is_demo() ? (demo_verify_main_admin(post('admin_id'), (string) ($_POST['admin_pw'] ?? '')) ?? '') : '';
    $gAction = is_demo() ? '' : (string) post('gemini');
    if ($error === '' && $gAction !== '') { // 구글 AI 키 (운영 사이트만)
        if ($gAction === 'delete') {
            ai_gemini_key_save('') ? flash('구글 AI 키를 지웠습니다. 이제 Claude 만 씁니다.', 'success') : flash('키 파일을 지우지 못했습니다.', 'error');
        } elseif ($gAction === 'resume') {
            setting_set('ai_gemini_pause_until', '0');
            flash('구글 AI 를 다시 먼저 쓰도록 했습니다.', 'success');
        } elseif ($gAction === 'model') {
            $v = ai_gemini_verify(ai_gemini_key());
            if ($v['error'] === null && in_array(post('model'), $v['models'], true)) {
                setting_set('ai_gemini_model', post('model'));
                flash('구글 AI 모델을 ' . post('model') . ' 로 바꿨습니다.', 'success');
            } else flash($v['error'] ?? '목록에 없는 모델입니다.', 'error');
        } else {
            $key = preg_replace('/\s+/', '', (string) ($_POST['gemini_key'] ?? ''));
            $v = ai_gemini_verify($key);
            if ($v['error'] !== null) flash($v['error'], 'error');
            elseif (!ai_gemini_key_save($key)) flash('서버에 키 파일을 쓰지 못했습니다 (app 폴더 쓰기 권한 확인 필요).', 'error');
            else {
                setting_set('ai_gemini_model', $v['models'][0]);
                setting_set('ai_gemini_pause_until', '0');
                flash('구글 AI 키를 저장했습니다. 이제 AI 분석·민원 분류 제안에 구글을 먼저 씁니다 (모델 ' . $v['models'][0] . ').', 'success');
            }
        }
        redirect('ai_key.php');
    }
    if ($error === '') {
        if (post('action') === 'delete') {
            ai_key_save('') ? flash('API 키를 지웠습니다.', 'success') : flash('키 파일을 지우지 못했습니다.', 'error');
            redirect('ai_key.php');
        }
        // 운영 사이트: 체험판(/demo)에 이미 넣은 키를 그대로 가져와 쓰기 (키를 다시 발급받지 않아도 되게)
        $key = !is_demo() && post('action') === 'import_demo' ? trim((string) @file_get_contents(ai_demo_key_file())) : preg_replace('/\s+/', '', (string) ($_POST['api_key'] ?? ''));
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
  <?php if (!$fromConfig && !is_demo() && $now === '' && is_file(ai_demo_key_file())): ?>
  <form method="post" style="margin-bottom:1rem">
    <?= csrf_field() ?><input type="hidden" name="action" value="import_demo">
    <p>체험판(/demo)에 넣어 둔 키가 있습니다. 새로 발급받지 않고 그 키를 운영에서도 쓸 수 있습니다.</p>
    <button class="btn primary block">체험판 키 가져오기</button>
  </form>
  <p class="muted tiny-text">또는 새 키를 아래에 직접 넣으세요.</p>
  <?php endif ?>
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
  <?php if (!is_demo()): $gKey = ai_gemini_key(); $gModel = ai_gemini_model(); $pause = (int) setting('ai_gemini_pause_until', '0'); ?>
  <h2 id="gemini">구글 AI 키 <small class="muted">먼저 사용</small></h2>
  <p class="muted small">구글 키가 있으면 AI 분석·민원 분류 제안에 <b>구글을 먼저</b> 씁니다. 충전금이 다 되거나 오류가 나면 그 자리에서 <b>Claude 로 넘어갑니다</b>
    (그 뒤 1시간은 구글을 건너뜀). 체험판(/demo)은 늘 Claude 를 씁니다.</p>
  <p>지금 상태: <?php if ($gKey === ''): ?><b>연결 안 됨</b> (Claude 만 사용)<?php else: ?><b>연결됨</b> <code><?= e(ai_key_masked($gKey)) ?></code> · 모델 <code><?= e($gModel) ?></code>
    <?php if ($pause > time()): ?><br><span class="warn small">잠시 쉬는 중 — <?= e(date('H:i', $pause)) ?>까지 Claude 사용</span><?php endif ?><?php endif ?></p>
  <?php if ($gKey !== ''): ?>
    <p class="small">구글 사용: <b><?= number_format((int) setting('ai_gemini_calls', '0')) ?>회</b> · 토큰 입력 <?= number_format((int) setting('ai_gemini_in', '0')) ?> / 출력 <?= number_format((int) setting('ai_gemini_out', '0')) ?>
      <?php if ($t = setting('ai_gemini_last_ok', '')): ?> · 마지막 성공 <?= e($t) ?><?php endif ?></p>
    <?php if ($t = setting('ai_gemini_last_error', '')): ?><p class="muted small">마지막 오류: <?= e($t) ?></p><?php endif ?>
    <div class="actions">
      <?php if ($pause > time()): ?><form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="gemini" value="resume"><button class="btn">지금 다시 구글 먼저</button></form><?php endif ?>
      <details><summary class="muted small">모델 바꾸기</summary>
        <?php $v = ai_gemini_verify($gKey); if ($v['error'] !== null): ?><p class="warn small"><?= e($v['error']) ?></p><?php else: ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="gemini" value="model">
          <select name="model"><?php foreach ($v['models'] as $m): ?><option<?= $m === $gModel ? ' selected' : '' ?>><?= e($m) ?></option><?php endforeach ?></select>
          <button class="btn small">저장</button></form><?php endif ?>
      </details>
      <form method="post" class="inline" onsubmit="return confirm('구글 AI 키를 지울까요? 이제 Claude 만 씁니다.')"><?= csrf_field() ?><input type="hidden" name="gemini" value="delete"><button class="btn danger">구글 키 지우기</button></form>
    </div>
  <?php endif ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="gemini" value="save">
    <label><?= $gKey === '' ? '구글 AI 키' : '새 구글 AI 키로 바꾸기' ?> <input name="gemini_key" type="password" placeholder="구글 AI Studio 의 API 키 (AIza…)" autocomplete="off" required></label>
    <button class="btn block">구글 키 확인 후 저장</button>
  </form>
  <p class="muted tiny-text">키는 서버의 <code>app/gemini_key.txt</code> 에만 저장됩니다. 저장할 때 구글에 키가 맞는지 확인하고 쓸 수 있는 모델 중 하나를 자동으로 고릅니다 (비용 없음).
    충전금을 다 쓰면 키를 지우거나 그대로 두세요 — 그대로 두면 1시간마다 한 번 구글을 시도하고 안 되면 Claude 로 넘어갑니다.</p>
  <?php endif ?>
  <p><a href="<?= e(url('ai.php')) ?>">← AI 분석으로</a></p>
</div>
<?php layout_footer();
