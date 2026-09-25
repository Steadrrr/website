<?php
/**
 * 객실관리 › AR사용관리 (공무직 이상)
 *   ar.php?ym=2026-10[&date=2026-10-05]
 *   - 상단: 그 해 사용가능횟수(한도) · 계획횟수 · 사용횟수 · 잔여  (1명 하루 = 1회)
 *   - 달력: 날짜마다 계획 인원 입력(위쪽 '사용계획 저장'으로 한꺼번에 저장), 날짜별 사용 인원(월간 사용보고에서)
 *   - AR 사용보고는 월 1건 (줄마다 사용일·성명·사용시간) — 이 달 보고서 작성·보기
 *   - 날짜를 고르면 그 날 계획 메모와 사용 내역
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
$from = $first->format('Y-m-d');
$to = $last->format('Y-m-d');
$plans = ar_plans($from, $to);
$used = ar_used_by_day($from, $to, (int) $user['id']);
// 이 달의 월간 사용보고 (남의 임시저장 제외)
$st = $pdo->prepare("SELECT j.id, j.type, j.work_date, j.status, j.revision, j.author_id, j.submitted_at, u.name AS author_name,
                            COUNT(w.id) AS people, COUNT(DISTINCT w.work_date) AS days, COUNT(DISTINCT w.name) AS names, COALESCE(SUM(w.minutes), 0) AS minutes
                       FROM journals j JOIN users u ON u.id = j.author_id LEFT JOIN ar_workers w ON w.journal_id = j.id
                      WHERE j.type = 'arwork' AND j.work_date BETWEEN ? AND ? AND (j.status <> 'draft' OR j.author_id = ?)
                      GROUP BY j.id ORDER BY j.id");
$st->execute([$from, $to, $user['id']]);
$reports = $st->fetchAll();
$monthPlan = array_sum(array_map(fn($p) => (int) $p['people'], $plans));
$monthUsed = array_sum(array_map(fn($u) => in_array($u['status'], ['pending', 'approved'], true) ? $u['people'] : 0, $used));
$remain = $sum['quota'] - $sum['used'];
$mLabel = (int) $first->format('n') . '월';

layout_header('AR사용관리', 'ar');
?>
<div class="card">
  <div class="card-head">
    <h1>AR사용관리 <small class="muted"><?= $year ?>년 · 아르바이트 1명 하루 사용 = 1회</small></h1>
    <div class="actions no-margin no-print">
      <button class="btn ghost" onclick="window.print()">인쇄</button>
      <?php if ($reports): ?><a class="btn" href="<?= e(url('view.php?id=' . $reports[0]['id'])) ?>"><?= $mLabel ?> 사용보고 보기</a>
      <?php else: ?><a class="btn primary" href="<?= e(url('write.php?type=arwork&date=' . $from)) ?>">+ <?= $mLabel ?> 사용보고 작성</a><?php endif ?>
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
  <p class="muted small">사용보고는 <b>월 1건</b>입니다. 사용횟수는 결재중·결재완료된 월간 사용보고의 줄 수(1명 하루 = 1회)입니다 (임시저장·반려 제외). 사용가능횟수는 해마다 공무직 이상이 입력합니다.</p>
</div>

<form method="post" class="card">
  <?= csrf_field() ?><input type="hidden" name="target" value="plans">
  <div class="cal-nav ar-cal-nav">
    <a class="btn" href="<?= e(url('ar.php?ym=' . $first->modify('-1 month')->format('Y-m'))) ?>">‹ 이전달</a>
    <strong><?= e($first->format('Y년 n월')) ?></strong>
    <a class="btn" href="<?= e(url('ar.php?ym=' . $first->modify('+1 month')->format('Y-m'))) ?>">다음달 ›</a>
    <a class="btn ghost" href="<?= e(url('ar.php')) ?>">오늘</a>
    <span class="muted small">이 달 계획 <b><?= $monthPlan ?></b>회 · 사용 <b><?= $monthUsed ?></b>회</span>
    <button class="btn primary no-print ar-plan-save">이 달 사용계획 저장</button>
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
        $u = $used[$date] ?? null;
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
        <?php if ($u): ?><a class="chip st-<?= e($u['status']) ?>" href="<?= e(url("ar.php?ym=$ym&date=$date")) ?>">사용 <?= $u['people'] ?>명</a><?php endif ?>
      </div>
    <?php endfor ?>
  </div>
  <div class="legend">
    <?php foreach (JOURNAL_STATUS as $k => $v): ?><span class="chip st-<?= $k ?>"><?= e($v) ?></span><?php endforeach ?>
    <span class="muted small">계획 인원을 고친 뒤 위의 '이 달 사용계획 저장'을 누르세요. 날짜(숫자)를 누르면 그 날의 계획 메모와 사용 내역을 봅니다.</span>
  </div>
</form>

<div class="grid2">
  <section class="card">
    <h2><?= $mLabel ?> AR 사용보고 <small class="muted">월 1건</small></h2>
    <?php if ($reports): foreach ($reports as $r): ?>
      <p><?= journal_badges($r) ?> · 작성 <?= e($r['author_name']) ?> · 문서번호 <?= (int) $r['id'] ?></p>
      <p><b><?= (int) $r['people'] ?>회</b> (<?= (int) $r['days'] ?>일 · <?= (int) $r['names'] ?>명) · <?= e(ar_hm((int) $r['minutes'])) ?>
        <?php if ($monthPlan): ?><small class="muted">/ 계획 <?= $monthPlan ?>회</small><?php endif ?></p>
      <div class="actions"><a class="btn" href="<?= e(url('view.php?id=' . $r['id'])) ?>">보기 · 결재</a>
        <?php if (can_edit_journal($r, $user)): ?><a class="btn ghost" href="<?= e(url('write.php?id=' . $r['id'])) ?>">수정 (사용 내역 추가)</a><?php endif ?></div>
    <?php endforeach; else: ?>
      <p class="muted"><?= $mLabel ?> 사용보고가 아직 없습니다.<?= $monthPlan ? " 작성 화면에 계획 {$monthPlan}회만큼 줄이 미리 만들어집니다." : '' ?></p>
      <div class="actions"><a class="btn primary" href="<?= e(url('write.php?type=arwork&date=' . $from)) ?>"><?= $mLabel ?> 사용보고 작성</a></div>
    <?php endif ?>
  </section>

  <?php if ($selected): $p = ar_plans($selected, $selected)[$selected] ?? null;
      $st = $pdo->prepare("SELECT w.* FROM ar_workers w JOIN journals j ON j.id = w.journal_id
                            WHERE j.type = 'arwork' AND w.work_date = ? AND j.status <> 'rejected' AND (j.status <> 'draft' OR j.author_id = ?) ORDER BY w.sort_no");
      $st->execute([$selected, $user['id']]);
      $dayRows = $st->fetchAll(); ?>
  <form method="post" class="card">
    <?= csrf_field() ?><input type="hidden" name="target" value="plan"><input type="hidden" name="work_date" value="<?= e($selected) ?>">
    <h2><?= e(date('n월 j일', strtotime($selected))) ?> (<?= weekday_ko($selected) ?>)</h2>
    <div class="row">
      <label>계획 인원<input name="people" value="<?= (int) ($p['people'] ?? 0) ?: '' ?>" inputmode="numeric" class="num" placeholder="0"></label>
      <label>메모<input name="memo" value="<?= e($p['memo'] ?? '') ?>" maxlength="200" placeholder="예: 주말 만실 대비 객실 청소"></label>
      <button class="btn primary">계획 저장</button>
    </div>
    <?php if ($p): ?><p class="muted small">마지막 입력: <?= e($p['user_name'] ?? '') ?> · <?= e(substr($p['updated_at'], 0, 16)) ?></p><?php endif ?>
    <h3>사용 내역 <small class="muted"><?= count($dayRows) ?>명</small></h3>
    <?php if ($dayRows): ?>
      <table class="table ar-list"><tbody>
        <?php foreach ($dayRows as $w): ?><tr><td><b><?= e($w['name']) ?></b></td><td><?= e(substr((string) $w['start_time'], 0, 5)) ?>~<?= e(substr((string) $w['end_time'], 0, 5)) ?></td><td class="right"><?= e(ar_hm((int) $w['minutes'])) ?></td><td><?= e($w['task'] ?? '') ?></td></tr><?php endforeach ?>
      </tbody></table>
    <?php else: ?><p class="muted small">이 날 사용 내역이 없습니다. <?= $mLabel ?> 사용보고에 이 날짜 줄을 추가하세요.</p><?php endif ?>
  </form>
  <?php endif ?>
</div>
<?php layout_footer();
