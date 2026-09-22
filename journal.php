<?php
/** 일지 달력 조회: journal.php?type=daily|sales|facility&ym=2026-09&date=2026-09-22 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();

$type = $_GET['type'] ?? 'daily';
if (!isset(JOURNAL_TYPES[$type])) $type = 'daily';

$selected = $_GET['date'] ?? '';
if (!valid_date($selected)) $selected = '';
$ym = $_GET['ym'] ?? ($selected ? substr($selected, 0, 7) : date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $ym) || !valid_date("$ym-01")) $ym = date('Y-m');
if ($selected === '' && $ym === date('Y-m')) $selected = date('Y-m-d');

$first = new DateTimeImmutable("$ym-01");
$last  = $first->modify('last day of this month');
$prev  = $first->modify('-1 month')->format('Y-m');
$next  = $first->modify('+1 month')->format('Y-m');

// 이 달의 일지 (남의 임시저장은 제외)
$st = db()->prepare(
    "SELECT j.id, j.work_date, j.status, j.author_id, u.name AS author_name,
            (SELECT COALESCE(SUM(s.card + s.cash + s.transfer), 0) FROM sales_items s WHERE s.journal_id = j.id) AS sales_amount
       FROM journals j JOIN users u ON u.id = j.author_id
      WHERE j.type = ? AND j.work_date BETWEEN ? AND ?
        AND (j.status <> 'draft' OR j.author_id = ?)
      ORDER BY j.work_date, j.id"
);
$st->execute([$type, $first->format('Y-m-d'), $last->format('Y-m-d'), $user['id']]);
$byDate = [];
foreach ($st as $row) {
    $byDate[$row['work_date']][] = $row;
}

layout_header(JOURNAL_TYPES[$type], $type);
?>
<div class="card">
  <div class="card-head">
    <h1><?= e(JOURNAL_TYPES[$type]) ?></h1>
    <a class="btn primary" href="<?= e(url("write.php?type=$type&date=" . ($selected ?: date('Y-m-d')))) ?>">+ 작성</a>
  </div>

  <div class="cal-nav">
    <a class="btn" href="<?= e(url("journal.php?type=$type&ym=$prev")) ?>">‹ 이전달</a>
    <strong><?= e($first->format('Y년 n월')) ?></strong>
    <a class="btn" href="<?= e(url("journal.php?type=$type&ym=$next")) ?>">다음달 ›</a>
    <a class="btn ghost" href="<?= e(url("journal.php?type=$type")) ?>">오늘</a>
  </div>

  <div class="calendar">
    <?php foreach (['일', '월', '화', '수', '목', '금', '토'] as $i => $w): ?>
      <div class="cal-h <?= $i === 0 ? 'sun' : ($i === 6 ? 'sat' : '') ?>"><?= $w ?></div>
    <?php endforeach ?>

    <?php for ($i = 0, $pad = (int) $first->format('w'); $i < $pad; $i++): ?><div class="cal-d empty"></div><?php endfor ?>

    <?php for ($d = $first; $d <= $last; $d = $d->modify('+1 day')):
        $date = $d->format('Y-m-d');
        $items = $byDate[$date] ?? [];
        $w = (int) $d->format('w');
        $cls = ['cal-d'];
        if ($w === 0) $cls[] = 'sun';
        if ($w === 6) $cls[] = 'sat';
        if ($date === date('Y-m-d')) $cls[] = 'today';
        if ($date === $selected) $cls[] = 'selected';
    ?>
      <a class="<?= implode(' ', $cls) ?>" href="<?= e(url("journal.php?type=$type&ym=$ym&date=$date")) ?>">
        <span class="num"><?= $d->format('j') ?></span>
        <?php if ($type === 'sales' && $items): ?>
          <span class="amt"><?= e(number_format(array_sum(array_column($items, 'sales_amount')))) ?></span>
        <?php endif ?>
        <?php foreach (array_slice($items, 0, 3) as $it): ?>
          <span class="chip st-<?= e($it['status']) ?>"><?= e($it['author_name']) ?></span>
        <?php endforeach ?>
        <?php if (count($items) > 3): ?><span class="more">+<?= count($items) - 3 ?></span><?php endif ?>
      </a>
    <?php endfor ?>
  </div>

  <div class="legend">
    <?php foreach (JOURNAL_STATUS as $k => $v): ?><span class="chip st-<?= $k ?>"><?= e($v) ?></span><?php endforeach ?>
  </div>
</div>

<?php if ($selected): $items = $byDate[$selected] ?? []; ?>
<div class="card">
  <div class="card-head">
    <h2><?= e(date('n월 j일', strtotime($selected))) ?> (<?= weekday_ko($selected) ?>)</h2>
    <a class="btn" href="<?= e(url("write.php?type=$type&date=$selected")) ?>">이 날짜로 작성</a>
  </div>
  <?php if (!$items): ?>
    <p class="muted">작성된 일지가 없습니다.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>작성자</th><?php if ($type === 'sales'): ?><th class="right">매출합계</th><?php endif ?><th>상태</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr class="clickable" onclick="location.href='<?= e(url('view.php?id=' . $it['id'])) ?>'">
          <td><?= e($it['author_name']) ?></td>
          <?php if ($type === 'sales'): ?><td class="right"><?= e(won($it['sales_amount'])) ?></td><?php endif ?>
          <td><?= status_badge($it['status']) ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  <?php endif ?>
</div>
<?php endif ?>
<?php layout_footer();
