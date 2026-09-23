<?php
defined('APP_ROOT') || exit;

/**
 * DB 자동 업그레이드.
 * 새 버전 파일을 FTP로 덮어쓰기만 하면, 첫 접속 때 부족한 테이블/컬럼을 만든다.
 * (기존 자료는 그대로 유지)
 */
const DB_VERSION = 15;

function db_version(): int
{
    try {
        return (int) db()->query("SELECT value FROM settings WHERE name = 'db_version'")->fetchColumn();
    } catch (PDOException) {
        return 0; // settings 테이블 없음 = 최초 설치 또는 v1
    }
}

function column_exists(string $table, string $column): bool
{
    $st = db()->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return (bool) $st->fetchColumn();
}

/** ENUM 컬럼에 값이 이미 있는가 (없을 때만 MODIFY — 이미 넓어진 ENUM을 옛 목록으로 좁히지 않도록) */
function enum_has(string $table, string $column, string $value): bool
{
    $st = db()->prepare('SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $column]);
    return str_contains((string) $st->fetchColumn(), "'" . $value . "'");
}

function db_migrate(): void
{
    $pdo = db();

    // 1) 없는 테이블 생성
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents(APP_ROOT . '/sql/schema.sql'));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        $pdo->exec($stmt);
    }

    // 2) v1 → v2: 전결 권한, 상품권입고 문서, 전결(생략) 결재 상태
    if (!column_exists('users', 'can_delegate')) {
        $pdo->exec("ALTER TABLE users ADD can_delegate TINYINT(1) NOT NULL DEFAULT 0 COMMENT '전결 권한' AFTER is_admin");
    }
    if (!enum_has('journals', 'type', 'voucher')) {
        $pdo->exec("ALTER TABLE journals MODIFY type ENUM('daily','sales','facility','voucher') NOT NULL");
    }
    if (!enum_has('approvals', 'status', 'skipped')) {
        $pdo->exec("ALTER TABLE approvals MODIFY status ENUM('waiting','approved','rejected','skipped') NOT NULL DEFAULT 'waiting'");
    }

    // 3) v2 → v3: 시설점검 관리팀, 등록시설 연결, 기간요금 이름
    if (!column_exists('journals', 'team_id')) {
        $pdo->exec("ALTER TABLE journals ADD team_id INT UNSIGNED NULL AFTER type");
    }
    if (!column_exists('facility_items', 'facility_id')) {
        $pdo->exec("ALTER TABLE facility_items ADD facility_id INT UNSIGNED NULL AFTER journal_id, ADD area VARCHAR(100) NULL AFTER facility_id, ADD INDEX idx_fac (facility_id)");
    }
    if (!column_exists('sales_lines', 'season')) {
        $pdo->exec("ALTER TABLE sales_lines ADD season VARCHAR(50) NULL AFTER rate");
    }
    db_seed_v3();

    // 4) v3 → v4: 객실 요금 3구분(비수기 평일/비수기 주말/성수기), 객실별 상품권 환급
    if (!column_exists('products', 'price_peak')) {
        $pdo->exec("ALTER TABLE products ADD price_peak INT UNSIGNED NOT NULL DEFAULT 0 AFTER price_weekend");
        // 이전의 '주말·성수기' 요금을 성수기 요금으로도 채워 둔다 (상품관리에서 수정)
        $pdo->exec("UPDATE products SET price_peak = price_weekend WHERE grp = 'room'");
    }
    if (!column_exists('products', 'refund_amount')) {
        $pdo->exec("ALTER TABLE products ADD refund_amount INT UNSIGNED NOT NULL DEFAULT 0 AFTER price_peak");
    }
    if (!enum_has('sales_lines', 'rate', 'peak')) {
        $pdo->exec("ALTER TABLE sales_lines MODIFY rate ENUM('weekday','weekend','peak') NULL");
    }
    // 이전 보고서 중 성수기 기간에 '주말·성수기'로 저장된 객실은 성수기로 분류
    $pdo->exec("UPDATE sales_lines SET rate = 'peak' WHERE grp = 'room' AND rate = 'weekend' AND season IS NOT NULL");
    if (!column_exists('sales_lines', 'refund_expected')) {
        $pdo->exec("ALTER TABLE sales_lines ADD refund_expected INT UNSIGNED NULL AFTER guests");
    }
    if (!column_exists('voucher_moves', 'line_id')) {
        $pdo->exec("ALTER TABLE voucher_moves ADD line_id INT UNSIGNED NULL AFTER journal_id");
    }
    $pdo->exec("INSERT IGNORE INTO settings (name, value) VALUES ('room_dc_weekday', '30'), ('room_dc_weekend', '10'), ('room_dc_peak', '10')");

    // 5) v4 → v5: 일지 수정(누구나, 결재 초기화) 이력, 공지사항
    if (!column_exists('journals', 'revision')) {
        $pdo->exec("ALTER TABLE journals ADD revision INT UNSIGNED NOT NULL DEFAULT 0 AFTER status,
                    ADD last_edited_at DATETIME NULL AFTER revision, ADD last_edited_by INT UNSIGNED NULL AFTER last_edited_at");
    }

    // 6) v5 → v6: 회원 소속 팀·보직 (회원관리에서 관리자가 입력)
    if (!column_exists('users', 'team_id')) {
        $pdo->exec("ALTER TABLE users ADD team_id INT UNSIGNED NULL AFTER can_delegate, ADD position VARCHAR(50) NULL AFTER team_id");
    }

    // 7) v6 → v7: 회원 개인 사진
    if (!column_exists('users', 'photo')) {
        $pdo->exec("ALTER TABLE users ADD photo VARCHAR(200) NULL AFTER position");
    }

    // 8) v7 → v8: 조직 구성 (팀 아래 반, 반장), 상위 부서·사업장 이름
    if (!column_exists('users', 'squad_id')) {
        $pdo->exec("ALTER TABLE users ADD squad_id INT UNSIGNED NULL AFTER team_id, ADD is_squad_leader TINYINT(1) NOT NULL DEFAULT 0 AFTER squad_id");
    }
    $pdo->exec("INSERT IGNORE INTO settings (name, value) VALUES ('org_top_name', '양평군청 산림과 산림휴양팀'), ('org_park_name', '양평쉬자파크')");

    // 9) v8 → v9: 일정표 — events 테이블은 1) 단계(schema.sql)에서 만들어진다

    // 10) v9 → v10: 근태관리 — 근무 설정(입사일·계약종료일·근무시간·휴무요일), 근태 문서, 공휴일·휴관일
    //     (attendance 테이블은 1) 단계에서 만들어진다)
    if (!column_exists('users', 'hire_date')) {
        $pdo->exec("ALTER TABLE users ADD hire_date DATE NULL AFTER is_squad_leader, ADD contract_end DATE NULL AFTER hire_date,
                    ADD work_start TIME NULL AFTER contract_end, ADD work_end TIME NULL AFTER work_start, ADD off_days VARCHAR(20) NULL AFTER work_end");
    }
    if (!enum_has('journals', 'type', 'attendance')) {
        $pdo->exec("ALTER TABLE journals MODIFY type ENUM('daily','sales','facility','voucher','attendance') NOT NULL");
    }
    if (!enum_has('events', 'category', 'holiday')) {
        $pdo->exec("ALTER TABLE events MODIFY category ENUM('event','construction','program','etc','holiday','closed') NOT NULL DEFAULT 'etc'");
    }

    // 11) v10 → v11: 객실 상품권 환급액을 요금구분(비수기 평일/비수기 주말/성수기)별로
    //     (예전 환급액을 세 칸에 똑같이 채워 둔다 — 상품관리에서 수정)
    if (!column_exists('products', 'refund_weekend')) {
        $pdo->exec("ALTER TABLE products ADD refund_weekend INT UNSIGNED NOT NULL DEFAULT 0 AFTER refund_amount,
                    ADD refund_peak INT UNSIGNED NOT NULL DEFAULT 0 AFTER refund_weekend");
        $pdo->exec("UPDATE products SET refund_weekend = refund_amount, refund_peak = refund_amount WHERE grp = 'room'");
    }

    // 12) v11 → v12: 시설대관(시간별 요금 + 야간 추가요금), 대관 숙박시설(정액, 매출보고에서 할인율)
    if (!enum_has('products', 'grp', 'rental')) {
        $pdo->exec("ALTER TABLE products MODIFY grp ENUM('ticket','room','rental','lodge') NOT NULL");
    }
    if (!column_exists('products', 'price_2h')) {
        $pdo->exec("ALTER TABLE products ADD price_2h INT UNSIGNED NOT NULL DEFAULT 0 AFTER refund_peak, ADD price_4h INT UNSIGNED NOT NULL DEFAULT 0 AFTER price_2h,
                    ADD price_day INT UNSIGNED NOT NULL DEFAULT 0 AFTER price_4h, ADD price_night INT UNSIGNED NOT NULL DEFAULT 0 AFTER price_day");
    }
    if (!enum_has('sales_lines', 'grp', 'rental')) {
        $pdo->exec("ALTER TABLE sales_lines MODIFY grp ENUM('ticket','room','rental','lodge') NOT NULL");
    }
    if (!column_exists('sales_lines', 'rent_time')) {
        $pdo->exec("ALTER TABLE sales_lines ADD rent_time VARCHAR(10) NULL AFTER refund_expected, ADD night TINYINT(1) NOT NULL DEFAULT 0 AFTER rent_time,
                    ADD dc_pct TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER night");
    }

    // 13) v12 → v13: 유실물관리 — lost_items 테이블은 1) 단계(schema.sql)에서 만들어진다

    // 14) v13 → v14: 프로그램 운영보고 (산림치유센터·유아숲체험원·숲해설) — program_sessions 는 1) 단계에서 만들어진다
    if (!enum_has('journals', 'type', 'healing')) {
        $pdo->exec("ALTER TABLE journals MODIFY type ENUM('daily','sales','facility','voucher','attendance','healing','kidsforest','guide') NOT NULL");
    }
    if (!enum_has('photos', 'owner_type', 'program')) {
        $pdo->exec("ALTER TABLE photos MODIFY owner_type ENUM('facility','equipment','program') NOT NULL");
    }
    $pdo->exec("INSERT IGNORE INTO settings (name, value) VALUES ('program_fee', '5000')");

    // 15) v14 → v15: 프로그램 할인 요금, 회원별 메인메뉴 접근 권한
    if (!column_exists('program_sessions', 'fee_type')) {
        $pdo->exec("ALTER TABLE program_sessions ADD fee_type ENUM('paid','discount','free') NOT NULL DEFAULT 'paid' AFTER is_paid");
        $pdo->exec("UPDATE program_sessions SET fee_type = 'free' WHERE is_paid = 0");
    }
    $pdo->exec("INSERT IGNORE INTO settings (name, value) VALUES ('program_fee_dc', '3000')");
    if (!column_exists('users', 'menu_access')) {
        $pdo->exec("ALTER TABLE users ADD menu_access VARCHAR(100) NULL AFTER off_days");
    }

    $pdo->prepare("INSERT INTO settings (name, value) VALUES ('db_version', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
        ->execute([(string) DB_VERSION]);
}

/** 기본 관리팀·기간요금 (비어 있을 때만) */
function db_seed_v3(): void
{
    $pdo = db();
    if ((int) $pdo->query('SELECT COUNT(*) FROM teams')->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO teams (name, sort_order) VALUES ('휴양림팀', 10), ('산림문화팀', 20)");
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM seasons')->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO seasons (grp, name, start_md, end_md, sort_order) VALUES ('room', '성수기', '07-01', '08-31', 10)");
        $pdo->exec("INSERT INTO seasons (grp, name, start_md, end_md, sort_order) VALUES ('ticket', '동절기', '11-01', '02-29', 10)");
        // 이미 등록된 유료 입장권은 동절기 50% 가격으로 채워 둔다 (상품관리에서 수정 가능)
        $sid = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO season_prices (season_id, product_id, price)
                       SELECT ?, id, FLOOR(price / 2) FROM products WHERE grp = 'ticket' AND is_free = 0")
            ->execute([$sid]);
    }
}
