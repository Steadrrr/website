<?php
/** 일지 달력 조회: journal.php?type=daily|sales|facility&ym=2026-09&date=2026-09-22 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();

$type = $_GET['type'] ?? 'daily';
if ($type === 'attendance') redirect('attendance.php');
if (!isset(JOURNAL_TYPES[$type])) $type = 'daily';
if ($type === 'arwork') redirect('ar.php' . (valid_date($_GET['date'] ?? '') ? '?ym=' . substr($_GET['date'], 0, 7) : '')); // AR 사용보고는 AR사용관리 달력에서
if ($menu = journal_menu($type)) require_menu($user, $menu);

$selected = $_GET['date'] ?? '';
if (!valid_date($selected)) $selected = '';
$ym = $_GET['ym'] ?? ($selected ? substr($selected, 0, 7) : date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $ym) || !valid_date("$ym-01")) $ym = date('Y-m');
if ($selected === '' && $ym === date('Y-m')) $selected = date('Y-m-d');

// 시설점검일지는 관리팀별 보기 (team=0 전체)
$teamId = $type === 'facility' ? (int) ($_GET['team'] ?? 0) : 0;
if ($teamId && !isset(teams_all()[$teamId])) $teamId = 0;
$tq = $teamId ? "&team=$teamId" : '';

$first = new DateTimeImmutable("$ym-01");
$last  = $first->modify('last day of this month');
$prev  = $first->modify('-1 month')->format('Y-m');
$next  = $first->modify('+1 month')->format('Y-m');

// 이 달의 일지 (남의 임시저장은 제외)
$st = db()->prepare(
    "SELECT j.id, j.type, j.work_date, j.status, j.author_id, j.team_id, j.revision, j.submitted_at, u.name AS author_name,
            " . SALES_AMOUNT_SQL . " AS sales_amount,
            (SELECT COUNT(*) FROM program_sessions p WHERE p.journal_id = j.id) AS prog_sessions,
            (SELECT COALESCE(SUM(p.total), 0) FROM program_sessions p WHERE p.journal_id = j.id) AS prog_people
       FROM journals j JOIN users u ON u.id = j.author_id
      WHERE j.type = ? AND j.work_date BETWEEN ? AND ?
        AND (j.status <> 'draft' OR j.author_id = ? OR j.type IN ('" . implode("','", SHARED_DRAFT_TYPES) . "'))" . ($teamId ? ' AND j.team_id = ?' : '') . "
      ORDER BY j.work_date, j.id"
);
$st->execute([$type, $first->format('Y-m-d'), $last->format('Y-m-d'), $user['id'], ...($teamId ? [$teamId] : [])]);
$byDate = [];
foreach ($st as $row) {
    $byDate[$row['work_date']][] = $row;
}

layout_header(JOURNAL_TYPES[$type], journal_nav_key($type));
$canWrite = can_write_type($user, $type); // 상품권 입고·금고점검은 공무직 이상만 작성
?>
<div class="card">
  <div class="card-head">
    <h1><?= e(JOURNAL_TYPES[$type]) ?></h1>
    <div class="actions no-margin no-print">
      <button class="btn ghost" onclick="window.print()">인쇄</button>
      <?php if ($canWrite): ?><a class="btn primary" href="<?= e(url("write.php?type=$type$tq&date=" . ($selected ?: date('Y-m-d')))) ?>">+ 작성</a><?php endif ?>
    </div>
  </div>

  <?php if ($type === 'facility'): ?>
  <div class="tabs team-tabs">
    <a href="<?= e(url("journal.php?type=facility&ym=$ym")) ?>" class="<?= $teamId ? '' : 'on' ?>">전체</a>
    <?php foreach (teams_all() as $t): ?>
      <a href="<?= e(url("journal.php?type=facility&team={$t['id']}&ym=$ym")) ?>" class="<?= $teamId === (int) $t['id'] ? 'on' : '' ?>"><?= e($t['name']) ?></a>
    <?php endforeach ?>
    <a href="<?= e(url('facilities.php' . ($teamId ? "?team=$teamId" : ''))) ?>" class="link-tab">시설물 목록 ›</a>
  </div>
  <?php endif ?>

  <div class="cal-nav">
    <a class="btn" href="<?= e(url("journal.php?type=$type$tq&ym=$prev")) ?>">‹ 이전달</a>
    <strong><?= e($first->format('Y년 n월')) ?></strong>
    <a class="btn" href="<?= e(url("journal.php?type=$type$tq&ym=$next")) ?>">다음달 ›</a>
    <a class="btn ghost" href="<?= e(url("journal.php?type=$type$tq")) ?>">오늘</a>
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
      <a class="<?= implode(' ', $cls) ?>" href="<?= e(url("journal.php?type=$type$tq&ym=$ym&date=$date")) ?>">
        <span class="num"><?= $d->format('j') ?></span>
        <?php if (in_array($type, SALE_DOC_TYPES, true) && $items): ?>
          <span class="amt"><?= e(number_format(array_sum(array_column($items, 'sales_amount')))) ?></span>
        <?php elseif (is_program_type($type) && $items): ?>
          <span class="amt"><?= (int) array_sum(array_column($items, 'prog_sessions')) ?>회 · <?= number_format(array_sum(array_column($items, 'prog_people'))) ?>명</span>
        <?php endif ?>
        <?php foreach (array_slice($items, 0, 3) as $it): ?>
          <span class="chip st-<?= e($it['status']) ?>"><?= $it['revision'] ? '✎' : '' ?><?= e($it['author_name']) ?><?= $type === 'facility' && !$teamId && $it['team_id'] ? '·' . e(mb_substr(team_name((int) $it['team_id']), 0, 2)) : '' ?></span>
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
    <?php if ($canWrite): ?><a class="btn" href="<?= e(url("write.php?type=$type$tq&date=$selected")) ?>">이 날짜로 작성</a><?php endif ?>
  </div>
  <?php if (!$items): ?>
    <p class="muted">작성된 일지가 없습니다.</p>
  <?php else: ?>
    <table class="table">
      <thead><tr><th>작성자</th><?php if ($type === 'facility'): ?><th>관리팀</th><?php endif ?><?php if (in_array($type, SALE_DOC_TYPES, true)): ?><th class="right"><?= $type === 'rooms' ? '객실 매출' : '매출합계' ?></th><?php endif ?><?php if (is_program_type($type)): ?><th class="right">회차</th><th class="right">인원</th><?php endif ?><th>상태</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr class="clickable" onclick="location.href='<?= e(url('view.php?id=' . $it['id'])) ?>'">
          <td><?= e($it['author_name']) ?></td>
          <?php if ($type === 'facility'): ?><td><?= e(team_name($it['team_id'] ? (int) $it['team_id'] : null)) ?></td><?php endif ?>
          <?php if (in_array($type, SALE_DOC_TYPES, true)): ?><td class="right"><?= e(won($it['sales_amount'])) ?></td><?php endif ?>
          <?php if (is_program_type($type)): ?><td class="right"><?= (int) $it['prog_sessions'] ?>회</td><td class="right"><?= number_format($it['prog_people']) ?>명</td><?php endif ?>
          <td><?= journal_badges($it) ?></td>
          <td class="right no-print"><?= can_edit_journal($it, $user) ? edit_button($it, 'btn small') : '' ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
    </table>
  <?php endif ?>
</div>
<?php endif ?>
<?php layout_footer();
