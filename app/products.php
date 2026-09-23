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

/** 객실 요금구분별 할인율(%) — 상품관리에서 일괄 설정 */
function room_dc_pct(string $rate): int
{
    return (int) setting('room_dc_' . $rate, (string) (RATE_DC_DEFAULT[$rate] ?? 0));
}

/** 요금구분별 정상 요금 */
function room_base_price(array $p, string $rate): int
{
    return (int) match ($rate) {
        'weekend' => $p['price_weekend'],
        'peak'    => $p['price_peak'],
        default   => $p['price'],
    };
}

/** 요금구분별 지역상품권 기준 환급액 */
function room_refund(array $p, string $rate): int
{
    return (int) match ($rate) {
        'weekend' => $p['refund_weekend'] ?? $p['refund_amount'],
        'peak'    => $p['refund_peak'] ?? $p['refund_amount'],
        default   => $p['refund_amount'],
    };
}

/** 객실 단가 (서버에서 항상 다시 계산한다. 화면 입력값은 믿지 않음). 할인가는 10원 단위 버림 */
function room_price(array $p, string $rate, bool $discount = false): int
{
    $base = room_base_price($p, $rate);
    $pct = room_dc_pct($rate);
    return $discount && $pct > 0 ? intdiv($base * (100 - $pct), 1000) * 10 : $base;
}

/** 해당 날짜 숙박의 기본 요금구분: 객실 성수기 기간 → 성수기, 금·토요일 → 비수기 주말, 그 외 → 비수기 평일 */
function rate_for_date(string $date): string
{
    if (season_for('room', $date)) return 'peak';
    return in_array((int) date('w', strtotime($date)), [5, 6], true) ? 'weekend' : 'weekday';
}

/** 시설대관 단가 = 시간 요금 + (야간이면) 야간 추가요금 */
function rental_price(array $p, ?string $time, bool $night): int
{
    return ($time && isset(RENT_TIMES[$time]) ? (int) $p[RENT_TIMES[$time][1]] : 0) + ($night ? (int) $p['price_night'] : 0);
}

/** 할인 적용 금액 (10원 단위 버림) */
function dc_amount(int $amount, int $pct): int
{
    $pct = max(0, min(100, $pct));
    return $pct > 0 ? intdiv($amount * (100 - $pct), 1000) * 10 : $amount;
}

/** 대관 숙박시설 실 수로 정해지는 할인 기준 (9실 이상 20%, 5실 이상 10%) */
function rent_dc_auto(int $lodgeRooms): ?string
{
    return $lodgeRooms >= 9 ? 'lodge9' : ($lodgeRooms >= 5 ? 'lodge5' : null);
}

/** 시설대관 매출 한 줄 설명: "4시간 + 야간" */
function rental_desc(?string $time, bool $night): string
{
    return implode(' + ', array_filter([$time ? (RENT_TIMES[$time][0] ?? $time) : null, $night ? RENT_NIGHT_LABEL : null]));
}

/* ───────────── 쉬자파크숙박 입실·퇴실 (자동 입장권) ───────────── */
const STAY_PRODUCTS = ['stay_in' => '쉬자파크숙박(입실)', 'stay_out' => '쉬자파크숙박(퇴실)'];

/** 그 날짜 매출보고의 객실 입실인원 합계 (없으면 0) */
function stay_guests(string $date): int
{
    $st = db()->prepare("SELECT COALESCE(SUM(l.guests), 0) FROM journals j JOIN sales_lines l ON l.journal_id = j.id
                          WHERE j.type = 'sales' AND j.work_date = ? AND l.grp = 'room'");
    $st->execute([$date]);
    return (int) $st->fetchColumn();
}

/** 퇴실 인원 = 전날 입실인원 합계 */
function stay_out_guests(string $date): int
{
    return stay_guests(date('Y-m-d', strtotime("$date -1 day")));
}

/** 시스템 상품 sys_key => 상품 */
function stay_products(): array
{
    $out = [];
    foreach (products_all() as $p) if (!empty($p['sys_key'])) $out[$p['sys_key']] = $p;
    return $out;
}

/**
 * 어떤 날의 매출보고가 저장·삭제되면 다음 날 매출보고의 '쉬자파크숙박(퇴실)' 수량을 다시 맞춘다
 * (다음 날 보고서가 이미 있을 때)
 */
function stay_sync_next(string $date): void
{
    $out = stay_products()['stay_out'] ?? null;
    if (!$out) return;
    $pdo = db();
    $next = date('Y-m-d', strtotime("$date +1 day"));
    $st = $pdo->prepare("SELECT id FROM journals WHERE type = 'sales' AND work_date = ?");
    $st->execute([$next]);
    $jid = (int) $st->fetchColumn();
    if (!$jid) return;
    $qty = stay_guests($date);
    $pdo->prepare('DELETE FROM sales_lines WHERE journal_id = ? AND product_id = ?')->execute([$jid, $out['id']]);
    if ($qty > 0) {
        $pdo->prepare("INSERT INTO sales_lines (journal_id, product_id, grp, name, is_free, unit_price, qty, amount) VALUES (?, ?, 'ticket', ?, 1, 0, ?, 0)")
            ->execute([$jid, $out['id'], $out['name'], $qty]);
    }
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
