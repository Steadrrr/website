<?php
defined('APP_ROOT') || exit;

/**
 * 근태관리 (관리원)
 *  - 근태 1건 = journals(type 'attendance') 1건 + attendance 1행. 결재선은 입력한 사람의 직급 기준 (일지와 같음)
 *  - 전일 근태(연차·병가·공가·결근)는 근무자의 휴무 요일과 공휴일을 뺀 근무일만 일수로 센다
 *  - 조퇴·외출은 시간 단위로 연차에서 차감 (연차 1일 = 8시간)
 *  - 연차: 입사일부터 1개월 만근(결근 없음)마다 1일 발생, 최대 min(11, 계약 개월 수). 최대치까지 당겨 쓸 수 있음
 *  - 병가 한도: 계약기간 3개월 미만 3일, 6개월 미만 6일, 그 이상 9일
 */

// 종류 => [이름, 색, 'day' 전일 | 'time' 시간 단위]
const ATT_KINDS = [
    'annual'   => ['연차', '#1a73e8', 'day'],
    'sick'     => ['병가', '#d81b60', 'day'],
    'official' => ['공가', '#0b8043', 'day'],
    'early'    => ['조퇴', '#f09300', 'time'],
    'out'      => ['외출', '#e67c73', 'time'],
    'absent'   => ['결근', '#3c4043', 'day'],
    'overtime' => ['초과근무', '#8e24aa', 'time'],
];
const ATT_DAY_MIN = 480;              // 연차 1일 = 8시간
const ATT_MAX_ANNUAL = 11;            // 1년 미만 계약의 최대 발생 연차
const ATT_ACTIVE = ['pending', 'approved']; // 한도·겹침 계산에 넣는 결재 상태 (반려는 제외)

function att_kind_name(string $kind): string
{
    return ATT_KINDS[$kind][0] ?? $kind;
}

function att_is_time_kind(string $kind): bool
{
    return (ATT_KINDS[$kind][2] ?? '') === 'time';
}

/** 근태 대상자 = 사용 중인 관리원 */
function att_is_subject(array $u): bool
{
    return (int) $u['rank_level'] === RANK_KEEPER && ($u['status'] ?? 'active') === 'active';
}

/** 공무직 이상·최고관리자: 모든 관리원의 근태를 입력·조회 */
function att_is_manager(array $me): bool
{
    return !empty($me['is_admin']) || (int) $me['rank_level'] >= RANK_WORKER;
}

function att_workers(?int $teamId = null): array
{
    $sql = "SELECT * FROM users WHERE status = 'active' AND rank_level = " . RANK_KEEPER . ($teamId ? ' AND team_id = ' . (int) $teamId : '')
         . ' ORDER BY team_id IS NULL, team_id, squad_id IS NULL, squad_id, is_squad_leader DESC, name';
    return db()->query($sql)->fetchAll();
}

