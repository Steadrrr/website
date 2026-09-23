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

/** @return array<int,array> 기간요금 id => 기간 */
function seasons_all(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT * FROM seasons ORDER BY grp, sort_order, id') as $s) {
            $cache[(int) $s['id']] = $s;
        }
    }
    return $cache;
}

/** @return array<int,array<int,int>> [season_id][product_id] => 기간 가격 */
function season_prices(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT * FROM season_prices') as $r) {
            $cache[(int) $r['season_id']][(int) $r['product_id']] = (int) $r['price'];
        }
    }
    return $cache;
}

/** 'MM-DD' 가 기간 안에 있는지 (11-01 ~ 02-29 처럼 해를 넘기는 기간 포함) */
function md_in_range(string $md, string $from, string $to): bool
{
    return $from <= $to ? ($md >= $from && $md <= $to) : ($md >= $from || $md <= $to);
}

/** 해당 날짜에 적용되는 기간요금 (없으면 null) */
function season_for(string $grp, string $date): ?array
{
    $md = date('m-d', strtotime($date));
    foreach (seasons_all() as $s) {
        if ($s['grp'] === $grp && $s['is_active'] && md_in_range($md, $s['start_md'], $s['end_md'])) return $s;
    }
    return null;
}

function season_label(array $s): string
{
    return $s['name'] . ' (' . str_replace('-', '/', $s['start_md']) . '~' . str_replace('-', '/', $s['end_md']) . ')';
}

/**
 * 입장권 단가: 기간요금(예: 동절기)이 적용되는 날이고 그 상품의 기간 가격이 있으면 기간 가격
 * @return array{0: int, 1: ?string} [단가, 적용된 기간 이름]
 */
function ticket_price(array $p, string $date): array
{
    if ($p['is_free']) return [0, null];
    $s = season_for('ticket', $date);
    if ($s && isset(season_prices()[(int) $s['id']][(int) $p['id']])) {
        return [season_prices()[(int) $s['id']][(int) $p['id']], $s['name']];
    }
    return [(int) $p['price'], null];
}

/** 객실 단가 (서버에서 항상 다시 계산한다. 화면 입력값은 믿지 않음) */
function room_price(array $p, string $rate, bool $discount = false): int
{
    $weekend = $rate === 'weekend';
    $normal  = (int) ($weekend ? $p['price_weekend'] : $p['price']);
    $dc      = (int) ($weekend ? $p['dc_weekend'] : $p['dc_weekday']);
    return $discount && $dc > 0 ? $dc : $normal; // 할인가 미설정(0)이면 정상가
}

/** 해당 날짜 숙박의 기본 요금구분: 금·토요일 또는 객실 성수기 기간 → 주말·성수기 */
function rate_for_date(string $date): string
{
    if (in_array((int) date('w', strtotime($date)), [5, 6], true)) return 'weekend';
    return season_for('room', $date) ? 'weekend' : 'weekday';
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
