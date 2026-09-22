<?php
defined('APP_ROOT') || exit;

/** 그래프/합계에 포함할 상태 조건 SQL 조각과 파라미터 */
function sales_status_filter(): array
{
    $statuses = config('chart_statuses', ['pending', 'approved']);
    return ['j.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')', $statuses];
}

function sales_total(string $from, string $to): int
{
    [$cond, $params] = sales_status_filter();
    $st = db()->prepare(
        "SELECT COALESCE(SUM(s.card + s.cash + s.transfer), 0)
           FROM journals j JOIN sales_items s ON s.journal_id = j.id
          WHERE j.type = 'sales' AND $cond AND j.work_date BETWEEN ? AND ?"
    );
    $st->execute([...$params, $from, $to]);
    return (int) $st->fetchColumn();
}

/**
 * 기간별 매출 시계열 (Chart.js 형식)
 * @param string $period day(최근 30일) | week(최근 12주, 월요일 시작) | month(최근 12개월)
 */
function sales_series(string $period): array
{
    $today = new DateTimeImmutable('today');
    $keys = [];
    $labels = [];

    switch ($period) {
        case 'week':
            $start = $today->modify('monday this week')->modify('-11 weeks');
            $keyExpr = "DATE_FORMAT(DATE_SUB(j.work_date, INTERVAL WEEKDAY(j.work_date) DAY), '%Y-%m-%d')";
            for ($d = $start, $i = 0; $i < 12; $i++, $d = $d->modify('+1 week')) {
                $keys[] = $d->format('Y-m-d');
                $labels[] = $d->format('n/j') . '주';
            }
            break;
        case 'month':
            $start = $today->modify('first day of this month')->modify('-11 months');
            $keyExpr = "DATE_FORMAT(j.work_date, '%Y-%m')";
            for ($d = $start, $i = 0; $i < 12; $i++, $d = $d->modify('+1 month')) {
                $keys[] = $d->format('Y-m');
                $labels[] = $d->format('y.n월');
            }
            break;
        default:
            $start = $today->modify('-29 days');
            $keyExpr = "DATE_FORMAT(j.work_date, '%Y-%m-%d')";
            for ($d = $start, $i = 0; $i < 30; $i++, $d = $d->modify('+1 day')) {
                $keys[] = $d->format('Y-m-d');
                $labels[] = $d->format('n/j');
            }
    }

    [$cond, $params] = sales_status_filter();
    $st = db()->prepare(
        "SELECT $keyExpr AS k, s.category, SUM(s.card + s.cash + s.transfer) AS amt
           FROM journals j JOIN sales_items s ON s.journal_id = j.id
          WHERE j.type = 'sales' AND $cond AND j.work_date BETWEEN ? AND ?
          GROUP BY k, s.category"
    );
    $st->execute([...$params, $start->format('Y-m-d'), $today->format('Y-m-d')]);

    $categories = config('sales_categories', []);
    $data = [];
    foreach ($st as $row) {
        if (!in_array($row['category'], $categories, true)) $categories[] = $row['category'];
        $data[$row['category']][$row['k']] = (int) $row['amt'];
    }

    $datasets = [];
    $totals = array_fill(0, count($keys), 0);
    foreach ($categories as $cat) {
        $series = [];
        foreach ($keys as $i => $k) {
            $v = $data[$cat][$k] ?? 0;
            $series[] = $v;
            $totals[$i] += $v;
        }
        $datasets[] = ['label' => $cat, 'data' => $series];
    }

    return ['period' => $period, 'labels' => $labels, 'datasets' => $datasets, 'totals' => $totals];
}
