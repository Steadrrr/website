<?php
defined('APP_ROOT') || exit;

/**
 * 프로그램 운영보고 (산림치유센터 · 유아숲체험원 · 유아숲(직영) · 숲해설)
 *  - 분야별로 하루 1건. 보고서 안에 회차(1회차부터 자동 번호)를 여러 개 입력
 *  - 회차: 단체명(개인 성명), 담당자, 운영시간, 인원(남·여 × 유아·초등·중고등·성인·65세이상), 유료/무료, 활동내용
 *  - 프로그램 금액 = 인원 합계 × 1인 참가비 — 유료(기본 5,000원) / 할인(기본 3,000원) / 무료 (설정 › 기본 정보)
 *  - 활동사진은 보고서에 여러 장 (photos.owner_type 'program', 긴 변 PROGRAM_PHOTO_MAX px)
 */
const PROGRAM_PHOTO_MAX = 1000;

function is_program_type(string $type): bool
{
    return isset(PROGRAM_TYPES[$type]);
}

const PROGRAM_FEE_TYPES = ['paid' => '유료', 'discount' => '할인', 'free' => '무료'];

/** 1인 참가비 (유료) */
function program_fee(): int
{
    return (int) setting('program_fee', '5000');
}

/** 1인 참가비 (할인) */
function program_fee_dc(): int
{
    return (int) setting('program_fee_dc', '3000');
}

/** 요금 구분별 1인 참가비 */
function program_fee_for(string $feeType): int
{
    return match ($feeType) { 'paid' => program_fee(), 'discount' => program_fee_dc(), default => 0 };
}

/** 인원 컬럼 목록: ['m_infant', 'f_infant', ...] */
function program_people_cols(): array
{
    $cols = [];
    foreach (array_keys(PROGRAM_AGES) as $a) { $cols[] = "m_$a"; $cols[] = "f_$a"; }
    return $cols;
}

function program_empty_session(): array
{
    return ['group_name' => '', 'staff' => '', 'start_time' => '', 'end_time' => '', 'is_paid' => 1, 'fee_type' => 'paid', 'activity' => '', 'total' => 0, 'amount' => 0]
        + array_fill_keys(program_people_cols(), 0);
}

function program_load(int $journalId): array
{
    $st = db()->prepare('SELECT * FROM program_sessions WHERE journal_id = ? ORDER BY session_no, id');
    $st->execute([$journalId]);
    return ['sessions' => $st->fetchAll()];
}

/** @return array{0: array, 1: string[]} */
function program_parse(string $type, string $workDate, int $journalId): array
{
    $errors = [];
    $sessions = [];
    $time = '/^([01]\d|2[0-3]):[0-5]\d$/';
    foreach ((array) ($_POST['s'] ?? []) as $row) {
        if (!is_array($row)) continue;
        $s = program_empty_session();
        $s['group_name'] = mb_substr(trim((string) ($row['group_name'] ?? '')), 0, 100);
        $s['staff'] = mb_substr(trim((string) ($row['staff'] ?? '')), 0, 100);
        foreach (program_people_cols() as $c) $s[$c] = min(9999, to_int($row[$c] ?? 0));
        $s['total'] = array_sum(array_intersect_key($s, array_flip(program_people_cols())));
        $s['activity'] = trim((string) ($row['activity'] ?? ''));
        $s['start_time'] = (string) ($row['start_time'] ?? '');
        $s['end_time'] = (string) ($row['end_time'] ?? '');
        $s['fee_type'] = isset(PROGRAM_FEE_TYPES[$row['fee_type'] ?? '']) ? $row['fee_type'] : 'paid';
        $s['is_paid'] = (int) ($s['fee_type'] !== 'free');
        // 아무것도 입력하지 않은 회차는 건너뜀
        if ($s['group_name'] === '' && $s['total'] === 0 && $s['activity'] === '' && $s['start_time'] === '') continue;

        $no = count($sessions) + 1;
        if ($s['group_name'] === '') $errors[] = "{$no}회차: 단체명(또는 개인 성명)을 입력하세요.";
        if ($s['total'] === 0) $errors[] = "{$no}회차: 인원을 입력하세요.";
        if ($s['start_time'] !== '' && !preg_match($time, $s['start_time'])) $errors[] = "{$no}회차: 시작 시간을 확인하세요.";
        if ($s['end_time'] !== '' && !preg_match($time, $s['end_time'])) $errors[] = "{$no}회차: 종료 시간을 확인하세요.";
        if ($s['start_time'] !== '' && $s['end_time'] !== '' && $s['end_time'] <= $s['start_time']) $errors[] = "{$no}회차: 종료 시간이 시작 시간보다 늦어야 합니다.";
        $s['session_no'] = $no;
        $s['fee'] = program_fee_for($s['fee_type']);
        $s['amount'] = $s['total'] * $s['fee'];
        $sessions[] = $s;
    }
    if (!$sessions) $errors[] = '회차를 1개 이상 입력하세요.';

    // 분야별 하루 1건 (통계 중복 방지)
    if (valid_date($workDate)) {
        $st = db()->prepare('SELECT id FROM journals WHERE type = ? AND work_date = ? AND id <> ?');
        $st->execute([$type, $workDate, $journalId]);
        if ($dup = $st->fetchColumn()) $errors[] = "해당 날짜의 " . JOURNAL_TYPES[$type] . "가 이미 있습니다. (문서번호 $dup) 그 보고서에 회차를 추가하세요.";
    }
    return [['sessions' => $sessions ?: [program_empty_session()]], $errors];
}

