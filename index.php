<?php
require __DIR__ . '/app/bootstrap.php';

$user = require_login();

$today     = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$monthStart = date('Y-m-01');

$kpi = [
    '오늘 매출'    => sales_total($today, $today),
    '이번 주 매출' => sales_total($weekStart, $today),
    '이번 달 매출' => sales_total($monthStart, $today),
];

// 오늘 작성된 일지 현황
$st = db()->prepare("SELECT type, COUNT(*) AS n FROM journals WHERE work_date = ? AND status <> 'draft' GROUP BY type");
$st->execute([$today]);
$todayCounts = array_column($st->fetchAll(), 'n', 'type');

$waiting = waiting_for_user($user, 5);

$recent = db()->query(
    "SELECT j.id, j.type, j.work_date, j.status, u.name AS author_name
       FROM journals j JOIN users u ON u.id = j.author_id
      WHERE j.status <> 'draft'
      ORDER BY j.updated_at DESC LIMIT 10"
)->fetchAll();

layout_header('대시보드', 'home');
?>
<div class="kpis">
  <?php foreach ($kpi as $label => $amount): ?>
    <div class="kpi"><span><?= e($label) ?></span><b><?= e(won($amount)) ?></b></div>
  <?php endforeach ?>
  <div class="kpi"><span>내 결재 대기</span><b><a href="<?= e(url('approvals.php')) ?>"><?= count(waiting_for_user($user)) ?>건</a></b></div>
</div>

<section class="card">
  <div class="card-head">
    <h2>매출 현황</h2>
    <div class="tabs" id="periodTabs">
      <button data-period="day" class="on">일별</button>
      <button data-period="week">주별</button>
      <button data-period="month">월별</button>
    </div>
  </div>
  <div class="chart-wrap"><canvas id="salesChart"></canvas></div>
  <p class="muted small">
    일별 최근 30일 · 주별 최근 12주(월요일 시작) · 월별 최근 12개월.
    <?= config('chart_statuses') === ['approved'] ? '결재완료된 매출보고만 집계합니다.' : '결재중·결재완료 매출보고를 집계합니다.' ?>
  </p>
</section>

<div class="grid2">
  <section class="card">
    <h2>오늘(<?= e(date('n/j')) ?>) 일지 현황</h2>
    <ul class="list">
      <?php foreach (JOURNAL_TYPES as $type => $label): ?>
        <li>
          <a href="<?= e(url("journal.php?type=$type&date=$today")) ?>"><?= e($label) ?></a>
          <?php $n = (int) ($todayCounts[$type] ?? 0) ?>
          <span class="<?= $n ? '' : 'muted' ?>"><?= $n ? $n . '건 작성' : '미작성' ?></span>
        </li>
      <?php endforeach ?>
    </ul>
  </section>

  <section class="card">
    <h2>내 결재 대기</h2>
    <?php if (!$waiting): ?>
      <p class="muted">결재할 문서가 없습니다.</p>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($waiting as $j): ?>
          <li><a href="<?= e(url('view.php?id=' . $j['id'])) ?>"><?= e(JOURNAL_TYPES[$j['type']]) ?> · <?= e($j['work_date']) ?></a><span><?= e($j['author_name']) ?></span></li>
        <?php endforeach ?>
      </ul>
    <?php endif ?>
  </section>
</div>

<section class="card">
  <h2>최근 일지</h2>
  <table class="table">
    <thead><tr><th>일자</th><th>구분</th><th>작성자</th><th>상태</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $j): ?>
      <tr onclick="location.href='<?= e(url('view.php?id=' . $j['id'])) ?>'" class="clickable">
        <td><?= e($j['work_date']) ?></td>
        <td><?= e(JOURNAL_TYPES[$j['type']]) ?></td>
        <td><?= e($j['author_name']) ?></td>
        <td><?= status_badge($j['status']) ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$recent): ?><tr><td colspan="4" class="muted center">아직 작성된 일지가 없습니다.</td></tr><?php endif ?>
    </tbody>
  </table>
</section>

<script>window.SALES_API = <?= json_encode(url('api/sales.php')) ?>;</script>
<?php layout_footer([
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js',
    url('assets/dashboard.js'),
]);
