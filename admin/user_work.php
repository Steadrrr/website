<?php
/** 근무 설정 (관리원): 입사일·계약만료일·근무시간·휴무 요일 — 최고관리자·팀장 */
require dirname(__DIR__) . '/app/bootstrap.php';

$me = require_manager();
$u = att_user((int) ($_GET['id'] ?? 0)) ?? abort(404, '회원을 찾을 수 없습니다.');
if ($u['is_admin'] && !$me['is_admin']) abort(403, '최고관리자 계정은 수정할 수 없습니다.');

$errors = [];
if (is_post()) {
    csrf_verify();
    $hire = post('hire_date');
    $end = post('contract_end');
    $ws = post('work_start');
    $we = post('work_end');
    $offs = array_values(array_intersect(array_map('strval', (array) ($_POST['off_days'] ?? [])), ['0', '1', '2', '3', '4', '5', '6']));
    $time = '/^([01]\d|2[0-3]):[0-5]\d$/';
    if ($hire !== '' && !valid_date($hire)) $errors[] = '입사일을 확인하세요.';
    if ($hire !== '' && $end === '') $errors[] = '계약만료일을 입력하세요. (근무 마지막 날, 예: 2026-10-31)';
    if ($end !== '' && (!valid_date($end) || $hire === '' || $end < $hire)) $errors[] = '계약만료일은 입사일 이후 날짜로 입력하세요.';
    if (!preg_match($time, $ws) || !preg_match($time, $we) || $we <= $ws) $errors[] = '근무시간을 확인하세요. (종료가 시작보다 늦어야 합니다)';
    if (count($offs) > 6) $errors[] = '휴무 요일이 너무 많습니다.';
    if (!$errors) {
        db()->prepare('UPDATE users SET hire_date = ?, contract_end = ?, work_start = ?, work_end = ?, off_days = ? WHERE id = ?')
            ->execute([$hire ?: null, $end ?: null, $ws, $we, $offs ? implode(',', $offs) : '-', $u['id']]);
        flash("{$u['name']}님 근무 설정을 저장했습니다.", 'success');
        redirect('admin/users.php');
    }
    $u = array_merge($u, ['hire_date' => $hire, 'contract_end' => $end, 'work_start' => $ws, 'work_end' => $we, 'off_days' => $offs ? implode(',', $offs) : '-']);
}

[$ws, $we] = att_work_hours($u);
$off = att_off_days($u);
$sum = att_summary($u);