/** 트랜잭션 안에서 호출. 회차 저장 + 활동사진 추가·삭제 */
function program_save(int $journalId, array $payload): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM program_sessions WHERE journal_id = ?')->execute([$journalId]);
    $cols = ['session_no', 'group_name', 'staff', 'start_time', 'end_time', ...program_people_cols(), 'total', 'is_paid', 'fee_type', 'fee', 'amount', 'activity'];
    $ins = $pdo->prepare('INSERT INTO program_sessions (journal_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')');
    foreach ($payload['sessions'] as $s) {
        $s['start_time'] = $s['start_time'] ?: null;
        $s['end_time'] = $s['end_time'] ?: null;
        $s['activity'] = $s['activity'] !== '' ? $s['activity'] : null;
        $s['staff'] = $s['staff'] !== '' ? $s['staff'] : null;
        $ins->execute([$journalId, ...array_map(fn($c) => $s[$c], $cols)]);
    }
    photos_delete('program', $journalId, (array) ($_POST['delete_photos'] ?? []));
    $user = current_user();
    foreach (photos_save_uploaded('program', $journalId, (int) ($user['id'] ?? 0), PROGRAM_PHOTO_MAX) as $err) flash($err, 'error');
}

/** "10:00~12:00" */
function program_time(array $s): string
{
    $t1 = $s['start_time'] ? substr((string) $s['start_time'], 0, 5) : '';
    $t2 = $s['end_time'] ? substr((string) $s['end_time'], 0, 5) : '';
    return $t1 || $t2 ? "$t1~$t2" : '';
}

/** 회차 합계: 회차 수, 인원, 유료·무료 인원, 금액, 남·여, 연령대별 */
function program_totals(array $sessions): array
{
    $t = ['sessions' => count($sessions), 'total' => 0, 'paid' => 0, 'discount' => 0, 'free' => 0, 'amount' => 0, 'm' => 0, 'f' => 0]
        + array_fill_keys(program_people_cols(), 0);
    foreach ($sessions as $s) {
        $t['total'] += (int) $s['total'];
        $t[$s['fee_type'] ?? ($s['is_paid'] ? 'paid' : 'free')] += (int) $s['total'];
        $t['amount'] += (int) $s['amount'];
        foreach (PROGRAM_AGES as $a => $_) {
            $t["m_$a"] += (int) $s["m_$a"];
            $t["f_$a"] += (int) $s["f_$a"];
            $t['m'] += (int) $s["m_$a"];
            $t['f'] += (int) $s["f_$a"];
        }
    }
    return $t;
}

/* ───────────── 입력 폼 ───────────── */

