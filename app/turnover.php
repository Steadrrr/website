<?php
defined('APP_ROOT') || exit;

/*
 * 입퇴실 목록 계산 (객실관리 › 청소관리). 예전 입퇴실현황 화면은 청소관리로 대체되어 없앴다.
 * 입실·퇴실 목록은 일일객실판매(반려 제외, 임시저장 포함)에서 계산한다: 입실예정 = 그 날 묵는 객실, 퇴실예정 = 전날 묵은 객실.
 * 연박은 일일객실판매에서 객실마다 고른 '연박 2·3박'(sales_lines.stay_nights)이 기준 — 그 날부터 그 박수만큼 한 손님.
 */

/** 기간 안의 객실별 판매(묵은) 날짜 [객실 id => [날짜 => ['g' => 입실 인원, 's' => 연박 박수]]] — 일일객실판매, 반려 제외 */
function rt_nights(string $from, string $to): array
{
    $st = db()->prepare("SELECT l.product_id, j.work_date, SUM(l.guests) AS g, MAX(l.stay_nights) AS s FROM journals j JOIN sales_lines l ON l.journal_id = j.id
                          WHERE j.type = 'rooms' AND j.status <> 'rejected' AND l.grp = 'room' AND j.work_date BETWEEN ? AND ?
                          GROUP BY l.product_id, j.work_date ORDER BY j.work_date");
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st as $r) $out[(int) $r['product_id']][$r['work_date']] = ['g' => (int) $r['g'], 's' => (int) $r['s']];
    return $out;
}

/**
 * 객실 하나의 숙박 구간: 연박(2·3박 …)을 고른 날부터 그 박수만큼은 한 손님 (다음 날 줄이 없어도 묵는 것으로 봄),
 * 연박이 아닌 판매는 하루씩 따로 (연달아 판매돼도 다른 손님).  [['from' => 첫날, 'last' => 마지막 날, 'nights', 'g'], ...]
 */
function rt_stays(array $days): array
{
    $stays = [];
    $until = '';
    foreach ($days as $d => $x) {
        if ($d <= $until) continue; // 앞선 연박 안의 날
        $n = max(1, $x['s']);
        $last = date('Y-m-d', strtotime("$d +" . ($n - 1) . ' days'));
        $stays[] = ['from' => $d, 'last' => $last, 'nights' => $n, 'g' => $x['g']];
        $until = $last;
    }
    return $stays;
}

/**
 * 그 날의 퇴실예정·입실예정 [out|in => [객실 id => ['p' => 상품, 'nights', 'guests', 'stay' => 연박 중(실제 퇴실·입실 없음)]]] (객실 순서대로)
 */
function turnover_lists(string $date): array
{
    $prev = date('Y-m-d', strtotime("$date -1 day"));
    // 연박을 이어 보려고 앞뒤 60일까지 읽는다
    $nights = rt_nights(date('Y-m-d', strtotime("$date -60 days")), date('Y-m-d', strtotime("$date +60 days")));
    $rooms = array_filter(products_all(), fn($p) => $p['grp'] === 'room');
    $types = room_types_all();
    $order = fn(array $p) => [(int) ($types[(int) $p['room_type_id']]['sort_order'] ?? 9999), (int) $p['sort_order'], (int) $p['id']];
    $lists = ['out' => [], 'in' => []];
    foreach ($nights as $pid => $days) {
        $p = $rooms[$pid] ?? null;
        if (!$p) continue;
        foreach (rt_stays($days) as $st) {
            $row = ['p' => $p, 'nights' => $st['nights'], 'guests' => $st['g']];
            // 퇴실예정: 전날 묵은 객실 — 연박 중이라 오늘도 묵으면 퇴실 없음
            if ($st['from'] <= $prev && $prev <= $st['last']) $lists['out'][$pid] = $row + ['stay' => $st['last'] > $prev];
            // 입실예정: 오늘 묵는 객실 — 연박으로 전부터 묵고 있으면 입실 없음
            if ($st['from'] <= $date && $date <= $st['last']) $lists['in'][$pid] = $row + ['stay' => $st['from'] < $date];
        }
    }
    foreach ($lists as &$l) uasort($l, fn($a, $b) => $order($a['p']) <=> $order($b['p']));
    unset($l);
    return $lists;
}
