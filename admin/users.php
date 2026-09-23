<?php
/** 회원 관리: 가입 승인, 직급 지정, 사용중지, 비밀번호 초기화 (최고관리자·팀장) */
require dirname(__DIR__) . '/app/bootstrap.php';

$me = require_manager();

if (is_post()) {
    csrf_verify();
    $id = (int) post('id');
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    $target = $st->fetch() ?: abort(404, '회원을 찾을 수 없습니다.');

    // 팀장은 최고관리자 계정을 건드릴 수 없음
    if ($target['is_admin'] && !$me['is_admin']) abort(403, '최고관리자 계정은 수정할 수 없습니다.');

    if (post('action') === 'reset_password') {
        $temp = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(9))), 0, 10);
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($temp, PASSWORD_DEFAULT), $id]);
        flash("{$target['name']}님의 임시 비밀번호: $temp  (본인에게 전달 후 내 정보에서 변경하도록 안내하세요)", 'success');
    } else {
        $rank   = (int) post('rank_level');
        $status = post('status');
        $admin  = $me['is_admin'] ? (int) (post('is_admin') === '1') : (int) $target['is_admin'];
        $delegate = (int) (post('can_delegate') === '1' && $rank === RANK_OFFICER); // 전결은 주무관만
        if (!isset(RANKS[$rank]) || !in_array($status, ['pending', 'active', 'disabled'], true)) abort(400, '잘못된 값입니다.');
        if ($id === (int) $me['id'] && ($status !== 'active' || ($me['is_admin'] && !$admin))) {
            abort(400, '본인 계정을 중지하거나 관리자 권한을 해제할 수 없습니다.');
        }
        $team = (int) post('team_id');
        $team = isset(teams_all()[$team]) ? $team : null;
        $position = mb_substr(post('position'), 0, 50) ?: null;
        db()->prepare('UPDATE users SET rank_level = ?, status = ?, is_admin = ?, can_delegate = ?, team_id = ?, position = ? WHERE id = ?')
            ->execute([$rank, $status, $admin, $delegate, $team, $position, $id]);
        flash("{$target['name']}님 정보를 저장했습니다.", 'success');
    }
    redirect('admin/users.php');
}

$users = db()->query(
    "SELECT * FROM users ORDER BY FIELD(status, 'pending', 'active', 'disabled'), rank_level DESC, name"
)->fetchAll();

layout_header('회원관리', 'admin');
?>
<section class="card">
  <h1>회원관리</h1>
  <p class="muted small">가입 신청자는 '승인대기' 상태입니다. 직급을 확인하고 <b>팀·보직</b>을 입력한 뒤 상태를 '사용'으로 바꾸면 로그인할 수 있습니다.
    (팀·보직은 회원가입 화면에는 없고 여기서만 입력합니다. 팀 목록은 <a href="<?= e(url('groups.php?kind=facility')) ?>">관리팀 관리</a>에서 바꿀 수 있습니다)<br>
    <b>전결권한</b>: 체크한 주무관은 팀장 부재 시 '전결' 버튼으로 팀장 결재 없이 문서를 최종 완료할 수 있습니다. (주무관 직급만 가능)</p>
  <div class="table-scroll">
  <table class="table users-table">
    <thead><tr><th>이름</th><th>아이디</th><th>연락처</th><th>직급</th><th>팀</th><th>보직</th><th>상태</th><th title="팀장 부재 시 주무관이 최종 결재">전결권한</th><?php if ($me['is_admin']): ?><th>관리자</th><?php endif ?><th></th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): $locked = $u['is_admin'] && !$me['is_admin']; ?>
      <tr class="<?= $u['status'] === 'pending' ? 'highlight' : '' ?>">
        <td><?= e($u['name']) ?></td>
          <td><?= e($u['username']) ?><br><small class="muted">가입 <?= e(substr($u['created_at'], 0, 10)) ?></small></td>
          <td><?= e($u['phone']) ?></td>
          <td><select name="rank_level" form="u<?= (int) $u['id'] ?>" <?= $locked ? 'disabled' : '' ?>>
            <?php foreach (RANKS as $v => $label): ?><option value="<?= $v ?>" <?= (int) $u['rank_level'] === $v ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
          </select></td>
          <td><select name="team_id" form="u<?= (int) $u['id'] ?>" <?= $locked ? 'disabled' : '' ?>>
            <option value="">(미지정)</option>
            <?php foreach (teams_all() as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $u['team_id'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach ?>
          </select></td>
          <td><input name="position" value="<?= e($u['position']) ?>" form="u<?= (int) $u['id'] ?>" placeholder="예: 매표·안내" maxlength="50" class="position-input" <?= $locked ? 'disabled' : '' ?>></td>
          <td><select name="status" form="u<?= (int) $u['id'] ?>" <?= $locked ? 'disabled' : '' ?>>
            <?php foreach (['pending' => '승인대기', 'active' => '사용', 'disabled' => '중지'] as $v => $label): ?>
              <option value="<?= $v ?>" <?= $u['status'] === $v ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach ?>
          </select></td>
          <td class="center"><?php if ((int) $u['rank_level'] === RANK_OFFICER): ?><input type="checkbox" name="can_delegate" value="1" form="u<?= (int) $u['id'] ?>" <?= $u['can_delegate'] ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>><?php else: ?><span class="muted">-</span><?php endif ?></td>
          <?php if ($me['is_admin']): ?>
            <td class="center"><input type="checkbox" name="is_admin" value="1" form="u<?= (int) $u['id'] ?>" <?= $u['is_admin'] ? 'checked' : '' ?>></td>
          <?php endif ?>
          <td class="nowrap">
            <?php if (!$locked): ?>
            <form method="post" id="u<?= (int) $u['id'] ?>">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button class="btn small primary" name="action" value="save">저장</button>
              <button class="btn small ghost" name="action" value="reset_password" onclick="return confirm('임시 비밀번호를 발급할까요?')">비번초기화</button>
            </form>
            <?php endif ?>
          </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
</section>
<?php layout_footer();