layout_header('근무 설정 · ' . $u['name'], $me['is_admin'] ? 'settings' : 'admin');
if ($me['is_admin']) settings_nav('users');
?>
<form method="post" class="card narrow">
  <?= csrf_field() ?>
  <div class="card-head">
    <h1><?= avatar($u, 'avatar avatar-sm') ?> <?= e($u['name']) ?> <small class="muted"><?= e(rank_name($u['rank_level'])) ?> · 근무 설정</small></h1>
    <a class="btn ghost" href="<?= e(url('admin/users.php')) ?>">‹ 회원관리</a>
  </div>
  <?php foreach ($errors as $m): ?><div class="flash flash-error"><?= e($m) ?></div><?php endforeach ?>
  <?php if (!att_is_subject($u)): ?><p class="flash flash-warn">근태관리는 관리원 직급만 대상입니다. (지금 직급: <?= e(rank_name($u['rank_level'])) ?>)</p><?php endif ?>

  <div class="row">
    <label>입사일<input type="date" name="hire_date" value="<?= e($u['hire_date']) ?>" <?= att_is_subject($u) ? 'required' : '' ?>></label>
    <label>계약만료일 <small class="muted">(근무 마지막 날)</small><input type="date" name="contract_end" value="<?= e($u['contract_end']) ?>" <?= att_is_subject($u) ? 'required' : '' ?>></label>
  </div>
  <p class="muted small" data-contract-preview></p>
  <div class="row">
    <label>근무 시작<input type="time" name="work_start" value="<?= e($ws) ?>" step="600" required></label>
    <label>근무 종료<input type="time" name="work_end" value="<?= e($we) ?>" step="600" required></label>
  </div>
  <label>휴무 요일 <small class="muted">(매주 쉬는 요일. 이 날과 공휴일은 연차·병가 일수에서 빠지고, 초과근무를 입력할 수 있습니다)</small></label>
  <div class="work-days">
    <?php foreach (['일', '월', '화', '수', '목', '금', '토'] as $i => $w): ?>
      <label><input type="checkbox" name="off_days[]" value="<?= $i ?>" <?= in_array($i, $off, true) ? 'checked' : '' ?>> <?= $w ?></label>
    <?php endforeach ?>
  </div>
  <?php if (trim((string) $u['off_days']) === ''): ?><p class="muted small">아직 저장하지 않아 토·일을 휴무로 표시했습니다.</p><?php endif ?>

  <?php if ($sum['set']): ?>
    <h3>연차·병가 현황 <small class="muted">(오늘 기준, 결재중 포함)</small></h3>
    <ul class="list">
      <li><span>계약기간</span><b><?= e(implode(' ~ ', $sum['period'])) ?> (<?= $sum['months'] ?>개월)</b></li>
      <li><span>발생 연차 / 최대</span><b><?= $sum['accrued'] ?>일 / <?= $sum['max'] ?>일</b></li>
      <li><span>사용 연차</span><b><?= e(att_fmt_min($sum['used_min'])) ?></b></li>
      <li><span>남은 연차</span><b><?= e(att_fmt_min($sum['remain_min'])) ?></b></li>
      <li><span>병가 사용 / 한도</span><b><?= $sum['sick_used'] ?>일 / <?= $sum['sick_limit'] ?>일</b></li>
    </ul>
  <?php endif ?>
  <p class="muted small">입사일부터 계약만료일까지가 계약기간이며, 이 기간 밖의 날짜에는 근태를 입력할 수 없습니다.
    한 달을 다 채우지 못한 마지막 달은 연차가 발생하지 않습니다. (예: 3/2 입사 ~ 10/31 만료 → 7개월, 3/1 입사 ~ 10/31 만료 → 8개월)<br>
    연차는 입사일부터 1개월 만근(결근 없음)마다 1일 발생하며, 최대 발생 연차(계약 개월 수, 최대 11일)까지 당겨 쓸 수 있습니다.
    병가 한도는 계약기간 3개월 미만 3일, 6개월 미만 6일, 6개월 이상 9일입니다.</p>
  <div class="actions"><button class="btn primary">저장</button></div>
</form>
<script>
// 계약 개월 수에 따른 최대 연차·병가 한도 미리 보기 (서버 계산과 같은 규칙)
(function () {
  const f = document.querySelector('form.card'), out = document.querySelector('[data-contract-preview]');
  const addM = (d, n) => { const t = new Date(d.getFullYear(), d.getMonth() + n, 1); t.setDate(Math.min(d.getDate(), new Date(t.getFullYear(), t.getMonth() + 1, 0).getDate())); return t; };
  function run() {
    const h = f.hire_date.value, e = f.contract_end.value;
    if (!h || !e || e < h) { out.textContent = ''; return; }
    const hd = new Date(h + 'T00:00:00'), end = new Date(e + 'T00:00:00'); end.setDate(end.getDate() + 1);
    let m = 0; while (m < 120 && addM(hd, m + 1) <= end) m++;
    const sick = m < 3 ? 3 : (m < 6 ? 6 : 9);
    out.textContent = `계약기간 ${m}개월 → 최대 발생 연차 ${Math.min(11, m)}일 · 병가 한도 ${sick}일`;
  }
  f.hire_date.addEventListener('change', run); f.contract_end.addEventListener('change', run); run();
})();
</script>
<?php layout_footer();
