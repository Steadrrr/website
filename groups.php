<?php
/** 설정 › 시설 구역·건물 / 장비 분류: groups.php?kind=facility | equipment (최고관리자). 팀은 설정 › 조직 구성에서 관리 */
require __DIR__ . '/app/bootstrap.php';

$user = require_admin();
$kind = ($_GET['kind'] ?? '') === 'equipment' ? 'equipment' : 'facility';
$back = "groups.php?kind=$kind";
$pdo = db();

if (is_post()) {
    csrf_verify();
    $id = (int) post('id');
    $action = post('action');
    $name = mb_substr(post('name'), 0, 100);
    $sort = (int) post('sort_order', '0');

    $table = $kind === 'facility' ? 'facilities' : 'equipment';
    if ($action === 'delete') {
        $st = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE group_id = ?");
        $st->execute([$id]);
        if ((int) $st->fetchColumn() > 0) {
            flash('등록된 ' . ($kind === 'facility' ? '세부시설' : '장비') . '이 있어 삭제할 수 없습니다. 사용 체크를 해제하세요.', 'error');
        } else {
            $pdo->prepare('DELETE FROM asset_groups WHERE id = ? AND kind = ?')->execute([$id, $kind]);
            flash('삭제했습니다.', 'success');
        }
    } elseif ($name === '' || !isset(teams_all()[(int) post('team_id')])) {
        flash('이름과 관리팀을 확인하세요.', 'error');
    } else {
        $row = [(int) post('team_id'), $name, $sort, post('is_active') === '1' ? 1 : 0];
        if ($id) {
            $pdo->prepare('UPDATE asset_groups SET team_id = ?, name = ?, sort_order = ?, is_active = ? WHERE id = ? AND kind = ?')->execute([...$row, $id, $kind]);
        } else {
            $pdo->prepare('INSERT INTO asset_groups (team_id, name, sort_order, is_active, kind) VALUES (?, ?, ?, ?, ?)')->execute([...$row, $kind]);
        }
        flash("'{$name}' 저장했습니다.", 'success');
    }
    redirect($back);
}

$groups = asset_groups($kind);
$st = $pdo->prepare('SELECT group_id, COUNT(*) AS n FROM ' . ($kind === 'facility' ? 'facilities' : 'equipment') . ' GROUP BY group_id');
$st->execute();
$counts = array_column($st->fetchAll(), 'n', 'group_id');
$label = ASSET_KINDS[$kind];

layout_header($label . ' 관리', 'settings');
settings_nav($kind);
?>
<section class="card">
  <div class="card-head">
    <h1><?= e($label) ?> 관리</h1>
    <a class="btn ghost" href="<?= e(url($kind === 'facility' ? 'facilities.php' : 'equipment.php')) ?>">‹ <?= $kind === 'facility' ? '시설물' : '장비' ?> 목록</a>
  </div>
  <p class="muted small">관리팀(대분류) 아래에 <?= $kind === 'facility' ? '건물이나 구역(예: 숲속의집, 산림문화휴양관, 야영장)' : '장비 분류(예: 예초·벌목장비, 차량, 전기설비)' ?>을 만들고,
    그 아래에 <?= $kind === 'facility' ? '세부시설' : '장비' ?>을 등록합니다.
    팀 추가·이름 변경은 <a href="<?= e(url('settings.php?tab=org')) ?>">조직 구성</a>에서 합니다.</p>

  <?php foreach (teams_all() as $tid => $team):
      $rows = array_filter($groups, fn($g) => (int) $g['team_id'] === $tid); ?>
    <h2 class="team-head"><?= e($team['name']) ?></h2>
    <table class="table product-table">
      <thead><tr><th>순서</th><th>이름</th><th>관리팀</th><th class="right">등록 수</th><th>사용</th><th></th></tr></thead>
      <tbody>
      <?php foreach ([...$rows, null] as $g): $fid = 'g' . ($g['id'] ?? "new$tid"); ?>
        <tr class="<?= $g ? ($g['is_active'] ? '' : 'inactive') : 'new-row' ?>">
          <td><input form="<?= $fid ?>" name="sort_order" value="<?= e($g['sort_order'] ?? (count($rows) + 1) * 10) ?>" class="num tiny"></td>
          <td><input form="<?= $fid ?>" name="name" value="<?= e($g['name'] ?? '') ?>" placeholder="<?= $g ? '' : '새 ' . e($label) ?>" required></td>
          <td><select form="<?= $fid ?>" name="team_id">
            <?php foreach (teams_all() as $t2): ?><option value="<?= (int) $t2['id'] ?>" <?= (int) $t2['id'] === $tid ? 'selected' : '' ?>><?= e($t2['name']) ?></option><?php endforeach ?>
          </select></td>
          <td class="right"><?= $g ? (int) ($counts[$g['id']] ?? 0) : '' ?></td>
          <td class="center"><input form="<?= $fid ?>" type="checkbox" name="is_active" value="1" <?= !$g || $g['is_active'] ? 'checked' : '' ?>></td>
          <td class="nowrap">
            <form method="post" id="<?= $fid ?>">
              <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($g['id'] ?? 0) ?>">
              <button class="btn small primary" name="action" value="save"><?= $g ? '저장' : '추가' ?></button>
              <?php if ($g): ?><button class="btn small ghost danger" name="action" value="delete" formnovalidate onclick="return confirm('삭제할까요?')">삭제</button><?php endif ?>
            </form>
          </td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  <?php endforeach ?>
</section>

<?php layout_footer();
