<?php
/** 조직도: 팀별 직원 사진·이름·직급·보직·전화번호 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
$members = db()->query(
    "SELECT id, name, rank_level, team_id, position, phone, photo FROM users
      WHERE status = 'active' ORDER BY rank_level DESC, name"
)->fetchAll();

// 팀 순서대로 묶고, 팀이 없는 직원은 맨 뒤 '팀 미지정'
$byTeam = [];
foreach (teams_all() as $tid => $t) $byTeam[$tid] = ['name' => $t['name'], 'members' => []];
$unassigned = [];
foreach ($members as $m) {
    if ($m['team_id'] && isset($byTeam[(int) $m['team_id']])) $byTeam[(int) $m['team_id']]['members'][] = $m;
    else $unassigned[] = $m;
}
if ($unassigned) $byTeam[0] = ['name' => '팀 미지정', 'members' => $unassigned];

$card = function (array $m): void { ?>
  <div class="member-card <?= (int) $m['rank_level'] >= RANK_LEADER ? 'leader' : '' ?>">
    <?= avatar($m, 'avatar avatar-lg') ?>
    <div class="member-info">
      <b><?= e($m['name']) ?></b> <span class="rank"><?= e(rank_name($m['rank_level'])) ?></span>
      <span class="position"><?= e($m['position'] ?: '-') ?></span>
      <?php if ($m['phone']): ?><a class="phone" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $m['phone'])) ?>"><?= e($m['phone']) ?></a><?php endif ?>
    </div>
  </div>
<?php };

layout_header('조직도', 'org');
?>
<section class="card org">
  <div class="card-head">
    <h1>조직도 <small class="muted"><?= e(config('site_name')) ?> · <?= count($members) ?>명</small></h1>
    <div class="actions no-margin no-print">
      <a class="btn" href="<?= e(url('member_photo.php')) ?>">내 사진 등록</a>
      <button class="btn ghost" onclick="window.print()">인쇄</button>
    </div>
  </div>

  <div class="org-teams">
    <?php foreach ($byTeam as $tid => $team): if (!$team['members'] && $tid) continue;
        // 팀장(가장 높은 직급)은 위, 나머지는 아래
        $top = array_filter($team['members'], fn($m) => (int) $m['rank_level'] >= RANK_LEADER);
        $rest = array_filter($team['members'], fn($m) => (int) $m['rank_level'] < RANK_LEADER); ?>
      <div class="org-team">
        <h2 class="org-team-name"><?= e($team['name']) ?> <small><?= count($team['members']) ?>명</small></h2>
        <?php if ($top): ?><div class="org-top"><?php foreach ($top as $m) $card($m) ?></div><?php endif ?>
        <div class="org-members"><?php foreach ($rest as $m) $card($m) ?></div>
      </div>
    <?php endforeach ?>
  </div>
  <?php if (can_manage_users($user)): ?>
    <p class="muted small no-print">팀·보직·연락처는 <a href="<?= e(url('admin/users.php')) ?>">회원관리</a>에서, 사진은 각자 '내 정보' 또는 회원관리에서 바꿀 수 있습니다.</p>
  <?php endif ?>
</section>
<?php layout_footer();
