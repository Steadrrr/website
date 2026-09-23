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

// 공지: 중요 공지 전부 + 최근 30일 공지 (합쳐서 최대 6개)
$notices = db()->query(
    "SELECT n.id, n.title, n.is_pinned, n.created_at, u.name AS author_name FROM notices n JOIN users u ON u.id = n.author_id
      WHERE n.is_pinned = 1 OR n.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      ORDER BY n.is_pinned DESC, n.created_at DESC LIMIT 6"
)->fetchAll();

$recent = db()->query(
    "SELECT j.*, u.name AS author_name
       FROM journals j JOIN users u ON u.id = j.author_id
      WHERE j.status <> 'draft'
      ORDER BY j.updated_at DESC LIMIT 10"
)->fetchAll();

// 왼쪽 프로필: 오늘 내가 쓴 일지 수, 결재 대기
$st = db()->prepare("SELECT COUNT(*) FROM journals WHERE author_id = ? AND work_date = ? AND status <> 'draft'");
$st->execute([$user['id'], $today]);
$myToday = (int) $st->fetchColumn();
$myWaiting = count(waiting_for_user($user));
// 오늘의 일정 (오늘 진행 중인 여러 날 일정 포함) — 공지사항 아래 카드
$todayEvents = events_between($today, $today);
// 다가오는 일정: 내일부터 2주 안에 시작하는 일정 (오늘 일정은 위 카드에 있으므로 제외)
$upcoming = array_slice(events_between(date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+14 days'))), 0, 6);
$upcoming = array_values(array_filter($upcoming, fn($ev) => $ev['start_date'] > $today));

layout_header('대시보드', 'home');
?>
<div class="dash-layout">
<aside class="card profile-card">
  <a href="<?= e(url('member_photo.php')) ?>" class="profile-photo" title="사진 바꾸기"><?= avatar($user, 'avatar avatar-xl') ?></a>
  <b class="profile-name"><?= e($user['name']) ?></b>
  <span class="profile-rank"><?= e(rank_name($user['rank_level'])) ?></span>
  <?php if ($aff = member_affiliation($user)): ?><span class="profile-aff"><?= e($aff) ?></span><?php endif ?>
  <span class="profile-position"><?= e($user['position'] ?: '보직 미지정') ?></span>
  <?php if (!$user['photo']): ?><a class="btn small" href="<?= e(url('member_photo.php')) ?>">사진 올리기</a><?php endif ?>
  <ul class="profile-stats">
    <li><span>오늘 작성한 일지</span><b><?= $myToday ?>건</b></li>
    <li><a href="<?= e(url('approvals.php')) ?>"><span>내 결재 대기</span><b class="<?= $myWaiting ? 'warn' : '' ?>"><?= $myWaiting ?>건</b></a></li>
  </ul>
  <div class="profile-links">
    <a href="<?= e(url('mypage.php')) ?>">내 정보</a> · <a href="<?= e(url('org.php')) ?>">조직도</a>
  </div>
  <div class="upcoming">
    <h4><a href="<?= e(url('schedule.php')) ?>">다가오는 일정 ›</a></h4>
    <?php foreach ($upcoming as $ev): ?>
      <a class="up-ev" href="<?= e(url('schedule.php?ym=' . substr(max($ev['start_date'], $today), 0, 7))) ?>" style="--c: <?= EVENT_CATEGORIES[$ev['category']][1] ?>">
        <i></i><span><b><?= e($ev['title']) ?></b><small><?= e(event_when($ev, true)) ?></small></span>
      </a>
    <?php endforeach ?>
    <?php if (!$upcoming): ?><p class="muted small">내일부터 2주 안에 일정이 없습니다.</p><?php endif ?>
  </div>
</aside>
<div class="dash-main">
<section class="card notice-board">
  <div class="card-head">
    <h2>공지사항</h2>
    <div class="actions no-margin">
      <?php if (can_write_notice($user)): ?><a class="btn small" href="<?= e(url('notice_edit.php')) ?>">+ 공지 쓰기</a><?php endif ?>
      <a class="btn small ghost" href="<?= e(url('notices.php')) ?>">전체 보기 ›</a>
    </div>
  </div>
  <?php if (!$notices): ?>
    <p class="muted small">최근 공지가 없습니다.</p>
  <?php else: ?>
    <ul class="notice-list">
      <?php foreach ($notices as $n): ?>
        <li class="<?= $n['is_pinned'] ? 'pinned' : '' ?>">
          <a href="<?= e(url('notices.php?id=' . $n['id'])) ?>"><?= $n['is_pinned'] ? '<span class="badge pin">중요</span> ' : '' ?><?= e($n['title']) ?><?= is_new($n['created_at']) ? ' <span class="new-dot">N</span>' : '' ?></a>
          <span class="muted small"><?= e($n['author_name']) ?> · <?= e(date('n/j', strtotime($n['created_at']))) ?></span>
        </li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>
</section>

<section class="card notice-board today-events">
  <div class="card-head">
    <h2>오늘의 일정 <small class="muted"><?= e(date('n월 j일', strtotime($today))) ?> (<?= weekday_ko($today) ?>)</small></h2>
    <div class="actions no-margin">
      <a class="btn small" href="<?= e(url('schedule.php?new=' . $today)) ?>">+ 일정 추가</a>
      <a class="btn small ghost" href="<?= e(url('schedule.php')) ?>">일정표 ›</a>
    </div>
  </div>
  <?php if (!$todayEvents): ?>
    <p class="muted small">오늘 일정이 없습니다.</p>
  <?php else: ?>
    <ul class="notice-list">
      <?php foreach ($todayEvents as $ev): [$catLabel, $catColor] = EVENT_CATEGORIES[$ev['category']]; ?>
        <li>
          <a href="<?= e(url('schedule.php?ym=' . substr($today, 0, 7))) ?>" style="--c: <?= $catColor ?>">
            <span class="badge ev-badge"><?= e($catLabel) ?></span> <?= e($ev['title']) ?>
            <?= $ev['location'] ? '<small class="muted"> · ' . e($ev['location']) . '</small>' : '' ?>
          </a>
          <span class="muted small nowrap"><?= e(event_when($ev)) ?></span>
        </li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>
</section>

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
    <thead><tr><th>일자</th><th>구분</th><th>작성자</th><th>상태</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($recent as $j): ?>
      <tr onclick="location.href='<?= e(url('view.php?id=' . $j['id'])) ?>'" class="clickable">
        <td><?= e($j['work_date']) ?></td>
        <td><?= e(JOURNAL_TYPES[$j['type']]) ?></td>
        <td><?= e($j['author_name']) ?></td>
        <td><?= journal_badges($j) ?></td>
        <td class="right"><?= can_edit_journal($j, $user) ? edit_button($j, 'btn small') : '' ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$recent): ?><tr><td colspan="5" class="muted center">아직 작성된 일지가 없습니다.</td></tr><?php endif ?>
    </tbody>
  </table>
</section>
</div><!-- /dash-main -->
</div><!-- /dash-layout -->

<script>window.SALES_API = <?= json_encode(url('api/sales.php')) ?>;</script>
<?php layout_footer([
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js',
    url('assets/dashboard.js'),
]);
