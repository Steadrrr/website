<?php
defined('APP_ROOT') || exit;

/**
 * DB 자동 업그레이드.
 * 새 버전 파일을 FTP로 덮어쓰기만 하면, 첫 접속 때 부족한 테이블/컬럼을 만든다.
 * (기존 자료는 그대로 유지)
 */
const DB_VERSION = 34;

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

    // 16) v15 → v16: 입장권 시스템 상품 '쉬자파크숙박(입실)'·'쉬자파크숙박(퇴실)' (무료, 수량 자동)
    if (!column_exists('products', 'sys_key')) {
        $pdo->exec("ALTER TABLE products ADD sys_key VARCHAR(20) NULL AFTER max_people");
    }
    db_seed_stay_products();

    // 17) v16 → v17: 프로그램 회차 담당자, 분야 '유아숲(직영)' 추가
    if (!enum_has('journals', 'type', 'kidsdirect')) {
        $pdo->exec("ALTER TABLE journals MODIFY type ENUM('daily','sales','facility','voucher','attendance','healing','kidsforest','guide','kidsdirect') NOT NULL");
    }
    if (!column_exists('program_sessions', 'staff')) {
        $pdo->exec("ALTER TABLE program_sessions ADD staff VARCHAR(100) NULL AFTER group_name");
    }

    // 18) v17 → v18: 시설대관·대관 숙박시설 통합 할인 (매출보고 1건에 하나)
    if (!column_exists('sales_meta', 'rent_dc_rule')) {
        $pdo->exec("ALTER TABLE sales_meta ADD rent_dc_rule VARCHAR(20) NULL AFTER ticket_cash, ADD rent_dc_pct TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER rent_dc_rule,
                    ADD rent_youth SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER rent_dc_pct");
    }

    // 19) v18 → v19: 조직도 숨김 (관리자 admin·테스트 test 계정은 처음부터 숨김)
    if (!column_exists('users', 'hide_in_org')) {
        $pdo->exec("ALTER TABLE users ADD hide_in_org TINYINT(1) NOT NULL DEFAULT 0 AFTER menu_access");
        $pdo->exec("UPDATE users SET hide_in_org = 1 WHERE LOWER(username) IN ('admin', 'test')");
    }

    // 20) v19 → v20: 유실물 등록번호 (등록 연도별 일련번호). 이미 등록된 유실물은 등록 순서대로 번호를 붙인다
    if (!column_exists('lost_items', 'reg_no')) {
        $pdo->exec("ALTER TABLE lost_items ADD reg_no VARCHAR(20) NULL AFTER id, ADD UNIQUE KEY uq_reg_no (reg_no)");
    }
    $seq = [];
    foreach ($pdo->query('SELECT id, YEAR(created_at) AS y FROM lost_items WHERE reg_no IS NULL ORDER BY created_at, id')->fetchAll() as $r) {
        $y = (int) $r['y'];
        if (!isset($seq[$y])) {
            $st = $pdo->prepare("SELECT COALESCE(MAX(CAST(SUBSTRING(reg_no, 6) AS UNSIGNED)), 0) FROM lost_items WHERE reg_no LIKE ?");
            $st->execute(["$y-%"]);
            $seq[$y] = (int) $st->fetchColumn();
        }
        $seq[$y]++;
        $pdo->prepare('UPDATE lost_items SET reg_no = ? WHERE id = ?')->execute([sprintf('%d-%04d', $y, $seq[$y]), $r['id']]);
    }
    foreach ($seq as $y => $n) {
        $pdo->prepare("INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = GREATEST(CAST(value AS UNSIGNED), VALUES(value))")
            ->execute(["lost_seq_$y", (string) $n]);
    }

    // 21) v20 → v21: 객실 분류 (room_types 는 1) 단계에서 생성) — 처음에는 2인실·4인실·독채
    if (!column_exists('products', 'room_type_id')) {
        $pdo->exec("ALTER TABLE products ADD room_type_id INT UNSIGNED NULL AFTER max_people");
    }
    if ((int) $pdo->query('SELECT COUNT(*) FROM room_types')->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO room_types (name, sort_order) VALUES ('2인실', 10), ('4인실', 20), ('독채', 30)");
    }

    // 22) v21 → v22: 메인메뉴 '통계' 분리 — 운영관리 권한이 있던 사원은 통계 권한도 켠다 (매출통계가 운영관리에 있었으므로)
    $pdo->exec("UPDATE users SET menu_access = CONCAT(menu_access, ',stat')
                 WHERE menu_access IS NOT NULL AND FIND_IN_SET('ops', menu_access) AND NOT FIND_IN_SET('stat', menu_access)");

    // 23) v22 → v23: 민원(complaints), 일지 작성·수정 기록(journal_logs) — 둘 다 1) 단계에서 생성

    // 24) v23 → v24: 기간별 가격표(price_periods, price_period_items) — 1) 단계에서 생성

    // 25) v24 → v25: 상품권 금고 관리 — 불출·반납(voucher_issues…), 금고점검 보고서(journals type vcheck, voucher_checks).
    //     이 날부터 매출보고 지급분은 담당자 보유(불출분)에서 빠진다. 그 전 지급분은 금고에서 바로 나간 것으로 본다.
    if (!enum_has('journals', 'type', 'vcheck')) {
        $pdo->exec("ALTER TABLE journals MODIFY type ENUM('daily','sales','facility','voucher','attendance','healing','kidsforest','guide','kidsdirect','vcheck') NOT NULL");
    }
    $pdo->exec("INSERT IGNORE INTO settings (name, value) VALUES ('vault_start', CURDATE())");

    // 26) v25 → v26: 객실 요금·인원·환급액을 객실 분류에서 관리 (분류를 저장하면 그 분류 객실에 덮어씀).
    //     처음에는 분류마다 그 분류 첫 객실의 값을 가져온다.
    if (!column_exists('products', 'base_people')) {
        $pdo->exec("ALTER TABLE products ADD base_people SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER refund_peak");
    }
    $applyTypes = !column_exists('room_types', 'max_people');
    if (!column_exists('room_types', 'max_people')) {
        $pdo->exec("ALTER TABLE room_types ADD base_people SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER name,
                    ADD max_people SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER base_people,
                    ADD price INT UNSIGNED NOT NULL DEFAULT 0 AFTER max_people, ADD price_weekend INT UNSIGNED NOT NULL DEFAULT 0 AFTER price,
                    ADD price_peak INT UNSIGNED NOT NULL DEFAULT 0 AFTER price_weekend, ADD refund_amount INT UNSIGNED NOT NULL DEFAULT 0 AFTER price_peak,
                    ADD refund_weekend INT UNSIGNED NOT NULL DEFAULT 0 AFTER refund_amount, ADD refund_peak INT UNSIGNED NOT NULL DEFAULT 0 AFTER refund_weekend");
        $pdo->exec("UPDATE room_types t JOIN products p ON p.id = (SELECT p2.id FROM products p2 WHERE p2.grp = 'room' AND p2.room_type_id = t.id ORDER BY p2.is_active DESC, p2.sort_order, p2.id LIMIT 1)
                       SET t.max_people = p.max_people, t.price = p.price, t.price_weekend = p.price_weekend, t.price_peak = p.price_peak,
                           t.refund_amount = p.refund_amount, t.refund_weekend = p.refund_weekend, t.refund_peak = p.refund_peak");
    }
    if ($applyTypes) { // 분류 값을 그 분류 객실에 적용 (같은 분류 객실은 인원·요금·환급액이 같아진다)
        $pdo->exec("UPDATE products p JOIN room_types t ON t.id = p.room_type_id SET " . implode(', ', array_map(fn($c) => "p.$c = t.$c", ROOM_TYPE_COLS)) . " WHERE p.grp = 'room'");
    }

    // 27) v26 → v27: 일정 분류 '대관', 반복 일정 묶음(series_id)
    if (!enum_has('events', 'category', 'rental')) {
        $pdo->exec("ALTER TABLE events MODIFY category ENUM('event','construction','program','rental','etc','holiday','closed') NOT NULL DEFAULT 'etc'");
    }
    if (!column_exists('events', 'series_id')) {
        $pdo->exec("ALTER TABLE events ADD series_id VARCHAR(20) NULL AFTER author_id, ADD INDEX idx_series (series_id)");
    }

    // 28) v27 → v28: 객실 판매·지역상품권 환급을 매출보고에서 분리 → 일일객실판매 문서(type rooms), 따로 결재.
    //     기존 매출보고의 객실 줄과 그 환급분을 날짜마다 새 일일객실판매 문서로 옮긴다 (작성자·결재 상태·결재 기록 그대로)
    if (!enum_has('journals', 'type', 'rooms')) {
        $pdo->exec("ALTER TABLE journals MODIFY type ENUM('daily','sales','facility','voucher','attendance','healing','kidsforest','guide','kidsdirect','vcheck','rooms') NOT NULL");
    }
    $olds = $pdo->query("SELECT j.* FROM journals j WHERE j.type = 'sales' AND (
                             EXISTS (SELECT 1 FROM sales_lines l WHERE l.journal_id = j.id AND l.grp = 'room')
                          OR EXISTS (SELECT 1 FROM voucher_moves m WHERE m.journal_id = j.id AND m.direction = 'out'))")->fetchAll();
    foreach ($olds as $j) {
        $pdo->prepare("INSERT INTO journals (type, work_date, author_id, content, status, revision, submitted_at, completed_at, created_at)
                       VALUES ('rooms', ?, ?, ?, ?, 0, ?, ?, ?)")
            ->execute([$j['work_date'], $j['author_id'], '매출보고(문서번호 ' . $j['id'] . ')에서 옮김', $j['status'], $j['submitted_at'], $j['completed_at'], $j['created_at']]);
        $rid = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO approvals (journal_id, step_order, required_rank, approver_id, status, comment, acted_at)
                       SELECT ?, step_order, required_rank, approver_id, status, comment, acted_at FROM approvals WHERE journal_id = ?')->execute([$rid, $j['id']]);
        $pdo->prepare("UPDATE voucher_moves m JOIN sales_lines l ON l.id = m.line_id SET m.journal_id = ? WHERE l.journal_id = ? AND l.grp = 'room'")->execute([$rid, $j['id']]);
        $pdo->prepare("UPDATE sales_lines SET journal_id = ? WHERE journal_id = ? AND grp = 'room'")->execute([$rid, $j['id']]);
        $pdo->prepare("UPDATE voucher_moves SET journal_id = ? WHERE journal_id = ? AND direction = 'out'")->execute([$rid, $j['id']]); // 객실 미지정 환급(이전 자료)
    }

    // 29) v28 → v29: 프로그램 분야 '숲해설(용문산)' (type guide2)
    if (!enum_has('journals', 'type', 'guide2')) {
        $pdo->exec("ALTER TABLE journals MODIFY type ENUM('daily','sales','facility','voucher','attendance','healing','kidsforest','guide','kidsdirect','vcheck','rooms','guide2') NOT NULL");
    }

    // 30) v29 → v30: 매출보고 '프로그램 판매' (sales_lines grp program — 그 날 프로그램 운영보고에서 자동, 없으면 직접 입력)
    if (!enum_has('sales_lines', 'grp', 'program')) {
        $pdo->exec("ALTER TABLE sales_lines MODIFY grp ENUM('ticket','room','rental','lodge','program') NOT NULL");
    }
    if (!column_exists('sales_lines', 'prog_type')) {
        $pdo->exec("ALTER TABLE sales_lines ADD prog_type VARCHAR(20) NULL AFTER dc_pct, ADD sessions SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER prog_type,
                    ADD auto TINYINT(1) NOT NULL DEFAULT 0 AFTER sessions");
    }

    // 31) v30 → v31: 매출보고 프로그램 판매 인원을 유료(qty, 할인 포함)·무료(guests)로 나눔.
    //     자동 줄은 그 날 운영보고로 다시 나누고, 직접 입력한 줄은 모두 유료로 본다.
    if ((int) $pdo->query("SELECT COUNT(*) FROM sales_lines WHERE grp = 'program' AND auto = 1 AND guests = 0")->fetchColumn()) {
        $pdo->exec("UPDATE sales_lines l JOIN journals s ON s.id = l.journal_id
                       SET l.qty = (SELECT COALESCE(SUM(IF(p.fee_type <> 'free', p.total, 0)), 0) FROM journals j JOIN program_sessions p ON p.journal_id = j.id
                                     WHERE j.type = l.prog_type AND j.work_date = s.work_date AND j.status <> 'rejected'),
                           l.guests = (SELECT COALESCE(SUM(IF(p.fee_type = 'free', p.total, 0)), 0) FROM journals j JOIN program_sessions p ON p.journal_id = j.id
                                     WHERE j.type = l.prog_type AND j.work_date = s.work_date AND j.status <> 'rejected')
                     WHERE l.grp = 'program' AND l.auto = 1");
    }

    // 32) v31 → v32: 프로그램 상품 (products grp program, 유료·할인 1인 요금). 운영보고 회차마다 프로그램을 고른다.
    //     기본정보의 1인 참가비(유료·할인)로 '기본 프로그램'을 만들고, 지난 회차는 모두 그 프로그램으로 둔다.
    if (!enum_has('products', 'grp', 'program')) {
        $pdo->exec("ALTER TABLE products MODIFY grp ENUM('ticket','room','rental','lodge','program') NOT NULL");
    }
    if (!column_exists('products', 'price_discount')) {
        $pdo->exec("ALTER TABLE products ADD price_discount INT UNSIGNED NOT NULL DEFAULT 0 AFTER price_night, ADD prog_types VARCHAR(200) NULL AFTER price_discount");
    }
    if (!column_exists('price_period_items', 'price_discount')) {
        $pdo->exec("ALTER TABLE price_period_items ADD price_discount INT UNSIGNED NOT NULL DEFAULT 0 AFTER price_night");
    }
    if (!column_exists('program_sessions', 'product_id')) {
        $pdo->exec("ALTER TABLE program_sessions ADD product_id INT UNSIGNED NULL AFTER staff, ADD product_name VARCHAR(100) NULL AFTER product_id");
    }
    if (!(int) $pdo->query("SELECT COUNT(*) FROM products WHERE grp = 'program'")->fetchColumn()) {
        $fee = function (string $k, int $d) use ($pdo): int {
            $v = $pdo->query('SELECT value FROM settings WHERE name = ' . $pdo->quote($k))->fetchColumn();
            return $v === false || $v === '' ? $d : (int) $v;
        };
        $pdo->prepare("INSERT INTO products (grp, name, price, price_discount, sort_order, is_active) VALUES ('program', '기본 프로그램', ?, ?, 10, 1)")
            ->execute([$fee('program_fee', 5000), $fee('program_fee_dc', 3000)]);
        $pid = (int) $pdo->lastInsertId();
        $pdo->prepare("UPDATE program_sessions SET product_id = ?, product_name = '기본 프로그램' WHERE product_id IS NULL")->execute([$pid]);
    }

    // 33) v32 → v33: 메인메뉴 객실관리 (객실판매관리·소모품관리·AR사용관리). supplies·supply_moves·ar_plans·ar_workers 는 1) 단계에서 생성
    //     운영관리 메뉴 권한이 있던 사원은 객실관리 권한도 켠다 (객실판매관리를 계속 쓰도록)
    if (!enum_has('journals', 'type', 'arwork')) {
        $pdo->exec("ALTER TABLE journals MODIFY type ENUM('daily','sales','facility','voucher','attendance','healing','kidsforest','guide','kidsdirect','vcheck','rooms','guide2','arwork') NOT NULL");
        $pdo->exec("UPDATE users SET menu_access = CONCAT(menu_access, ',room')
                     WHERE menu_access IS NOT NULL AND FIND_IN_SET('ops', menu_access) AND NOT FIND_IN_SET('room', menu_access)");
    }

    // 34) v33 → v34: AR 사용보고를 월 단위로 (줄마다 사용일 ar_workers.work_date). 지난 일별 보고서의 줄은 그 보고서 날짜를 사용일로
    if (!column_exists('ar_workers', 'work_date')) {
        $pdo->exec("ALTER TABLE ar_workers ADD work_date DATE NULL AFTER journal_id, ADD INDEX idx_work_date (work_date)");
    }
    $pdo->exec("UPDATE ar_workers w JOIN journals j ON j.id = w.journal_id SET w.work_date = j.work_date WHERE w.work_date IS NULL");

    $pdo->prepare("INSERT INTO settings (name, value) VALUES ('db_version', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)")
        ->execute([(string) DB_VERSION]);
}

/** 쉬자파크숙박 입실·퇴실 입장권 (없을 때만 만든다) */
function db_seed_stay_products(): void
{
    $pdo = db();
    foreach (STAY_PRODUCTS as $key => $name) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM products WHERE sys_key = ?');
        $st->execute([$key]);
        if (!(int) $st->fetchColumn()) {
            $pdo->prepare("INSERT INTO products (grp, name, is_free, price, sys_key, sort_order, is_active) VALUES ('ticket', ?, 1, 0, ?, ?, 1)")
                ->execute([$name, $key, $key === 'stay_in' ? 9000 : 9001]);
        }
    }
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
