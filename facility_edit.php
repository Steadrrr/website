<?php
/** 세부시설 등록/수정: facility_edit.php?id=3  또는  ?team=1 / ?group=2 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
if (!can_manage_assets($user)) abort(403, '권한이 없습니다. (주무관 이상)');
$pdo = db();

$id = (int) ($_GET['id'] ?? 0);
$f = $id ? (facility_find($id) ?? abort(404, '시설을 찾을 수 없습니다.')) : null;
$groups = asset_groups('facility');
if (!$groups) {
    flash('등록된 구역·건물이 없습니다. 최고관리자가 설정 › 시설 구역·건물에서 먼저 만들어야 합니다.', 'error');
    redirect($user['is_admin'] ? 'groups.php?kind=facility' : 'facilities.php');
}

if (is_post()) {
    csrf_verify();

    if (post('action') === 'delete' && $f) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM facility_items WHERE facility_id = ?');
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            $pdo->prepare('UPDATE facilities SET is_active = 0 WHERE id = ?')->execute([$id]);
            flash('점검 기록이 있어 삭제 대신 사용안함으로 바꿨습니다.', 'info');
            redirect('facility.php?id=' . $id);
        }
        photos_delete_all('facility', $id);
        $pdo->prepare('DELETE FROM facilities WHERE id = ?')->execute([$id]);
        flash('삭제했습니다.', 'success');
        redirect('facilities.php?team=' . $f['team_id']);
    }

    $groupId = (int) post('group_id');
    $name = mb_substr(post('name'), 0, 100);
    if (!isset($groups[$groupId]) || $name === '') {
        flash('구역·건물과 시설명을 확인하세요.', 'error');
        redirect($id ? "facility_edit.php?id=$id" : 'facility_edit.php');
    }
    $row = [$groupId, $name, post('spec') ?: null, post('note') ?: null, (int) post('sort_order', '0'), post('is_active') === '1' ? 1 : 0];
    if ($f) {
        $pdo->prepare('UPDATE facilities SET group_id = ?, name = ?, spec = ?, note = ?, sort_order = ?, is_active = ? WHERE id = ?')->execute([...$row, $id]);
    } else {
        $pdo->prepare('INSERT INTO facilities (group_id, name, spec, note, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)')->execute($row);
        $id = (int) $pdo->lastInsertId();
    }
    photos_delete('facility', $id, (array) ($_POST['delete_photos'] ?? []));
    foreach (photos_save_uploaded('facility', $id, (int) $user['id']) as $err) flash($err, 'error');
    flash("'{$name}' 저장했습니다.", 'success');
    redirect(post('action') === 'save_next' ? 'facility_edit.php?group=' . $groupId : 'facility.php?id=' . $id);
}

// 신규 등록 시 기본 구역: ?group= 또는 ?team= 의 첫 구역
$groupId = (int) ($f['group_id'] ?? ($_GET['group'] ?? 0));
if (!$groupId && ($team = (int) ($_GET['team'] ?? 0))) {
    foreach ($groups as $g) if ((int) $g['team_id'] === $team) { $groupId = (int) $g['id']; break; }
}

layout_header($f ? $f['name'] . ' 수정' : '세부시설 등록', 'facilities');
?>
<form method="post" enctype="multipart/form-data" class="card">
  <?= csrf_field() ?>
  <h1><?= $f ? '세부시설 수정' : '세부시설 등록' ?></h1>
  <div class="row">
    <label>관리팀 › 구역·건물<select name="group_id" required><?= group_options('facility', $groupId) ?></select></label>
    <label>시설명<input name="name" value="<?= e($f['name'] ?? '') ?>" required placeholder="예: 101호 보일러, 공용화장실, 데크 A구역"></label>
  </div>
  <label>규격·스펙 <small class="muted">(설비·장비류: 제조사, 모델, 용량, 설치일 등)</small>
    <textarea name="spec" rows="4" placeholder="제조사: ○○&#10;모델: ○○-123&#10;용량: 25,000kcal&#10;설치: 2021-05"><?= e($f['spec'] ?? '') ?></textarea></label>
  <label>비고<textarea name="note" rows="3"><?= e($f['note'] ?? '') ?></textarea></label>
  <div class="row">
    <label>표시 순서<input name="sort_order" value="<?= e($f['sort_order'] ?? 0) ?>" class="num" inputmode="numeric"></label>
    <label class="inline-check"><input type="checkbox" name="is_active" value="1" <?= !$f || $f['is_active'] ? 'checked' : '' ?>> 사용 (점검일지에 표시)</label>
  </div>
  <?php render_photo_editor('facility', $f ? (int) $f['id'] : null) ?>
  <div class="actions">
    <?php if ($f): ?>
      <button class="btn danger" name="action" value="delete" formnovalidate onclick="return confirm('삭제할까요? (점검 기록이 있으면 사용안함 처리)')">삭제</button>
    <?php endif ?>
    <a class="btn ghost" href="<?= e(url($f ? 'facility.php?id=' . $f['id'] : 'facilities.php')) ?>">취소</a>
    <?php if (!$f): ?><button class="btn" name="action" value="save_next">저장 후 계속 등록</button><?php endif ?>
    <button class="btn primary" name="action" value="save">저장</button>
  </div>
</form>
<?php layout_footer();
