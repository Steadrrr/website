<?php
/** 관리팀·중분류 관리: groups.php?kind=facility (구역·건물) | equipment (장비 분류) */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
if (!can_manage_assets($user)) abort(403, '권한이 없습니다. (주무관 이상)');
$kind = ($_GET['kind'] ?? '') === 'equipment' ? 'equipment' : 'facility';
$back = "groups.php?kind=$kind";
$pdo = db();

if (is_post()) {
    csrf_verify();
    $id = (int) post('id');
    $action = post('action');
    $name = mb_substr(post('name'), 0, 100);
    $sort = (int) post('sort_order', '0');

    if (post('target') === 'team') {
        if ($action === 'delete') {
            $st = $pdo->prepare('SELECT COUNT(*) FROM asset_groups WHERE team_id = ?');
            $st->execute([$id]);
            if ((int) $st->fetchColumn() > 0) {
                flash('하위 분류가 있는 관리팀은 삭제할 수 없습니다.', 'error');
            } else {
                $pdo->prepare('DELETE FROM teams WHERE id = ?')->execute([$id]);
                flash('관리팀을 삭제했습니다.', 'success');
            }
        } elseif ($name === '') {
            flash('팀 이름을 입력하세요.', 'error');
        } elseif ($id) {
            $pdo->prepare('UPDATE teams SET name = ?, sort_order = ? WHERE id = ?')->execute([$name, $sort, $id]);
            flash('저장했습니다.', 'success');
        } else {
            $pdo->prepare('INSERT INTO teams (name, sort_order) VALUES (?, ?)')->execute([$name, $sort]);
            flash('관리팀을 추가했습니다.', 'success');
        }
        redirect($back);
    }

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

layout_header($label . ' 관리', $kind === 'facility' ? 'facilities' : 'equipment');
?>
<section class="card">
  <div class="card-head">
    <h1><?= e($label) ?> 관리</h1>
    <a class="btn ghost" href="<?= e(url($kind === 'facility' ? 'facilities.php' : 'equipment.php')) ?>">‹ <?= $kind === 'facility' ? '시설물' : '장비' ?> 목록</a>
  </div>
  <p class="muted small">관리팀(대분류) 아래에 <?= $kind === 'facility' ? '건물이나 구역(예: 숲속의집, 산림문화휴양관, 야영장)' : '장비 분류(예: 예초·벌목장비, 차량, 전기설비)' ?>을 만들고,
    그 아래에 <?= $kind === 'facility' ? '세부시설' : '장비' ?>을 등록합니다.</p>

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

<section class="card">
  <h2>관리팀 (대분류)</h2>
  <p class="muted small">시설물과 장비가 같은 관리팀을 사용합니다.</p>
  <table class="table product-table">
    <thead><tr><th>순서</th><th>팀 이름</th><th></th></tr></thead>
    <tbody>
    <?php foreach ([...array_values(teams_all()), null] as $t): $fid = 't' . ($t['id'] ?? 'new'); ?>
      <tr class="<?= $t ? '' : 'new-row' ?>">
        <td><input form="<?= $fid ?>" name="sort_order" value="<?= e($t['sort_order'] ?? (count(teams_all()) + 1) * 10) ?>" class="num tiny"></td>
        <td><input form="<?= $fid ?>" name="name" value="<?= e($t['name'] ?? '') ?>" placeholder="<?= $t ? '' : '새 관리팀' ?>" required></td>
        <td class="nowrap">
          <form method="post" id="<?= $fid ?>">
            <?= csrf_field() ?><input type="hidden" name="target" value="team"><input type="hidden" name="id" value="<?= (int) ($t['id'] ?? 0) ?>">
            <button class="btn small primary" name="action" value="save"><?= $t ? '저장' : '추가' ?></button>
            <?php if ($t): ?><button class="btn small ghost danger" name="action" value="delete" formnovalidate onclick="return confirm('삭제할까요?')">삭제</button><?php endif ?>
          </form>
        </td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</section>
<?php layout_footer();