function att_user(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** 이 사람의 근태를 볼 수 있는가 (본인, 공무직 이상, 최고관리자) */
function att_can_view(array $me, array $worker): bool
{
    return (int) $me['id'] === (int) $worker['id'] || att_is_manager($me);
}

/** 입력 권한. 문제가 있으면 오류 문구, 없으면 null */
function att_entry_error(array $me, array $worker, string $kind): ?string
{
    if (!isset(ATT_KINDS[$kind])) return '근태 종류를 고르세요.';
    if (!att_is_subject($worker)) return '근태 입력 대상은 관리원입니다.';
    if ($kind === 'absent' && empty($me['is_admin']) && (int) $me['rank_level'] < RANK_OFFICER) return '결근은 주무관 이상만 입력할 수 있습니다.';
    if ($kind === 'overtime' && !att_is_manager($me)) return '초과근무는 공무직 이상만 입력할 수 있습니다.';
    if (!att_is_manager($me) && (int) $me['id'] !== (int) $worker['id']) return '본인의 근태만 입력할 수 있습니다.';
    return null;
}

/** 입력 창에서 고를 수 있는 종류 */
function att_kinds_for(array $me): array
{
    return array_filter(ATT_KINDS, function ($k) use ($me) {
        if ($k === 'absent') return !empty($me['is_admin']) || (int) $me['rank_level'] >= RANK_OFFICER;
        if ($k === 'overtime') return att_is_manager($me);
        return true;
    }, ARRAY_FILTER_USE_KEY);
}

/* ───────────── 근무 설정 ───────────── */

/** 휴무 요일 (0=일 … 6=토). 설정 전이면 토·일 */
function att_off_days(array $u): array
{
    $raw = trim((string) ($u['off_days'] ?? ''));
    if ($raw === '') return [0, 6];
    if ($raw === '-') return [];       // 휴무 요일 없음
    return array_values(array_unique(array_map('intval', array_filter(explode(',', $raw), fn($x) => $x !== '' && ctype_digit($x) && $x <= 6))));
}

/** 계약만료일이 지났는가 */
function att_expired(array $u, ?string $today = null): bool
{
    $p = att_period($u);
    return $p && $p[1] < ($today ?? date('Y-m-d'));
}

function att_off_label(array $u): string
{
    $days = att_off_days($u);
    return $days ? implode('·', array_map(fn($d) => ['일', '월', '화', '수', '목', '금', '토'][$d], $days)) : '없음';
}

/** [시작, 종료] 'HH:MM' (설정 전이면 09:00~18:00) */
function att_work_hours(array $u): array
{
    return [substr((string) ($u['work_start'] ?: '09:00'), 0, 5), substr((string) ($u['work_end'] ?: '18:00'), 0, 5)];
}

function att_work_configured(array $u): bool
{
    return !empty($u['hire_date']) && !empty($u['contract_end']) && !empty($u['work_start']) && trim((string) $u['off_days']) !== '';
}

/** 월 더하기 (1/31 + 1개월 = 2/28 처럼 말일을 넘지 않게) */
function att_add_months(string $date, int $n): string
{
    $d = new DateTimeImmutable($date);
    $target = $d->modify('first day of this month')->modify("+$n months");
    $day = min((int) $d->format('j'), (int) $target->format('t'));
    return $target->format('Y-m-') . sprintf('%02d', $day);
}

/** 계약 기간 [입사일, 계약만료일] (입사일 없으면 null). 만료일이 없는 예전 자료는 입사일 + 1년 - 1일로 계산 */
function att_period(array $u): ?array
{
    if (empty($u['hire_date'])) return null;
    $to = $u['contract_end'] ?: date('Y-m-d', strtotime(att_add_months($u['hire_date'], 12) . ' -1 day'));
    return [$u['hire_date'], $to];
}

/** 계약기간 개월 수 (꽉 찬 달만) */
function att_contract_months(array $u): int
{
    $p = att_period($u);
    if (!$p) return 0;
    $end = date('Y-m-d', strtotime($p[1] . ' +1 day'));
    $n = 0;
    while ($n < 120 && att_add_months($p[0], $n + 1) <= $end) $n++;
    return $n;
}

function att_sick_limit(int $months): int
{
    return $months < 3 ? 3 : ($months < 6 ? 6 : 9);
}

/** 기간 안에서 이 사람의 근무일 (휴무 요일·공휴일 제외) */
function att_workdays(array $u, string $from, string $to, ?array $holidays = null): array
{
    $holidays ??= holiday_dates($from, $to);
    $off = att_off_days($u);
    $out = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
        if (!in_array((int) date('w', strtotime($d)), $off, true) && !isset($holidays[$d])) $out[] = $d;
    }
    return $out;
}

/** 이 날이 근무자의 휴무일 또는 공휴일인가 */
function att_is_rest_day(array $u, string $date): bool
{
    return !att_workdays($u, $date, $date);
}

/* ───────────── 조회 ───────────── */

/**
 * 근태 목록. $f: user_id, team_id, from, to, statuses(기본 결재중·완료), exclude(journal_id)
 */
