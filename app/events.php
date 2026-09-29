<?php
defined('APP_ROOT') || exit;

/* 일정표: 분류와 색 (구글 캘린더 색상 계열). 일정표(main)와 프로그램일정(program)이 같은 events 테이블을 분류로 나눠 쓴다 */
const EVENT_CATEGORIES = [
    'event'        => ['행사', '#3f51b5'],
    'construction' => ['공사', '#e8710a'],
    'rental'       => ['대관', '#00897b'],
    'etc'          => ['기타', '#8e24aa'],
    'holiday'      => ['공휴일', '#d50000'],
    // 프로그램일정 (메인메뉴 프로그램 › 프로그램일정)
    'p_healing'    => ['산림치유', '#0b8043'],
    'p_kids'       => ['유아숲', '#f09300'],
    'p_guide'      => ['숲해설', '#039be5'],
];
// 달력별 분류 (예전 '프로그램'·'휴관일' 분류는 DB v39 에서 프로그램일정·기타로 옮김)
const EVENT_CALENDARS = [
    'main'    => ['label' => '일정표', 'page' => 'schedule.php', 'cats' => ['event', 'construction', 'rental', 'etc', 'holiday'], 'default' => 'event'],
    'program' => ['label' => '프로그램일정', 'page' => 'program_schedule.php', 'cats' => ['p_healing', 'p_kids', 'p_guide'], 'default' => 'p_healing'],
];
// 최고관리자만 등록·수정하는 분류 (근태관리의 근무일 계산에 쓰임: 공휴일은 근무일에서 빠짐)
const ADMIN_EVENT_CATEGORIES = ['holiday'];

/** 그 분류가 속한 달력 (main / program) */
function event_calendar(string $cat): string
{
    foreach (EVENT_CALENDARS as $k => $c) if (in_array($cat, $c['cats'], true)) return $k;
    return 'main';
}

/** 그 일정을 보는 달력 페이지 주소 (그 달) */
function event_page_url(array $ev, ?string $date = null): string
{
    return EVENT_CALENDARS[event_calendar($ev['category'])]['page'] . '?ym=' . substr($date ?? $ev['start_date'], 0, 7);
}

/** 이 분류로 일정을 만들 수 있는가 */
/* ───────────── 반복 일정 ───────────── */
const EVENT_REPEATS = ['weekly' => '매주', 'monthly' => '매월', 'yearly' => '매년'];
const EVENT_REPEAT_MAX = 400;          // 한 번에 만드는 최대 개수
const EVENT_REPEAT_MAX_YEARS = 3;      // 반복 기간 최대 (년)

/**
 * 반복 규칙에 맞는 시작일 목록 (시작일 ~ 반복 종료일)
 * $r = ['repeat' => weekly|monthly|yearly, 'wd' => [0..6], 'mode' => date|nth, 'day' => 1..31, 'nth' => 1..4|-1(마지막), 'nwd' => 0..6, 'month' => 1..12]
 */
function event_repeat_dates(string $start, string $until, array $r): array
{
    $out = [];
    for ($t = strtotime($start), $end = strtotime($until); $t <= $end; $t = strtotime('+1 day', $t)) {
        $w = (int) date('w', $t);
        $day = (int) date('j', $t);
        $monthOk = $r['repeat'] !== 'yearly' || (int) date('n', $t) === (int) $r['month'];
        $inMonth = $r['mode'] === 'nth'
            ? $w === (int) $r['nwd'] && ((int) $r['nth'] === -1 ? $day + 7 > (int) date('t', $t) : (int) ceil($day / 7) === (int) $r['nth'])
            : $day === (int) $r['day'];
        $ok = $r['repeat'] === 'weekly' ? in_array($w, $r['wd'], true) : $monthOk && $inMonth;
        if ($ok) $out[] = date('Y-m-d', $t);
        if (count($out) > EVENT_REPEAT_MAX) break;
    }
    return $out;
}

/** "매주 월·수" / "매월 15일" / "매월 마지막 금요일" / "매년 3월 1일" */
function event_repeat_label(array $r): string
{
    $wd = ['일', '월', '화', '수', '목', '금', '토'];
    if ($r['repeat'] === 'weekly') return '매주 ' . implode('·', array_map(fn($w) => $wd[$w], $r['wd']));
    $pre = $r['repeat'] === 'yearly' ? '매년 ' . (int) $r['month'] . '월 ' : '매월 ';
    return $pre . ($r['mode'] === 'nth' ? ((int) $r['nth'] === -1 ? '마지막' : (int) $r['nth'] . '번째') . ' ' . $wd[(int) $r['nwd']] . '요일' : (int) $r['day'] . '일');
}

