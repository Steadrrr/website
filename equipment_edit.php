<?php
/** 장비 등록/수정: equipment_edit.php?id=3  또는  ?team=1 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
if (!can_manage_assets($user)) abort(403, '권한이 없습니다. (주무관 이상)');
$pdo = db();

$id = (int) ($_GET['id'] ?? 0);
$e = $id ? (equipment_find($id) ?? abort(404, '장비를 찾을 수 없습니다.')) : null;
$groups = asset_groups('equipment');
if (!$groups) {
    flash('등록된 장비 분류가 없습니다. 최고관리자가 설정 › 장비 분류에서 먼저 만들어야 합니다.', 'error');
    redirect($user['is_admin'] ? 'groups.php?kind=equipment' : 'equipment.php');
}

if (is_post()) {
    csrf_verify();

    if (post('action') === 'delete' && $e) {
        if (!$user['is_admin']) abort(403, '장비 삭제는 최고관리자만 할 수 있습니다. 사용하지 않는 장비는 불용처리하세요.');
        photos_delete_all('equipment', $id);
        $pdo->prepare('DELETE FROM equipment WHERE id = ?')->execute([$id]);
        flash('삭제했습니다.', 'success');
        redirect('equipment.php');
    }

    $groupId = (int) post('group_id');
    $name = mb_substr(post('name'), 0, 100);
    $acquired = valid_date(post('acquired_on')) ? post('acquired_on') : null;
    if (!isset($groups[$groupId]) || $name === '') {
        flash('장비 분류와 장비명을 확인하세요.', 'error');
        redirect($id ? "equipment_edit.php?id=$id" : 'equipment_edit.php');
    }
    $row = [$groupId, $name, mb_substr(post('model'), 0, 100) ?: null, mb_substr(post('serial_no'), 0, 100) ?: null, post('spec') ?: null,
        $acquired, to_int(post('acquired_cost')) ?: null, mb_substr(post('location'), 0, 100) ?: null, post('note') ?: null];
    $pdo->beginTransaction();
    if ($e) {
        $pdo->prepare('UPDATE equipment SET group_id = ?, name = ?, model = ?, serial_no = ?, spec = ?, acquired_on = ?, acquired_cost = ?, location = ?, note = ? WHERE id = ?')
            ->execute([...$row, $id]);
    } else {
        $pdo->prepare('INSERT INTO equipment (group_id, name, model, serial_no, spec, acquired_on, acquired_cost, location, note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute($row);
        $id = (int) $pdo->lastInsertId();
        equipment_log($id, 'register', $acquired ?? date('Y-m-d'), (int) $user['id'], '신규 등록', null, null, 'active');
    }
    $pdo->commit();
    photos_delete('equipment', $id, (array) ($_POST['delete_photos'] ?? []));
    foreach (photos_save_uploaded('equipment', $id, (int) $user['id']) as $err) flash($err, 'error');
    flash("'{$name}' 저장했습니다.", 'success');
    redirect('equipment_view.php?id=' . $id);
}

$groupId = (int) ($e['group_id'] ?? 0);
if (!$groupId && ($team = (int) ($_GET['team'] ?? 0))) {
    foreach ($groups as $g) if ((int) $g['team_id'] === $team) { $groupId = (int) $g['id']; break; }
}

layout_header($e ? $e['name'] . ' 수정' : '신규 장비 등록', 'equipment');
?>
<form method="post" enctype="multipart/form-data" class="card">
  <?= csrf_field() ?>
  <h1><?= $e ? '장비 정보 수정' : '신규 장비 등록' ?></h1>
  <div class="row">
    <label>관리팀 › 장비 분류<select name="group_id" required><?= group_options('equipment', $groupId) ?></select></label>
    <label>장비명<input name="name" value="<?= e($e['name'] ?? '') ?>" required placeholder="예: 예초기 1호"></label>
  </div>
  <div class="row">
    <label>모델<input name="model" value="<?= e($e['model'] ?? '') ?>" placeholder="제조사 / 모델명"></label>
    <label>일련번호<input name="serial_no" value="<?= e($e['serial_no'] ?? '') ?>"></label>
    <label>보관 위치<input name="location" value="<?= e($e['location'] ?? '') ?>" placeholder="예: 관리사무소 창고"></label>
  </div>
  <div class="row">
    <label>취득일<input type="date" name="acquired_on" value="<?= e($e['acquired_on'] ?? '') ?>"></label>
    <label>취득금액(원)<input name="acquired_cost" value="<?= e(!empty($e['acquired_cost']) ? number_format($e['acquired_cost']) : '') ?>" class="num" inputmode="numeric" data-money></label>
  </div>
  <label>규격·스펙<textarea name="spec" rows="4" placeholder="배기량: 25.4cc&#10;무게: 5.5kg&#10;연료: 혼합유 25:1"><?= e($e['spec'] ?? '') ?></textarea></label>
  <label>비고<textarea name="note" rows="2"><?= e($e['note'] ?? '') ?></textarea></label>
  <?php render_photo_editor('equipment', $e ? (int) $e['id'] : null) ?>
  <div class="actions">
    <?php if ($e && $user['is_admin']): ?>
      <button class="btn danger" name="action" value="delete" formnovalidate onclick="return confirm('장비와 모든 이력을 완전히 삭제합니다. 잘못 등록한 경우에만 사용하세요.')">삭제</button>
    <?php endif ?>
    <a class="btn ghost" href="<?= e(url($e ? 'equipment_view.php?id=' . $e['id'] : 'equipment.php')) ?>">취소</a>
    <button class="btn primary" name="action" value="save">저장</button>
  </div>
</form>
<?php layout_footer();