function att_records(array $f = []): array
{
    $where = ['1 = 1'];
    $args = [];
    $statuses = $f['statuses'] ?? ATT_ACTIVE;
    $where[] = 'j.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
    array_push($args, ...$statuses);
    if (!empty($f['user_id'])) { $where[] = 'a.user_id = ?'; $args[] = (int) $f['user_id']; }
    if (!empty($f['team_id'])) { $where[] = 'u.team_id = ?'; $args[] = (int) $f['team_id']; }
    if (!empty($f['from']))    { $where[] = 'a.end_date >= ?'; $args[] = $f['from']; }
    if (!empty($f['to']))      { $where[] = 'a.start_date <= ?'; $args[] = $f['to']; }
    if (!empty($f['exclude'])) { $where[] = 'a.journal_id <> ?'; $args[] = (int) $f['exclude']; }
    $st = db()->prepare(
        'SELECT a.*, j.status, j.content, j.author_id, j.revision, u.name AS user_name, u.team_id, au.name AS author_name
           FROM attendance a JOIN journals j ON j.id = a.journal_id JOIN users u ON u.id = a.user_id JOIN users au ON au.id = j.author_id
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY a.start_date, a.start_time, u.name, a.journal_id'
    );
    $st->execute($args);
    return $st->fetchAll();
}

function att_find(int $journalId): ?array
{
    $st = db()->prepare('SELECT a.*, u.name AS user_name FROM attendance a JOIN users u ON u.id = a.user_id WHERE a.journal_id = ?');
    $st->execute([$journalId]);
    return $st->fetch() ?: null;
}

/**
 * 연차·병가 현황
 * @return array{set:bool, period:?array, months:int, max:int, accrued:int, used_min:int, remain_min:int, can_use_min:int,
 *               sick_used:int, sick_limit:int, annual_days:int, time_min:int}
 */
function att_summary(array $u, ?string $today = null, int $excludeJournal = 0): array
{
    $today ??= date('Y-m-d');
    $p = att_period($u);
    $out = ['set' => (bool) $p, 'period' => $p, 'months' => 0, 'max' => 0, 'accrued' => 0, 'used_min' => 0, 'remain_min' => 0,
        'can_use_min' => 0, 'sick_used' => 0, 'sick_limit' => 3, 'annual_days' => 0, 'time_min' => 0];
    if (!$p) return $out;

    $recs = att_records(['user_id' => $u['id'], 'from' => $p[0], 'to' => $p[1], 'exclude' => $excludeJournal]);
    $months = att_contract_months($u);
    $out['months'] = $months;
    $out['max'] = min(ATT_MAX_ANNUAL, $months);
    $out['sick_limit'] = att_sick_limit($months);

    // 1개월 만근마다 1일 (그 달에 결근이 있으면 발생하지 않음)
    $absent = array_filter($recs, fn($r) => $r['kind'] === 'absent');
    for ($k = 1; $k <= $out['max']; $k++) {
        $mStart = att_add_months($p[0], $k - 1);
        $mEnd = date('Y-m-d', strtotime(att_add_months($p[0], $k) . ' -1 day'));
        if ($mEnd >= $today) break; // 아직 그 달이 끝나지 않음
        $hasAbsent = array_filter($absent, fn($r) => $r['start_date'] <= $mEnd && $r['end_date'] >= $mStart);
        if (!$hasAbsent) $out['accrued']++;
    }

    foreach ($recs as $r) {
        if ($r['start_date'] < $p[0] || $r['start_date'] > $p[1]) continue;
        if ($r['kind'] === 'annual') $out['annual_days'] += (int) $r['days'];
        elseif (in_array($r['kind'], ['early', 'out'], true)) $out['time_min'] += (int) $r['minutes'];
        elseif ($r['kind'] === 'sick') $out['sick_used'] += (int) $r['days'];
    }
    $out['used_min'] = $out['annual_days'] * ATT_DAY_MIN + $out['time_min'];
    $out['remain_min'] = $out['accrued'] * ATT_DAY_MIN - $out['used_min'];
    $out['can_use_min'] = max(0, $out['max'] * ATT_DAY_MIN - $out['used_min']);
    return $out;
}

