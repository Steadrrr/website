<?php
/**
 * 조직도
 *   상위 부서(예: 양평군청 산림과 산림휴양팀) — 팀장·주무관
 *     └ 사업장(예: 양평쉬자파크)
 *         └ 팀 — 맨 위에 공무직
 *             └ 반 — 맨 위에 반장, 그 아래 반원
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
$members = db()->query(
    "SELECT id, name, rank_level, team_id, squad_id, is_squad_leader, position, phone, photo FROM users
      WHERE status = 'active' ORDER BY rank_level DESC, is_squad_leader DESC, name"
)->fetchAll();

$officials = [];                  // 팀장·주무관
$teams = [];                      // team_id => ['heads' => 공무직, 'squads' => [squad_id => [leaders, members]], 'nosquad' => []]
foreach (teams_all() as $tid => $t) $teams[$tid] = ['name' => $t['name'], 'heads' => [], 'squads' => [], 'nosquad' => []];
foreach (squads_all() as $sid => $s) $teams[(int) $s['team_id']]['squads'][$sid] = ['name' => $s['name'], 'leaders' => [], 'members' => []];
$unassigned = [];

foreach ($members as $m) {
    $tid = (int) $m['team_id'];
    $sid = (int) $m['squad_id'];
    if ((int) $m['rank_level'] >= RANK_OFFICER) {
        $officials[] = $m;
    } elseif (!isset($teams[$tid])) {
        $unassigned[] = $m;
    } elseif ((int) $m['rank_level'] === RANK_WORKER) {
        $teams[$tid]['heads'][] = $m;                           // 공무직은 팀 맨 위
    } elseif ($sid && isset($teams[$tid]['squads'][$sid])) {
        $teams[$tid]['squads'][$sid][$m['is_squad_leader'] ? 'leaders' : 'members'][] = $m;
    } else {
        $teams[$tid]['nosquad'][] = $m;
    }
}
$teamCount = fn(array $t) => count($t['heads']) + count($t['nosquad'])
    + array_sum(array_map(fn($s) => count($s['leaders']) + count($s['members']), $t['squads']));

$card = function (array $m, string $extra = ''): void { ?>
  <div class="member-card <?= $extra ?>">
    <?= avatar($m, 'avatar avatar-lg') ?>
    <div class="member-info">
      <b><?= e($m['name']) ?></b>
      <span class="rank"><?= e(rank_name($m['rank_level'])) ?><?= $m['is_squad_leader'] && $m['squad_id'] ? ' · 반장' : '' ?></span>
      <span class="position"><?= e($m['position'] ?: '-') ?></span>
      <?php if ($m['phone']): ?><a class="phone" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $m['phone'])) ?>"><?= e($m['phone']) ?></a><?php endif ?>
    </div>
  </div>
<?php };

layout_header('조직도', 'org');
?>
<section class="card org">
  <div class="card-head">
    <h1>조직도 <small class="muted"><?= count($members) ?>명</small></h1>
    <div class="actions no-margin no-print">
      <a class="btn" href="<?= e(url('member_photo.php')) ?>">내 사진 등록</a>
      <?php if ($user['is_admin']): ?><a class="btn ghost" href="<?= e(url('settings.php?tab=org')) ?>">조직 구성 설정</a><?php endif ?>
      <button class="btn ghost" onclick="window.print()">인쇄</button>
    </div>
  </div>

  <div class="org-chart">
    <div class="org-gov">
      <div class="org-title gov"><?= e(setting('org_top_name', '담당 부서')) ?></div>
      <div class="org-row"><?php foreach ($officials as $m) $card($m, (int) $m['rank_level'] >= RANK_LEADER ? 'leader' : ''); ?>
        <?php if (!$officials): ?><p class="muted small">팀장·주무관이 없습니다.</p><?php endif ?></div>
    </div>
    <div class="org-line"></div>

    <div class="org-park">
      <div class="org-title park"><?= e(setting('org_park_name', '사업장')) ?></div>
      <div class="org-teams">
        <?php foreach ($teams as $t): ?>
          <div class="org-team">
            <h2 class="org-team-name"><?= e($t['name']) ?> <small><?= $teamCount($t) ?>명</small></h2>
            <div class="org-top">
              <?php foreach ($t['heads'] as $m) $card($m, 'head'); ?>
              <?php if (!$t['heads']): ?><p class="muted small">공무직 미배치</p><?php endif ?>
            </div>
            <div class="org-squads">
              <?php foreach ($t['squads'] as $s): ?>
                <div class="org-squad">
                  <h3><?= e($s['name']) ?> <small><?= count($s['leaders']) + count($s['members']) ?>명</small></h3>
                  <?php if ($s['leaders']): ?><div class="org-row"><?php foreach ($s['leaders'] as $m) $card($m, 'squad-leader'); ?></div><?php endif ?>
                  <div class="org-members"><?php foreach ($s['members'] as $m) $card($m); ?></div>
                  <?php if (!$s['leaders'] && !$s['members']): ?><p class="muted small">반원 없음</p><?php endif ?>
                </div>
              <?php endforeach ?>
              <?php if ($t['nosquad']): ?>
                <div class="org-squad nosquad">
                  <h3>반 미지정 <small><?= count($t['nosquad']) ?>명</small></h3>
                  <div class="org-members"><?php foreach ($t['nosquad'] as $m) $card($m); ?></div>
                </div>
              <?php endif ?>
            </div>
          </div>
        <?php endforeach ?>
      </div>
    </div>

    <?php if ($unassigned): ?>
      <div class="org-team nosquad unassigned-box">
        <h2 class="org-team-name">소속 미지정 <small><?= count($unassigned) ?>명</small></h2>
        <div class="org-members"><?php foreach ($unassigned as $m) $card($m); ?></div>
      </div>
    <?php endif ?>
  </div>
  <?php if (can_manage_users($user)): ?>
    <p class="muted small no-print">팀·반·반장·보직·연락처는 <a href="<?= e(url('admin/users.php')) ?>">회원관리</a>에서 바꿉니다. 사진은 각자 '내 정보'에서 올립니다.</p>
  <?php endif ?>
</section>
<?php layout_footer();
