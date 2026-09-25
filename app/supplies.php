<?php
defined('APP_ROOT') || exit;

/**
 * 객실 소모품 (객실관리 › 소모품관리)
 *  - 품목: 사진·품명·규격(스펙)·분류·단위·적정재고
 *  - 수불: 품목·날짜마다 입고·출고 1줄 (supply_moves). 재고 = 그 날까지 입고 합 − 출고 합
 */
const SUPPLY_PHOTO_MAX = 800;

/** @return array<int,array> id => 품목 ($activeOnly 면 사용 중인 것만) */
function supplies_all(bool $activeOnly = false): array
{
    $out = [];
    foreach (db()->query('SELECT * FROM supplies' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY is_active DESC, sort_order, category, name, id') as $s) $out[(int) $s['id']] = $s;
    return $out;
}

function supply_find(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM supplies WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** 그 날짜 전날까지의 재고: supply_id => 재고 */
function supply_stock_before(string $date): array
{
    $st = db()->prepare('SELECT supply_id, SUM(in_qty) - SUM(out_qty) AS n FROM supply_moves WHERE move_date < ? GROUP BY supply_id');
    $st->execute([$date]);
    return array_map('intval', array_column($st->fetchAll(), 'n', 'supply_id'));
}

/** 그 날의 수불: supply_id => 줄 */
function supply_moves_on(string $date): array
{
    $st = db()->prepare('SELECT m.*, u.name AS user_name FROM supply_moves m LEFT JOIN users u ON u.id = m.user_id WHERE m.move_date = ?');
    $st->execute([$date]);
    $out = [];
    foreach ($st as $r) $out[(int) $r['supply_id']] = $r;
    return $out;
}

/** 현재(오늘까지 입력된 모든) 재고: supply_id => 재고 */
function supply_stock_now(): array
{
    return array_map('intval', array_column(db()->query('SELECT supply_id, SUM(in_qty) - SUM(out_qty) AS n FROM supply_moves GROUP BY supply_id')->fetchAll(), 'n', 'supply_id'));
}

/**
 * 한 품목의 입고·출고를 바꾸면 그 날 이후 어느 날이라도 재고가 음수가 되는지 검사
 * @return ?string 음수가 되는 첫 날짜 (없으면 null)
 */
function supply_negative_after(int $supplyId, string $date, int $in, int $out): ?string
{
    $st = db()->prepare('SELECT move_date, in_qty, out_qty FROM supply_moves WHERE supply_id = ? AND move_date <> ? ORDER BY move_date');
    $st->execute([$supplyId, $date]);
    $days = [];
    foreach ($st as $r) $days[$r['move_date']] = (int) $r['in_qty'] - (int) $r['out_qty'];
    $days[$date] = $in - $out;
    ksort($days);
    $bal = 0;
    foreach ($days as $d => $delta) {
        $bal += $delta;
        if ($d >= $date && $bal < 0) return $d;
    }
    return null;
}

function supply_photo(array $s, string $class = 'supply-thumb'): string
{
    if (!empty($s['photo']) && is_file(APP_ROOT . '/' . $s['photo'])) {
        return '<img class="' . e($class) . '" src="' . e(url($s['photo'])) . '" alt="' . e($s['name']) . '" loading="lazy">';
    }
    return '<span class="' . e($class) . ' supply-nophoto">사진<br>없음</span>';
}

/** "샴푸 (500ml, 펌프형)" */
function supply_label(array $s): string
{
    return $s['name'] . (!empty($s['spec']) ? " ({$s['spec']})" : '');
}