/** 회차 카드 1개. $key = 폼 배열 키 (JS 가 새 회차는 새 키로 만든다) */
function program_session_card(string $key, array $s, int $no): void
{
    $n = fn(string $c) => "s[$key][$c]";
    $val = fn(string $c) => e((int) ($s[$c] ?? 0) ?: '');
    ?>
  <div class="prog-session" data-session>
    <div class="prog-session-head">
      <b><span data-no><?= $no ?></span>회차</b>
      <button type="button" class="btn small ghost danger" data-remove-session>회차 삭제</button>
    </div>
    <div class="row">
      <label>단체명 (또는 개인 성명)<input name="<?= $n('group_name') ?>" value="<?= e($s['group_name'] ?? '') ?>" maxlength="100" placeholder="예: ○○어린이집 / 홍길동"></label>
      <label>담당자<input name="<?= $n('staff') ?>" value="<?= e($s['staff'] ?? '') ?>" maxlength="100" placeholder="예: 김숲해설, 이치유"></label>
      <label>운영시간
        <span class="time-range"><input type="time" name="<?= $n('start_time') ?>" value="<?= e(substr((string) ($s['start_time'] ?? ''), 0, 5)) ?>" step="600">
          ~ <input type="time" name="<?= $n('end_time') ?>" value="<?= e(substr((string) ($s['end_time'] ?? ''), 0, 5)) ?>" step="600"></span></label>
      <div class="prog-paid">
        <span class="label-text">프로그램 금액</span>
        <?php foreach (PROGRAM_FEE_TYPES as $ft => $label): $fee = program_fee_for($ft); ?>
          <label class="inline-check"><input type="radio" name="<?= $n('fee_type') ?>" value="<?= $ft ?>" data-fee-type data-fee="<?= $fee ?>" <?= ($s['fee_type'] ?? 'paid') === $ft ? 'checked' : '' ?>>
            <?= e($label) ?><?= $fee ? ' <small class="muted">(1인 ' . number_format($fee) . '원)</small>' : '' ?></label>
        <?php endforeach ?>
      </div>
    </div>
    <div class="table-scroll">
    <table class="table prog-people">
      <thead><tr><th>인원</th><?php foreach (PROGRAM_AGES as $label): ?><th><?= e($label) ?></th><?php endforeach ?><th class="right">계</th></tr></thead>
      <tbody>
        <?php foreach (['m' => '남', 'f' => '여'] as $g => $gl): ?>
          <tr data-gender="<?= $g ?>"><th><?= $gl ?></th>
            <?php foreach (PROGRAM_AGES as $a => $_): ?>
              <td><input name="<?= $n("{$g}_$a") ?>" value="<?= $val("{$g}_$a") ?>" inputmode="numeric" class="num tiny" data-people data-age="<?= $a ?>" data-g="<?= $g ?>" placeholder="0"></td>
            <?php endforeach ?>
            <td class="right" data-gsum="<?= $g ?>">0</td></tr>
        <?php endforeach ?>
      </tbody>
      <tfoot><tr><th>계</th><?php foreach (PROGRAM_AGES as $a => $_): ?><th class="right" data-asum="<?= $a ?>">0</th><?php endforeach ?>
        <th class="right"><b data-total>0</b>명</th></tr></tfoot>
    </table>
    </div>
    <p class="prog-amount">인원 합계 <b data-total-text>0명</b> · 프로그램 금액 <b data-amount>0원</b></p>
    <label>활동내용<textarea name="<?= $n('activity') ?>" rows="3" placeholder="예: 오감 숲체험, 숲길 걷기, 나무 이름 알기"><?= e($s['activity'] ?? '') ?></textarea></label>
  </div>
    <?php
}

function program_form(string $type, array $payload, ?array $journal): void
{
    $sessions = $payload['sessions'] ?: [program_empty_session()];
    ?>
<div data-program-form>
  <h3><?= e(PROGRAM_TYPES[$type]) ?> 프로그램 운영 <small class="muted">회차는 1회차부터 자동으로 번호가 붙습니다</small></h3>
  <div data-sessions>
    <?php foreach (array_values($sessions) as $i => $s) program_session_card((string) $i, $s, $i + 1) ?>
  </div>
  <template id="sessionTpl"><?php program_session_card('__KEY__', program_empty_session(), 0) ?></template>
  <button type="button" class="btn" data-add-session>+ 회차 추가</button>

  <div class="grand prog-grand">합계 <span data-sum-sessions>0회</span> · 인원 <b data-sum-total>0명</b>
    <small class="muted">(남 <span data-sum-m>0</span> · 여 <span data-sum-f>0</span> / 유료 <span data-sum-paid>0</span> · 할인 <span data-sum-discount>0</span> · 무료 <span data-sum-free>0</span>)</small>
    · 금액 <b data-sum-amount>0원</b></div>

  <h3>활동사진</h3>
  <?php render_photo_editor('program', $journal ? (int) $journal['id'] : null, PROGRAM_PHOTO_MAX) ?>
</div>
<script src="<?= e(url('assets/program.js')) ?>" defer></script>
    <?php
}

/* ───────────── 보기 ───────────── */

