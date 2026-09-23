<?php
/** 장비 목록: equipment.php?team=1&status=active|repair|disposed|all&q=검색어 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
$teamId = (int) ($_GET['team'] ?? 0);
if ($teamId && !isset(teams_all()[$teamId])) $teamId = 0;
$status = $_GET['status'] ?? 'inuse';
$q = trim((string) ($_GET['q'] ?? ''));

$where = ['1 = 1'];
$params = [];
if ($teamId) { $where[] = 'g.team_id = ?'; $params[] = $teamId; }
if ($status === 'inuse') $where[] = "e.status <> 'disposed'";
elseif (isset(EQUIPMENT_STATUS[$status])) { $where[] = 'e.status = ?'; $params[] = $status; }
if ($q !== '') {
    $where[] = '(e.name LIKE ? OR e.model LIKE ? OR e.serial_no LIKE ? OR e.location LIKE ?)';
    array_push($params, ...array_fill(0, 4, '%' . $q . '%'));
}
$st = db()->prepare(
    'SELECT e.*, g.name AS group_name, g.team_id, t.name AS team_name,
            (SELECT MAX(l.log_date) FROM equipment_logs l WHERE l.equipment_id = e.id AND l.kind IN (\'inspect\', \'repair\', \'maintain\')) AS last_log
       FROM equipment e JOIN asset_groups g ON g.id = e.group_id JOIN teams t ON t.id = g.team_id
      WHERE ' . implode(' AND ', $where) . '
      ORDER BY t.sort_order, t.id, g.sort_order, g.id, e.name'
);
$st->execute($params);
$tree = [];
foreach ($st as $e) $tree[$e['team_name']][$e['group_name']][] = $e;
$thumbs = photo_thumbs('equipment');

$counts = array_column(db()->query('SELECT status, COUNT(*) AS n FROM equipment GROUP BY status')->fetchAll(), 'n', 'status');
$link = fn(array $over) => 'equipment.php?' . http_build_query(array_filter(['team' => $teamId ?: null, 'status' => $status, 'q' => $q, ...$over]));

layout_header('장비', 'equipment');
?>
<section class="card">
  <div class="card-head">
    <h1>장비관리</h1>
    <div class="actions no-margin no-print">
      <?php if (can_manage_assets($user)): ?>
        <?php if ($user['is_admin']): ?><a class="btn" href="<?= e(url('groups.php?kind=equipment')) ?>">장비 분류 관리</a><?php endif ?>
        <a class="btn primary" href="<?= e(url('equipment_edit.php' . ($teamId ? "?team=$teamId" : ''))) ?>">+ 신규 장비 등록</a>
      <?php endif ?>
      <button class="btn ghost" onclick="window.print()">인쇄</button>
    </div>
  </div>

  <div class="kpis k3">
    <?php foreach (EQUIPMENT_STATUS as $k => $label): ?>
      <a class="kpi <?= $status === $k ? 'total' : '' ?>" href="<?= e(url($link(['status' => $k]))) ?>"><span><?= e($label) ?></span><b><?= (int) ($counts[$k] ?? 0) ?>대</b></a>
    <?php endforeach ?>
  </div>

  <div class="tabs team-tabs no-print">
    <a href="<?= e(url($link(['team' => null]))) ?>" class="<?= $teamId ? '' : 'on' ?>">전체</a>
    <?php foreach (teams_all() as $t): ?>
      <a href="<?= e(url($link(['team' => $t['id']]))) ?>" class="<?= $teamId === (int) $t['id'] ? 'on' : '' ?>"><?= e($t['name']) ?></a>
    <?php endforeach ?>
  </div>
  <form class="filter no-print" method="get">
    <?php if ($teamId): ?><input type="hidden" name="team" value="<?= $teamId ?>"><?php endif ?>
    <select name="status">
      <option value="inuse" <?= $status === 'inuse' ? 'selected' : '' ?>>보유 장비 (불용 제외)</option>
      <?php foreach (EQUIPMENT_STATUS as $k => $label): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
      <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>전체</option>
    </select>
    <input name="q" value="<?= e($q) ?>" placeholder="장비명·모델·일련번호·위치 검색">
    <button class="btn small">조회</button>
  </form>

  <?php if (!$tree): ?><p class="muted">조건에 맞는 장비가 없습니다.</p><?php endif ?>
  <?php foreach ($tree as $teamName => $groups): ?>
    <h2 class="team-head"><?= e($teamName) ?></h2>
    <?php foreach ($groups as $groupName => $items): ?>
      <h3 class="area-head"><?= e($groupName) ?> <small class="muted"><?= count($items) ?>대</small></h3>
      <div class="table-scroll">
      <table class="table">
        <thead><tr><th class="no-print"></th><th>장비명</th><th>모델 / 일련번호</th><th>보관 위치</th><th>취득일</th><th>최근 점검·수리</th><th>상태</th></tr></thead>
        <tbody>
        <?php foreach ($items as $e): ?>
          <tr class="clickable <?= $e['status'] === 'disposed' ? 'inactive' : '' ?>" onclick="location.href='<?= e(url('equipment_view.php?id=' . $e['id'])) ?>'">
            <td class="no-print mini-thumb"><?php if (isset($thumbs[$e['id']])): ?><img src="<?= e(url($thumbs[$e['id']])) ?>" alt=""><?php endif ?></td>
            <td><b><?= e($e['name']) ?></b></td>
            <td><?= e($e['model']) ?><?= $e['serial_no'] ? '<br><small class="muted">' . e($e['serial_no']) . '</small>' : '' ?></td>
            <td><?= e($e['location']) ?></td>
            <td class="nowrap"><?= e($e['acquired_on']) ?></td>
            <td class="nowrap"><?= e($e['last_log']) ?></td>
            <td><?= equipment_badge($e['status']) ?></td>
          </tr>
        <?php endforeach ?>
        </tbody>
      </table>
      </div>
    <?php endforeach ?>
  <?php endforeach ?>
</section>
<?php layout_footer();
