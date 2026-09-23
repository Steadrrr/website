<?php
defined('APP_ROOT') || exit;

/** @return array<int,array> id => 상품 */
function products_all(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT * FROM products ORDER BY grp, sort_order, id') as $p) {
            $cache[(int) $p['id']] = $p;
        }
    }
    return $cache;
}

/** 그룹별 판매중 상품 + (수정 중인 보고서에 이미 들어있는) 미사용 상품 */
function products_for_form(string $grp, array $includeIds = []): array
{
    return array_filter(
        products_all(),
        fn($p) => $p['grp'] === $grp && ($p['is_active'] || in_array((int) $p['id'], $includeIds, true))
    );
}

/** 상품 단가 계산 (서버에서 항상 다시 계산한다. 화면 입력값은 믿지 않음) */
function product_unit_price(array $p, ?string $rate = null, bool $discount = false): int
{
    if ($p['grp'] === 'ticket') {
        return $p['is_free'] ? 0 : (int) $p['price'];
    }
    $weekend = $rate === 'weekend';
    $normal  = (int) ($weekend ? $p['price_weekend'] : $p['price']);
    $dc      = (int) ($weekend ? $p['dc_weekend'] : $p['dc_weekday']);
    return $discount && $dc > 0 ? $dc : $normal; // 할인가 미설정(0)이면 정상가
}

/** 해당 날짜 숙박의 기본 요금구분: 금·토요일 또는 성수기 → 주말·성수기 */
function rate_for_date(string $date): string
{
    $ts = strtotime($date);
    if (in_array((int) date('w', $ts), [5, 6], true)) return 'weekend';
    $md = date('m-d', $ts);
    foreach (config('peak_seasons', [['07-15', '08-24']]) as [$from, $to]) {
        if ($md >= $from && $md <= $to) return 'weekend';
    }
    return 'weekday';
}

function voucher_denoms(): array
{
    return config('voucher_denoms', [1000, 5000, 10000]);
}

function denom_label(int $denom): string
{
    return $denom >= 10000 && $denom % 10000 === 0 ? ($denom / 10000) . '만원권' : number_format($denom / 1000) . '천원권';
}

/**
 * 수불에 반영되는 조건 (SQL 조각, 별칭 j = journals, m = voucher_moves)
 *   입고: 상품권입고 문서가 결재완료된 것만
 *   출고: 매출보고가 임시저장이 아닌 것 (실제로 손님에게 나간 상품권)
 */
const VOUCHER_COUNTED_SQL = "((m.direction = 'in' AND j.status = 'approved') OR (m.direction = 'out' AND j.status <> 'draft'))";

/** 권종별 재고 매수. $before 를 주면 그 날짜 이전까지의 재고(이월) */
function voucher_stock(?string $before = null): array
{
    $sql = 'SELECT m.denom, SUM(IF(m.direction = \'in\', m.qty, -CAST(m.qty AS SIGNED))) AS n
              FROM voucher_moves m JOIN journals j ON j.id = m.journal_id
             WHERE ' . VOUCHER_COUNTED_SQL . ($before ? ' AND j.work_date < ?' : '') . '
             GROUP BY m.denom';
    $st = db()->prepare($sql);
    $st->execute($before ? [$before] : []);
    $stock = array_fill_keys(voucher_denoms(), 0);
    foreach ($st as $r) {
        $stock[(int) $r['denom']] = (int) $r['n'];
    }
    return $stock;
}

function voucher_amount(array $byDenom): int
{
    $sum = 0;
    foreach ($byDenom as $denom => $qty) $sum += (int) $denom * (int) $qty;
    return $sum;
}
