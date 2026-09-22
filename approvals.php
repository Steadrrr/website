<?php
/** 결재함: 내 결재 차례 문서 + 내가 올린 문서 진행현황 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
$waiting = waiting_for_user($user);

$st = db()->prepare(
    "SELECT j.* FROM journals j
      WHERE j.author_id = ? AND j.status IN ('draft', 'pending', 'rejected')
      ORDER BY j.work_date DESC, j.id DESC LIMIT 50"
);
$st->execute([$user['id']]);
$mine = $st->fetchAll();

layout_header('결재함', 'approval');
?>
<section class="card">
  <h1>결재 대기 <small class="muted">(<?= e(rank_name($user['rank_level'])) ?> 결재 차례)</small></h1>
  <table class="table">
    <thead><tr><th>일자</th><th>구분</th><th>작성자</th><th>상신일시</th></tr></thead>
    <tbody>
    <?php foreach ($waiting as $j): ?>
      <tr class="clickable" onclick="location.href='<?= e(url('view.php?id=' . $j['id'])) ?>'">
        <td><?= e($j['work_date']) ?></td>
        <td><?= e(JOURNAL_TYPES[$j['type']]) ?></td>
        <td><?= e($j['author_name']) ?> <small class="muted"><?= e(rank_name($j['author_rank'])) ?></small></td>
        <td><?= e($j['submitted_at']) ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$waiting): ?><tr><td colspan="4" class="center muted">결재할 문서가 없습니다.</td></tr><?php endif ?>
    </tbody>
  </table>
</section>

<section class="card">
  <h2>내가 작성한 문서 (진행중·반려·임시저장)</h2>
  <table class="table">
    <thead><tr><th>일자</th><th>구분</th><th>상태</th></tr></thead>
    <tbody>
    <?php foreach ($mine as $j): ?>
      <tr class="clickable" onclick="location.href='<?= e(url('view.php?id=' . $j['id'])) ?>'">
        <td><?= e($j['work_date']) ?></td>
        <td><?= e(JOURNAL_TYPES[$j['type']]) ?></td>
        <td><?= status_badge($j['status']) ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$mine): ?><tr><td colspan="3" class="center muted">없음</td></tr><?php endif ?>
    </tbody>
  </table>
</section>
<?php layout_footer();
