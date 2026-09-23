<?php
defined('APP_ROOT') || exit;

/* 일정표: 분류와 색 (구글 캘린더 색상 계열) */
const EVENT_CATEGORIES = [
    'event'        => ['행사', '#3f51b5'],
    'construction' => ['공사', '#e8710a'],
    'program'      => ['프로그램', '#0b8043'],
    'etc'          => ['기타', '#8e24aa'],
];

/** 수정·삭제 권한: 작성자, 주무관 이상, 최고관리자 */
function can_edit_event(array $ev, array $user): bool
{
    return (int) $ev['author_id'] === (int) $user['id'] || (int) $user['rank_level'] >= RANK_OFFICER || !empty($user['is_admin']);
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