function can_use_event_category(string $cat, array $user): bool
{
    return isset(EVENT_CATEGORIES[$cat]) && (!in_array($cat, ADMIN_EVENT_CATEGORIES, true) || !empty($user['is_admin']));
}

/** 수정·삭제 권한: 작성자, 주무관 이상, 최고관리자 (공휴일은 최고관리자만) */
function can_edit_event(array $ev, array $user): bool
{
    if (in_array($ev['category'], ADMIN_EVENT_CATEGORIES, true)) return !empty($user['is_admin']);
    return (int) $ev['author_id'] === (int) $user['id'] || (int) $user['rank_level'] >= RANK_OFFICER || !empty($user['is_admin']);
}

/** 기간 안의 공휴일 날짜 목록 ['2026-10-03' => '개천절', ...] */
function holiday_dates(string $from, string $to, array $cats = ['holiday']): array
{
    $out = [];
    foreach (events_between($from, $to, $cats) as $ev) {
        for ($d = max($ev['start_date'], $from); $d <= min($ev['end_date'], $to); $d = date('Y-m-d', strtotime("$d +1 day"))) {
            $out[$d] = isset($out[$d]) ? $out[$d] . ', ' . $ev['title'] : $ev['title'];
        }
    }
    return $out;
}

/** 기간과 겹치는 일정 ($cats: 이 분류만, null 이면 전부) */
function events_between(string $from, string $to, ?array $cats = null): array
{
    $in = $cats ? ' AND e.category IN (' . implode(',', array_fill(0, count($cats), '?')) . ')' : '';
    $st = db()->prepare(
        'SELECT e.*, u.name AS author_name FROM events e JOIN users u ON u.id = e.author_id
          WHERE e.start_date <= ? AND e.end_date >= ?' . $in . '
          ORDER BY e.start_date, e.all_day DESC, e.start_time, (e.end_date > e.start_date) DESC, e.id'
    );
    $st->execute([$to, $from, ...($cats ?? [])]);
    return array_values(array_filter($st->fetchAll(), fn($e) => isset(EVENT_CATEGORIES[$e['category']])));
}

/** "10:00" / "종일" / "9/23 ~ 9/25" 같은 짧은 시간 표시 */
function event_when(array $ev, bool $withDate = false): string
{
    $d = fn(string $x) => date('n/j', strtotime($x)) . '(' . weekday_ko($x) . ')';
    $multi = $ev['start_date'] !== $ev['end_date'];
    $time = $ev['all_day'] ? '종일' : substr((string) $ev['start_time'], 0, 5) . ($ev['end_time'] ? '~' . substr((string) $ev['end_time'], 0, 5) : '');
    if ($multi) return $d($ev['start_date']) . ' ~ ' . $d($ev['end_date']) . ($ev['all_day'] ? '' : ' ' . $time);
    return ($withDate ? $d($ev['start_date']) . ' ' : '') . $time;
}

/**
 * 달력 한 주(일~토)에 들어갈 막대를 줄(lane)에 배치한다. 여러 날 일정이 먼저, 겹치지 않게.
 * $items 는 start_date, end_date, all_day, start_time 을 가진 배열 (일정·근태 공용)
 * @return array{0: array, 1: array<int,int>} [배치된 막대들, 요일별 숨겨진 개수]
 */
function week_layout(array $items, DateTimeImmutable $weekStart, int $visibleLanes = 3): array
{
    $ws = $weekStart->format('Y-m-d');
    $we = $weekStart->modify('+6 days')->format('Y-m-d');
    $items = array_values(array_filter($items, fn($e) => $e['start_date'] <= $we && $e['end_date'] >= $ws));
    usort($items, function ($a, $b) {
        $la = strtotime($a['end_date']) - strtotime($a['start_date']);
        $lb = strtotime($b['end_date']) - strtotime($b['start_date']);
        return [$a['start_date'], -$la, !$a['all_day'], (string) $a['start_time']] <=> [$b['start_date'], -$lb, !$b['all_day'], (string) $b['start_time']];
    });
    $occupied = []; // lane => [day => true]
    $placed = [];
    $hidden = array_fill(0, 7, 0);
    foreach ($items as $e) {
        $s = max(0, (int) ((strtotime($e['start_date']) - strtotime($ws)) / 86400));
        $t = min(6, (int) round((strtotime($e['end_date']) - strtotime($ws)) / 86400));
        for ($lane = 0; ; $lane++) {
            $free = true;
            for ($d = $s; $d <= $t; $d++) if (!empty($occupied[$lane][$d])) { $free = false; break; }
            if ($free) break;
        }
        for ($d = $s; $d <= $t; $d++) $occupied[$lane][$d] = true;
        if ($lane >= $visibleLanes) {
            for ($d = $s; $d <= $t; $d++) $hidden[$d]++;
            continue;
        }
        $placed[] = ['ev' => $e, 'lane' => $lane, 'start' => $s, 'span' => $t - $s + 1,
            'contL' => $e['start_date'] < $ws, 'contR' => $e['end_date'] > $we];
    }
    return [$placed, $hidden];
}

