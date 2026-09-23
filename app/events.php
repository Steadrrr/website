<?php
defined('APP_ROOT') || exit;

/* 일정표: 분류와 색 (구글 캘린더 색상 계열) */
const EVENT_CATEGORIES = [
    'event'        => ['행사', '#3f51b5'],
    'construction' => ['공사', '#e8710a'],
    'program'      => ['프로그램', '#0b8043'],
    'etc'          => ['기타', '#8e24aa'],
    'holiday'      => ['공휴일', '#d50000'],
    'closed'       => ['휴관일', '#616161'],
];
// 최고관리자만 등록·수정하는 분류 (근태관리의 근무일 계산에 쓰임: 공휴일은 근무일에서 빠짐)
const ADMIN_EVENT_CATEGORIES = ['holiday', 'closed'];

/** 이 분류로 일정을 만들 수 있는가 */
function can_use_event_category(string $cat, array $user): bool
{
    return isset(EVENT_CATEGORIES[$cat]) && (!in_array($cat, ADMIN_EVENT_CATEGORIES, true) || !empty($user['is_admin']));
}

/** 수정·삭제 권한: 작성자, 주무관 이상, 최고관리자 (공휴일·휴관일은 최고관리자만) */
function can_edit_event(array $ev, array $user): bool
{
    if (in_array($ev['category'], ADMIN_EVENT_CATEGORIES, true)) return !empty($user['is_admin']);
    return (int) $ev['author_id'] === (int) $user['id'] || (int) $user['rank_level'] >= RANK_OFFICER || !empty($user['is_admin']);
}

/** 기간 안의 공휴일 날짜 목록 ['2026-10-03' => '개천절', ...] */
function holiday_dates(string $from, string $to, array $cats = ['holiday']): array
{
    $out = [];
    foreach (events_between($from, $to) as $ev) {
        if (!in_array($ev['category'], $cats, true)) continue;
        for ($d = max($ev['start_date'], $from); $d <= min($ev['end_date'], $to); $d = date('Y-m-d', strtotime("$d +1 day"))) {
            $out[$d] = isset($out[$d]) ? $out[$d] . ', ' . $ev['title'] : $ev['title'];
        }
    }
    return $out;
}

/** 기간과 겹치는 일정 */
function events_between(string $from, string $to): array
{
    $st = db()->prepare(
        'SELECT e.*, u.name AS author_name FROM events e JOIN users u ON u.id = e.author_id
          WHERE e.start_date <= ? AND e.end_date >= ?
          ORDER BY e.start_date, e.all_day DESC, e.start_time, (e.end_date > e.start_date) DESC, e.id'
    );
    $st->execute([$to, $from]);
    return $st->fetchAll();
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
