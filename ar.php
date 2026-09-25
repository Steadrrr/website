<?php
/**
 * 객실관리 › AR사용관리 (공무직 이상)
 *   ar.php?ym=2026-10[&date=2026-10-05]
 *   - 상단: 그 해 사용가능횟수(한도) · 계획횟수 · 사용횟수 · 잔여  (1명 하루 = 1회)
 *   - 달력: 날짜마다 계획 인원 입력(한꺼번에 저장), 사용보고(결재) 상태
 *   - 날짜를 고르면 계획 메모와 그 날 AR 사용보고 작성·보기
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
require_menu($user, 'room');
if (!can_ar($user)) abort(403, 'AR사용관리는 공무직 이상만 사용할 수 있습니다.');
$pdo = db();

$selected = valid_date($_GET['date'] ?? '') ? $_GET['date'] : '';
$ym = $_GET['ym'] ?? ($selected ? substr($selected, 0, 7) : date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $ym) || !valid_date("$ym-01")) $ym = date('Y-m');
if ($selected === '' && $ym === date('Y-m')) $selected = date('Y-m-d');
$first = new DateTimeImmutable("$ym-01");
$last = $first->modify('last day of this month');
$year = (int) $first->format('Y');
$self = fn(array $q = []) => 'ar.php?' . http_build_query(['ym' => $ym] + ($selected ? ['date' => $selected] : []) + $q);

if (is_post()) {
    csrf_verify();
    $target = post('target');
    if ($target === 'quota') {
        if (!can_ar_quota($user)) abort(403, '사용가능횟수는 공무직 이상이 정합니다.');
        $y = (int) post('year');
        if ($y < 2000 || $y > 2100) abort(400, '잘못된 값입니다.');
        setting_set("ar_quota_$y", (string) to_int(post('quota')));
        flash("{$y}년 AR 사용가능횟수를 " . number_format(to_int(post('quota'))) . '회로 저장했습니다.', 'success');
    } elseif ($target === 'plans') { // 달력의 계획 인원 한꺼번에
        $n = 0;
        foreach ((array) ($_POST['plans'] ?? []) as $date => $people) {
            if (!valid_date((string) $date) || substr((string) $date, 0, 7) !== $ym) continue;
            $people = min(999, to_int($people));
            $old = ar_plans($date, $date)[$date] ?? null;
            if ((int) ($old['people'] ?? 0) === $people) continue;
            if ($people === 0 && trim((string) ($old['memo'] ?? '')) === '') {
                $pdo->prepare('DELETE FROM ar_plans WHERE work_date = ?')->execute([$date]);
            } else {
                $pdo->prepare('INSERT INTO ar_plans (work_date, people, user_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE people = VALUES(people), user_id = VALUES(user_id)')
                    ->execute([$date, $people, $user['id']]);
            }
            $n++;
        }
        flash($n ? "사용계획 {$n}일을 저장했습니다." : '바뀐 계획이 없습니다.', $n ? 'success' : 'info');
    } elseif ($target === 'plan') { // 선택한 날의 계획 인원·메모
        $date = post('work_date');
        if (!valid_date($date)) abort(400, '잘못된 날짜입니다.');
        $people = min(999, to_int(post('people')));
        $memo = mb_substr(post('memo'), 0, 200);
        if ($people === 0 && $memo === '') {
            $pdo->prepare('DELETE FROM ar_plans WHERE work_date = ?')->execute([$date]);
            flash(date('n월 j일', strtotime($date)) . ' 사용계획을 지웠습니다.', 'success');
        } else {
            $pdo->prepare('INSERT INTO ar_plans (work_date, people, memo, user_id) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE people = VALUES(people), memo = VALUES(memo), user_id = VALUES(user_id)')
                ->execute([$date, $people, $memo !== '' ? $memo : null, $user['id']]);
            flash(date('n월 j일', strtotime($date)) . " 사용계획 {$people}명을 저장했습니다.", 'success');
        }
    }
    redirect($self());
}

$sum = ar_year_summary($year);
$plans = ar_plans($first->format('Y-m-d'), $last->format('Y-m-d'));
// 이 달의 AR 사용보고 (남의 임시저장 제외)
$st = $pdo->prepare("SELECT j.id, j.work_date, j.status, j.revision, j.author_id, u.name AS author_name,
                            COUNT(w.id) AS people, COALESCE(SUM(w.minutes), 0) AS minutes, GROUP_CONCAT(w.name ORDER BY w.sort_no SEPARATOR ', ') AS names
                       FROM journals j JOIN users u ON u.id = j.author_id LEFT JOIN ar_workers w ON w.journal_id = j.id
                      WHERE j.type = 'arwork' AND j.work_date BETWEEN ? AND ? AND (j.status <> 'draft' OR j.author_id = ?)
                      GROUP BY j.id ORDER BY j.work_date, j.id");
$st->execute([$first->format('Y-m-d'), $last->format('Y-m-d'), $user['id']]);
$reports = [];
foreach ($st as $r) $reports[$r['work_date']] = $r;
$counted = fn($r) => in_array($r['status'], ['pending', 'approved'], true);
$monthPlan = array_sum(array_map(fn($p) => (int) $p['people'], $plans));
$monthUsed = array_sum(array_map(fn($r) => $counted($r) ? (int) $r['people'] : 0, $reports));
$remain = $sum['quota'] - $sum['used'];

layout_header('AR사용관리', 'ar');
?>
<div class="card">
  <div class="card-head">
    <h1>AR사용관리 <small class="muted"><?= $year ?>년 · 아르바이트 1명 하루 사용 = 1회</small></h1>
    <div class="actions no-margin no-print">
      <button class="btn ghost" onclick="window.print()">인쇄</button>
      <a class="btn primary" href="<?= e(url('write.php?type=arwork&date=' . ($selected ?: date('Y-m-d')))) ?>">+ AR 사용보고</a>
    </div>
  </div>
  <div class="kpis k4 ar-kpis">
    <div class="kpi"><span>사용가능횟수</span><b><?= number_format($sum['quota']) ?>회</b>
      <?php if (can_ar_quota($user)): ?>
        <form method="post" class="ar-quota-form no-print"><?= csrf_field() ?><input type="hidden" name="target" value="quota"><input type="hidden" name="year" value="<?= $year ?>">
          <input name="quota" value="<?= $sum['quota'] ?: '' ?>" inputmode="numeric" class="num tiny" placeholder="0" aria-label="<?= $year ?>년 사용가능횟수"><button class="btn small">저장</button></form>
      <?php elseif (!$sum['quota']): ?><small class="muted">공무직 이상이 입력</small><?php endif ?></div>
    <div class="kpi"><span>계획횟수</span><b><?= number_format($sum['planned']) ?>회</b>
      <small class="<?= $sum['quota'] && $sum['planned'] > $sum['quota'] ? 'warn' : 'muted' ?>"><?= $sum['quota'] ? ($sum['planned'] > $sum['quota'] ? '한도 ' . number_format($sum['planned'] - $sum['quota']) . '회 초과' : '계획 후 여유 ' . number_format($sum['quota'] - $sum['planned']) . '회') : '' ?></small></div>
    <div class="kpi"><span>사용횟수</span><b><?= number_format($sum['used']) ?>회</b><small class="muted">결재완료 <?= number_format($sum['approved']) ?>회 · <?= e(ar_hm($sum['minutes'])) ?></small></div>
    <div class="kpi total"><span>잔여 (가능 − 사용)</span><b class="<?= $remain < 0 ? 'warn' : '' ?>"><?= number_format($remain) ?>회</b>
      <?php if ($sum['quota']): ?><div class="ar-bar" title="사용 <?= $sum['used'] ?> / 계획 <?= $sum['planned'] ?> / 가능 <?= $sum['quota'] ?>">
        <i class="plan" style="width: <?= min(100, round($sum['planned'] / $sum['quota'] * 100)) ?>%"></i><i class="used" style="width: <?= min(100, round($sum['used'] / $sum['quota'] * 100)) ?>%"></i></div><?php endif ?></div>
  </div>
  <p class="muted small">사용횟수는 결재중·결재완료된 AR 사용보고의 인원 합계입니다 (임시저장·반려 제외). 사용가능횟수는 해마다 공무직 이상이 입력합니다.</p>
</div>

<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="target" value="plans">
  <div class="cal-nav">
    <a class="btn" href="<?= e(url('ar.php?ym=' . $first->modify('-1 month')->format('Y-m'))) ?>">‹ 이전달</a>
    <strong><?= e($first->format('Y년 n월')) ?></strong>
    <a class="btn" href="<?= e(url('ar.php?ym=' . $first->modify('+1 month')->format('Y-m'))) ?>">다음달 ›</a>
    <a class="btn ghost" href="<?= e(url('ar.php')) ?>">오늘</a>
    <span class="muted small">이 달 계획 <b><?= $monthPlan ?></b>회 · 사용 <b><?= $monthUsed ?></b>회</span>
  </div>
  <div class="calendar ar-calendar">
    <?php foreach (['일', '월', '화', '수', '목', '금', '토'] as $i => $w): ?>
      <div class="cal-h <?= $i === 0 ? 'sun' : ($i === 6 ? 'sat' : '') ?>"><?= $w ?></div>
    <?php endforeach ?>
    <?php for ($i = 0, $pad = (int) $first->format('w'); $i < $pad; $i++): ?><div class="cal-d empty"></div><?php endfor ?>
    <?php for ($d = $first; $d <= $last; $d = $d->modify('+1 day')):
        $date = $d->format('Y-m-d');
        $w = (int) $d->format('w');
        $p = $plans[$date] ?? null;
        $r = $reports[$date] ?? null;
        $cls = ['cal-d'];
        if ($w === 0) $cls[] = 'sun';
        if ($w === 6) $cls[] = 'sat';
        if ($date === date('Y-m-d')) $cls[] = 'today';
        if ($date === $selected) $cls[] = 'selected';
    ?>
      <div class="<?= implode(' ', $cls) ?>">
        <a class="num" href="<?= e(url("ar.php?ym=$ym&date=$date")) ?>"><?= $d->format('j') ?></a>
        <label class="ar-plan-in" title="계획 인원<?= $p && $p['memo'] ? ' · ' . e($p['memo']) : '' ?>">계획
          <input name="plans[<?= $date ?>]" value="<?= $p && $p['people'] ? (int) $p['people'] : '' ?>" inputmode="numeric" class="num" placeholder="-">명</label>
        <?php if ($p && $p['memo']): ?><span class="ar-memo" title="<?= e($p['memo']) ?>">📝</span><?php endif ?>
        <?php if ($r): ?><a class="chip st-<?= e($r['status']) ?>" href="<?= e(url('view.php?id=' . $r['id'])) ?>">사용 <?= (int) $r['people'] ?>명</a><?php endif ?>
      </div>
    <?php endfor ?>
  </div>
  <div class="legend">
    <?php foreach (JOURNAL_STATUS as $k => $v): ?><span class="chip st-<?= $k ?>"><?= e($v) ?></span><?php endforeach ?>
    <span class="muted small">날짜(숫자)를 누르면 그 날의 계획 메모와 사용보고를 봅니다.</span>
  </div>
  <div class="actions no-print"><button class="btn primary">이 달 사용계획 저장</button></div>
</form>

<?php if ($selected): $p = ar_plans($selected, $selected)[$selected] ?? null; $r = $reports[$selected] ?? (function () use ($pdo, $selected, $user) {
    $st = $pdo->prepare("SELECT j.id, j.status, j.revision, j.author_id, u.name AS author_name FROM journals j JOIN users u ON u.id = j.author_id
                          WHERE j.type = 'arwork' AND j.work_date = ? AND (j.status <> 'draft' OR j.author_id = ?)");
    $st->execute([$selected, $user['id']]);
    return $st->fetch() ?: null;
})(); ?>
<div class="grid2">
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="target" value="plan"><input type="hidden" name="work_date" value="<?= e($selected) ?>">
    <h2><?= e(date('n월 j일', strtotime($selected))) ?> (<?= weekday_ko($selected) ?>) 사용계획</h2>
    <div class="row">
      <label>계획 인원<input name="people" value="<?= (int) ($p['people'] ?? 0) ?: '' ?>" inputmode="numeric" class="num" placeholder="0"></label>
      <label>메모<input name="memo" value="<?= e($p['memo'] ?? '') ?>" maxlength="200" placeholder="예: 주말 만실 대비 객실 청소"></label>
    </div>
    <?php if ($p): ?><p class="muted small">마지막 입력: <?= e($p['user_name'] ?? '') ?> · <?= e(substr($p['updated_at'], 0, 16)) ?></p><?php endif ?>
    <div class="actions"><button class="btn primary">계획 저장</button></div>
  </form>
  <section class="card">
    <h2>AR 사용보고</h2>
    <?php if ($r): ?>
      <p><?= journal_badges($r) ?> · 작성 <?= e($r['author_name']) ?><?php if (isset($r['people'])): ?> · <b><?= (int) $r['people'] ?>명</b> · <?= e(ar_hm((int) $r['minutes'])) ?><?php endif ?></p>
      <?php if (!empty($r['names'])): ?><p class="small"><?= e($r['names']) ?></p><?php endif ?>
      <div class="actions"><a class="btn" href="<?= e(url('view.php?id=' . $r['id'])) ?>">보기 · 결재</a>
        <?php if (can_edit_journal($r + ['type' => 'arwork'], $user)): ?><a class="btn ghost" href="<?= e(url('write.php?id=' . $r['id'])) ?>">수정</a><?php endif ?></div>
    <?php else: ?>
      <p class="muted">이 날 작성된 AR 사용보고가 없습니다.<?= $p && $p['people'] ? " (계획 {$p['people']}명)" : '' ?></p>
      <div class="actions"><a class="btn primary" href="<?= e(url('write.php?type=arwork&date=' . $selected)) ?>">이 날짜로 사용보고 작성</a></div>
    <?php endif ?>
  </section>
</div>
<?php endif ?>

<?php if ($reports): ?>
<section class="card">
  <h2><?= e($first->format('n월')) ?> AR 사용보고</h2>
  <div class="table-scroll">
  <table class="table ar-list">
    <thead><tr><th>일자</th><th class="right">계획</th><th class="right">사용</th><th>아르바이트</th><th class="right">근무시간</th><th>작성</th><th>상태</th></tr></thead>
    <tbody>
    <?php foreach ($reports as $date => $r): ?>
      <tr class="clickable" onclick="location.href='<?= e(url('view.php?id=' . $r['id'])) ?>'">
        <td class="nowrap"><?= e($date) ?> (<?= weekday_ko($date) ?>)</td><td class="right"><?= (int) ($plans[$date]['people'] ?? 0) ?>명</td><td class="right"><b><?= (int) $r['people'] ?>명</b></td>
        <td><?= e($r['names'] ?? '') ?></td><td class="right nowrap"><?= e(ar_hm((int) $r['minutes'])) ?></td><td><?= e($r['author_name']) ?></td><td><?= journal_badges($r) ?></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif ?>
<?php layout_footer();