/** 분 → "2일 3시간 30분" (1일 = 8시간) */
function att_fmt_min(int $min, bool $days = true): string
{
    $neg = $min < 0;
    $min = abs($min);
    $parts = [];
    if ($days && intdiv($min, ATT_DAY_MIN)) { $parts[] = intdiv($min, ATT_DAY_MIN) . '일'; $min %= ATT_DAY_MIN; }
    if (intdiv($min, 60)) $parts[] = intdiv($min, 60) . '시간';
    if ($min % 60) $parts[] = ($min % 60) . '분';
    return ($neg ? '-' : '') . ($parts ? implode(' ', $parts) : ($days ? '0일' : '0분'));
}

/** "9/23(수) ~ 9/25(금) · 3일" / "9/23(수) 15:00~18:00 · 3시간" */
function att_when(array $r, bool $withAmount = true): string
{
    $d = fn(string $x) => date('n/j', strtotime($x)) . '(' . weekday_ko($x) . ')';
    if (att_is_time_kind($r['kind'])) {
        $s = $d($r['start_date']) . ' ' . substr((string) $r['start_time'], 0, 5) . '~' . substr((string) $r['end_time'], 0, 5);
        return $s . ($withAmount ? ' · ' . att_fmt_min((int) $r['minutes'], false) : '');
    }
    $s = $d($r['start_date']) . ($r['end_date'] !== $r['start_date'] ? ' ~ ' . $d($r['end_date']) : '');
    return $s . ($withAmount ? ' · ' . (int) $r['days'] . '일' : '');
}

/* ───────────── 입력 검사 · 저장 ───────────── */

/** "HH:MM" → 분 */
function att_time_min(string $t): int
{
    return (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2);
}

/**
 * 입력값 검사. $in: kind, start_date, end_date, start_time, end_time
 * @return array{0: ?array, 1: string[], 2: string[]} [저장할 값, 오류, 안내]
 */
