<?php
defined('APP_ROOT') || exit;

/*
 * 경진대회 체험용 데모 사이트 (config 'demo' => true 일 때만 동작)
 *  - 가상 직원·객실·시설·민원 등 약 6개월치 자료를 만든다. 날짜는 '오늘' 기준이라 언제 열어도 최근 자료가 보인다.
 *  - 날짜가 바뀐 뒤 첫 접속 때 전부 지우고 다시 만든다 (매일 밤 초기화). 체험하며 바꾼 내용은 다음 날 사라진다.
 *  - 안전장치: 데모 표시(settings.demo_db)가 없는 DB에 자료가 있으면 운영 DB로 보고 절대 지우지 않는다.
 */

const DEMO_PASSWORD = 'demo1234';

/** 역할별 체험 계정 [아이디 => [이름, 직급, 최고관리자, 설명]] — 로그인 화면 버튼 */
const DEMO_ACCOUNTS = [
    'demo_keeper'  => ['김숲',   RANK_KEEPER,  0, '현장 직원 — 업무일지·청소관리·민원 입력'],
    'demo_worker'  => ['이나무', RANK_WORKER,  0, '중간관리 — 1차 결재·상품권 불출·객실 운영'],
    'demo_officer' => ['박하늘', RANK_OFFICER, 0, '담당 공무원 — 결재·통계·AI 예측 확인'],
    'demo_leader'  => ['최산림', RANK_LEADER,  1, '팀장(관리자) — 최종 결재·설정 전체'],
];

/** 데모 사이트면 날짜가 바뀐 뒤 첫 접속 때 자료를 새로 만든다 (bootstrap 에서 호출) */
function demo_maybe_reset(): void
{
    if (!is_demo()) return;
    $today = date('Y-m-d');
    if (setting('demo_seeded_on') === $today) return;
    $pdo = db();
    $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($users > 0 && setting('demo_db') !== '1') {
        setup_error("이 DB에는 이미 자료가 있지만 데모용 표시가 없습니다.\n운영 DB를 잘못 연결했을 수 있어 초기화하지 않았습니다. app/config.php 의 DB 정보를 확인하세요.");
    }
    // 여러 사람이 동시에 열어도 한 번만 만든다
    if ((int) $pdo->query("SELECT GET_LOCK('demo_reset', 30)")->fetchColumn() !== 1) return;
    try {
        $st = $pdo->query("SELECT value FROM settings WHERE name = 'demo_seeded_on'");
        if ($st->fetchColumn() !== $today) demo_seed();
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('demo_reset')");
    }
}

