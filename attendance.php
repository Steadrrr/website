<?php
/**
 * 근태관리 (사원)
 *   attendance.php?ym=2026-09[&team=1][&user=5]      근태 달력 (모든 근무자, 팀·사람별 조회)
 *   attendance.php?view=sheet&user=5&ym=2026-09        개인 월간 근태표 (&export=xlsx 엑셀, 인쇄)
 *   attendance.php?api=check&...                       입력 창의 실시간 검사 (연차·병가 현황, 진단서 안내)
 *   attendance.php?file=문서번호                       진단서 등 첨부 열람 (본인·공무직 이상)
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/xlsx.php';

$me = require_login();
require_menu($me, 'att');
$pdo = db();
$isMgr = att_is_manager($me);

$ym = $_GET['ym'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ym) || !valid_date("$ym-01")) $ym = date('Y-m');
$view = ($_GET['view'] ?? '') === 'sheet' ? 'sheet' : 'month';

/* ───────────── 첨부 열람 ───────────── */
if (isset($_GET['file'])) {
    $att = att_find((int) $_GET['file']) ?? abort(404, '근태 문서를 찾을 수 없습니다.');
    $worker = att_user((int) $att['user_id']);
    if (!$worker || !att_can_view($me, $worker)) abort(403, '첨부 파일은 본인과 공무직 이상만 볼 수 있습니다.');
    $path = APP_ROOT . '/' . $att['attachment'];
    if (!$att['attachment'] || !is_file($path)) abort(404, '첨부 파일이 없습니다.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($path));
    header("Content-Disposition: inline; filename=\"attendance-{$att['journal_id']}." . pathinfo($path, PATHINFO_EXTENSION) . '"');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

/* ───────────── 실시간 검사 (입력 창) ───────────── */
if (($_GET['api'] ?? '') === 'check') {
    header('Content-Type: application/json; charset=utf-8');
    $worker = att_user($isMgr ? (int) ($_GET['user'] ?? 0) : (int) $me['id']);
    $out = ['errors' => [], 'notes' => [], 'amount' => '', 'summary' => null];
    if (!$worker) {
        $out['errors'][] = '근무자를 고르세요.';
    } else {
        $kind = (string) ($_GET['kind'] ?? '');
        $sum = att_summary($worker);
        $out['summary'] = [
            'set' => $sum['set'], 'period' => $sum['period'] ? implode(' ~ ', $sum['period']) : '',
            'accrued' => $sum['accrued'] . '일', 'max' => $sum['max'] . '일', 'used' => att_fmt_min($sum['used_min']),
            'remain' => att_fmt_min($sum['remain_min']), 'can_use' => att_fmt_min($sum['can_use_min']),
            'sick' => "{$sum['sick_used']}일 / {$sum['sick_limit']}일",
            'work' => implode('~', att_work_hours($worker)) . ' · 휴무 ' . att_off_label($worker),
            'configured' => att_work_configured($worker),
        ];
        if ($err = att_entry_error($me, $worker, $kind)) {
            $out['errors'][] = $err;
        } elseif (valid_date($_GET['start_date'] ?? '')) {
            [$row, $errors, $notes] = att_validate($worker, [
                'kind' => $kind, 'start_date' => $_GET['start_date'], 'end_date' => (string) ($_GET['end_date'] ?? ''),
                'start_time' => (string) ($_GET['start_time'] ?? ''), 'end_time' => (string) ($_GET['end_time'] ?? ''),
            ]);
            $out['errors'] = $errors;
            $out['notes'] = $notes;
            if ($row) {
                $out['amount'] = att_is_time_kind($kind) ? att_fmt_min($row['minutes'], false) : $row['days'] . '일 (근무일 기준)';
                if (in_array($kind, ['early', 'out'], true) && ($br = att_break($worker))
                    && $row['start_time'] < $br[1] && $row['end_time'] > $br[0]) $out['amount'] .= " (점심 휴게 {$br[0]}~{$br[1]} 제외)";
                $elapsed = att_time_min($row['end_time'] ?? '00:00') - att_time_min($row['start_time'] ?? '00:00');
                if ($kind === 'overtime' && $elapsed > $row['minutes']) $out['amount'] .= ' (휴게 ' . att_fmt_min($elapsed - $row['minutes'], false) . ' 제외)';
                $out['cert'] = (bool) $row['cert_required'];
            }
        }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ───────────── 근태 입력 ───────────── */
if (is_post()) {
    csrf_verify();
    $worker = att_user($isMgr ? (int) post('worker_id') : (int) $me['id']) ?? abort(404, '근무자를 찾을 수 없습니다.');
    $kind = post('kind');
    $back = 'attendance.php?ym=' . substr(valid_date(post('start_date')) ? post('start_date') : "$ym-01", 0, 7);
    if ($err = att_entry_error($me, $worker, $kind)) {
        flash($err, 'error');
        redirect($back);
    }
    [$row, $errors, $notes] = att_validate($worker, [
        'kind' => $kind, 'start_date' => post('start_date'), 'end_date' => post('end_date'),
        'start_time' => post('start_time'), 'end_time' => post('end_time'),
    ]);
    $file = null;
    if (!$errors) {
        [$file, $upErr] = att_store_attachment($_FILES['attachment'] ?? []);
        if ($upErr) $errors[] = $upErr;
    }
    if ($errors) {
        foreach ($errors as $m) flash($m, 'error');
        redirect($back);
    }
    $id = att_create($me, $worker, $row, mb_substr(post('reason'), 0, 1000), $file);
    $j = journal_find($id);
    flash("{$worker['name']} " . att_kind_name($kind) . ' ' . att_when($row) . ' — ' . ($j['status'] === 'approved' ? '결재완료' : '결재를 올렸습니다.'), 'success');
    foreach ($notes as $m) flash($m, 'info');
    if ($row['cert_required'] && !$file) flash('진단서가 첨부되지 않았습니다. 이 문서 화면에서 나중에 첨부할 수 있습니다.', 'error');
    redirect('view.php?id=' . $id);
}

$teams = teams_all();
$teamId = (int) ($_GET['team'] ?? 0);
if (!isset($teams[$teamId])) $teamId = 0;
$allWorkers = att_workers();
$workerMap = array_column($allWorkers, null, 'id');

/* ═════════════ 개인 월간 근태표 ═════════════ */
if ($view === 'sheet') {
    $uid = (int) ($_GET['user'] ?? 0);
    if (!$uid) $uid = att_is_subject($me) ? (int) $me['id'] : (int) ($allWorkers[0]['id'] ?? 0);
    $worker = $uid ? att_user($uid) : null;
    if ($worker && !att_can_view($me, $worker)) abort(403, '다른 사람의 근태표는 공무직 이상만 볼 수 있습니다.');

    [$first, $last] = month_grid($ym);
    $from = $first->format('Y-m-d');
    $to = $last->format('Y-m-d');
    $rows = [];
    $tot = ['work' => 0, 'annual' => 0, 'sick' => 0, 'official' => 0, 'absent' => 0, 'early' => 0, 'out' => 0, 'overtime' => 0];
    if ($worker) {
        $recs = att_records(['user_id' => $worker['id'], 'from' => $from, 'to' => $to]);
        $hol = holiday_dates($from, $to);
        $closed = holiday_dates($from, $to, ['closed']);
        $off = att_off_days($worker);
        [$ws, $we] = att_work_hours($worker);
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
            $w = (int) date('w', strtotime($d));
            $isOff = in_array($w, $off, true);
            $dayType = isset($hol[$d]) ? '공휴일' : ($isOff ? '휴무' : '근무');
            $marks = $notes = [];
            $ot = '';
            foreach ($recs as $r) {
                if ($r['start_date'] > $d || $r['end_date'] < $d) continue;
                $pend = $r['status'] === 'pending' ? ' (결재중)' : '';
                if (att_is_time_kind($r['kind'])) {
                    $t = substr((string) $r['start_time'], 0, 5) . '~' . substr((string) $r['end_time'], 0, 5);
                    if ($r['kind'] === 'overtime') $ot = $t . ' (' . att_fmt_min((int) $r['minutes'], false) . ')' . $pend;
                    else $marks[] = att_kind_name($r['kind']) . ' ' . $t . $pend;
                    $tot[$r['kind']] += (int) $r['minutes'];
                } elseif ($dayType === '근무') { // 전일 근태는 근무일에만 센다
                    $marks[] = att_kind_name($r['kind']) . $pend;
                    $tot[$r['kind']]++;
                }
                else continue; // 휴무일·공휴일은 전일 근태 표시·비고 생략
                if ($r['content']) $notes[] = att_kind_name($r['kind']) . ': ' . $r['content'];
            }
            if ($dayType === '근무') $tot['work']++;
            $rows[] = [
                'date' => $d, 'w' => $w, 'type' => $dayType . (isset($hol[$d]) ? ' ' . $hol[$d] : '') . (isset($closed[$d]) ? ' · 휴관 ' . $closed[$d] : ''),
                'hours' => $dayType === '근무' ? "$ws~$we" : '', 'marks' => implode(', ', $marks), 'ot' => $ot,
                'note' => implode(' / ', $notes), 'off' => $dayType !== '근무',
            ];
        }
        $sum = att_summary($worker);
    }
    $title = ($worker ? $worker['name'] . ' ' : '') . $first->format('Y년 n월') . ' 근태표';
    $totLine = $worker ? "근무일 {$tot['work']}일 · 연차 {$tot['annual']}일 · 조퇴·외출 " . att_fmt_min($tot['early'] + $tot['out'], false)
        . " · 병가 {$tot['sick']}일 · 공가 {$tot['official']}일 · 결근 {$tot['absent']}일 · 초과근무 " . att_fmt_min($tot['overtime'], false) : '';

    if ($worker && ($_GET['export'] ?? '') === 'xlsx') {
        xlsx_send(str_replace(' ', '_', $title) . '.xlsx', [[
            'name' => $first->format('Y-m') . ' ' . $worker['name'],
            'title' => $title,
            'subtitle' => member_affiliation($worker) . ' · 근무시간 ' . implode('~', att_work_hours($worker)) . ' · 휴무 ' . att_off_label($worker)
                . ' · 출력 ' . date('Y-m-d H:i'),
            'header' => ['일자', '요일', '구분', '근무시간', '근태', '초과근무', '비고'],
            'rows' => array_map(fn($r) => [$r['date'], weekday_ko($r['date']), $r['type'], $r['hours'], $r['marks'], $r['ot'], $r['note']], $rows),
            'footer' => [['합계', '', $totLine, '', '', '', ''],
                ['연차 현황', '', $sum['set'] ? "발생 {$sum['accrued']}일 / 최대 {$sum['max']}일 · 사용 " . att_fmt_min($sum['used_min']) . ' · 남은 ' . att_fmt_min($sum['remain_min'])
                    . " · 병가 {$sum['sick_used']}일 / {$sum['sick_limit']}일" : '입사일 미등록', '', '', '', '']],
            'widths' => [12, 6, 18, 13, 28, 20, 40],
        ]]);
    }

    layout_header($title, 'att_sheet');
    ?>
<section class="card att-sheet">
  <div class="card-head no-print">
    <h1>개인 월간 근태표</h1>
    <div class="actions no-margin">
      <?php if ($worker): ?>
        <a class="btn" href="<?= e(url('attendance.php?' . http_build_query(['view' => 'sheet', 'user' => $worker['id'], 'ym' => $ym, 'export' => 'xlsx']))) ?>">엑셀 다운로드</a>
        <button class="btn" type="button" onclick="window.print()">인쇄 · PDF</button>
      <?php endif ?>
      <a class="btn ghost" href="<?= e(url('attendance.php?ym=' . $ym)) ?>">근태 달력 ›</a>
    </div>
  </div>
  <form method="get" class="filters no-print">
    <input type="hidden" name="view" value="sheet">
    <?php if ($isMgr): ?>
      <label>근무자
        <select name="user" onchange="this.form.submit()">
          <?php $tg = false; foreach ($allWorkers as $w): $tn = team_name($w['team_id'] ? (int) $w['team_id'] : null);
              if ($tg !== $tn): ?><?= $tg === false ? '' : '</optgroup>' ?><optgroup label="<?= e($tn) ?>"><?php $tg = $tn; endif ?>
            <option value="<?= (int) $w['id'] ?>" <?= $worker && (int) $w['id'] === (int) $worker['id'] ? 'selected' : '' ?>><?= e($w['name']) ?><?= att_expired($w) ? ' (계약만료)' : '' ?></option>
          <?php endforeach ?><?= $tg === false ? '' : '</optgroup>' ?>
        </select>
      </label>
    <?php else: ?><input type="hidden" name="user" value="<?= (int) $me['id'] ?>"><?php endif ?>
    <label>월<input type="month" name="ym" value="<?= e($ym) ?>" onchange="this.form.submit()"></label>
    <a class="btn ghost" href="<?= e(url('attendance.php?' . http_build_query(['view' => 'sheet', 'user' => $worker['id'] ?? null, 'ym' => $first->modify('-1 month')->format('Y-m')]))) ?>">‹ 이전달</a>
    <a class="btn ghost" href="<?= e(url('attendance.php?' . http_build_query(['view' => 'sheet', 'user' => $worker['id'] ?? null, 'ym' => $first->modify('+1 month')->format('Y-m')]))) ?>">다음달 ›</a>
  </form>

  <?php if (!$worker): ?>
    <p class="muted">근태 대상 사원이 없습니다. 회원관리에서 사원을 등록하세요.</p>
  <?php else: ?>
    <div class="att-sheet-head">
      <div>
        <h2><?= e($title) ?></h2>
        <p class="muted small"><?= e(member_affiliation($worker) ?: '소속 미지정') ?> · 근무시간 <?= e(implode('~', att_work_hours($worker))) ?> · 휴무 <?= e(att_off_label($worker)) ?>
          <?php if ($sum['set']): ?> · 계약기간 <?= e(implode(' ~ ', $sum['period'])) ?><?php endif ?></p>
        <?php if (!att_work_configured($worker)): ?><p class="flash flash-error small no-margin">근무 설정(입사일·근무시간·휴무요일)이 입력되지 않았습니다. 회원관리 › 근무 설정에서 입력하세요. (휴무는 토·일로 계산 중)</p><?php endif ?>
      </div>
      <table class="stampbox"><tr><th>본인</th><th>공무직</th><th>주무관</th><th>팀장</th></tr><tr><td></td><td></td><td></td><td></td></tr></table>
    </div>
    <div class="table-scroll">
    <table class="table att-sheet-table">
      <thead><tr><th>일자</th><th>구분</th><th>근무시간</th><th>근태</th><th>초과근무</th><th>비고</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['off'] ? 'off' : '' ?> <?= $r['w'] === 0 ? 'd-sun' : ($r['w'] === 6 ? 'd-sat' : '') ?>">
          <td class="nowrap"><?= e(date('n/j', strtotime($r['date']))) ?> <span class="wd">(<?= weekday_ko($r['date']) ?>)</span></td>
          <td><?= e($r['type']) ?></td>
          <td class="nowrap"><?= e($r['hours']) ?></td>
          <td><b><?= e($r['marks']) ?></b></td>
          <td class="nowrap"><?= e($r['ot']) ?></td>
          <td class="small"><?= e($r['note']) ?></td>
        </tr>
      <?php endforeach ?>
      </tbody>
      <tfoot><tr><th colspan="6" class="left"><?= e($totLine) ?></th></tr></tfoot>
    </table>
    </div>
    <?php if ($sum['set']): ?>
      <p class="small">연차 현황(오늘 기준): 발생 <b><?= $sum['accrued'] ?>일</b> / 최대 <?= $sum['max'] ?>일 · 사용 <b><?= e(att_fmt_min($sum['used_min'])) ?></b>
        · 남은 연차 <b><?= e(att_fmt_min($sum['remain_min'])) ?></b> · 병가 <?= $sum['sick_used'] ?>일 / <?= $sum['sick_limit'] ?>일
        <span class="muted">(결재중 포함, 연차 1일 = 8시간)</span></p>
    <?php endif ?>
  <?php endif ?>
</section>
<?php
    layout_footer();
    exit;
}

/* ═════════════ 근태 달력 ═════════════ */
$personId = (int) ($_GET['user'] ?? 0);
if (!isset($workerMap[$personId])) $personId = 0;
[$first, $last, $gridStart, $gridEnd] = month_grid($ym);
$gs = $gridStart->format('Y-m-d');
$ge = $gridEnd->format('Y-m-d');
$today = date('Y-m-d');
$recs = att_records(['from' => $gs, 'to' => $ge, 'team_id' => $teamId, 'user_id' => $personId]);
$items = att_calendar_items($recs);
$hol = holiday_dates($gs, $ge);
$closed = holiday_dates($gs, $ge, ['closed']);
$monthRecs = array_filter($recs, fn($r) => $r['start_date'] <= $last->format('Y-m-d') && $r['end_date'] >= $first->format('Y-m-d'));
$kindCount = array_count_values(array_column($monthRecs, 'kind'));
$person = $personId ? $workerMap[$personId] : null;
$personOff = $person ? att_off_days($person) : [];

// 왼쪽 연차 현황: 고른 사람(볼 권한이 있을 때) 또는 본인
$sumUser = $person && att_can_view($me, $person) ? $person : (att_is_subject($me) ? $me : null);
$sum = $sumUser ? att_summary($sumUser) : null;

$selectable = $isMgr ? $allWorkers : (att_is_subject($me) ? [$me] : []);
$canCreate = (bool) $selectable;
$q = fn(array $o) => 'attendance.php?' . http_build_query(array_merge(['ym' => $ym, 'team' => $teamId ?: null, 'user' => $personId ?: null], $o));
const ATT_LANES = 4;

layout_header('근태관리 ' . $first->format('Y년 n월'), 'attendance');
?>
<style><?php foreach (array_keys(ATT_KINDS) as $k): ?>.gcal.hide-<?= $k ?> [data-kind="<?= $k ?>"]<?= $k === array_key_last(ATT_KINDS) ? '' : ',' ?><?php endforeach ?> { display: none !important; }</style>
<div class="gcal att-cal" id="gcal">
  <aside class="gcal-side no-print">
    <?php if ($canCreate): ?>
      <button class="gcal-create" type="button" data-create="<?= e($ym === substr($today, 0, 7) ? $today : "$ym-01") ?>"><span>+</span> 근태 입력</button>
    <?php endif ?>

    <?php if ($sum): ?>
      <div class="att-summary">
        <h4><?= e($sumUser['name']) ?> 연차·병가</h4>
        <?php if (!$sum['set']): ?>
          <p class="muted small">입사일이 등록되지 않았습니다. 관리자에게 근무 설정을 요청하세요.</p>
        <?php else: ?>
          <ul class="att-sum-list">
            <li><span>발생 연차</span><b><?= $sum['accrued'] ?>일 <small class="muted">/ 최대 <?= $sum['max'] ?>일</small></b></li>
            <li><span>사용 연차</span><b><?= e(att_fmt_min($sum['used_min'])) ?></b></li>
            <li><span>남은 연차</span><b class="<?= $sum['remain_min'] < 0 ? 'warn' : 'good' ?>"><?= e(att_fmt_min($sum['remain_min'])) ?></b></li>
            <li><span>병가 사용</span><b><?= $sum['sick_used'] ?>일 <small class="muted">/ <?= $sum['sick_limit'] ?>일</small></b></li>
          </ul>
          <p class="muted tiny-text">계약 <?= e(implode(' ~ ', $sum['period'])) ?> · 결재중 포함</p>
        <?php endif ?>
      </div>
    <?php endif ?>

    <form method="get" class="att-filter">
      <input type="hidden" name="ym" value="<?= e($ym) ?>">
      <label>팀
        <select name="team" onchange="this.form.user.value=''; this.form.submit()">
          <option value="">전체</option>
          <?php foreach ($teams as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $teamId === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach ?>
        </select>
      </label>
      <label>근무자
        <select name="user" onchange="this.form.submit()">
          <option value="">전체</option>
          <?php foreach ($allWorkers as $w): if ($teamId && (int) $w['team_id'] !== $teamId) continue; ?>
            <option value="<?= (int) $w['id'] ?>" <?= $personId === (int) $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?><?= att_expired($w) ? ' (계약만료)' : '' ?></option>
          <?php endforeach ?>
        </select>
      </label>
    </form>

    <div class="gcal-filters">
      <h4>근태 종류</h4>
      <?php foreach (ATT_KINDS as $key => [$label, $color]): ?>
        <label style="--c: <?= $color ?>"><input type="checkbox" data-filter="<?= $key ?>" checked><span class="box"></span><?= e($label) ?>
          <small><?= (int) ($kindCount[$key] ?? 0) ?></small></label>
      <?php endforeach ?>
      <p class="muted tiny-text att-legend"><span class="lg-pending"></span> 테두리만 있는 것은 결재중</p>
    </div>
  </aside>

  <section class="gcal-main">
    <div class="gcal-toolbar">
      <a class="btn" href="<?= e(url($q(['ym' => date('Y-m')]))) ?>">오늘</a>
      <a class="icon-btn" href="<?= e(url($q(['ym' => $first->modify('-1 month')->format('Y-m')]))) ?>" aria-label="이전 달">‹</a>
      <a class="icon-btn" href="<?= e(url($q(['ym' => $first->modify('+1 month')->format('Y-m')]))) ?>" aria-label="다음 달">›</a>
      <h1><?= e($first->format('Y년 n월')) ?> <small class="muted"><?= e($person ? $person['name'] : ($teamId ? $teams[$teamId]['name'] : '전체')) ?></small></h1>
      <div class="actions no-margin no-print" style="margin-left:auto">
        <?php if (!empty($me['is_admin'])): ?><a class="btn ghost" href="<?= e(url('schedule.php?ym=' . $ym . '&new=' . "$ym-01" . '&cat=holiday')) ?>">+ 공휴일·휴관일</a><?php endif ?>
        <a class="btn ghost" href="<?= e(url('attendance.php?' . http_build_query(['view' => 'sheet', 'user' => $person['id'] ?? null, 'ym' => $ym]))) ?>">월간 근태표</a>
        <button class="btn ghost" type="button" onclick="window.print()">인쇄</button>
      </div>
    </div>

    <div class="gcal-month">
      <div class="gcal-head"><?php foreach (['일', '월', '화', '수', '목', '금', '토'] as $i => $w): ?><div class="<?= $i === 0 ? 'sun' : ($i === 6 ? 'sat' : '') ?>"><?= $w ?></div><?php endforeach ?></div>
      <?php for ($w = $gridStart; $w <= $gridEnd; $w = $w->modify('+7 days')):
          [$bars, $hidden] = week_layout($items, $w, ATT_LANES); ?>
        <div class="gcal-week att-week">
          <div class="gcal-days">
            <?php for ($i = 0; $i < 7; $i++): $d = $w->modify("+$i days"); $ds = $d->format('Y-m-d');
                $cls = [$d->format('m') !== $first->format('m') ? 'other' : '', $ds === $today ? 'today' : '', $i === 0 ? 'sun' : ($i === 6 ? 'sat' : ''),
                    isset($hol[$ds]) ? 'holiday' : '', isset($closed[$ds]) ? 'closed' : '', $person && in_array($i, $personOff, true) ? 'offday' : '']; ?>
              <div class="gcal-day <?= implode(' ', array_filter($cls)) ?>" <?= $canCreate ? 'data-create="' . $ds . '"' : '' ?>>
                <span class="gcal-num" data-day="<?= $ds ?>"><?= $d->format('j') ?></span>
                <?php if (isset($hol[$ds])): ?><span class="day-tag hol" title="공휴일"><?= e($hol[$ds]) ?></span><?php endif ?>
                <?php if (isset($closed[$ds])): ?><span class="day-tag closed" title="휴관일"><?= e($closed[$ds]) ?></span><?php endif ?>
                <?php if ($person && in_array($i, $personOff, true) && !isset($hol[$ds])): ?><span class="day-tag off">휴무</span><?php endif ?>
              </div>
            <?php endfor ?>
          </div>
          <div class="gcal-events">
            <?php foreach ($bars as $b): $e = $b['ev']; $color = ATT_KINDS[$e['kind']][1]; $bar = (bool) $e['all_day']; ?>
              <a href="<?= e(url('view.php?id=' . $e['id'])) ?>" class="gcal-ev <?= $bar ? 'bar' : 'dot' ?> <?= $e['status'] === 'pending' ? 'pending' : '' ?> <?= $b['contL'] ? 'cont-l' : '' ?> <?= $b['contR'] ? 'cont-r' : '' ?>"
                 data-kind="<?= e($e['kind']) ?>" style="--c: <?= $color ?>; grid-column: <?= $b['start'] + 1 ?> / span <?= $b['span'] ?>; grid-row: <?= $b['lane'] + 1 ?>;"
                 title="<?= e($e['title'] . ' · ' . $e['when'] . ($e['status'] === 'pending' ? ' · 결재중' : '')) ?>">
                <?php if (!$bar): ?><i></i><?php endif ?><span class="n"><?= e($bar ? $e['title'] : $e['rec']['user_name'] . ' ' . att_kind_name($e['kind'])) ?></span>
              </a>
            <?php endforeach ?>
            <?php foreach ($hidden as $i => $n): if (!$n) continue; ?>
              <button type="button" class="gcal-more" data-day="<?= $w->modify("+$i days")->format('Y-m-d') ?>" style="grid-column: <?= $i + 1 ?>; grid-row: <?= ATT_LANES + 1 ?>;">+<?= $n ?><span class="wide">개 더보기</span></button>
            <?php endforeach ?>
          </div>
        </div>
      <?php endfor ?>
    </div>
    <p class="muted small no-print">날짜의 빈 칸을 누르면 그 날짜로 근태를 입력하고, 근태를 누르면 결재 문서를 볼 수 있습니다.
      공휴일·휴관일은 최고관리자가 <a href="<?= e(url('schedule.php?ym=' . $ym)) ?>">일정표</a>에 등록하며, 공휴일과 각자의 휴무일은 연차·병가 일수에서 빠집니다.</p>
  </section>
</div>

<!-- 날짜별 목록 / 근태 입력 창 -->
<dialog id="attDialog" class="ev-dialog att-dialog">
  <div class="ev-pane" data-pane="day">
    <div class="ev-tools"><b data-day-title></b><button type="button" class="icon-btn" data-close title="닫기">✕</button></div>
    <div class="ev-daylist"></div>
    <?php if ($canCreate): ?><div class="actions"><button type="button" class="btn primary" data-day-create>+ 이 날짜에 근태 입력</button></div><?php endif ?>
  </div>

  <?php if ($canCreate): ?>
  <form method="post" enctype="multipart/form-data" class="ev-pane ev-form" data-pane="form">
    <?= csrf_field() ?>
    <div class="ev-tools"><b>근태 입력</b><button type="button" class="icon-btn" data-close title="닫기">✕</button></div>
    <?php if ($isMgr): ?>
      <label>근무자
        <select name="worker_id" required>
          <option value="">선택</option>
          <?php $tg = false; foreach ($allWorkers as $w): $tn = team_name($w['team_id'] ? (int) $w['team_id'] : null);
              if ($tg !== $tn): ?><?= $tg === false ? '' : '</optgroup>' ?><optgroup label="<?= e($tn) ?>"><?php $tg = $tn; endif ?>
            <option value="<?= (int) $w['id'] ?>" <?= $personId === (int) $w['id'] ? 'selected' : '' ?>><?= e($w['name']) ?><?= att_expired($w) ? ' (계약만료)' : '' ?></option>
          <?php endforeach ?><?= $tg === false ? '' : '</optgroup>' ?>
        </select>
      </label>
    <?php else: ?>
      <input type="hidden" name="worker_id" value="<?= (int) $me['id'] ?>">
      <p class="muted small">근무자: <b><?= e($me['name']) ?></b> (본인)</p>
    <?php endif ?>
    <div class="ev-cats">
      <?php foreach (att_kinds_for($me) as $key => [$label, $color, $unit]): ?>
        <label style="--c: <?= $color ?>"><input type="radio" name="kind" value="<?= $key ?>" data-unit="<?= $unit ?>" <?= $key === 'annual' ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
      <?php endforeach ?>
    </div>
    <div class="ev-dates">
      <label>시작일<input type="date" name="start_date" required></label>
      <label data-day-only>종료일<input type="date" name="end_date"></label>
    </div>
    <div class="ev-times" data-times="free" hidden>
      <label>시작 시간<input type="time" name="start_time" step="600"></label>
      <label>종료 시간<input type="time" name="end_time" step="600"></label>
    </div>
    <div class="ev-times" data-times="hour" hidden>
      <label>시작 시각<select name="start_time" data-hour></select></label>
      <label>종료 시각<select name="end_time" data-hour></select></label>
      <p class="muted tiny-text" data-break></p>
    </div>
    <div class="att-check" aria-live="polite"></div>
    <label data-attach hidden>진단서 등 첨부 <small class="muted">(사진 또는 PDF)</small><input type="file" name="attachment" accept="image/*,application/pdf"></label>
    <label>사유<textarea name="reason" rows="2" placeholder="예: 개인 사정, 병원 진료"></textarea></label>
    <p class="muted tiny-text">연차·병가·공가·결근은 하루 단위(휴무일·공휴일 제외), 조퇴·외출은 시간 단위로 연차에서 차감(1일 = 8시간), 초과근무는 공휴일·휴무일에만 입력하며 4시간마다 30분 휴게시간을 뺍니다.
      입력하면 결재가 바로 올라갑니다.</p>
    <div class="actions"><button type="button" class="btn ghost" data-close>취소</button><button class="btn primary">입력 · 결재 올리기</button></div>
  </form>
  <?php endif ?>
</dialog>

<script>
window.ATT = {
  items: <?= json_encode(array_map(fn($i) => ['id' => $i['id'], 'kind' => $i['kind'], 'start_date' => $i['start_date'], 'end_date' => $i['end_date'],
      'title' => $i['title'], 'when' => $i['when'], 'pending' => $i['status'] === 'pending'], $items), JSON_UNESCAPED_UNICODE) ?>,
  kinds: <?= json_encode(array_map(fn($k) => ['label' => $k[0], 'color' => $k[1], 'unit' => $k[2]], ATT_KINDS), JSON_UNESCAPED_UNICODE) ?>,
  workers: <?= json_encode(array_map(fn($w) => ['hours' => att_work_hours($w), 'options' => att_hour_options($w), 'break' => att_break($w)], $workerMap) ?: new stdClass(), JSON_UNESCAPED_UNICODE) ?>,
  viewUrl: <?= json_encode(url('view.php?id=')) ?>,
  checkUrl: <?= json_encode(url('attendance.php?api=check')) ?>,
  openNew: <?= json_encode(valid_date($_GET['new'] ?? '') ? $_GET['new'] : null) ?>,
};
</script>
<?php layout_footer([url('assets/attendance.js')]);