function att_validate(array $worker, array $in, int $excludeJournal = 0): array
{
    $kind = $in['kind'];
    $errors = $notes = [];
    $start = $in['start_date'];
    $end = att_is_time_kind($kind) ? $start : ($in['end_date'] ?: $start);
    if (!valid_date($start) || !valid_date($end)) return [null, ['날짜를 확인하세요.'], []];
    if ($end < $start) return [null, ['종료일이 시작일보다 빠릅니다.'], []];
    if ((strtotime($end) - strtotime($start)) / 86400 > 60) return [null, ['한 번에 60일까지만 입력할 수 있습니다.'], []];

    $row = ['kind' => $kind, 'start_date' => $start, 'end_date' => $end, 'start_time' => null, 'end_time' => null,
        'days' => 0, 'minutes' => 0, 'cert_required' => 0];
    $others = att_records(['user_id' => $worker['id'], 'from' => $start, 'to' => $end, 'exclude' => $excludeJournal]);
    $sum = att_summary($worker, null, $excludeJournal);
    if ($sum['set'] && ($start < $sum['period'][0] || $end > $sum['period'][1])) {
        return [null, ["계약기간({$sum['period'][0]} ~ {$sum['period'][1]}) 안의 날짜만 입력할 수 있습니다."], []];
    }

    if (!att_is_time_kind($kind)) {
        $days = att_workdays($worker, $start, $end);
        if (!$days) return [null, ['선택한 기간은 모두 휴무일·공휴일입니다. 근무일에만 입력할 수 있습니다.'], []];
        $row['days'] = count($days);
        foreach ($others as $o) {
            if ($o['kind'] === 'overtime') continue;
            $errors[] = '이미 등록된 근태와 겹칩니다: ' . att_kind_name($o['kind']) . ' ' . att_when($o, false) . ($o['status'] === 'pending' ? ' (결재중)' : '');
        }
    } else {
        $t1 = (string) $in['start_time'];
        $t2 = (string) $in['end_time'];
        $re = '/^([01]\d|2[0-3]):[0-5]\d$/';
        if (!preg_match($re, $t1) || !preg_match($re, $t2)) return [null, ['시간을 확인하세요.'], []];
        if ($t2 <= $t1) return [null, ['종료 시간이 시작 시간보다 늦어야 합니다.'], []];
        $row['start_time'] = $t1;
        $row['end_time'] = $t2;
        $row['minutes'] = att_time_min($t2) - att_time_min($t1);
        $rest = att_is_rest_day($worker, $start);
        if ($kind === 'overtime' && !$rest) {
            $errors[] = '초과근무는 공휴일 또는 근무자의 휴무일(' . att_off_label($worker) . ')에만 입력할 수 있습니다.';
        }
        if ($kind !== 'overtime') {
            if ($rest) $errors[] = '휴무일·공휴일에는 ' . att_kind_name($kind) . '을(를) 입력할 수 없습니다.';
            [$ws, $we] = att_work_hours($worker);
            if ($t1 < $ws || $t2 > $we) $errors[] = att_kind_name($kind) . " 시간은 근무시간({$ws}~{$we}) 안이어야 합니다.";
        }
        foreach ($others as $o) {
            $overlapTime = att_is_time_kind($o['kind']) && substr((string) $o['start_time'], 0, 5) < $t2 && substr((string) $o['end_time'], 0, 5) > $t1;
            $conflict = $kind === 'overtime' ? $o['kind'] === 'overtime' && $overlapTime
                                             : ($o['kind'] !== 'overtime' && (!att_is_time_kind($o['kind']) || $overlapTime));
            if ($conflict) $errors[] = '이미 등록된 근태와 겹칩니다: ' . att_kind_name($o['kind']) . ' ' . att_when($o, false) . ($o['status'] === 'pending' ? ' (결재중)' : '');
        }
    }

    // 연차 (조퇴·외출은 연차에서 시간 단위로 차감)
    if (in_array($kind, ['annual', 'early', 'out'], true)) {
        if (!$sum['set']) {
            $errors[] = '입사일이 등록되지 않아 연차를 계산할 수 없습니다. 관리자에게 회원관리 › 근무 설정을 요청하세요.';
        } else {
            $need = $kind === 'annual' ? $row['days'] * ATT_DAY_MIN : $row['minutes'];
            if ($need > $sum['can_use_min']) {
                $errors[] = '연차가 부족합니다. 최대 발생 연차 ' . $sum['max'] . '일 중 ' . att_fmt_min($sum['used_min']) . ' 사용(결재중 포함) · '
                          . '더 쓸 수 있는 연차 ' . att_fmt_min($sum['can_use_min']) . ' / 신청 ' . att_fmt_min($need);
            } elseif ($sum['used_min'] + $need > $sum['accrued'] * ATT_DAY_MIN) {
                $notes[] = '아직 발생하지 않은 연차를 당겨 씁니다. (발생 ' . $sum['accrued'] . '일 · 사용 후 ' . att_fmt_min($sum['accrued'] * ATT_DAY_MIN - $sum['used_min'] - $need) . ')';
            }
        }
    }

    // 병가: 한도, 진단서 안내
    if ($kind === 'sick') {
        if (!$sum['set']) {
            $errors[] = '입사일이 등록되지 않아 병가 한도를 계산할 수 없습니다. 관리자에게 회원관리 › 근무 설정을 요청하세요.';
        } elseif ($sum['sick_used'] + $row['days'] > $sum['sick_limit']) {
            $errors[] = "병가 한도를 넘습니다. (계약기간 {$sum['months']}개월 → 연 {$sum['sick_limit']}일, 사용 {$sum['sick_used']}일, 신청 {$row['days']}일)";
        } else {
            $total = $sum['sick_used'] + $row['days'];
            if ($row['days'] >= 3 || $total > 6) {
                $row['cert_required'] = 1;
                $notes[] = '의사의 진단서를 첨부해야 합니다. (' . ($row['days'] >= 3 ? '연속 3일 이상 병가' : "연 6일 초과 — 올해 합계 {$total}일") . ')';
            }
        }
    }
    return [$errors ? null : $row, array_values(array_unique($errors)), $notes];
}