function program_view(array $journal): void
{
    $sessions = program_load((int) $journal['id'])['sessions'];
    $t = program_totals($sessions);
    ?>
  <div class="kpis k4 prog-kpis">
    <div class="kpi"><span>운영 회차</span><b><?= $t['sessions'] ?>회</b></div>
    <div class="kpi"><span>참여 인원</span><b><?= number_format($t['total']) ?>명</b><small class="muted">남 <?= $t['m'] ?> · 여 <?= $t['f'] ?></small></div>
    <div class="kpi"><span>유료 / 할인 / 무료</span><b><?= number_format($t['paid']) ?> / <?= number_format($t['discount']) ?> / <?= number_format($t['free']) ?>명</b></div>
    <div class="kpi total"><span>프로그램 금액</span><b><?= e(won($t['amount'])) ?></b></div>
  </div>
  <div class="table-scroll">
  <table class="table prog-view">
    <thead>
      <tr><th rowspan="2">회차</th><th rowspan="2">단체명(성명)</th><th rowspan="2">담당자</th><th rowspan="2">운영시간</th>
        <?php foreach (PROGRAM_AGES as $label): ?><th colspan="2" class="center"><?= e($label) ?></th><?php endforeach ?>
        <th rowspan="2" class="right">합계</th><th rowspan="2">구분</th><th rowspan="2" class="right">금액</th></tr>
      <tr><?php foreach (PROGRAM_AGES as $_): ?><th class="right">남</th><th class="right">여</th><?php endforeach ?></tr>
    </thead>
    <tbody>
    <?php foreach ($sessions as $s): ?>
      <tr><td class="center"><?= (int) $s['session_no'] ?></td><td><?= e($s['group_name']) ?></td><td><?= e($s['staff'] ?? '') ?></td><td class="nowrap"><?= e(program_time($s)) ?></td>
        <?php foreach (PROGRAM_AGES as $a => $_): ?><td class="right"><?= $s["m_$a"] ?: '' ?></td><td class="right"><?= $s["f_$a"] ?: '' ?></td><?php endforeach ?>
        <td class="right"><b><?= number_format($s['total']) ?></b></td><td><?= e(PROGRAM_FEE_TYPES[$s['fee_type']] ?? '') ?><?= $s['fee'] ? ' <small class="muted">' . number_format($s['fee']) . '</small>' : '' ?></td><td class="right"><?= number_format($s['amount']) ?></td></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr><th colspan="4">합계 <?= $t['sessions'] ?>회</th>
      <?php foreach (PROGRAM_AGES as $a => $_): ?><th class="right"><?= $t["m_$a"] ?></th><th class="right"><?= $t["f_$a"] ?></th><?php endforeach ?>
      <th class="right"><?= number_format($t['total']) ?></th><th></th><th class="right"><?= number_format($t['amount']) ?></th></tr></tfoot>
  </table>
  </div>
  <?php if (array_filter(array_column($sessions, 'activity'))): ?>
    <h3>활동내용</h3>
    <?php foreach ($sessions as $s): if (!$s['activity']) continue; ?>
      <div class="prog-activity"><b><?= (int) $s['session_no'] ?>회차 · <?= e($s['group_name']) ?></b><div class="pre"><?= e($s['activity']) ?></div></div>
    <?php endforeach ?>
  <?php endif ?>
  <?php if ($photos = photos_for('program', (int) $journal['id'])): ?>
    <h3>활동사진 <small class="muted"><?= count($photos) ?>장</small></h3>
    <?php render_gallery($photos) ?>
  <?php endif ?>
    <?php
}

/** 수정 이력 비교용 */
function program_snapshot(array $journal): array
{
    $lines = [];
    foreach (program_load((int) $journal['id'])['sessions'] as $s) {
        $people = [];
        foreach (PROGRAM_AGES as $a => $label) {
            if ($s["m_$a"] || $s["f_$a"]) $people[] = "$label 남{$s["m_$a"]}·여{$s["f_$a"]}";
        }
        $lines[] = "{$s['session_no']}회차 · {$s['group_name']}" . (!empty($s['staff']) ? " · 담당 {$s['staff']}" : '') . (program_time($s) ? ' · ' . program_time($s) : '')
            . ' · ' . implode(', ', $people) . " = {$s['total']}명 · " . (PROGRAM_FEE_TYPES[$s['fee_type']] ?? '') . ($s['amount'] ? ' ' . number_format($s['amount']) . '원' : '')
            . ($s['activity'] ? ' · 활동: ' . preg_replace('/\s+/', ' ', $s['activity']) : '');
    }
    return ['회차' => $lines, '활동사진' => count(photos_for('program', (int) $journal['id'])) . '장'];
}