/** 데모 자료 전체 다시 만들기 */
function demo_seed(): void
{
    if (!is_demo()) throw new RuntimeException('데모 사이트에서만 실행할 수 있습니다.');
    $pdo = db();
    @set_time_limit(120);
    mt_srand(20261030); // 매번 같은 모양의 자료 (날짜만 오늘 기준으로 이동)

    // 1) 비우기 (settings 는 DB 버전·기본값이 있어 남긴다)
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        if ($t !== 'settings') $pdo->exec("TRUNCATE TABLE `$t`");
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    demo_clear_uploads();
    setting_set('demo_db', '1');

    $pdo->beginTransaction();
    try {
        demo_seed_data();
        setting_set('demo_seeded_on', date('Y-m-d'));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** 체험하며 올린 사진 등 지우기 (데모 사이트의 uploads 폴더만, _diag 제외) */
function demo_clear_uploads(): void
{
    if (!is_demo() || setting('demo_db') !== '1') return;
    $root = APP_ROOT . '/uploads';
    if (!is_dir($root)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($root) + 1);
        if (str_starts_with($rel, '_diag') || in_array($rel, ['.htaccess', 'index.html'], true)) continue;
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
}

/** 한 줄 넣고 id 돌려주기 */
function demo_ins(string $table, array $row): int
{
    $cols = array_keys($row);
    db()->prepare("INSERT INTO `$table` (`" . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($row));
    return (int) db()->lastInsertId();
}

function demo_pick(array $a): mixed
{
    return $a[mt_rand(0, count($a) - 1)];
}

/** 확률(0~1) */
function demo_chance(float $p): bool
{
    return mt_rand() / mt_getrandmax() < $p;
}

/**
 * 문서 하나 + 결재선. $state: approved(결재완료) / pending(첫 결재 대기) / draft
 * @return int journal id
 */
function demo_journal(string $type, string $date, array $author, string $state, array $byRank, array $extra = []): int
{
    $line = approval_line_for((int) $author['rank_level'], $type);
    $at = $date . ' 17:' . sprintf('%02d', mt_rand(0, 59)) . ':00';
    $status = $state === 'draft' ? 'draft' : ($state === 'pending' && $line ? 'pending' : 'approved');
    $id = demo_ins('journals', $extra + [
        'type' => $type, 'work_date' => $date, 'author_id' => $author['id'], 'status' => $status,
        'submitted_at' => $state === 'draft' ? null : $at, 'submitted_by' => $state === 'draft' ? null : $author['id'],
        'completed_at' => $status === 'approved' ? date('Y-m-d H:i:s', strtotime($at) + 3600 * 15) : null,
        'created_at' => $date . ' 09:10:00',
    ]);
    if ($state === 'draft') return $id;
    foreach ($line as $i => $rank) {
        $done = $status === 'approved';
        demo_ins('approvals', [
            'journal_id' => $id, 'step_order' => $i + 1, 'required_rank' => $rank,
            'approver_id' => $done ? $byRank[$rank]['id'] : null, 'status' => $done ? 'approved' : 'waiting',
            'acted_at' => $done ? date('Y-m-d H:i:s', strtotime($at) + 3600 * (14 + $i)) : null,
        ]);
    }
    return $id;
}

function demo_seed_data(): void
{
    $today = date('Y-m-d');
    $days = 180;
    $start = date('Y-m-d', strtotime("-$days days"));
    $closedWd = array_map('intval', array_filter(explode(',', (string) setting('closed_weekdays', '2')), 'strlen'));

    setting_set('org_park_name', '쉬자파크');
    setting_set('org_top_name', '○○군 산림휴양과');
    setting_set('vault_start', $start);

    /* ── 조직·직원 ── */
    $t1 = demo_ins('teams', ['name' => '휴양림팀', 'sort_order' => 1, 'use_facility' => 1]);
    $t2 = demo_ins('teams', ['name' => '산림문화팀', 'sort_order' => 2, 'use_facility' => 1]);
    $sqRoom = demo_ins('squads', ['team_id' => $t1, 'name' => '객실반', 'sort_order' => 1]);
    $sqFac = demo_ins('squads', ['team_id' => $t1, 'name' => '시설반', 'sort_order' => 2]);

    $hash = password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT);
    $U = [];
    foreach (DEMO_ACCOUNTS as $username => [$name, $rank, $admin, $desc]) {
        $U[$username] = ['id' => demo_ins('users', [
            'username' => $username, 'password_hash' => $hash, 'name' => $name, 'rank_level' => $rank, 'is_admin' => $admin,
            'can_delegate' => $rank === RANK_OFFICER ? 1 : 0, 'team_id' => $t1, 'status' => 'active',
            'position' => [RANK_KEEPER => '객실 관리', RANK_WORKER => '객실반장', RANK_OFFICER => '휴양림 운영 담당', RANK_LEADER => '휴양림팀장'][$rank],
            'squad_id' => $rank <= RANK_WORKER ? $sqRoom : null, 'is_squad_leader' => $rank === RANK_WORKER ? 1 : 0,
            'hire_date' => date('Y-m-d', strtotime('-' . (200 + $rank * 300) . ' days')),
            'work_start' => '09:00:00', 'work_end' => '18:00:00',
        ]), 'rank_level' => $rank, 'name' => $name];
    }
    $nologin = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT); // 화면용 직원 (로그인 불가)
    foreach ([['정다온', RANK_KEEPER, $sqRoom, '객실 관리'], ['한솔', RANK_KEEPER, $sqFac, '시설 관리'], ['윤바람', RANK_WORKER, $sqFac, '시설반장'],
              ['서하린', RANK_WORKER, null, '산림치유지도사']] as $i => [$name, $rank, $sq, $pos]) {
        $U["staff$i"] = ['id' => demo_ins('users', [
            'username' => "staff$i", 'password_hash' => $nologin, 'name' => $name, 'rank_level' => $rank, 'team_id' => $sq ? $t1 : $t2,
            'squad_id' => $sq, 'is_squad_leader' => $pos === '시설반장' ? 1 : 0, 'position' => $pos, 'status' => 'active',
            'hire_date' => date('Y-m-d', strtotime('-' . (300 + $i * 120) . ' days')),
        ]), 'rank_level' => $rank, 'name' => $name];
    }
    $byRank = [RANK_WORKER => $U['demo_worker'], RANK_OFFICER => $U['demo_officer'], RANK_LEADER => $U['demo_leader']];
    $keeper = $U['demo_keeper'];
    $worker = $U['demo_worker'];
    $officer = $U['demo_officer'];
    $fieldStaff = [$keeper, $U['staff0'], $U['staff1']];

    /* ── 상품: 입장권·객실·프로그램 ── */
    $tk = [];
    foreach ([['성인 입장권', 2000, 0], ['청소년 입장권', 1000, 0], ['어린이·경로 (무료)', 0, 1]] as $i => [$n, $p, $free]) {
        $tk[] = ['id' => demo_ins('products', ['grp' => 'ticket', 'name' => $n, 'price' => $p, 'is_free' => $free, 'sort_order' => $i]), 'name' => $n, 'price' => $p, 'free' => $free];
    }
    $types = [];
    foreach ([['4인실', 4, 6, 70000, 90000, 110000, 10000, 15000, 20000], ['8인실', 8, 10, 120000, 150000, 180000, 15000, 20000, 30000],
              ['독채', 6, 8, 150000, 190000, 230000, 20000, 30000, 30000]] as $i => $r) {
        $types[$r[0]] = ['id' => demo_ins('room_types', ['name' => $r[0], 'base_people' => $r[1], 'max_people' => $r[2], 'price' => $r[3], 'price_weekend' => $r[4],
            'price_peak' => $r[5], 'refund_amount' => $r[6], 'refund_weekend' => $r[7], 'refund_peak' => $r[8], 'sort_order' => $i]), 'r' => $r];
    }
    $rooms = []; // [id, name, type, 건물]
    $roomDefs = [['숲속의집 A-101', '4인실', 'A'], ['숲속의집 A-102', '4인실', 'A'], ['숲속의집 A-103', '4인실', 'A'], ['숲속의집 A-104', '8인실', 'A'],
                 ['숲속의집 B-201', '4인실', 'B'], ['숲속의집 B-202', '4인실', 'B'], ['숲속의집 B-203', '8인실', 'B'], ['숲속의집 B-204', '8인실', 'B'],
                 ['산림휴양관 301', '4인실', 'C'], ['산림휴양관 302', '4인실', 'C'], ['독채 솔향', '독채', 'D'], ['독채 별빛', '독채', 'D']];
    foreach ($roomDefs as $i => [$n, $tn, $bld]) {
        $r = $types[$tn]['r'];
        $rooms[] = ['id' => demo_ins('products', ['grp' => 'room', 'name' => $n, 'room_type_id' => $types[$tn]['id'], 'base_people' => $r[1], 'max_people' => $r[2],
            'price' => $r[3], 'price_weekend' => $r[4], 'price_peak' => $r[5], 'refund_amount' => $r[6], 'refund_weekend' => $r[7], 'refund_peak' => $r[8],
            'sort_order' => $i]), 'name' => $n, 'r' => $r, 'bld' => $bld];
    }
    $progHeal = demo_ins('products', ['grp' => 'program', 'name' => '산림치유 숲테라피', 'price' => 5000, 'price_discount' => 2500, 'prog_types' => 'healing', 'sort_order' => 1]);
    $peak = demo_ins('seasons', ['grp' => 'room', 'name' => '여름 성수기', 'start_md' => '07-15', 'end_md' => '08-20', 'sort_order' => 1]);

    /* ── 시설물·장비 ── */
    $fg = []; // 구역 이름 => id
    $fac = []; // 구역 이름 => [시설 이름 => id]
    $facDefs = [
        '숲속의집 A동' => ['보일러(A동)', '급수·온수 배관', '전기 분전반', '데크·테라스'],
        '숲속의집 B동' => ['보일러(B동)', '급수·온수 배관', '전기 분전반', '데크·테라스'],
        '산림휴양관' => ['냉난방기', '엘리베이터', '공용 화장실', '소방설비'],
        '야외시설' => ['물놀이장', '어린이 놀이터', '바비큐장', '산책로', '주차장'],
        '관리사무소' => ['CCTV·방송설비', '정수기'],
    ];
    $i = 0;
    foreach ($facDefs as $g => $items) {
        $fg[$g] = demo_ins('asset_groups', ['kind' => 'facility', 'team_id' => $t1, 'name' => $g, 'sort_order' => $i++]);
        foreach ($items as $k => $n) $fac[$g][$n] = demo_ins('facilities', ['group_id' => $fg[$g], 'name' => $n, 'sort_order' => $k]);
    }
    $eg = [];
    foreach (['조경·예초 장비', '차량·운반', '냉난방·설비', '청소 장비'] as $k => $n) {
        $eg[$n] = demo_ins('asset_groups', ['kind' => 'equipment', 'team_id' => $t1, 'name' => $n, 'sort_order' => $k]);
    }
    // [이름, 분류, 모델, 취득일(일 전), 취득가, 위치, 상태, 이력 [[일 전, kind, 내용, 비용, 업체]]]
    $eqDefs = [
        ['A동 가스보일러', '냉난방·설비', 'KD-30H', 3900, 2800000, '숲속의집 A동 기계실', 'repair', [
            [160, 'inspect', '난방 시즌 전 정기점검 — 이상 없음', null, '○○설비'], [75, 'repair', '점화 불량, 점화봉 교체', 180000, '○○설비'],
            [40, 'repair', '순환펌프 소음, 베어링 교체', 260000, '○○설비'], [12, 'repair', '온수 온도 불안정, 3방밸브 교체 (부품 노후)', 340000, '○○설비'],
            [3, 'inspect', '재발 — 업체 "교체 시기(내용연수 10년 경과)" 의견', null, '○○설비']]],
        ['B동 가스보일러', '냉난방·설비', 'KD-30H', 1500, 3100000, '숲속의집 B동 기계실', 'active', [
            [160, 'inspect', '정기점검 — 이상 없음', null, '○○설비'], [30, 'inspect', '정기점검 — 이상 없음', null, '○○설비']]],
        ['휴양관 시스템 냉난방기', '냉난방·설비', 'SAC-60', 2200, 9500000, '산림휴양관 옥상', 'active', [
            [120, 'maintain', '실외기 필터 청소·냉매 점검', 150000, '△△공조'], [20, 'inspect', '난방 전환 점검', null, '△△공조']]],
        ['물놀이장 순환펌프', '냉난방·설비', 'WP-5', 2600, 1900000, '야외 물놀이장 기계실', 'active', [
            [110, 'maintain', '개장 전 점검·여과기 청소', 90000, '□□펌프'], [70, 'repair', '누수, 메커니컬 씰 교체', 210000, '□□펌프'],
            [50, 'repair', '모터 과열로 정지, 콘덴서 교체', 120000, '□□펌프']]],
        ['예초기 1호', '조경·예초 장비', 'BC-430', 1300, 450000, '장비창고', 'active', [[140, 'maintain', '날·오일 교체', 35000, '장비상사'], [60, 'repair', '시동 불량, 점화플러그 교체', 25000, '장비상사']]],
        ['예초기 2호', '조경·예초 장비', 'BC-430', 600, 470000, '장비창고', 'active', [[140, 'maintain', '날·오일 교체', 35000, '장비상사']]],
        ['송풍기', '조경·예초 장비', 'EB-700', 900, 620000, '장비창고', 'active', [[25, 'inspect', '낙엽철 대비 점검', null, null]]],
        ['전동 운반카트', '차량·운반', 'EV-CART 2', 1800, 7800000, '관리사무소 주차장', 'active', [[90, 'repair', '배터리 성능 저하, 배터리 1개 교체', 380000, '◇◇모빌리티'], [15, 'inspect', '브레이크 점검', null, '◇◇모빌리티']]],
        ['1톤 트럭', '차량·운반', '포터Ⅱ', 2500, 18000000, '관리사무소 주차장', 'active', [[100, 'maintain', '엔진오일·필터 교환', 120000, '○○카센터']]],
        ['고압세척기', '청소 장비', 'HD-6/13', 1100, 1300000, '청소용품 창고', 'active', [[45, 'repair', '호스 연결부 누수', 40000, '장비상사']]],
        ['업소용 청소기', '청소 장비', 'NT-30', 700, 520000, '청소용품 창고', 'active', []],
    ];
    $eqIds = [];
    foreach ($eqDefs as [$n, $g, $model, $ago, $cost, $loc, $status, $logs]) {
        $acq = date('Y-m-d', strtotime("-$ago days"));
        $eid = demo_ins('equipment', ['group_id' => $eg[$g], 'name' => $n, 'model' => $model, 'acquired_on' => $acq, 'acquired_cost' => $cost, 'location' => $loc, 'status' => $status]);
        $eqIds[$n] = $eid;
        demo_ins('equipment_logs', ['equipment_id' => $eid, 'log_date' => $acq, 'kind' => 'register', 'content' => '장비 등록', 'user_id' => $officer['id'], 'status_after' => 'active']);
        foreach ($logs as [$lago, $kind, $content, $lcost, $vendor]) {
            if ($lago > $days) continue;
            demo_ins('equipment_logs', ['equipment_id' => $eid, 'log_date' => date('Y-m-d', strtotime("-$lago days")), 'kind' => $kind, 'content' => $content,
                'cost' => $lcost, 'vendor' => $vendor, 'user_id' => $U['staff2']['id'], 'status_after' => $kind === 'repair' && $lago < 5 && $status === 'repair' ? 'repair' : null]);
        }
    }

    /* ── 날짜별 업무: 객실판매·매출·업무일지·민원 ── */
    $amTpl = ['입장객 안내 및 매표소 운영', '퇴실 객실 점검 및 청소 배정', '산책로 순찰 (낙석·쓰러진 나무 확인)', '물놀이장 수질 점검', '객실 비품 보충', '주차장 정리·안내'];
    $pmTpl = ['입실 안내 및 객실키 전달', '바비큐장 정리', '시설 순찰 및 쓰레기 수거', '예약 문의 전화 응대', '프로그램 참가자 안내', '소모품 재고 확인'];
    $eveTpl = ['야간 순찰 (객실동·주차장)', '바비큐장 소등·정리', '입실 지연 고객 응대'];

    // 민원 내용 견본 [소분류 => [내용, 조치]]
    $cplText = [
        '1-1-1' => ['다음 달 예약 오픈일 문의', '매월 1일 10시 오픈 안내'], '1-1-3' => ['군민 할인 적용 방법 문의', '신분증 지참 시 현장 확인 안내'],
        '1-1-4' => ['우천 시 취소 위약금 문의', '취소 규정 안내'], '1-2-1' => ['입실 시간 전 짐 보관 가능 여부', '관리사무소 보관 안내'],
        '1-2-3' => ['반려동물 동반 가능 객실 문의', '동반 불가 안내'], '1-2-5' => ['바비큐 숯·그릴 대여 문의', '매점 판매 안내'],
        '1-3-1' => ['휴장일 문의', '매주 화요일 휴장 안내'], '1-3-5' => ['물놀이장 운영 기간 문의', '7~8월 운영 안내'],
        '1-4-2' => ['치유 프로그램 예약 방법', '전화 예약 안내'], '2-1-2' => ['수건 추가 요청', '수건 4장 전달'],
        '2-1-1' => ['휴대폰 충전기 대여', '충전기 대여 후 회수'], '2-1-3' => ['객실 변경 요청 (소음)', '동일 등급 객실로 변경'],
        '3-1-1' => ['욕실 머리카락·싱크대 음식물 남아 있음', '즉시 재청소, 청소 점검표 보완'], '3-1-3' => ['침구 부족', '침구 1세트 추가 전달'],
        '3-1-2' => ['객실 냄새 (환기 부족)', '환기·탈취 조치'],
        '3-2-1' => ['난방이 약하고 방바닥이 미지근함', '보일러 재가동, 업체 점검 요청'], '3-2-2' => ['온수가 나오다 찬물로 바뀜', '보일러 확인, 업체 수리 요청'],
        '3-2-3' => ['욕실 전등 깜빡임', '안정기 교체'], '3-2-4' => ['TV 리모컨 작동 안 됨', '건전지 교체'], '3-2-5' => ['욕실 배수구 막힘', '배수구 청소'],
        '3-3-1' => ['놀이터 그네 고리 소음', '고리 윤활·조임'], '3-3-2' => ['산책로 데크 일부 들뜸', '임시 통제 후 보수'],
        '4-1-1' => ['바비큐장 처마 밑 말벌집', '119 협조 제거'], '4-1-2' => ['객실 내 지네 출현', '방역 및 틈새 막음'],
        '4-2-2' => ['물놀이장 미끄러짐 (경상)', '응급처치, 미끄럼 방지 매트 추가'], '4-4-1' => ['폭우로 산책로 일부 유실', '구간 통제, 복구 요청'],
        '4-3-2' => ['단수 (배관 공사 영향)', '급수차 지원, 안내 문자'],
    ];
    $roomIdsBld = [];
    foreach ($rooms as $r) $roomIdsBld[$r['bld']][] = $r;

    $dailyIds = [];
    $soldYesterday = [];
    for ($d = 0; $d <= $days; $d++) {
        $date = date('Y-m-d', strtotime("$start +$d days"));
        $ts = strtotime($date);
        $wd = (int) date('w', $ts);
        $mon = (int) date('n', $ts);
        $md = date('m-d', $ts);
        $isPeak = $md >= '07-15' && $md <= '08-20';
        $isWeekend = in_array($wd, [5, 6], true); // 금·토 숙박
        $ago = $days - $d; // 0 = 오늘
        $state = $ago === 0 ? 'draft' : ($ago === 1 ? 'pending' : 'approved');
        $author = $fieldStaff[$d % 3];
        $closed = in_array($wd, $closedWd, true);

        // 업무일지 (휴장일도 순찰은 함)
        $jid = demo_journal('daily', $date, $state === 'approved' ? $author : $keeper, $state, $byRank, ['weather' => demo_pick(['맑음', '맑음', '흐림', '구름많음', $mon >= 6 && $mon <= 8 ? '비' : '맑음'])]);
        $dailyIds[$date] = $jid;
        $who = $state === 'approved' ? $author : $keeper;
        foreach (['am' => $amTpl, 'pm' => $pmTpl, 'eve' => $eveTpl] as $slot => $tpl) {
            if ($slot === 'eve' && !demo_chance(0.5)) continue;
            if ($ago === 0 && $slot !== 'am') continue; // 오늘은 오전까지만 써 둠
            $lines = $closed ? ['휴장일 — 시설 순찰 및 정비'] : array_unique([demo_pick($tpl), demo_pick($tpl)]);
            demo_ins('daily_entries', ['journal_id' => $jid, 'slot' => $slot, 'user_id' => $who['id'], 'content' => implode("\n", $lines), 'created_at' => "$date 11:00:00"]);
        }
        db()->prepare('UPDATE journals SET content = ? WHERE id = ?')->execute([daily_compose($jid), $jid]);

        if ($closed) {
            $soldYesterday = [];
            continue;
        }

        // 객실 판매 (금·토 거의 만실, 평일 30~50%, 성수기 높음)
        $occ = $isPeak ? ($isWeekend ? 1.0 : 0.8) : ($isWeekend ? 0.92 : ($mon >= 10 ? 0.45 : 0.35));
        $rate = $isPeak ? 'peak' : ($isWeekend ? 'weekend' : 'weekday');
        $rj = demo_journal('rooms', $date, $ago <= 1 ? $keeper : $author, $state, $byRank);
        $sold = [];
        foreach ($rooms as $r) {
            if (!demo_chance($occ)) continue;
            [$n, $base, $max, $p1, $p2, $p3, $f1, $f2, $f3] = $r['r'];
            $price = ['weekday' => $p1, 'weekend' => $p2, 'peak' => $p3][$rate];
            $refund = ['weekday' => $f1, 'weekend' => $f2, 'peak' => $f3][$rate];
            $dcReason = demo_chance(0.15) ? demo_pick(['multichild', 'local', 'disabled', 'multichild', 'work']) : null;
            $pct = $dcReason ? room_dc_pct_for($rate, $dcReason) : 0;
            $amount = (int) round($price * (100 - $pct) / 100);
            $refundExp = $dcReason && !room_dc_refundable($rate, $dcReason) ? 0 : $refund;
            demo_ins('sales_lines', ['journal_id' => $rj, 'product_id' => $r['id'], 'grp' => 'room', 'name' => $r['name'], 'rate' => $rate,
                'season' => $isPeak ? '여름 성수기' : null, 'discounted' => $dcReason ? 1 : 0, 'dc_reason' => $dcReason, 'unit_price' => $price,
                'qty' => 1, 'guests' => mt_rand(max(2, $base - 2), $max), 'refund_expected' => $refundExp, 'amount' => $amount]);
            $sold[] = $r;
        }

        // 매출보고 (입장권·프로그램)
        $sj = demo_journal('sales', $date, $ago <= 1 ? $worker : $U['staff2'], $state, $byRank);
        $visit = (int) round(($isPeak ? 900 : ($wd === 0 || $wd === 6 ? 420 : 140)) * (mt_rand(80, 120) / 100) * ($mon >= 11 ? 0.6 : 1));
        $ticketTotal = 0;
        foreach ($tk as $k => $t) {
            $q = (int) round($visit * [0.55, 0.15, 0.30][$k]);
            $amt = $t['free'] ? 0 : $q * $t['price'];
            $ticketTotal += $amt;
            demo_ins('sales_lines', ['journal_id' => $sj, 'product_id' => $t['id'], 'grp' => 'ticket', 'name' => $t['name'], 'is_free' => $t['free'],
                'unit_price' => $t['price'], 'qty' => $q, 'amount' => $amt]);
        }
        demo_ins('sales_meta', ['journal_id' => $sj, 'ticket_cash' => (int) round($ticketTotal * 0.2 / 1000) * 1000]);

        // 민원: 방문객·객실 수에 비례 + 계절·시설 상태에 따라
        $cands = [];
        $add = function (string $code, float $p, ?array $room = null, ?string $facGroup = null) use (&$cands) {
            if (demo_chance($p)) $cands[] = [$code, $room, $facGroup];
        };
        $add(demo_pick(['1-1-1', '1-1-3', '1-1-4', '1-3-1', '1-4-2']), 0.55);
        $add(demo_pick(['1-2-1', '1-2-3', '1-2-5']), 0.25 + 0.03 * count($sold));
        if ($sold) {
            $add(demo_pick(['2-1-2', '2-1-1', '2-1-3']), 0.04 * count($sold), demo_pick($sold));
            // 주말 만실 → 청소 품질 불만 (퇴실 몰리는 일·월요일 다음 입실)
            $add(demo_pick(['3-1-1', '3-1-3', '3-1-2']), ($isWeekend ? 0.35 : 0.08) + ($isPeak ? 0.15 : 0), demo_pick($sold));
            $add(demo_pick(['3-2-3', '3-2-4', '3-2-5']), 0.07, demo_pick($sold));
            // A동 보일러 노후: 날이 추워질수록, 최근일수록 난방·온수 민원 증가
            $soldA = array_values(array_filter($sold, fn($r) => $r['bld'] === 'A'));
            if ($soldA) {
                $cold = $mon >= 10 || $mon <= 3 ? 1.0 : ($mon === 9 || $mon === 4 || $mon === 5 ? 0.4 : 0.1);
                $recent = $ago < 20 ? 1.0 : ($ago < 50 ? 0.6 : 0.25);
                $add(demo_pick(['3-2-1', '3-2-2']), 0.5 * $cold * $recent * min(1, count($soldA) / 2), demo_pick($soldA));
            }
            $soldB = array_values(array_filter($sold, fn($r) => $r['bld'] !== 'A'));
            if ($soldB && ($mon >= 10 || $mon <= 3)) $add(demo_pick(['3-2-1', '3-2-2']), 0.03, demo_pick($soldB));
        }
        if ($mon >= 7 && $mon <= 9) $add('4-1-1', 0.08, null, '야외시설');
        if ($mon >= 6 && $mon <= 9) $add('4-1-2', 0.05, $sold ? demo_pick($sold) : null);
        if ($isPeak || ($mon === 8)) {
            $add('1-3-5', 0.4, null, '야외시설');
            $add('4-2-2', 0.05, null, '야외시설');
        }
        if ($mon === 7 && demo_chance(0.06)) $add('4-4-1', 1.0, null, '야외시설');
        $add(demo_pick(['3-3-1', '3-3-2']), 0.05, null, '야외시설');

        foreach ($cands as [$code, $room, $fgName]) {
            [$text, $act] = $cplText[$code];
            $c1 = substr($code, 0, 1);
            $status = $c1 === CPL_SIMPLE ? 'done' : ($ago <= 2 ? demo_pick(['open', 'progress']) : ($ago <= 6 && demo_chance(0.3) ? 'progress' : 'done'));
            demo_ins('complaints', [
                'journal_id' => $jid, 'work_date' => $date, 'cat1' => $c1, 'cat2' => substr($code, 0, 3), 'cat3' => $code,
                'channel' => $room ? demo_pick(['visit', 'visit', 'phone']) : demo_pick(['phone', 'phone', 'online', 'visit']),
                'place_type' => $room ? 'room' : ($fgName ? 'facility' : null), 'place_id' => $room ? $room['id'] : ($fgName ? $fg[$fgName] : null),
                'place_name' => $room ? $room['name'] : $fgName, 'content' => $text, 'qty' => $c1 === CPL_SIMPLE ? mt_rand(1, 3) : 1,
                'status' => $status, 'done_date' => $status === 'done' ? date('Y-m-d', $ts + ($c1 === CPL_SIMPLE ? 0 : 86400 * mt_rand(0, 2))) : null,
                'action' => $status === 'open' ? null : $act, 'receiver_id' => $who['id'], 'created_at' => "$date 14:00:00",
            ]);
        }

        // 프로그램 (토요일 산림치유)
        if ($wd === 6) {
            $pj = demo_journal('healing', $date, $U['staff3'], $state === 'draft' ? 'approved' : $state, $byRank);
            foreach ([['10:00:00', '12:00:00'], ['14:00:00', '16:00:00']] as $no => [$s, $e]) {
                $ppl = ['m_adult' => mt_rand(2, 6), 'f_adult' => mt_rand(3, 8), 'm_senior' => mt_rand(0, 3), 'f_senior' => mt_rand(0, 4)];
                $tot = array_sum($ppl);
                demo_ins('program_sessions', $ppl + ['journal_id' => $pj, 'session_no' => $no + 1, 'group_name' => demo_pick(['개인 참가', '○○복지관', '△△초 학부모회', '개인 참가']),
                    'staff' => $U['staff3']['name'], 'product_id' => $progHeal, 'product_name' => '산림치유 숲테라피', 'start_time' => $s, 'end_time' => $e,
                    'total' => $tot, 'fee_type' => 'paid', 'fee' => 5000, 'amount' => $tot * 5000, 'activity' => '오감 산책, 명상, 차 마시기']);
            }
        }
        $soldYesterday = $sold;
    }

    /* ── 시설점검 (매주 월요일, 휴양림팀) ── */
    for ($d = 0; $d <= $days; $d++) {
        $date = date('Y-m-d', strtotime("$start +$d days"));
        if ((int) date('w', strtotime($date)) !== 1) continue;
        $ago = $days - $d;
        $fj = demo_journal('facility', $date, $U['staff1'], $ago <= 1 ? 'pending' : 'approved', $byRank, ['team_id' => $t1]);
        $mon = (int) date('n', strtotime($date));
        foreach ($fac as $g => $list) {
            foreach ($list as $n => $fid) {
                $res = '정상';
                $note = null;
                if ($n === '보일러(A동)') {
                    if ($ago < 15) [$res, $note] = ['수리요청', '온수 온도 불안정 재발, 업체 교체 권고'];
                    elseif ($ago < 45) [$res, $note] = ['점검필요', '순환펌프 소음, 점화 지연'];
                    elseif ($ago < 80) [$res, $note] = [demo_pick(['정상', '점검필요']), '점화 지연 간헐 발생'];
                } elseif ($n === '물놀이장' && ($mon === 7 || $mon === 8)) {
                    if (demo_chance(0.3)) [$res, $note] = ['점검필요', '순환펌프 과열 확인'];
                } elseif ($n === '산책로' && demo_chance(0.12)) {
                    [$res, $note] = [demo_pick(['점검필요', '수리요청']), '데크 들뜸·낙엽 미끄럼'];
                } elseif (demo_chance(0.04)) {
                    [$res, $note] = ['점검필요', '경미한 이상, 다음 주 재확인'];
                }
                demo_ins('facility_items', ['journal_id' => $fj, 'facility_id' => $fid, 'area' => $g, 'facility' => $n, 'result' => $res, 'note' => $note]);
            }
        }
    }

    /* ── 오늘 청소관리: 어제 묵은 객실 = 오늘 퇴실 ── */
    $now = time();
    foreach ($soldYesterday as $k => $r) {
        $out = $k % 3 !== 2 ? date('Y-m-d H:i:s', strtotime($today . ' 10:' . sprintf('%02d', 5 + $k * 3))) : null;
        $clean = $out && $k % 3 === 0 ? date('Y-m-d H:i:s', strtotime($out) + 3600 + $k * 120) : null;
        if ($out && strtotime($out) > $now) $out = $clean = null; // 아직 오전이면 퇴실 전
        if ($clean && strtotime($clean) > $now) $clean = null;
        demo_ins('room_clean', ['work_date' => $today, 'product_id' => $r['id'], 'out_at' => $out, 'out_by' => $out ? $keeper['id'] : null,
            'clean_at' => $clean, 'clean_by' => $clean ? $U['staff0']['id'] : null]);
    }

    /* ── 결재 대기 문서 (역할별 결재함 체험용) ── */
    $pj = demo_journal('purchase', date('Y-m-d', strtotime('-1 day')), $worker, 'pending', $byRank, ['content' => '객실 소모품 구매']);
    demo_ins('purchase_meta', ['journal_id' => $pj, 'vendor' => '○○생활용품', 'pay_method' => 'card', 'total' => 214000]);
    foreach ([['수건 (40수)', '흰색', 40, 3500], ['주방세제', '1L', 12, 2500], ['쓰레기봉투', '50L', 10, 4400]] as $k => [$n, $spec, $q, $p]) {
        demo_ins('purchase_items', ['journal_id' => $pj, 'sort_no' => $k + 1, 'name' => $n, 'spec' => $spec, 'qty' => $q, 'unit_price' => $p, 'amount' => $q * $p]);
    }
    db()->prepare('UPDATE purchase_meta SET total = (SELECT SUM(amount) FROM purchase_items WHERE journal_id = ?) WHERE journal_id = ?')->execute([$pj, $pj]);
    $leave = date('Y-m-d', strtotime('+7 days'));
    $aj = demo_journal('attendance', date('Y-m-d', strtotime('-1 day')), $keeper, 'pending', $byRank, ['content' => '개인 사유 연차']);
    demo_ins('attendance', ['journal_id' => $aj, 'user_id' => $keeper['id'], 'kind' => 'annual', 'start_date' => $leave, 'end_date' => $leave, 'days' => 1]);

    /* ── 상품권 입고 (금고) ── */
    $vj = demo_journal('voucher', $start, $worker, 'approved', $byRank, ['content' => '지역상품권 입고']);
    foreach ([[10000, 400], [5000, 300], [1000, 200]] as [$denom, $q]) demo_ins('voucher_moves', ['journal_id' => $vj, 'direction' => 'in', 'denom' => $denom, 'qty' => $q]);
    $cj = demo_journal('vcheck', date('Y-m-d', strtotime('-10 days')), $worker, 'approved', $byRank, ['content' => '월간 금고점검']);
    foreach ([[10000, 400], [5000, 300], [1000, 200]] as [$denom, $q]) demo_ins('voucher_checks', ['journal_id' => $cj, 'denom' => $denom, 'book_qty' => $q, 'actual_qty' => $q]);
    for ($k = 6; $k >= 1; $k--) {
        $date = date('Y-m-d', strtotime("-$k days"));
        $iid = demo_ins('voucher_issues', ['work_date' => $date, 'kind' => 'issue', 'holder_id' => $keeper['id'], 'issuer_id' => $worker['id'], 'created_by' => $worker['id'], 'created_at' => "$date 14:00:00"]);
        demo_ins('voucher_issue_items', ['issue_id' => $iid, 'denom' => 10000, 'qty' => mt_rand(4, 10)]);
        demo_ins('voucher_issue_items', ['issue_id' => $iid, 'denom' => 5000, 'qty' => mt_rand(2, 6)]);
    }

    /* ── 공지·일정·유실물·소모품 ── */
    demo_ins('notices', ['title' => '[체험판] 이 사이트는 가상 자료로 만든 데모입니다', 'is_pinned' => 1, 'author_id' => $U['demo_leader']['id'],
        'body' => "직원·객실·민원 등 모든 자료는 가상입니다. 마음껏 눌러 보세요.\n바꾼 내용은 매일 밤 처음 상태로 돌아갑니다."]);
    demo_ins('notices', ['title' => '동절기 보일러 점검 일정 안내', 'author_id' => $officer['id'], 'body' => '11월 첫째 주 숲속의집 A·B동 보일러 정밀점검 예정입니다.', 'created_at' => date('Y-m-d H:i:s', strtotime('-3 days'))]);
    demo_ins('notices', ['title' => '주말 만실 대비 청소 인력 배치', 'author_id' => $worker['id'], 'body' => '금·토 만실 예상 — 일요일 오전 청소 2인 추가 배치합니다.', 'created_at' => date('Y-m-d H:i:s', strtotime('-6 days'))]);
    foreach ([[2, 2, '가을 숲 음악회', 'event', '야외무대'], [9, 11, 'A동 보일러 정밀점검', 'construction', '숲속의집 A동'], [5, 5, '○○초 숲체험 단체', 'rental', '교육관'],
              [-4, -4, '소방 합동훈련', 'etc', '산림휴양관']] as [$a, $b, $title, $cat, $loc]) {
        demo_ins('events', ['title' => $title, 'category' => $cat, 'start_date' => date('Y-m-d', strtotime("$a days")), 'end_date' => date('Y-m-d', strtotime("$b days")),
            'location' => $loc, 'author_id' => $officer['id']]);
    }
    $y = date('Y');
    foreach ([[3, '검은색 우산', '바비큐장', 'received'], [8, '어린이 운동화 (분홍)', '물놀이장', 'contacted'], [15, '안경 (뿔테)', '숲속의집 B-202', 'shipped'],
              [26, '보조배터리', '산림휴양관 301', 'returned']] as $k => [$ago, $n, $place, $st]) {
        demo_ins('lost_items', ['reg_no' => sprintf('%s-%04d', $y, $k + 1), 'name' => $n, 'found_date' => date('Y-m-d', strtotime("-$ago days")), 'place' => $place,
            'finder' => $keeper['name'], 'status' => $st, 'author_id' => $keeper['id']]);
    }
    foreach ([['수건', '40수 흰색', '욕실용품', '장', 80], ['두루마리 휴지', '30m', '욕실용품', '롤', 60], ['주방세제', '1L', '주방용품', '개', 10],
              ['종량제 봉투', '50L', '청소용품', '장', 30], ['일회용 수세미', '', '주방용품', '개', 40]] as $k => [$n, $spec, $cat, $unit, $safe]) {
        $sid = demo_ins('supplies', ['name' => $n, 'spec' => $spec, 'category' => $cat, 'unit' => $unit, 'safety_stock' => $safe, 'sort_order' => $k]);
        demo_ins('supply_moves', ['supply_id' => $sid, 'move_date' => date('Y-m-d', strtotime('-30 days')), 'in_qty' => $safe * 3, 'note' => '월초 입고']);
        for ($w = 4; $w >= 1; $w--) {
            demo_ins('supply_moves', ['supply_id' => $sid, 'move_date' => date('Y-m-d', strtotime('-' . ($w * 7 - 1) . ' days')), 'out_qty' => (int) round($safe * mt_rand(40, 70) / 100), 'note' => '객실 지급']);
        }
    }
}

/** 체험 계정으로 바로 로그인 (비밀번호 없이, 데모 사이트에서만) */
function demo_login(string $username): ?array
{
    if (!is_demo() || !isset(DEMO_ACCOUNTS[$username])) return null;
    $st = db()->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
    $st->execute([$username]);
    $u = $st->fetch() ?: null;
    if (!$u) return null;
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id'];
    return $u;
}

/** 데모에서 막는 저장: 체험 계정 자체를 바꾸면 다른 체험자가 로그인할 수 없어진다 */
function demo_block_post(string $what): void
{
    if (!is_demo() || !is_post()) return;
    flash("체험판에서는 $what 변경이 막혀 있습니다. 다른 메뉴는 자유롭게 써 보세요.", 'error');
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('index.php'))); // 같은 화면으로 (GET)
    exit;
}
