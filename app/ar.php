<?php
defined('APP_ROOT') || exit;

/**
 * AR(아르바이트) 사용관리 (객실관리 › AR사용관리, 공무직 이상)
 *  - 사용계획: 날짜별 계획 인원 (ar_plans) — 달력에서 입력
 *  - 사용보고: 월 1건 (journals type arwork, work_date = 그 달 1일) — 줄마다 사용일·아르바이트 성명·사용시간을 입력해 결재
 *  - 횟수: 아르바이트 1명이 하루 사용 = 1회
 *      사용가능횟수 = 연간 한도 (settings ar_quota_YYYY, 공무직 이상이 입력)
 *      계획횟수 = 그 해 계획 인원 합계, 사용횟수 = 그 해 사용보고(결재중·결재완료)의 인원 합계
 */
const AR_TYPE = 'arwork';

function ar_empty_worker(): array
{
    return ['work_date' => '', 'name' => '', 'start_time' => '09:00', 'end_time' => '18:00', 'break_min' => 60, 'minutes' => 0, 'task' => ''];
}

/** 분 → "8시간 30분" */
function ar_hm(int $min): string
{
    $h = intdiv($min, 60);
    $m = $min % 60;
    return ($h ? "{$h}시간" : '') . ($m ? ($h ? ' ' : '') . "{$m}분" : '') ?: '0분';
}

/** 사용가능횟수 (연간) */
function ar_quota(int $year): int
{
    return (int) setting("ar_quota_$year", '0');
}

/** 한도를 정할 수 있는 사람: 공무직 이상·최고관리자 (AR사용관리를 쓰는 사람 모두) */
function can_ar_quota(array $u): bool
{
    return can_ar($u);
}

/** @return array{quota:int, planned:int, used:int, approved:int, minutes:int} 그 해 요약 */
function ar_year_summary(int $year): array
{
    $from = "$year-01-01";
    $to = "$year-12-31";
    $st = db()->prepare('SELECT COALESCE(SUM(people), 0) FROM ar_plans WHERE work_date BETWEEN ? AND ?');
    $st->execute([$from, $to]);
    $planned = (int) $st->fetchColumn();
    $st = db()->prepare("SELECT COUNT(w.id) AS used, COALESCE(SUM(IF(j.status = 'approved', 1, 0)), 0) AS approved, COALESCE(SUM(w.minutes), 0) AS minutes
                           FROM journals j JOIN ar_workers w ON w.journal_id = j.id
                          WHERE j.type = 'arwork' AND j.status IN ('pending', 'approved') AND w.work_date BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    $r = $st->fetch();
    return ['quota' => ar_quota($year), 'planned' => $planned, 'used' => (int) $r['used'], 'approved' => (int) $r['approved'], 'minutes' => (int) $r['minutes']];
}

/** @return array<string,array> 날짜 => 계획 */
function ar_plans(string $from, string $to): array
{
    $st = db()->prepare('SELECT p.*, u.name AS user_name FROM ar_plans p LEFT JOIN users u ON u.id = p.user_id WHERE p.work_date BETWEEN ? AND ?');
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st as $r) $out[$r['work_date']] = $r;
    return $out;
}

/** 그 날의 계획 인원 (없으면 0) */
function ar_plan_people(string $date): int
{
    return (int) (ar_plans($date, $date)[$date]['people'] ?? 0);
}

/** 'YYYY-MM-DD' → [그 달 1일, 말일] */
function ar_month_range(string $date): array
{
    $first = substr($date, 0, 7) . '-01';
    return [$first, date('Y-m-t', strtotime($first))];
}