/** 근태 저장 + 결재 상신 (입력한 사람의 직급으로 결재선) */
function att_create(array $me, array $worker, array $row, string $reason, ?string $attachment): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO journals (type, work_date, author_id, content, status) VALUES ('attendance', ?, ?, ?, 'draft')")
            ->execute([$row['start_date'], $me['id'], $reason !== '' ? $reason : null]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO attendance (journal_id, user_id, kind, start_date, end_date, start_time, end_time, days, minutes, attachment, cert_required)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $worker['id'], $row['kind'], $row['start_date'], $row['end_date'], $row['start_time'], $row['end_time'],
                $row['days'], $row['minutes'], $attachment, $row['cert_required']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    journal_submit(['id' => $id], (int) $me['rank_level']);
    return $id;
}

/** 취소(삭제) 권한: 주무관 이상·최고관리자는 언제나, 입력한 사람·본인은 결재완료 전까지 */
function att_can_cancel(array $journal, array $att, array $me): bool
{
    if (!empty($me['is_admin']) || (int) $me['rank_level'] >= RANK_OFFICER) return true;
    $mine = (int) $journal['author_id'] === (int) $me['id'] || (int) $att['user_id'] === (int) $me['id'];
    return $mine && $journal['status'] !== 'approved';
}

/**
 * 진단서 등 첨부 저장 (사진 또는 PDF). 개인정보라 uploads/attendance 는 직접 열 수 없게 막고
 * attendance.php?file=문서번호 로만 내려준다.
 * @return array{0: ?string, 1: ?string} [경로, 오류]
 */
function att_store_attachment(array $f): array
{
    $err = (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return [null, '첨부 파일이 너무 큽니다.'];
    if ($err !== UPLOAD_ERR_OK) return [null, "첨부 파일 업로드 실패 (오류 {$err})"];
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!isset($allowed[$mime])) return [null, '첨부는 사진(jpg, png) 또는 PDF 파일만 올릴 수 있습니다.'];

    $base = APP_ROOT . '/uploads/attendance';
    $dirRel = 'uploads/attendance/' . date('Ym');
    if (!is_dir(APP_ROOT . '/' . $dirRel) && !@mkdir(APP_ROOT . '/' . $dirRel, 0755, true)) {
        return [null, 'uploads 폴더를 만들 수 없습니다. FTP에서 uploads 폴더 권한을 707로 설정하세요.'];
    }
    if (!is_file("$base/.htaccess")) {
        @file_put_contents("$base/.htaccess", "# 진단서 등 개인정보: 주소로 직접 열 수 없음 (attendance.php?file= 로만 열람)\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Deny from all\n</IfModule>\n");
    }
    $file = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($f['tmp_name'], APP_ROOT . "/$dirRel/$file")) {
        return [null, '첨부 파일 저장 실패. uploads 폴더 쓰기 권한(707)을 확인하세요.'];
    }
    return ["$dirRel/$file", null];
}

/** 달력에 그릴 막대 (week_layout 용) */
function att_calendar_items(array $recs): array
{
    return array_map(function ($r) {
        $time = att_is_time_kind($r['kind']);
        return [
            'id' => (int) $r['journal_id'], 'start_date' => $r['start_date'], 'end_date' => $r['end_date'],
            'all_day' => $time ? 0 : 1, 'start_time' => $time ? substr((string) $r['start_time'], 0, 5) : '',
            'kind' => $r['kind'], 'status' => $r['status'], 'user_id' => (int) $r['user_id'],
            'title' => $r['user_name'] . ' ' . att_kind_name($r['kind']) . ($time ? ' ' . substr((string) $r['start_time'], 0, 5) . '~' . substr((string) $r['end_time'], 0, 5) : ''),
            'when' => att_when($r), 'rec' => $r,
        ];
    }, $recs);
}

/** 결재함 등에 쓰는 문서 이름 ("근태 · 홍길동 연차 9/23(수)") */
function journal_type_label(array $j): string
{
    if ($j['type'] !== 'attendance') return JOURNAL_TYPES[$j['type']] ?? $j['type'];
    $a = att_find((int) $j['id']);
    return $a ? '근태 · ' . $a['user_name'] . ' ' . att_kind_name($a['kind']) . ' ' . att_when($a, false) : '근태';
}
