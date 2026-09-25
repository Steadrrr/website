<?php
/** 기타 › 업데이트: 사이트 기능 추가·수정 내역 (app/changelog.php) */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
$byDate = [];
foreach (CHANGELOG as $c) $byDate[$c['date']][] = $c;

layout_header('업데이트', 'updates');
?>
<section class="card">
  <h1>업데이트 내역</h1>
  <p class="muted small">사이트에 추가되거나 바뀐 기능입니다. 최근 것이 위에 있습니다. 7일 이내 업데이트는 <span class="badge st-approved">NEW</span> 표시.</p>
  <?php foreach ($byDate as $date => $list): $new = strtotime($date) >= strtotime('-7 days'); ?>
    <div class="changelog-day">
      <h2><?= e(date('Y년 n월 j일', strtotime($date))) ?> <small class="muted">(<?= weekday_ko($date) ?>)</small><?= $new ? ' <span class="badge st-approved">NEW</span>' : '' ?></h2>
      <?php foreach ($list as $c): ?>
        <div class="changelog-item">
          <h3><?= e($c['title']) ?><?php if (!empty($c['menu'])): ?> <small class="muted"><?= e($c['menu']) ?></small><?php endif ?></h3>
          <ul><?php foreach ($c['items'] as $it): ?><li><?= e($it) ?></li><?php endforeach ?></ul>
        </div>
      <?php endforeach ?>
    </div>
  <?php endforeach ?>
</section>
<?php layout_footer();
