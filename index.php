<?php
require __DIR__ . '/app/bootstrap.php';

$user = require_login();

$today = date('Y-m-d');
$initial = dashboard_series('day'); // 첫 화면 숫자 (그래프는 JS가 API로 다시 불러옴)

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
<div class="card-head">
  <h1>판매 현황</h1>
  <div class="tabs" id="periodTabs">
    <button data-period="day" class="on">일별</button>
    <button data-period="week">주별</button>
    <button data-period="month">월별</button>
  </div>
</div>

<div class="grid2">
  <section class="card">
    <h2>입장권 판매 <small class="muted" data-current-label><?= e($initial['current']['label']) ?></small></h2>
    <div class="kpis k3">
      <div class="kpi"><span>무료</span><b><span data-kpi="free"><?= number_format($initial['current']['free']) ?></span>매</b></div>
      <div class="kpi"><span>유료</span><b><span data-kpi="paid"><?= number_format($initial['current']['paid']) ?></span>매</b></div>
      <div class="kpi total"><span>합계</span><b><span data-kpi="total"><?= number_format($initial['current']['total']) ?></span>매</b></div>
    </div>
    <div class="chart-wrap"><canvas id="ticketChart"></canvas></div>
  </section>

  <section class="card">
    <h2>객실 판매 <small class="muted" data-current-label><?= e($initial['current']['label']) ?></small></h2>
    <div class="kpis k2">
      <div class="kpi"><span>판매 객실</span><b><span data-kpi="rooms"><?= number_format($initial['current']['rooms']) ?></span>실</b></div>
      <div class="kpi"><span>입실 인원</span><b><span data-kpi="guests"><?= number_format($initial['current']['guests']) ?></span>명</b></div>
    </div>
    <div class="chart-wrap"><canvas id="roomChart"></canvas></div>
  </section>
</div>
<p class="muted small">
  일별 최근 30일 · 주별 최근 12주(월요일 시작) · 월별 최근 12개월.
  <?= config('chart_statuses') === ['approved'] ? '결재완료된 매출보고만 집계합니다.' : '결재중·결재완료 매출보고를 집계합니다.' ?>
</p>

<div class="grid2">
  <section class="card">
    <h2>오늘(<?= e(date('n/j')) ?>) 일지 현황</h2>
    <ul class="list">
      <?php foreach (DAILY_TYPES as $type): $label = JOURNAL_TYPES[$type]; ?>
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
