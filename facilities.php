<?php
/** 시설물 목록: 관리팀 > 구역·건물 > 세부시설 (facilities.php?team=1) */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
$teamId = (int) ($_GET['team'] ?? 0);
if ($teamId && !isset(teams_all()[$teamId])) $teamId = 0;
$showInactive = !empty($_GET['all']);

$facilities = facilities_list($teamId ?: null, !$showInactive);
$thumbs = photo_thumbs('facility');
$normal = normal_result();

// 시설별 최근 점검결과와 이상 건수 (임시저장 제외)
$last = $abnormal = [];
$st = db()->prepare(
    "SELECT fi.facility_id, fi.result, j.work_date
       FROM facility_items fi JOIN journals j ON j.id = fi.journal_id
      WHERE fi.facility_id IS NOT NULL AND j.status <> 'draft'
      ORDER BY j.work_date, fi.id"
);
$st->execute();
foreach ($st as $r) {
    $fid = (int) $r['facility_id'];
    $last[$fid] = $r; // 날짜순이므로 마지막 값이 최근
    if ($r['result'] !== $normal) $abnormal[$fid] = ($abnormal[$fid] ?? 0) + 1;
}

// 최근 30일 이상 내역
$st = db()->prepare(
    "SELECT fi.*, j.work_date, j.id AS journal_id, g.team_id
       FROM facility_items fi
       JOIN journals j ON j.id = fi.journal_id
       LEFT JOIN facilities f ON f.id = fi.facility_id
       LEFT JOIN asset_groups g ON g.id = f.group_id
      WHERE j.status <> 'draft' AND fi.result <> ? AND j.work_date >= ?" . ($teamId ? ' AND (j.team_id = ? OR g.team_id = ?)' : '') . "
      ORDER BY j.work_date DESC, fi.id DESC LIMIT 30"
);
$st->execute([$normal, date('Y-m-d', strtotime('-30 days')), ...($teamId ? [$teamId, $teamId] : [])]);
$recentIssues = $st->fetchAll();

// 팀 > 구역 으로 묶기 (빈 구역도 표시)
$tree = [];
foreach (asset_groups('facility', !$showInactive) as $g) {
    if ($teamId && (int) $g['team_id'] !== $teamId) continue;
    $tree[$g['team_name']][$g['id']] = ['group' => $g, 'items' => []];
}
foreach ($facilities as $f) {
    $tree[$f['team_name']][$f['group_id']]['items'][] = $f;
}

layout_header('시설물', 'facilities');
?>
<section class="card">
  <div class="card-head">
    <h1>시설물</h1>
    <div class="actions no-margin no-print">
      <a class="btn" href="<?= e(url('journal.php?type=facility' . ($teamId ? "&team=$teamId" : ''))) ?>">점검일지</a>
      <?php if (can_manage_assets($user)): ?>
        <?php if ($user['is_admin']): ?><a class="btn" href="<?= e(url('groups.php?kind=facility')) ?>">구역·건물 관리</a><?php endif ?>
        <a class="btn primary" href="<?= e(url('facility_edit.php' . ($teamId ? "?team=$teamId" : ''))) ?>">+ 세부시설 등록</a>
      <?php endif ?>
      <button class="btn ghost" onclick="window.print()">인쇄</button>
    </div>
  </div>
  <div class="tabs team-tabs no-print">
    <a href="<?= e(url('facilities.php')) ?>" class="<?= $teamId ? '' : 'on' ?>">전체</a>
    <?php foreach (teams_all() as $t): ?>
      <a href="<?= e(url('facilities.php?team=' . $t['id'])) ?>" class="<?= $teamId === (int) $t['id'] ? 'on' : '' ?>"><?= e($t['name']) ?></a>
    <?php endforeach ?>
  </div>
  <label class="inline-check no-print"><input type="checkbox" onchange="location.href='?<?= $teamId ? "team=$teamId&" : '' ?>all=' + (this.checked ? 1 : '')" <?= $showInactive ? 'checked' : '' ?>> 사용안함 시설도 보기</label>

  <?php if (!$tree): ?>
    <p class="muted">등록된 구역·건물이 없습니다. 최고관리자가 <?= $user['is_admin'] ? '<a href="' . e(url('groups.php?kind=facility')) . '">설정 › 시설 구역·건물</a>' : '설정 › 시설 구역·건물' ?>에서 먼저 만들어야 합니다.</p>
  <?php endif ?>

  <?php foreach ($tree as $teamName => $areas): ?>
    <h2 class="team-head"><?= e($teamName) ?></h2>
    <?php foreach ($areas as $area): ?>
      <h3 class="area-head"><?= e($area['group']['name']) ?> <small class="muted"><?= count($area['items']) ?>개</small></h3>
      <?php if (!$area['items']): ?><p class="muted small">등록된 세부시설이 없습니다.</p><?php endif ?>
      <div class="asset-grid">
        <?php foreach ($area['items'] as $f): $fid = (int) $f['id']; $lr = $last[$fid] ?? null; ?>
          <a class="asset-card <?= $f['is_active'] ? '' : 'inactive' ?>" href="<?= e(url('facility.php?id=' . $fid)) ?>">
            <div class="asset-thumb"><?php if (isset($thumbs[$fid])): ?><img src="<?= e(url($thumbs[$fid])) ?>" alt=""><?php else: ?><span>사진 없음</span><?php endif ?></div>
            <div class="asset-body">
              <b><?= e($f['name']) ?></b>
              <small><?php if ($lr): ?>최근 <?= e(date('n/j', strtotime($lr['work_date']))) ?> <span class="result r-<?= e($lr['result']) ?>"><?= e($lr['result']) ?></span><?php else: ?><span class="muted">점검기록 없음</span><?php endif ?></small>
              <?php if (!empty($abnormal[$fid])): ?><small class="warn">이상 이력 <?= $abnormal[$fid] ?>건</small><?php endif ?>
            </div>
          </a>
        <?php endforeach ?>
      </div>
    <?php endforeach ?>
  <?php endforeach ?>
</section>

<section class="card">
  <h2>최근 30일 이상 내역</h2>
  <table class="table">
    <thead><tr><th>점검일</th><th>구역</th><th>시설</th><th>결과</th><th>내용 / 조치사항</th></tr></thead>
    <tbody>
    <?php foreach ($recentIssues as $r): ?>
      <tr class="clickable" onclick="location.href='<?= e(url($r['facility_id'] ? 'facility.php?id=' . $r['facility_id'] : 'view.php?id=' . $r['journal_id'])) ?>'">
        <td><?= e($r['work_date']) ?></td><td><?= e($r['area'] ?? '기타') ?></td><td><?= e($r['facility']) ?></td>
        <td><span class="result r-<?= e($r['result']) ?>"><?= e($r['result']) ?></span></td><td><?= e($r['note']) ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$recentIssues): ?><tr><td colspan="5" class="center muted">최근 30일간 이상 내역이 없습니다.</td></tr><?php endif ?>
    </tbody>
  </table>
</section>
<?php layout_footer();