/** 날짜별 사용 인원 (결재중·결재완료 + 내 임시저장): 날짜 => ['people', 'status', 'journal_id'] */
function ar_used_by_day(string $from, string $to, int $userId): array
{
    $st = db()->prepare("SELECT w.work_date, COUNT(*) AS people, j.status, j.id AS journal_id
                           FROM ar_workers w JOIN journals j ON j.id = w.journal_id
                          WHERE j.type = 'arwork' AND w.work_date BETWEEN ? AND ? AND j.status <> 'rejected' AND (j.status <> 'draft' OR j.author_id = ?)
                          GROUP BY w.work_date, j.id, j.status ORDER BY w.work_date");
    $st->execute([$from, $to, $userId]);
    $out = [];
    foreach ($st as $r) $out[$r['work_date']] = ['people' => (int) $r['people'], 'status' => $r['status'], 'journal_id' => (int) $r['journal_id']];
    return $out;
}

/* ───────────── 월간 사용보고 (journals type arwork, work_date = 그 달 1일) ───────────── */

function ar_load(int $journalId): array
{
    $st = db()->prepare('SELECT * FROM ar_workers WHERE journal_id = ? ORDER BY work_date, sort_no, id');
    $st->execute([$journalId]);
    return ['workers' => $st->fetchAll()];
}

/** @return array{0: array, 1: string[]}  $workDate = 그 달 1일 */
function ar_parse(string $workDate, int $journalId): array
{
    $errors = [];
    $rows = [];
    $time = '/^([01]\d|2[0-3]):[0-5]\d$/';
    [$from, $to] = ar_month_range($workDate);
    foreach ((array) ($_POST['w'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $w = [
            'work_date'  => (string) ($row['work_date'] ?? ''),
            'name'       => mb_substr(trim((string) ($row['name'] ?? '')), 0, 50),
            'start_time' => (string) ($row['start_time'] ?? ''),
            'end_time'   => (string) ($row['end_time'] ?? ''),
            'break_min'  => min(600, to_int($row['break_min'] ?? 0)),
            'task'       => mb_substr(trim((string) ($row['task'] ?? '')), 0, 200),
        ];
        if ($w['name'] === '' && $w['task'] === '') continue; // 비운 줄은 건너뜀
        $no = count($rows) + 1;
        $who = $w['name'] !== '' ? "({$w['name']})" : '';
        if ($w['name'] === '') $errors[] = "{$no}번째 줄: 아르바이트 성명을 입력하세요.";
        if (!valid_date($w['work_date']) || $w['work_date'] < $from || $w['work_date'] > $to) $errors[] = "{$no}번째 줄{$who}: 사용일을 " . (int) substr($from, 5, 2) . '월 안의 날짜로 고르세요.';
        if (!preg_match($time, $w['start_time']) || !preg_match($time, $w['end_time'])) {
            $errors[] = "{$no}번째 줄{$who}: 사용시간(시작·종료)을 입력하세요.";
            $w['minutes'] = 0;
        } else {
            [$sh, $sm] = array_map('intval', explode(':', $w['start_time']));
            [$eh, $em] = array_map('intval', explode(':', $w['end_time']));
            $w['minutes'] = ($eh * 60 + $em) - ($sh * 60 + $sm) - $w['break_min'];
            if ($w['end_time'] <= $w['start_time']) $errors[] = "{$no}번째 줄{$who}: 종료 시간이 시작 시간보다 늦어야 합니다.";
            elseif ($w['minutes'] <= 0) $errors[] = "{$no}번째 줄{$who}: 휴게시간이 사용시간보다 깁니다.";
        }
        $w['minutes'] = max(0, $w['minutes']);
        $rows[] = $w;
    }
    if (!$rows) $errors[] = '아르바이트 사용 내역을 1줄 이상 입력하세요.';
    $keys = array_map(fn($w) => $w['work_date'] . '|' . $w['name'], $rows);
    foreach (array_unique(array_diff_assoc($keys, array_unique($keys))) as $k) {
        [$d, $n] = explode('|', $k, 2);
        $errors[] = "{$d} {$n}: 같은 날 같은 사람이 두 줄입니다. (한 사람은 하루 1줄)";
    }
    usort($rows, fn($a, $b) => [$a['work_date'], $a['start_time'], $a['name']] <=> [$b['work_date'], $b['start_time'], $b['name']]);
    foreach ($rows as $i => &$w) $w['sort_no'] = $i + 1;
    unset($w);
    if (valid_date($workDate)) {
        $st = db()->prepare("SELECT id FROM journals WHERE type = 'arwork' AND work_date BETWEEN ? AND ? AND id <> ?");
        $st->execute([$from, $to, $journalId]);
        if ($dup = $st->fetchColumn()) $errors[] = (int) substr($from, 5, 2) . "월 AR 사용보고가 이미 있습니다. (문서번호 $dup) 그 보고서를 수정해 사용 내역을 더하세요.";
    }
    return [['workers' => $rows ?: [ar_empty_worker()]], $errors];
}

/** 트랜잭션 안에서 호출 */
function ar_save(int $journalId, array $payload): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM ar_workers WHERE journal_id = ?')->execute([$journalId]);
    $ins = $pdo->prepare('INSERT INTO ar_workers (journal_id, work_date, sort_no, name, start_time, end_time, break_min, minutes, task) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($payload['workers'] as $w) {
        $ins->execute([$journalId, $w['work_date'], $w['sort_no'], $w['name'], $w['start_time'], $w['end_time'], $w['break_min'], $w['minutes'], $w['task'] !== '' ? $w['task'] : null]);
    }
}

function ar_worker_row(string $key, array $w, string $from, string $to): void
{
    $n = fn(string $c) => "w[$key][$c]";
    ?>
  <tr data-ar-row>
    <td class="center" data-ar-no></td>
    <td><input type="date" name="<?= $n('work_date') ?>" value="<?= e((string) $w['work_date']) ?>" min="<?= $from ?>" max="<?= $to ?>" data-ar-date></td>
    <td><input name="<?= $n('name') ?>" value="<?= e($w['name']) ?>" maxlength="50" placeholder="성명" class="ar-name" list="arNames"></td>
    <td class="nowrap"><input type="time" name="<?= $n('start_time') ?>" value="<?= e(substr((string) $w['start_time'], 0, 5)) ?>" step="600" data-ar-t>
      ~ <input type="time" name="<?= $n('end_time') ?>" value="<?= e(substr((string) $w['end_time'], 0, 5)) ?>" step="600" data-ar-t></td>
    <td><input name="<?= $n('break_min') ?>" value="<?= (int) $w['break_min'] ?>" inputmode="numeric" class="num tiny" data-ar-t> 분</td>
    <td class="right nowrap" data-ar-min>-</td>
    <td><input name="<?= $n('task') ?>" value="<?= e($w['task'] ?? '') ?>" maxlength="200" placeholder="예: 객실 청소, 침구 교체"></td>
    <td><button type="button" class="btn small ghost danger" data-ar-del>삭제</button></td>
  </tr>
    <?php
}

function ar_form(array $payload, string $workDate, ?array $journal): void
{
    [$from, $to] = ar_month_range($workDate);
    $plans = ar_plans($from, $to);
    $planTotal = array_sum(array_map(fn($p) => (int) $p['people'], $plans));
    $workers = $payload['workers'];
    if (!$journal && !is_post() && (!$workers || $workers === [ar_empty_worker()])) {
        // 새 보고서: 이 달 계획 인원만큼 날짜별로 줄을 미리 만든다
        $workers = [];
        foreach ($plans as $d => $p) for ($i = 0; $i < (int) $p['people']; $i++) $workers[] = ['work_date' => $d] + ar_empty_worker();
        if (!$workers) $workers = [['work_date' => (date('Y-m') === substr($from, 0, 7) ? date('Y-m-d') : $from)] + ar_empty_worker()];
    }
    $names = array_values(array_unique(array_filter(array_map(fn($r) => $r['name'], db()->query("SELECT DISTINCT name FROM ar_workers ORDER BY name")->fetchAll()))));
    ?>
<div data-ar-form>
  <h3><?= (int) substr($from, 5, 2) ?>월 아르바이트 사용 내역 <small class="muted">이 달 계획 <?= $planTotal ?>회 · 1명 하루 사용 = 1회 · 한 줄 = 한 사람의 하루</small></h3>
  <datalist id="arNames"><?php foreach ($names as $nm): ?><option><?= e($nm) ?></option><?php endforeach ?></datalist>
  <div class="table-scroll">
  <table class="table ar-table">
    <thead><tr><th>No</th><th>사용일</th><th>성명</th><th>사용시간</th><th>휴게</th><th class="right">근무시간</th><th>업무내용</th><th></th></tr></thead>
    <tbody data-ar-body>
      <?php foreach (array_values($workers) as $i => $w) ar_worker_row((string) $i, $w + ar_empty_worker(), $from, $to) ?>
    </tbody>
    <tfoot><tr><th colspan="5">합계 <span data-ar-count>0</span>회 (<span data-ar-days>0</span>일 · <span data-ar-people>0</span>명)</th><th class="right" data-ar-total>0분</th><th colspan="2"></th></tr></tfoot>
  </table>
  </div>
  <template id="arRowTpl"><?php ar_worker_row('__KEY__', ar_empty_worker(), $from, $to) ?></template>
  <button type="button" class="btn" data-ar-add>+ 줄 추가</button> <small class="muted">새 줄은 마지막 줄의 사용일·시간을 이어받습니다. 저장하면 사용일 순으로 정렬됩니다.</small>
</div>
<script>
(function () {
  const form = document.querySelector('[data-ar-form]');
  const body = form.querySelector('[data-ar-body]');
  const hm = (m) => { const h = Math.floor(m / 60), r = m % 60; return (h ? h + '시간' : '') + (r ? (h ? ' ' : '') + r + '분' : '') || '0분'; };
  const mins = (v) => { const p = /^(\d{2}):(\d{2})$/.exec(v || ''); return p ? +p[1] * 60 + +p[2] : null; };
  function recalc() {
    let total = 0, count = 0; const days = new Set(), people = new Set();
    body.querySelectorAll('[data-ar-row]').forEach((tr, i) => {
      tr.querySelector('[data-ar-no]').textContent = i + 1;
      const [s, e] = [...tr.querySelectorAll('input[type=time]')].map((x) => mins(x.value));
      const brk = parseInt(tr.querySelector('input[name$="[break_min]"]').value, 10) || 0;
      const m = s !== null && e !== null && e > s ? Math.max(0, e - s - brk) : 0;
      tr.querySelector('[data-ar-min]').textContent = m ? hm(m) : '-';
      const name = tr.querySelector('.ar-name').value.trim();
      if (name !== '') { count++; total += m; people.add(name); days.add(tr.querySelector('[data-ar-date]').value); }
    });
    form.querySelector('[data-ar-count]').textContent = count;
    form.querySelector('[data-ar-days]').textContent = days.size;
    form.querySelector('[data-ar-people]').textContent = people.size;
    form.querySelector('[data-ar-total]').textContent = hm(total);
  }
  let seq = Date.now();
  form.querySelector('[data-ar-add]').addEventListener('click', () => {
    const last = body.lastElementChild;
    body.insertAdjacentHTML('beforeend', document.getElementById('arRowTpl').innerHTML.replace(/__KEY__/g, 'n' + seq++));
    const row = body.lastElementChild;
    if (last) ['[data-ar-date]', 'input[name$="[start_time]"]', 'input[name$="[end_time]"]', 'input[name$="[break_min]"]']
      .forEach((sel) => { row.querySelector(sel).value = last.querySelector(sel).value; });
    recalc();
    row.querySelector('.ar-name').focus();
  });
  body.addEventListener('click', (ev) => {
    if (!ev.target.matches('[data-ar-del]')) return;
    const tr = ev.target.closest('tr');
    if (body.querySelectorAll('[data-ar-row]').length === 1) { tr.querySelectorAll('.ar-name, input[name$="[task]"]').forEach((x) => { x.value = ''; }); }
    else tr.remove();
    recalc();
  });
  body.addEventListener('input', recalc);
  recalc();
})();
</script>
    <?php
}

/** 월간 사용보고 제목용: "2026년 9월" */
function ar_month_label(string $workDate): string
{
    return (int) substr($workDate, 0, 4) . '년 ' . (int) substr($workDate, 5, 2) . '월';
}

function ar_view(array $journal): void
{
    $workers = ar_load((int) $journal['id'])['workers'];
    $total = array_sum(array_column($workers, 'minutes'));
    [$from, $to] = ar_month_range($journal['work_date']);
    $plan = array_sum(array_map(fn($p) => (int) $p['people'], ar_plans($from, $to)));
    $byDate = [];
    foreach ($workers as $w) $byDate[$w['work_date'] ?? $journal['work_date']][] = $w;
    $byName = [];
    foreach ($workers as $w) {
        $byName[$w['name']] ??= ['days' => 0, 'minutes' => 0];
        $byName[$w['name']]['days']++;
        $byName[$w['name']]['minutes'] += (int) $w['minutes'];
    }
    ksort($byName);
    ?>
  <div class="kpis k4">
    <div class="kpi"><span>사용횟수</span><b><?= count($workers) ?>회</b><small class="muted"><?= count($byDate) ?>일 · <?= count($byName) ?>명</small></div>
    <div class="kpi"><span>이 달 계획</span><b><?= $plan ?>회</b><?php if ($plan && $plan !== count($workers)): ?><small class="warn">계획과 <?= count($workers) - $plan > 0 ? '+' : '' ?><?= count($workers) - $plan ?>회</small><?php endif ?></div>
    <div class="kpi"><span>근무시간 합계</span><b><?= e(ar_hm($total)) ?></b></div>
    <div class="kpi total"><span>사용 월</span><b><?= e(ar_month_label($journal['work_date'])) ?></b></div>
  </div>
  <div class="table-scroll">
  <table class="table ar-table ar-view">
    <thead><tr><th>사용일</th><th>성명</th><th>사용시간</th><th class="right">휴게</th><th class="right">근무시간</th><th>업무내용</th></tr></thead>
    <tbody>
    <?php foreach ($byDate as $d => $rows): foreach ($rows as $i => $w): ?>
      <tr class="<?= $i === 0 ? 'ar-day-first' : '' ?>">
        <?php if ($i === 0): ?><td rowspan="<?= count($rows) ?>" class="nowrap"><b><?= e(date('n/j', strtotime($d))) ?></b> (<?= weekday_ko($d) ?>)<br><small class="muted"><?= count($rows) ?>명</small></td><?php endif ?>
        <td><b><?= e($w['name']) ?></b></td>
        <td class="nowrap"><?= e(substr((string) $w['start_time'], 0, 5)) ?> ~ <?= e(substr((string) $w['end_time'], 0, 5)) ?></td>
        <td class="right"><?= (int) $w['break_min'] ?>분</td><td class="right"><?= e(ar_hm((int) $w['minutes'])) ?></td><td><?= e($w['task'] ?? '') ?></td></tr>
    <?php endforeach; endforeach ?>
    </tbody>
    <tfoot><tr><th colspan="4">합계 <?= count($workers) ?>회 (<?= count($byDate) ?>일 · <?= count($byName) ?>명)</th><th class="right"><?= e(ar_hm($total)) ?></th><th></th></tr></tfoot>
  </table>
  </div>
  <h3>아르바이트별 합계</h3>
  <div class="table-scroll">
  <table class="table ar-table">
    <thead><tr><th>성명</th><th class="right">사용일수(횟수)</th><th class="right">근무시간</th></tr></thead>
    <tbody>
    <?php foreach ($byName as $nm => $a): ?><tr><td><?= e($nm) ?></td><td class="right"><?= $a['days'] ?>일</td><td class="right"><?= e(ar_hm($a['minutes'])) ?></td></tr><?php endforeach ?>
    </tbody>
  </table>
  </div>
    <?php
}

/** 수정 이력 비교용 */
function ar_snapshot(array $journal): array
{
    $lines = array_map(fn($w) => ($w['work_date'] ? date('n/j', strtotime($w['work_date'])) . ' ' : '') . "{$w['name']} " . substr((string) $w['start_time'], 0, 5) . '~' . substr((string) $w['end_time'], 0, 5)
        . " (휴게 {$w['break_min']}분, " . ar_hm((int) $w['minutes']) . ')' . (!empty($w['task']) ? " · {$w['task']}" : ''), ar_load((int) $journal['id'])['workers']);
    return ['사용 월' => ar_month_label($journal['work_date']), '아르바이트' => $lines];
}