/** 달력 격자의 첫날(첫 주 일요일)과 마지막 날(마지막 주 토요일) */
function month_grid(string $ym): array
{
    $first = new DateTimeImmutable("$ym-01");
    $last = $first->modify('last day of this month');
    return [$first, $last, $first->modify('-' . (int) $first->format('w') . ' days'), $last->modify('+' . (6 - (int) $last->format('w')) . ' days')];
}

/**
 * 시간 격자(일·주 보기)에서 하루의 시간 일정 배치: 겹치는 일정은 나란히 칸을 나눈다.
 * @return array<int, array{0: array, 1: int, 2: int, 3: int, 4: int}> [일정, 시작분, 끝분, 칸 번호, 칸 수]
 */
function timegrid_columns(array $list, callable $mins): array
{
    $items = [];
    foreach ($list as $e) {
        $s = $mins($e['start_time']);
        $en = max($mins($e['end_time']) ?? $s + 60, $s + 30); // 종료 시간이 없으면 1시간, 너무 짧으면 30분으로 보여 줌
        $items[] = [$e, $s, $en];
    }
    usort($items, fn($a, $b) => [$a[1], -$a[2]] <=> [$b[1], -$b[2]]);
    $out = [];
    $group = [];   // 서로 겹쳐 이어지는 묶음
    $colsEnd = []; // 칸별 마지막 끝분
    $groupEnd = -1;
    $flush = function () use (&$out, &$group, &$colsEnd) {
        foreach ($group as $g) $out[] = [...$g, count($colsEnd)];
        $group = [];
        $colsEnd = [];
    };
    foreach ($items as [$e, $s, $en]) {
        if ($group && $s >= $groupEnd) $flush();
        $col = null;
        foreach ($colsEnd as $c => $end) if ($end <= $s) { $col = $c; break; }
        $col ??= count($colsEnd);
        $colsEnd[$col] = $en;
        $group[] = [$e, $s, $en, $col];
        $groupEnd = max($group ? $groupEnd : -1, $en);
    }
    $flush();
    return $out;
}

/* ───────────── 휴관일 (설정 › 휴관일) — 객실이용통계의 가동률 계산에서 뺀다 ───────────── */

/** 정기 휴관 요일 [0=일 … 6=토] (기본 화요일) */
function closed_weekdays(): array
{
    $v = trim((string) setting('closed_weekdays', '2'));
    if ($v === '') return [];
    return array_values(array_unique(array_filter(array_map('intval', explode(',', $v)), fn($w) => $w >= 0 && $w <= 6)));
}

/** 명절 등 휴관일 목록 (최근 것이 위) */
function closed_days_all(): array
{
    return db()->query('SELECT * FROM closed_days ORDER BY date_from DESC, id DESC')->fetchAll();
}

/** 기간 안의 휴관일 ['2026-10-06' => '정기 휴관(화)', '2027-02-07' => '2027 설날 휴관', ...] */
function closed_dates(string $from, string $to): array
{
    $out = [];
    $wds = closed_weekdays();
    $wdName = ['일', '월', '화', '수', '목', '금', '토'];
    if ($wds) {
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
            $w = (int) date('w', strtotime($d));
            if (in_array($w, $wds, true)) $out[$d] = "정기 휴관({$wdName[$w]})";
        }
    }
    $st = db()->prepare('SELECT * FROM closed_days WHERE date_from <= ? AND date_to >= ? ORDER BY date_from, id');
    $st->execute([$to, $from]);
    foreach ($st as $c) {
        for ($d = max($c['date_from'], $from); $d <= min($c['date_to'], $to); $d = date('Y-m-d', strtotime("$d +1 day"))) $out[$d] = $c['name'];
    }
    ksort($out);
    return $out;
}
