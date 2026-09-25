<?php
defined('APP_ROOT') || exit;

/**
 * AR(아르바이트) 사용관리 (객실관리 › AR사용관리, 공무직 이상)
 *  - 사용계획: 날짜별 계획 인원 (ar_plans) — 달력에서 입력
 *  - 사용보고: 사용일별 1건 (journals type arwork) — 아르바이트 성명·사용시간을 입력해 결재
 *  - 횟수: 아르바이트 1명이 하루 사용 = 1회
 *      사용가능횟수 = 연간 한도 (settings ar_quota_YYYY, 주무관 이상이 입력)
 *      계획횟수 = 그 해 계획 인원 합계, 사용횟수 = 그 해 사용보고(결재중·결재완료)의 인원 합계
 */
const AR_TYPE = 'arwork';

function ar_empty_worker(): array
{
    return ['name' => '', 'start_time' => '09:00', 'end_time' => '18:00', 'break_min' => 60, 'minutes' => 0, 'task' => ''];
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

/** 한도를 정할 수 있는 사람: 주무관 이상·최고관리자 */
function can_ar_quota(array $u): bool
{
    return !empty($u['is_admin']) || (int) $u['rank_level'] >= RANK_OFFICER;
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
                          WHERE j.type = 'arwork' AND j.status IN ('pending', 'approved') AND j.work_date BETWEEN ? AND ?");
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

/* ───────────── 사용보고 (journals type arwork) ───────────── */

function ar_load(int $journalId): array
{
    $st = db()->prepare('SELECT * FROM ar_workers WHERE journal_id = ? ORDER BY sort_no, id');
    $st->execute([$journalId]);
    return ['workers' => $st->fetchAll()];
}

/** @return array{0: array, 1: string[]} */
function ar_parse(string $workDate, int $journalId): array
{
    $errors = [];
    $rows = [];
    $time = '/^([01]\d|2[0-3]):[0-5]\d$/';
    foreach ((array) ($_POST['w'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $w = [
            'name'       => mb_substr(trim((string) ($row['name'] ?? '')), 0, 50),
            'start_time' => (string) ($row['start_time'] ?? ''),
            'end_time'   => (string) ($row['end_time'] ?? ''),
            'break_min'  => min(600, to_int($row['break_min'] ?? 0)),
            'task'       => mb_substr(trim((string) ($row['task'] ?? '')), 0, 200),
        ];
        if ($w['name'] === '' && $w['task'] === '') continue; // 비운 줄은 건너뜀
        $no = count($rows) + 1;
        if ($w['name'] === '') $errors[] = "{$no}번째 줄: 아르바이트 성명을 입력하세요.";
        if (!preg_match($time, $w['start_time']) || !preg_match($time, $w['end_time'])) {
            $errors[] = "{$no}번째 줄({$w['name']}): 사용시간(시작·종료)을 입력하세요.";
            $w['minutes'] = 0;
        } else {
            [$sh, $sm] = array_map('intval', explode(':', $w['start_time']));
            [$eh, $em] = array_map('intval', explode(':', $w['end_time']));
            $w['minutes'] = ($eh * 60 + $em) - ($sh * 60 + $sm) - $w['break_min'];
            if ($w['end_time'] <= $w['start_time']) $errors[] = "{$no}번째 줄({$w['name']}): 종료 시간이 시작 시간보다 늦어야 합니다.";
            elseif ($w['minutes'] <= 0) $errors[] = "{$no}번째 줄({$w['name']}): 휴게시간이 사용시간보다 깁니다.";
        }
        $w['minutes'] = max(0, $w['minutes']);
        $w['sort_no'] = $no;
        $rows[] = $w;
    }
    if (!$rows) $errors[] = '아르바이트를 1명 이상 입력하세요.';
    $names = array_map(fn($w) => $w['name'], $rows);
    if (count($names) !== count(array_unique($names))) $errors[] = '같은 성명이 두 번 들어 있습니다. (한 사람은 하루 1줄)';
    if (valid_date($workDate)) {
        $st = db()->prepare("SELECT id FROM journals WHERE type = 'arwork' AND work_date = ? AND id <> ?");
        $st->execute([$workDate, $journalId]);
        if ($dup = $st->fetchColumn()) $errors[] = "해당 날짜의 AR 사용보고가 이미 있습니다. (문서번호 $dup) 그 보고서를 수정하세요.";
    }
    return [['workers' => $rows ?: [ar_empty_worker()]], $errors];
}

/** 트랜잭션 안에서 호출 */
function ar_save(int $journalId, array $payload): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM ar_workers WHERE journal_id = ?')->execute([$journalId]);
    $ins = $pdo->prepare('INSERT INTO ar_workers (journal_id, sort_no, name, start_time, end_time, break_min, minutes, task) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($payload['workers'] as $w) {
        $ins->execute([$journalId, $w['sort_no'], $w['name'], $w['start_time'], $w['end_time'], $w['break_min'], $w['minutes'], $w['task'] !== '' ? $w['task'] : null]);
    }
}

function ar_worker_row(string $key, array $w): void
{
    $n = fn(string $c) => "w[$key][$c]";
    ?>
  <tr data-ar-row>
    <td class="center" data-ar-no></td>
    <td><input name="<?= $n('name') ?>" value="<?= e($w['name']) ?>" maxlength="50" placeholder="성명" class="ar-name"></td>
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
    $workers = $payload['workers'];
    $plan = ar_plan_people($workDate);
    if (!$journal && !is_post() && (!$workers || $workers === [ar_empty_worker()])) {
        $workers = array_fill(0, max(1, $plan), ar_empty_worker()); // 계획 인원만큼 줄을 미리 만든다
    }
    ?>
<div data-ar-form>
  <h3>아르바이트 사용 내역 <small class="muted"><?= $plan ? "이 날 계획 {$plan}명" : '이 날 사용계획 없음' ?> · 1명 하루 사용 = 1회</small></h3>
  <div class="table-scroll">
  <table class="table ar-table">
    <thead><tr><th>No</th><th>성명</th><th>사용시간</th><th>휴게</th><th class="right">근무시간</th><th>업무내용</th><th></th></tr></thead>
    <tbody data-ar-body>
      <?php foreach (array_values($workers) as $i => $w) ar_worker_row((string) $i, $w + ar_empty_worker()) ?>
    </tbody>
    <tfoot><tr><th colspan="4">합계 <span data-ar-count>0</span>명</th><th class="right" data-ar-total>0분</th><th colspan="2"></th></tr></tfoot>
  </table>
  </div>
  <template id="arRowTpl"><?php ar_worker_row('__KEY__', ar_empty_worker()) ?></template>
  <button type="button" class="btn" data-ar-add>+ 아르바이트 추가</button>
</div>
<script>
(function () {
  const form = document.querySelector('[data-ar-form]');
  const body = form.querySelector('[data-ar-body]');
  const hm = (m) => { const h = Math.floor(m / 60), r = m % 60; return (h ? h + '시간' : '') + (r ? (h ? ' ' : '') + r + '분' : '') || '0분'; };
  const mins = (v) => { const p = /^(\d{2}):(\d{2})$/.exec(v || ''); return p ? +p[1] * 60 + +p[2] : null; };
  function recalc() {
    let total = 0, count = 0;
    body.querySelectorAll('[data-ar-row]').forEach((tr, i) => {
      tr.querySelector('[data-ar-no]').textContent = i + 1;
      const [s, e] = [...tr.querySelectorAll('input[type=time]')].map((x) => mins(x.value));
      const brk = parseInt(tr.querySelector('input[name$="[break_min]"]').value, 10) || 0;
      const m = s !== null && e !== null && e > s ? Math.max(0, e - s - brk) : 0;
      tr.querySelector('[data-ar-min]').textContent = m ? hm(m) : '-';
      if (tr.querySelector('.ar-name').value.trim() !== '') { count++; total += m; }
    });
    form.querySelector('[data-ar-count]').textContent = count;
    form.querySelector('[data-ar-total]').textContent = hm(total);
  }
  let seq = Date.now();
  form.querySelector('[data-ar-add]').addEventListener('click', () => {
    body.insertAdjacentHTML('beforeend', document.getElementById('arRowTpl').innerHTML.replace(/__KEY__/g, 'n' + seq++));
    recalc();
    body.lastElementChild.querySelector('.ar-name').focus();
  });
  body.addEventListener('click', (ev) => {
    if (!ev.target.matches('[data-ar-del]')) return;
    const tr = ev.target.closest('tr');
    if (body.querySelectorAll('[data-ar-row]').length === 1) { tr.querySelectorAll('input:not([type=time])').forEach((x) => { x.value = x.name.endsWith('[break_min]') ? '60' : ''; }); }
    else tr.remove();
    recalc();
  });
  body.addEventListener('input', recalc);
  recalc();
})();
</script>
    <?php
}

function ar_view(array $journal): void
{
    $workers = ar_load((int) $journal['id'])['workers'];
    $total = array_sum(array_column($workers, 'minutes'));
    $plan = ar_plan_people($journal['work_date']);
    ?>
  <div class="kpis k3">
    <div class="kpi"><span>사용 인원 (횟수)</span><b><?= count($workers) ?>명</b></div>
    <div class="kpi"><span>이 날 계획</span><b><?= $plan ?>명</b><?php if ($plan && $plan !== count($workers)): ?><small class="warn">계획과 <?= count($workers) - $plan > 0 ? '+' : '' ?><?= count($workers) - $plan ?>명</small><?php endif ?></div>
    <div class="kpi total"><span>근무시간 합계</span><b><?= e(ar_hm($total)) ?></b></div>
  </div>
  <div class="table-scroll">
  <table class="table ar-table">
    <thead><tr><th>No</th><th>성명</th><th>사용시간</th><th class="right">휴게</th><th class="right">근무시간</th><th>업무내용</th></tr></thead>
    <tbody>
    <?php foreach ($workers as $w): ?>
      <tr><td class="center"><?= (int) $w['sort_no'] ?></td><td><b><?= e($w['name']) ?></b></td>
        <td class="nowrap"><?= e(substr((string) $w['start_time'], 0, 5)) ?> ~ <?= e(substr((string) $w['end_time'], 0, 5)) ?></td>
        <td class="right"><?= (int) $w['break_min'] ?>분</td><td class="right"><?= e(ar_hm((int) $w['minutes'])) ?></td><td><?= e($w['task'] ?? '') ?></td></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th colspan="4">합계 <?= count($workers) ?>명</th><th class="right"><?= e(ar_hm($total)) ?></th><th></th></tr></tfoot>
  </table>
  </div>
    <?php
}

/** 수정 이력 비교용 */
function ar_snapshot(array $journal): array
{
    $lines = array_map(fn($w) => "{$w['name']} " . substr((string) $w['start_time'], 0, 5) . '~' . substr((string) $w['end_time'], 0, 5)
        . " (휴게 {$w['break_min']}분, " . ar_hm((int) $w['minutes']) . ')' . (!empty($w['task']) ? " · {$w['task']}" : ''), ar_load((int) $journal['id'])['workers']);
    return ['아르바이트' => $lines];
}
