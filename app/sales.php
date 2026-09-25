<?php
defined('APP_ROOT') || exit;

/** 대시보드 집계에 포함할 상태 조건 SQL 조각과 파라미터 */
function sales_status_filter(): array
{
    $statuses = config('chart_statuses', ['pending', 'approved']);
    return ['j.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')', $statuses];
}

/**
 * 기간 구간 정의
 * @param string $period day(최근 30일) | week(최근 12주, 월요일 시작) | month(최근 12개월)
 * @return array{0: DateTimeImmutable, 1: string[], 2: string[], 3: string, 4: string, 5: DateTimeImmutable} [시작일, 키, 라벨, SQL 키식, 현재구간 이름, 종료일]
 */
function period_buckets(string $period): array
{
    $today = new DateTimeImmutable('today');
    $keys = $labels = [];

    switch ($period) {
        case 'week':
            $start = $today->modify('monday this week')->modify('-11 weeks');
            for ($d = $start, $i = 0; $i < 12; $i++, $d = $d->modify('+1 week')) {
                $keys[] = $d->format('Y-m-d');
                $labels[] = $d->format('n/j') . '주';
            }
            return [$start, $keys, $labels, "DATE_FORMAT(DATE_SUB(j.work_date, INTERVAL WEEKDAY(j.work_date) DAY), '%Y-%m-%d')", '이번 주', $today->modify('sunday this week')];
        case 'month':
            $start = $today->modify('first day of this month')->modify('-11 months');
            for ($d = $start, $i = 0; $i < 12; $i++, $d = $d->modify('+1 month')) {
                $keys[] = $d->format('Y-m');
                $labels[] = $d->format('y.n월');
            }
            return [$start, $keys, $labels, "DATE_FORMAT(j.work_date, '%Y-%m')", '이번 달', $today->modify('last day of this month')];
        default:
            $start = $today->modify('-29 days');
            for ($d = $start, $i = 0; $i < 30; $i++, $d = $d->modify('+1 day')) {
                $keys[] = $d->format('Y-m-d');
                $labels[] = $d->format('n/j');
            }
            return [$start, $keys, $labels, "DATE_FORMAT(j.work_date, '%Y-%m-%d')", '오늘', $today];
    }
}

/**
 * 대시보드 시계열: 입장권(무료/유료 매수), 객실(판매 객실수/입실인원)
 * current = 마지막 구간(오늘/이번 주/이번 달) 값
 */
function dashboard_series(string $period): array
{
    [$start, $keys, $labels, $keyExpr, $currentLabel, $end] = period_buckets($period);
    [$cond, $params] = sales_status_filter();

    $st = db()->prepare(
        "SELECT $keyExpr AS k,
                SUM(IF(l.grp = 'ticket' AND l.is_free = 1, l.qty, 0)) AS free,
                SUM(IF(l.grp = 'ticket' AND l.is_free = 0, l.qty, 0)) AS paid,
                SUM(IF(l.grp = 'room', l.qty, 0))    AS rooms,
                SUM(IF(l.grp = 'room', l.guests, 0)) AS guests
           FROM journals j JOIN sales_lines l ON l.journal_id = j.id
          WHERE " . SALE_DOC_SQL . " AND $cond AND j.work_date BETWEEN ? AND ?
          GROUP BY k"
    );
    $st->execute([...$params, $start->format('Y-m-d'), $end->format('Y-m-d')]);
    $rows = [];
    foreach ($st as $r) $rows[$r['k']] = $r;

    $series = ['free' => [], 'paid' => [], 'rooms' => [], 'guests' => []];
    foreach ($keys as $k) {
        foreach ($series as $name => $_) {
            $series[$name][] = (int) ($rows[$k][$name] ?? 0);
        }
    }
    $last = fn(string $name) => (int) end($series[$name]);

    return [
        'period'  => $period,
        'labels'  => $labels,
        'series'  => $series,
        'current' => [
            'label'  => $currentLabel,
            'free'   => $last('free'),
            'paid'   => $last('paid'),
            'total'  => $last('free') + $last('paid'),
            'rooms'  => $last('rooms'),
            'guests' => $last('guests'),
        ],
    ];
}
