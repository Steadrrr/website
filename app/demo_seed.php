<?php
defined('APP_ROOT') || exit;

/*
 * 체험판 가상 자료 만들기 (app/demo.php 의 demo_seed() 가 초기화할 때만 불러온다)
 *
 * 실제 쉬자파크의 기록(2024~2026 시설 보수 대장·객실 민원 관리대장·전화 응대 현황·고객 만족도 조사·업무 단톡방)을
 * 분석해 '모양'만 본떴다. 사람 이름·연락처·업체명·고객 글은 넣지 않았다.
 *  - 객실 19개(초가원·해·달·별·숲·구름·바람), 시설 구역(치유의집·치유센터·산림교육센터·상하수도·공원)
 *  - 설비 이력: 실제로 반복된 고장(객실 온수보일러 히터봉·차단기, 관정 펌프, 해B 2층 에어컨, 제설기 등)을 실제 날짜 간격대로
 *  - 민원·문의: 실제 월별 문의량(5~8월 많음), 계절 패턴(5~6월 벌집, 여름 에어컨, 겨울 온수·난방, 늘 배수·통신)
 * 날짜는 '오늘' 기준으로 옮겨 언제 열어도 최근 2년 자료처럼 보인다.
 */

/** 실제 기록의 기준일 — 이 날이 체험판의 '오늘'이 되도록 날짜를 옮긴다 */
const DEMO_ANCHOR = '2026-10-21';

/** 실제 날짜 → 체험판 날짜 */
function demo_shift(string $realDate): string
{
    static $offset = null;
    $offset ??= (int) round((strtotime(date('Y-m-d')) - strtotime(DEMO_ANCHOR)) / 86400);
    return date('Y-m-d', strtotime("$realDate $offset days"));
}

/** 그 날의 업무일지 id (없으면 결재완료로 하나 만든다 — 오래된 민원을 붙일 곳) */
function demo_daily_for(string $date, array &$dailyIds, array $author, array $byRank): int
{
    if (!isset($dailyIds[$date])) {
        $dailyIds[$date] = demo_journal('daily', $date, $author, 'approved', $byRank, ['weather' => '맑음']);
        demo_ins('daily_entries', ['journal_id' => $dailyIds[$date], 'slot' => 'am', 'user_id' => $author['id'], 'content' => '시설 순찰 및 객실 점검', 'created_at' => "$date 11:00:00"]);
        db()->prepare('UPDATE journals SET content = ? WHERE id = ?')->execute([daily_compose($dailyIds[$date]), $dailyIds[$date]]);
    }
    return $dailyIds[$date];
}

function demo_seed_data(): void
{
    $today = date('Y-m-d');
    $days = 400; // 일지·판매·민원: 최근 400일 (작년 같은 달과 비교 가능)
    $start = date('Y-m-d', strtotime("-$days days"));
    $closedWd = array_map('intval', array_filter(explode(',', (string) setting('closed_weekdays', '2')), 'strlen'));

    setting_set('org_park_name', '쉬자파크');
    setting_set('org_top_name', '○○군 정원산림과');
    setting_set('vault_start', $start);

    /* ── 조직·직원 (모두 가상 인물) ── */
    $t1 = demo_ins('teams', ['name' => '산림휴양팀', 'sort_order' => 1, 'use_facility' => 1]);
    $t2 = demo_ins('teams', ['name' => '공원관리팀', 'sort_order' => 2, 'use_facility' => 1]);
    $sqRoom = demo_ins('squads', ['team_id' => $t1, 'name' => '객실반', 'sort_order' => 1]);
    $sqFac = demo_ins('squads', ['team_id' => $t2, 'name' => '시설반', 'sort_order' => 1]);

    $hash = password_hash(DEMO_PASSWORD, PASSWORD_DEFAULT);
    $U = [];
    foreach (DEMO_ACCOUNTS as $username => [$name, $rank, $admin, $desc]) {
        $U[$username] = ['id' => demo_ins('users', [
            'username' => $username, 'password_hash' => $hash, 'name' => $name, 'rank_level' => $rank, 'is_admin' => $admin,
            'can_delegate' => $rank === RANK_OFFICER ? 1 : 0, 'team_id' => $t1, 'status' => 'active',
            'position' => [RANK_KEEPER => '객실 관리', RANK_WORKER => '객실반장', RANK_OFFICER => '쉬자파크 운영 담당', RANK_LEADER => '산림휴양팀장'][$rank],
            'squad_id' => $rank <= RANK_WORKER ? $sqRoom : null, 'is_squad_leader' => $rank === RANK_WORKER ? 1 : 0,
            'hire_date' => date('Y-m-d', strtotime('-' . (200 + $rank * 300) . ' days')),
            'work_start' => '09:00:00', 'work_end' => '18:00:00',
        ]), 'rank_level' => $rank, 'name' => $name];
    }
    $nologin = password_hash(bin2hex(random_bytes(12)), PASSWORD_DEFAULT); // 화면용 직원 (로그인 불가)
    foreach ([['정다온', RANK_KEEPER, $t1, $sqRoom, '객실 청소'], ['한솔', RANK_KEEPER, $t2, $sqFac, '시설 관리'], ['윤바람', RANK_WORKER, $t2, $sqFac, '시설반장'],
              ['서하린', RANK_WORKER, $t1, null, '산림치유지도사'], ['오세찬', RANK_KEEPER, $t2, $sqFac, '야간 근무']] as $i => [$name, $rank, $team, $sq, $pos]) {
        $U["staff$i"] = ['id' => demo_ins('users', [
            'username' => "staff$i", 'password_hash' => $nologin, 'name' => $name, 'rank_level' => $rank, 'team_id' => $team,
            'squad_id' => $sq, 'is_squad_leader' => $pos === '시설반장' ? 1 : 0, 'position' => $pos, 'status' => 'active',
            'hire_date' => date('Y-m-d', strtotime('-' . (300 + $i * 120) . ' days')),
        ]), 'rank_level' => $rank, 'name' => $name];
    }
    $byRank = [RANK_WORKER => $U['demo_worker'], RANK_OFFICER => $U['demo_officer'], RANK_LEADER => $U['demo_leader']];
    $keeper = $U['demo_keeper'];
    $worker = $U['demo_worker'];
    $officer = $U['demo_officer'];
    $facMan = $U['staff1'];
    $fieldStaff = [$keeper, $U['staff0'], $U['staff4']];

    /* ── 상품: 입장권·객실·프로그램 ── */
    $tk = [];
    foreach ([['성인 입장권', 2000, 0], ['청소년 입장권', 1000, 0], ['어린이·경로 (무료)', 0, 1]] as $i => [$n, $p, $free]) {
        $tk[] = ['id' => demo_ins('products', ['grp' => 'ticket', 'name' => $n, 'price' => $p, 'is_free' => $free, 'sort_order' => $i]), 'name' => $n, 'price' => $p, 'free' => $free];
    }
    // [이름, 기준, 최대, 평일, 주말, 성수기, 환급 평일, 주말, 성수기] — 금액은 가상
    $typeDefs = ['단층형' => ['단층형(4인)', 4, 6, 70000, 90000, 110000, 10000, 15000, 20000],
                 '복층형' => ['복층형(6인)', 6, 8, 100000, 130000, 150000, 15000, 20000, 30000],
                 '한옥형' => ['한옥 초가원(6인)', 6, 8, 120000, 150000, 170000, 20000, 30000, 30000]];
    $types = [];
    $i = 0;
    foreach ($typeDefs as $k => $r) {
        $types[$k] = ['id' => demo_ins('room_types', ['name' => $r[0], 'base_people' => $r[1], 'max_people' => $r[2], 'price' => $r[3], 'price_weekend' => $r[4],
            'price_peak' => $r[5], 'refund_amount' => $r[6], 'refund_weekend' => $r[7], 'refund_peak' => $r[8], 'sort_order' => $i++]), 'r' => $r];
    }
    $rooms = []; // 이름 => [id, name, r, type]
    $roomDefs = ['초가원A' => '한옥형', '초가원B' => '한옥형', '초가원C' => '한옥형', '해A' => '복층형', '해B' => '복층형', '달A' => '복층형', '달B' => '복층형',
                 '별A' => '복층형', '별B' => '복층형', '숲A' => '복층형', '숲B' => '복층형', '구름A' => '단층형', '구름B' => '단층형', '구름C' => '단층형',
                 '구름D' => '단층형', '바람A' => '단층형', '바람B' => '단층형', '바람C' => '단층형', '바람D' => '단층형'];
    $i = 0;
    foreach ($roomDefs as $n => $tn) {
        $r = $types[$tn]['r'];
        $rooms[$n] = ['id' => demo_ins('products', ['grp' => 'room', 'name' => $n, 'room_type_id' => $types[$tn]['id'], 'base_people' => $r[1], 'max_people' => $r[2],
            'price' => $r[3], 'price_weekend' => $r[4], 'price_peak' => $r[5], 'refund_amount' => $r[6], 'refund_weekend' => $r[7], 'refund_peak' => $r[8],
            'sort_order' => $i++]), 'name' => $n, 'r' => $r, 'type' => $tn];
    }
    $progHeal = demo_ins('products', ['grp' => 'program', 'name' => '산림치유 숲테라피', 'price' => 5000, 'price_discount' => 2500, 'prog_types' => 'healing', 'sort_order' => 1]);
    demo_ins('seasons', ['grp' => 'room', 'name' => '여름 성수기', 'start_md' => '07-15', 'end_md' => '08-20', 'sort_order' => 1]);

    /* ── 시설물 (실제 구역 구성) ── */
    $fg = $fac = [];
    $facDefs = [
        [$t1, '치유의집 객실동', ['객실 온수보일러', '객실 에어컨', '욕실 배수·위생설비', '전기·조명·차단기', 'TV·와이파이', '테라스·데크']],
        [$t1, '치유의집 보일러실·세탁실', ['초가원 보일러실 순환펌프', '세탁기·건조기', '분전반(조명 제어)']],
        [$t1, '치유센터', ['온수순환펌프', '오수펌프', '지하 피트층 배수펌프', '냉난방기', '소방설비']],
        [$t1, '산림교육센터', ['가압펌프·물탱크', '화장실 위생설비', '강의실 음향·영상']],
        [$t2, '상하수도(관정)', ['1호 관정(카페 앞)', '4호 관정(개수대)', '5호 관정(발목풀장)', '6호 관정(피크닉정원)', '치유의집 물탱크']],
        [$t2, '공원시설', ['공원 데크(폭포 앞)', '치유숲길', '발목풀장', '유아숲 놀이터', '바비큐장·개수대', '안내 표지판']],
        [$t2, '쉬자센터·카페·매표소', ['냉난방기', '입구 출입차단기', '방송·통신설비']],
    ];
    $k = 0;
    foreach ($facDefs as [$team, $g, $items]) {
        $fg[$g] = demo_ins('asset_groups', ['kind' => 'facility', 'team_id' => $team, 'name' => $g, 'sort_order' => $k++]);
        foreach ($items as $j => $n) $fac[$g][$n] = demo_ins('facilities', ['group_id' => $fg[$g], 'name' => $n, 'sort_order' => $j]);
    }
    $cplFacGroup = ['공원' => $fg['공원시설'], '물' => $fg['상하수도(관정)'], '치유센터' => $fg['치유센터'], '교육관' => $fg['산림교육센터']];

    /* ── 장비·설비와 이력 (실제 고장 기록의 날짜 간격 그대로, 업체명은 일반 명칭) ── */
    $eg = [];
    foreach ([[$t1, '객실 온수보일러'], [$t1, '냉난방기'], [$t2, '펌프·급수설비'], [$t2, '차량·운반'], [$t2, '제설 장비'], [$t2, '조경 장비']] as $j => [$team, $n]) {
        $eg[$n] = demo_ins('asset_groups', ['kind' => 'equipment', 'team_id' => $team, 'name' => $n, 'sort_order' => $j]);
    }
    $eq = [];
    $addEq = function (string $key, string $group, string $name, string $acq, ?int $cost, string $loc, string $model = '') use (&$eq, $eg, $officer) {
        $acq = demo_shift($acq);
        $eq[$key] = demo_ins('equipment', ['group_id' => $eg[$group], 'name' => $name, 'model' => $model ?: null, 'acquired_on' => $acq, 'acquired_cost' => $cost, 'location' => $loc, 'status' => 'active']);
        demo_ins('equipment_logs', ['equipment_id' => $eq[$key], 'log_date' => $acq, 'kind' => 'register', 'content' => '장비 등록', 'user_id' => $officer['id'], 'status_after' => 'active']);
    };
    foreach ($rooms as $n => $r) $addEq("boiler:$n", '객실 온수보일러', "온수보일러 ($n)", '2019-06-01', 1200000, "치유의집 $n 보일러실", '전기온수기 히터봉식');
    $addEq('ac:해B', '냉난방기', '2층 에어컨 (해B)', '2019-06-01', 1500000, '해B 2층', '벽걸이형');
    $addEq('ac:달B', '냉난방기', '2층 에어컨 (달B)', '2019-06-01', 1500000, '달B 2층', '벽걸이형');
    $addEq('ac:rooms', '냉난방기', '객실 에어컨 (전 객실 교체분)', '2026-04-28', 38000000, '치유의집 전 객실', '냉난방 겸용');
    $addEq('pump:choga', '펌프·급수설비', '초가원 보일러실 순환펌프·가압모터', '2019-06-01', 2500000, '초가원 보일러실');
    $addEq('pump:heal', '펌프·급수설비', '치유센터 온수순환펌프', '2020-03-01', 2000000, '치유센터 기계실');
    $addEq('pump:sewage', '펌프·급수설비', '치유센터 오수펌프', '2020-03-01', 1800000, '치유센터 지하 피트층');
    $addEq('pump:pit', '펌프·급수설비', '치유센터 피트층 배수펌프', '2020-03-01', 1600000, '치유센터 지하 피트층');
    $addEq('pump:edu', '펌프·급수설비', '산림교육센터 가압펌프', '2016-05-01', 3000000, '산림교육센터 기계실');
    $addEq('well:1', '펌프·급수설비', '1호 관정 (수중모터·압력탱크)', '2015-04-01', 4500000, '카페 앞');
    $addEq('well:4', '펌프·급수설비', '4호 관정 (수중모터·인버터)', '2017-04-01', 4000000, '개수대·야외무대 사이');
    $addEq('well:5', '펌프·급수설비', '5호 관정 (수중모터)', '2017-04-01', 4000000, '발목풀장');
    $addEq('well:6', '펌프·급수설비', '6호 관정 인버터', '2024-11-13', 1500000, '피크닉정원');
    $addEq('snow:old', '제설 장비', '제설기 (구형)', '2016-11-01', 9000000, '장비창고');
    $addEq('snow:new', '제설 장비', '제설기 (신형)', '2025-02-10', 12000000, '장비창고');
    $addEq('cart:3', '차량·운반', '전동카트 3호차', '2022-03-01', 7800000, '관리사무소 주차장');
    $addEq('truck:bongo', '차량·운반', '1톤 트럭 (봉고)', '2012-05-01', 15000000, '관리사무소 주차장');
    $addEq('radiator', '냉난방기', '이동식 라디에이터 (17대)', '2018-11-01', 1700000, '객실 예비품 창고');
    $addEq('mower', '조경 장비', '예초기', '2023-04-01', 450000, '장비창고');

    // [실제 날짜, 장비, 종류, 내용, 비용, 업체, 상태, 연결 민원 [객실|구역, 분류, 내용]]
    $events = [
        ['2025-06-18', 'boiler:구름A', 'repair', '누전으로 차단기 떨어짐, 히터봉 교체', 180000, '보일러 업체', null, ['구름A', '3-2-2', '온수가 안 나옴']],
        ['2025-06-18', 'boiler:바람A', 'repair', '누전으로 차단기 떨어짐, 히터봉 교체', 180000, '보일러 업체', null, null],
        ['2025-09-10', 'boiler:바람A', 'repair', '히터봉 결착 불량으로 누수, 히터봉 교환', 210000, '보일러 업체', null, null],
        ['2025-01-24', 'boiler:숲A', 'repair', '보일러실 배관 파열로 누수', null, '자체', null, ['숲A', '3-2-2', '온수가 안 나옴']],
        ['2025-11-18', 'boiler:구름B', 'repair', '온수 불량, 히터봉 교체', 180000, '보일러 업체', null, ['구름B', '3-2-2', '샤워 중 온수가 끊김']],
        ['2025-12-14', 'boiler:숲A', 'repair', '온수 안 나옴, 히터봉 교체', 180000, '보일러 업체', null, ['숲A', '3-2-2', '온수가 안 나옴']],
        ['2026-02-01', 'boiler:숲B', 'repair', '보일러실 바닥 누수·누전으로 차단기 반복 트립, 히터봉 교체', 180000, '보일러 업체', null, ['숲B', '3-2-2', '온수가 안 나옴']],
        ['2026-02-22', 'boiler:구름A', 'repair', '차단기 용량 증설 (20A → 30A)', 30000, '자체', null, null],
        ['2026-03-01', 'boiler:구름A', 'inspect', '차단기 교체 후 재트립 — 고객 객실 변경', null, '자체', null, ['구름A', '3-2-2', '온수가 안 나와 객실 변경']],
        ['2026-03-01', 'boiler:구름B', 'inspect', '온수보일러 차단기 이상 — 고객 객실 변경', null, '자체', null, ['구름B', '3-2-2', '온수가 안 나와 객실 변경']],
        ['2026-03-01', 'boiler:해B', 'inspect', '온수보일러 차단기 이상 — 고객 객실 변경', null, '자체', null, ['해B', '3-2-2', '온수가 안 나와 객실 변경']],
        ['2026-03-07', 'boiler:구름A', 'repair', '차단기·히터봉 교체', 150000, '자체', null, null],
        ['2026-03-07', 'boiler:구름B', 'repair', '차단기·히터봉 교체', 150000, '자체', null, null],
        ['2026-06-15', 'boiler:숲B', 'repair', '연박 중 온수 고장 (차단기 떨어짐), 재가동', null, '자체', null, ['숲B', '3-2-2', '온수가 안 나옴']],
        ['2026-06-20', 'boiler:숲B', 'inspect', '온수 안 나옴 재발 — 보일러실 바닥 누수 확인, 업체 점검 필요', null, '자체', null, ['숲B', '3-2-2', '퇴실 전 온수가 안 나옴 (재발)']],
        ['2025-12-16', 'pump:choga', 'repair', '가압모터·인버터 고장으로 교체 (중고품)', 650000, '펌프 업체', null, null],
        ['2026-03-14', 'pump:choga', 'repair', '온수배관 부식 탈락·순환모터 파손 — 초가원 난방 불가, 긴급 수리', 900000, '보일러 업체', null, ['초가원B', '3-2-1', '난방·온수가 안 됨']],
        ['2026-03-20', 'pump:choga', 'repair', '초가원 보일러 배관 수리', 400000, '보일러 업체', null, null],
        ['2026-04-23', 'pump:choga', 'repair', '초가원C 온수순환펌프 소음·이상 — 교체', 350000, '보일러 업체', null, null],
        ['2024-11-29', 'pump:heal', 'repair', '온수 가압탱크 파손으로 누수, 밸브 차단 후 교체', 450000, '펌프 업체', null, null],
        ['2026-03-17', 'pump:heal', 'inspect', '온수배관 미세 누수 확인', null, '자체', null, null],
        ['2026-05-02', 'pump:heal', 'repair', '야간 온수순환펌프 파손 — 배관 잠금 후 교체 (초가원과 같은 증상)', 380000, '보일러 업체', null, null],
        ['2024-11-20', 'pump:sewage', 'repair', '오수펌프 고장으로 교체', 700000, '정화조 업체', null, null],
        ['2025-04-26', 'pump:sewage', 'repair', '오수펌프 고장으로 운영 중지, 수리', 300000, '정화조 업체', null, null],
        ['2026-03-16', 'pump:sewage', 'repair', '대변기 막힘·오수 배출 불량 점검', 150000, '정화조 업체', null, null],
        ['2026-03-17', 'pump:pit', 'repair', '지하 저수조 배관 파손으로 피트층 침수, 배관 차단', null, '자체', null, null],
        ['2026-03-18', 'pump:pit', 'inspect', '누전으로 배수펌프 제어반 작동 불가', null, '자체', null, null],
        ['2026-03-19', 'pump:pit', 'repair', '수중모터 2대 고장 — 모터·수위센서 교체', 1600000, '수도 업체', null, null],
        ['2024-06-11', 'pump:edu', 'repair', '노후 가압펌프 누수', 550000, '펌프 업체', null, null],
        ['2026-01-15', 'pump:edu', 'inspect', '물탱크 물 부족·가압모터 공회전 (화장실 플러셔 고장 원인)', null, '자체', null, null],
        ['2026-04-23', 'pump:edu', 'repair', '모터 이상 작동으로 정지 — 수압 저하', null, '펌프 업체', null, ['교육관', '3-3-1', '교육관 수압이 약함']],
        ['2026-04-27', 'pump:edu', 'repair', '체크밸브 고장, 모터 1대로 임시 운전', null, '펌프 업체', null, null],
        ['2026-04-29', 'pump:edu', 'repair', '모터 수리 완료', 800000, '펌프 업체', null, null],
        ['2024-06-03', 'well:1', 'repair', '급수 장애', null, '자체', null, ['물', '4-3-2', '카페·화장실 물이 안 나옴']],
        ['2024-07-09', 'well:1', 'repair', '누전으로 단수', 250000, '전기 업체', null, ['물', '4-3-2', '단수']],
        ['2024-07-17', 'well:1', 'repair', '누전으로 단수 재발', null, '자체', null, ['물', '4-3-2', '단수 재발']],
        ['2024-09-23', 'well:1', 'repair', '노후 압력탱크 파손, 교체', 600000, '펌프 업체', null, null],
        ['2025-05-23', 'well:1', 'repair', '센서 고장', 200000, '펌프 업체', null, null],
        ['2025-02-16', 'well:4', 'repair', '수중모터 고장 — 1일간 객실·세탁실 물 사용 불가', null, '펌프 업체', null, ['물', '4-3-2', '객실 물이 안 나옴']],
        ['2025-02-17', 'well:4', 'repair', '수중모터 교체', 1800000, '펌프 업체', null, null],
        ['2025-03-31', 'well:4', 'repair', '인버터 고장, 임시 조치', null, '펌프 업체', null, null],
        ['2025-05-14', 'well:4', 'repair', '인버터 수리', 450000, '펌프 업체', null, null],
        ['2024-05-24', 'well:5', 'repair', '펌프 과압력으로 배관 터짐', 300000, '펌프 업체', null, null],
        ['2024-11-12', 'well:5', 'repair', '파이프 파손, 보수', null, '펌프 업체', null, null],
        ['2024-11-13', 'well:5', 'repair', '수중모터 고장으로 교체 (6마력 → 7.5마력)', 2200000, '펌프 업체', null, null],
        ['2025-09-18', 'well:6', 'repair', '낙뢰로 센서 고장, 수리', 300000, '펌프 업체', null, null],
        ['2025-06-23', 'ac:해B', 'repair', '냉방 불가, 냉매 보충', 120000, '에어컨 서비스', null, ['해B', '3-2-1', '2층 에어컨이 시원하지 않음']],
        ['2025-07-07', 'ac:해B', 'repair', '냉방 불가 재발', null, '자체', null, ['해B', '3-2-1', '2층 에어컨이 안 됨']],
        ['2025-07-12', 'ac:해B', 'repair', '가스 누출 — 수리 불가 판정 (수리비보다 교체가 유리)', null, '에어컨 서비스', 'repair', ['해B', '3-2-1', '2층 에어컨 고장']],
        ['2026-04-28', 'ac:해B', 'dispose', '전 객실 에어컨 교체 때 신품으로 교체', null, '자체', 'disposed', null],
        ['2024-07-15', 'ac:달B', 'repair', '2층 천장형 에어컨 고장', 250000, '에어컨 서비스', null, ['달B', '3-2-1', '2층 에어컨 고장']],
        ['2026-06-06', 'ac:rooms', 'repair', '바람D 에어컨 바람 방향 날개 고장 — 임시 고정', null, '자체', null, ['바람D', '3-2-1', '거실 에어컨 작동 안 함']],
        ['2026-06-24', 'ac:rooms', 'inspect', '해A 에어컨 작동 불량 민원 — 과부하 시험 이상 없음', null, '자체', null, ['해A', '3-2-1', '에어컨 작동 불량']],
        ['2026-07-18', 'ac:rooms', 'inspect', '해B 2층 에어컨 냉방 약함 — 점검 진행 중', null, '자체', null, ['해B', '3-2-1', '2층 에어컨이 잘 안 됨']],
        ['2024-11-12', 'snow:old', 'inspect', '동절기 사전점검', null, '자체', null, null],
        ['2024-12-03', 'snow:old', 'repair', '브라켓 교체', 200000, '제설장비 업체', null, null],
        ['2024-12-05', 'snow:old', 'repair', '전선 교체', 80000, '제설장비 업체', null, null],
        ['2024-12-16', 'snow:old', 'repair', '유압계통 고장 — 상하 작동 불가', 450000, '제설장비 업체', null, null],
        ['2025-02-04', 'snow:old', 'dispose', '삽날 브라켓 파손으로 사용 불가 — 신형으로 교체', null, '자체', 'disposed', null],
        ['2026-01-12', 'snow:new', 'other', '강설 — 진입로·공원 내부 염화칼슘 살포', null, '자체', null, null],
        ['2026-01-19', 'snow:new', 'other', '새벽 적설 — 숙박동 도로 제설', null, '자체', null, null],
        ['2024-05-23', 'cart:3', 'repair', '운행 중 고장, 임대업체 수리', null, '카트 임대업체', null, null],
        ['2024-11-02', 'cart:3', 'repair', '고장 수리', null, '카트 임대업체', null, null],
        ['2025-02-04', 'cart:3', 'repair', '배터리 교체', 380000, '카트 임대업체', null, null],
        ['2026-01-15', 'cart:3', 'maintain', '배터리·타이어 정비', 250000, '카트 임대업체', null, null],
        ['2026-06-05', 'cart:3', 'repair', '케이블 단선 — 예비 부품으로 자체 수리', 10000, '자체', null, null],
        ['2025-01-17', 'truck:bongo', 'repair', '클러치·변속 불가로 운행 중지, 수리', 900000, '정비소', null, null],
        ['2025-06-13', 'truck:bongo', 'dispose', '파워 유압 누유로 운행 중지 — 폐차 결정', null, '자체', 'disposed', null],
        ['2026-03-16', 'radiator', 'inspect', '점검 결과 17대 중 15대 작동 불량 (차단기 떨어짐·가열 안 됨) — 동절기 전 교체 필요', null, '자체', null, null],
        ['2026-04-28', 'mower', 'repair', '시동 불능 — 부품 구입 후 자체 수리', 40000, '자체', null, null],
    ];
    $eqStatus = [];
    $eventCpl = []; // 체험판 날짜 => [[장소, 분류, 내용, 장비 내용]]
    foreach ($events as [$real, $key, $kind, $content, $cost, $vendor, $after, $cpl]) {
        $date = demo_shift($real);
        demo_ins('equipment_logs', ['equipment_id' => $eq[$key], 'log_date' => $date, 'kind' => $kind, 'content' => $content, 'cost' => $cost, 'vendor' => $vendor,
            'status_after' => $after, 'user_id' => $facMan['id']]);
        if ($after) $eqStatus[$key] = [$after, $date, $content];
        if ($cpl) $eventCpl[$date][] = [$cpl[0], $cpl[1], $cpl[2], $content];
    }
    foreach ($eqStatus as $key => [$st, $date, $why]) {
        db()->prepare('UPDATE equipment SET status = ?, disposed_on = ?, dispose_reason = ? WHERE id = ?')
            ->execute([$st, $st === 'disposed' ? $date : null, $st === 'disposed' ? $why : null, $eq[$key]]);
    }

    /* ── 민원·문의 견본 (실제 기록을 일반 표현으로) ── */
    // 단순문의 [분류, 내용, 월별 가중치(없으면 1)]
    $inq = [
        ['1-1-1', '객실 예약 방법·오픈일 문의', null], ['1-1-2', '군민우선예약 일정 문의', null], ['1-1-3', '요금·할인(다자녀·바우처) 문의', null],
        ['1-1-4', '예약 취소·변경 규정 문의', null], ['1-2-1', '입실 시간·객실키 반납 장소 문의', null], ['1-2-2', '인원 추가·예약자 외 입실 문의', null],
        ['1-2-4', '객실 집기류·비치품 문의', null], ['1-2-5', '바비큐 가능 여부 문의', null], ['1-2-6', '객실 주차 대수 문의', null],
        ['1-3-1', '공원 개방·휴관 시간 문의', null], ['1-3-3', '반려견 동반 문의', null], ['1-3-4', '카페·식당·매점 문의', null],
        ['1-3-5', '발목풀장·물놀이 시설 운영 문의', [6 => 3, 7 => 4, 8 => 4]], ['1-3-6', '대중교통·휠체어 이용 문의', null],
        ['1-3-7', '공사·통제 구간 문의', null], ['1-4-1', '숲해설 프로그램 문의', null], ['1-4-2', '치유센터 프로그램 문의', null],
        ['1-4-3', '유아숲 체험 문의', [4 => 2, 5 => 2, 6 => 2, 9 => 2, 10 => 2]], ['1-4-4', '교육관 대관 문의', null], ['1-5-1', '분실물 문의', null],
    ];
    $inqWeight = [5, 8, 4, 3, 5, 3, 2, 3, 2, 4, 2, 2, 2, 1, 2, 1, 2, 1, 1, 1];
    // 실제 월별 전화 문의량 (하루 평균, 1~12월)
    $inqPerDay = [1 => 1.3, 2 => 1.2, 3 => 2.0, 4 => 2.6, 5 => 4.0, 6 => 5.0, 7 => 3.3, 8 => 3.5, 9 => 2.4, 10 => 2.6, 11 => 1.5, 12 => 1.3];
    // 객실 불편 [분류, 내용, 조치, 계절 함수(월) → 배율, 자주 나는 객실]
    $roomCpl = [
        ['3-2-5', ['샤워실 배수가 잘 안 됨', '세면대 아래로 물이 역류함', '화장실 배수구 막힘'], '배수구 관통·약품 처리', fn($m) => 1.0, ['초가원C', '숲B', '바람D', '구름A', '별A']],
        ['3-2-4', ['2층 와이파이가 잘 안 잡힘', 'TV가 안 나옴 (셋톱박스)', '휴대폰 수신 불량'], '공유기·셋톱박스 재설정, 통신사 점검 요청', fn($m) => 1.0, ['별B', '달B', '숲A', '숲B', '해A', '바람D']],
        ['3-2-1', ['에어컨이 시원하지 않음', '에어컨 작동 안 함'], '필터 청소·작동 점검', fn($m) => in_array($m, [6, 7, 8], true) ? 2.5 : 0, []],
        ['3-2-1', ['난방이 늦게 데워짐', '실내가 추움 (외풍)'], '보일러 가동 확인, 온풍기 지원', fn($m) => in_array($m, [11, 12, 1, 2], true) ? 1.5 : 0, ['바람B', '바람C', '바람D', '구름D']],
        ['3-2-3', ['거실 등이 안 들어옴', '샤워부스 등이 나감', '차단기가 내려감'], '전등 교체·차단기 복구', fn($m) => 1.0, []],
        ['3-2-5', ['중문이 레일에서 빠짐', '현관문 말발굽 파손', '휴지걸이 덮개 빠짐'], '현장 보수', fn($m) => 0.6, []],
        ['3-2-6', ['테라스 조명이 어두움', '피크닉테이블 송진이 묻음'], '조명 추가 검토, 안내문 부착', fn($m) => in_array($m, [5, 6, 7, 8, 9], true) ? 1.0 : 0.2, []],
        ['3-1-1', ['이불·매트에 머리카락', '식탁이 끈적거림', '싱크대에 음식물 찌꺼기', '2층 바닥 먼지'], '즉시 재청소, 청소 점검표 보완', fn($m) => 1.3, []],
        ['3-1-3', ['수건이 부족함', '이불이 2채만 있음'], '수건·침구 추가 투입', fn($m) => 1.0, []],
        ['3-1-4', ['수저가 모자람', '주방 집게가 없음', '프라이팬 코팅이 벗겨짐'], '주방용품 보충·교체', fn($m) => 1.0, []],
        ['3-1-2', ['객실에 음식 냄새가 남', '수건에서 냄새가 남'], '환기·탈취, 수건 교체', fn($m) => in_array($m, [6, 7, 8], true) ? 1.5 : 0.7, []],
        ['2-1-1', ['선풍기 요청', '거실 의자 추가 요청'], '물품 대여', fn($m) => in_array($m, [6, 7, 8], true) ? 1.5 : 0.4, []],
        ['4-1-1', ['처마에 말벌집이 있음', '베란다에 벌집이 있음'], '약품 살포 후 벌집 제거', fn($m) => [5 => 3.0, 6 => 3.0, 7 => 1.5, 8 => 1.5, 9 => 1.0][$m] ?? 0, []],
        ['4-1-2', ['객실에 지네가 나옴'], '방역 및 틈새 막음', fn($m) => in_array($m, [5, 6, 7, 8], true) ? 0.6 : 0, ['초가원A', '초가원B', '초가원C']],
    ];
    $roomCplW = [1.4, 1.0, 1.0, 0.9, 0.7, 0.4, 0.4, 1.6, 0.9, 0.9, 0.6, 0.6, 0.6, 0.3];

    /* ── 날짜별 업무: 업무일지·객실판매·매출·민원 ── */
    $amTpl = ['입장객 안내 및 매표소 운영', '퇴실 객실 점검 및 청소 배정', '숲길 순찰 (쓰러진 나무·데크 확인)', '관정·물탱크 수위 확인', '객실 비품 보충', '주차장 정리·안내'];
    $pmTpl = ['입실 안내 및 객실키 전달', '바비큐장·개수대 정리', '시설 순찰 및 쓰레기 수거', '예약 문의 전화 응대', '치유 프로그램 참가자 안내', '소모품 재고 확인'];
    $eveTpl = ['야간 순찰 (객실동·주차장)', '바비큐장 소등·정리', '입실 지연 고객 응대'];

    $dailyIds = [];
    $soldYesterday = [];
    $roomNights = []; // 날짜 => 판매 객실 수 (소모품 사용량 계산)
    $cplRow = function (int $jid, string $date, string $code, ?array $room, ?int $facGroup, ?string $facName, string $text, ?string $act, int $ago, array $who, int $qty = 1, ?string $channel = null) {
        $c1 = substr($code, 0, 1);
        $status = $c1 === CPL_SIMPLE ? 'done' : ($ago <= 2 ? demo_pick(['open', 'progress']) : ($ago <= 7 && demo_chance(0.3) ? 'progress' : 'done'));
        demo_ins('complaints', [
            'journal_id' => $jid, 'work_date' => $date, 'cat1' => $c1, 'cat2' => substr($code, 0, 3), 'cat3' => $code,
            'channel' => $channel ?? ($c1 === CPL_SIMPLE ? demo_pick(['phone', 'phone', 'phone', 'online']) : ($room ? demo_pick(['visit', 'visit', 'phone']) : demo_pick(['phone', 'visit']))),
            'place_type' => $room ? 'room' : ($facGroup ? 'facility' : null), 'place_id' => $room ? $room['id'] : $facGroup,
            'place_name' => $room ? $room['name'] : $facName, 'content' => $text, 'qty' => $qty,
            'status' => $status, 'done_date' => $status === 'done' ? date('Y-m-d', strtotime($date) + ($c1 === CPL_SIMPLE ? 0 : 86400 * mt_rand(0, 2))) : null,
            'action' => $status === 'open' || $c1 === CPL_SIMPLE ? null : $act, 'receiver_id' => $who['id'], 'created_at' => "$date 14:00:00",
        ]);
    };

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
        $who = $state === 'approved' ? $author : $keeper;
        $closed = in_array($wd, $closedWd, true);

        // 업무일지
        $jid = demo_journal('daily', $date, $who, $state, $byRank, ['weather' => demo_pick(['맑음', '맑음', '흐림', '구름많음', $mon >= 6 && $mon <= 8 ? '비' : ($mon === 12 || $mon <= 2 ? '눈' : '맑음')])]);
        $dailyIds[$date] = $jid;
        foreach (['am' => $amTpl, 'pm' => $pmTpl, 'eve' => $eveTpl] as $slot => $tpl) {
            if ($slot === 'eve' && !demo_chance(0.5)) continue;
            if ($ago === 0 && $slot !== 'am') continue; // 오늘은 오전까지만
            $lines = $closed ? ['휴관일 — 시설 순찰 및 정비'] : array_unique([demo_pick($tpl), demo_pick($tpl)]);
            demo_ins('daily_entries', ['journal_id' => $jid, 'slot' => $slot, 'user_id' => $who['id'], 'content' => implode("\n", $lines), 'created_at' => "$date 11:00:00"]);
        }
        db()->prepare('UPDATE journals SET content = ? WHERE id = ?')->execute([daily_compose($jid), $jid]);

        // 설비 고장에 따른 민원 (실제 날짜 간격)
        foreach ($eventCpl[$date] ?? [] as [$place, $code, $text, $eqText]) {
            $room = $rooms[$place] ?? null;
            $gid = $room ? null : ($cplFacGroup[$place] ?? null);
            $cplRow($jid, $date, $code, $room, $gid, $gid ? (array_search($gid, $fg, true) ?: null) : null, $text, $eqText, $ago, $who);
        }

        if ($closed) {
            $soldYesterday = [];
            continue;
        }

        // 단순문의 (전화·온라인): 실제 월별 문의량
        $n = (int) round($inqPerDay[$mon] * ($wd === 0 || $wd === 6 ? 1.4 : 0.9) * mt_rand(60, 140) / 100);
        $byCode = [];
        for ($q = 0; $q < $n; $q++) {
            $w = [];
            foreach ($inq as $k => [$code, $text, $mw]) $w[$k] = $inqWeight[$k] * ($mw[$mon] ?? ($mw ? 0.3 : 1));
            $r = mt_rand() / mt_getrandmax() * array_sum($w);
            foreach ($w as $k => $x) {
                if (($r -= $x) <= 0) break;
            }
            $byCode[$k] = ($byCode[$k] ?? 0) + 1;
        }
        foreach ($byCode as $k => $qty) $cplRow($jid, $date, $inq[$k][0], null, null, null, $inq[$k][1], null, $ago, $who, $qty);

        // 객실 판매 (금·토 거의 만실, 평일 30~50%, 성수기 높음)
        $occ = $isPeak ? ($isWeekend ? 1.0 : 0.8) : ($isWeekend ? 0.92 : (in_array($mon, [5, 6, 9, 10], true) ? 0.5 : 0.35));
        $rate = $isPeak ? 'peak' : ($isWeekend ? 'weekend' : 'weekday');
        $rj = demo_journal('rooms', $date, $ago <= 1 ? $keeper : $author, $state, $byRank);
        $sold = [];
        foreach ($rooms as $r) {
            if (!demo_chance($occ)) continue;
            [, $base, $max, $p1, $p2, $p3, $f1, $f2, $f3] = $r['r'];
            $price = ['weekday' => $p1, 'weekend' => $p2, 'peak' => $p3][$rate];
            $refund = ['weekday' => $f1, 'weekend' => $f2, 'peak' => $f3][$rate];
            $dcReason = demo_chance(0.15) ? demo_pick(['multichild', 'local', 'disabled', 'multichild', 'work']) : null;
            $pct = $dcReason ? room_dc_pct_for($rate, $dcReason) : 0;
            demo_ins('sales_lines', ['journal_id' => $rj, 'product_id' => $r['id'], 'grp' => 'room', 'name' => $r['name'], 'rate' => $rate,
                'season' => $isPeak ? '여름 성수기' : null, 'discounted' => $dcReason ? 1 : 0, 'dc_reason' => $dcReason, 'unit_price' => $price,
                'qty' => 1, 'guests' => mt_rand(max(2, $base - 2), $max), 'refund_expected' => $dcReason && !room_dc_refundable($rate, $dcReason) ? 0 : $refund,
                'amount' => (int) round($price * (100 - $pct) / 100)]);
            $sold[] = $r;
        }

        // 매출보고 (입장권)
        $sj = demo_journal('sales', $date, $ago <= 1 ? $worker : $U['staff2'], $state, $byRank);
        $visit = (int) round(($isPeak ? 900 : ($wd === 0 || $wd === 6 ? 420 : 140)) * (mt_rand(80, 120) / 100) * (in_array($mon, [12, 1, 2], true) ? 0.5 : 1));
        $ticketTotal = 0;
        foreach ($tk as $k => $t) {
            $q = (int) round($visit * [0.55, 0.15, 0.30][$k]);
            $amt = $t['free'] ? 0 : $q * $t['price'];
            $ticketTotal += $amt;
            demo_ins('sales_lines', ['journal_id' => $sj, 'product_id' => $t['id'], 'grp' => 'ticket', 'name' => $t['name'], 'is_free' => $t['free'],
                'unit_price' => $t['price'], 'qty' => $q, 'amount' => $amt]);
        }
        demo_ins('sales_meta', ['journal_id' => $sj, 'ticket_cash' => (int) round($ticketTotal * 0.2 / 1000) * 1000]);

        // 객실 불편·긴급 민원: 판매 객실 수 × 계절 × 객실별 경향 (실제 관리대장 비율)
        foreach ($sold as $r) {
            foreach ($roomCpl as $k => [$code, $texts, $act, $season, $hot]) {
                $p = 0.0065 * $roomCplW[$k] * $season($mon) * ($hot && in_array($r['name'], $hot, true) ? 3 : 1) * ($code[0] === '3' && $code[2] === '1' && $wd === 0 ? 1.5 : 1);
                if (demo_chance($p)) $cplRow($jid, $date, $code, $r, null, null, demo_pick($texts), $act, $ago, $who);
            }
        }
        // 공원 쪽 민원·안전
        if (in_array($mon, [5, 6], true) && demo_chance(0.12)) $cplRow($jid, $date, '4-1-1', null, $fg['공원시설'], '공원시설', '놀이터·데크 주변 말벌집', '즉시 제거', $ago, $who);
        if (in_array($mon, [6, 7, 8], true) && demo_chance(0.05)) $cplRow($jid, $date, '3-3-1', null, $fg['공원시설'], '공원시설', '발목풀장 타일 들뜸·미끄러움', '임시 통제 후 보수', $ago, $who);
        if (demo_chance(0.04)) $cplRow($jid, $date, demo_pick(['3-3-1', '3-3-2']), null, $fg['공원시설'], '공원시설', demo_pick(['데크 판재 파손·부식', '숲길에 쓰러진 나무', '안내 표지판이 헷갈림']), '라바콘 설치 후 보수', $ago, $who);
        if (in_array($mon, [12, 1, 2], true) && demo_chance(0.06)) $cplRow($jid, $date, '4-4-1', null, $fg['공원시설'], '공원시설', '눈길 진입로 통행 문의', '제설·염화칼슘 살포', $ago, $who);

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
        $roomNights[$date] = count($sold);
        $soldYesterday = $sold;
    }
    // 400일보다 오래된 설비 고장 민원도 붙인다 (그 날 업무일지를 만들어서)
    foreach ($eventCpl as $date => $list) {
        if ($date >= $start) continue;
        $jid = demo_daily_for($date, $dailyIds, $keeper, $byRank);
        foreach ($list as [$place, $code, $text, $eqText]) {
            $room = $rooms[$place] ?? null;
            $gid = $room ? null : ($cplFacGroup[$place] ?? null);
            $cplRow($jid, $date, $code, $room, $gid, $gid ? (array_search($gid, $fg, true) ?: null) : null, $text, $eqText, 999, $keeper);
        }
    }

    /* ── 시설점검 (매주 월요일, 팀별) — 설비 이력과 맞물리게 ── */
    $issueNear = []; // 시설 이름 => [체험판 날짜 …]
    $facOf = ['boiler:' => '객실 온수보일러', 'ac:' => '객실 에어컨', 'pump:choga' => '초가원 보일러실 순환펌프', 'pump:heal' => '온수순환펌프', 'pump:sewage' => '오수펌프',
              'pump:pit' => '지하 피트층 배수펌프', 'pump:edu' => '가압펌프·물탱크', 'well:1' => '1호 관정(카페 앞)', 'well:4' => '4호 관정(개수대)', 'well:5' => '5호 관정(발목풀장)', 'well:6' => '6호 관정(피크닉정원)'];
    foreach ($events as [$real, $key, $kind, $content]) {
        if (!in_array($kind, ['repair', 'inspect'], true)) continue;
        foreach ($facOf as $prefix => $fname) {
            if (str_starts_with($key, $prefix)) $issueNear[$fname][] = [demo_shift($real), $content];
        }
    }
    for ($d = 0; $d <= $days; $d++) {
        $date = date('Y-m-d', strtotime("$start +$d days"));
        if ((int) date('w', strtotime($date)) !== 1) continue;
        $ago = $days - $d;
        $mon = (int) date('n', strtotime($date));
        foreach ([$t1 => [$facMan, ['치유의집 객실동', '치유의집 보일러실·세탁실', '치유센터', '산림교육센터']], $t2 => [$U['staff2'], ['상하수도(관정)', '공원시설', '쉬자센터·카페·매표소']]] as $team => [$by, $groups]) {
            $fj = demo_journal('facility', $date, $by, $ago <= 1 ? 'pending' : 'approved', $byRank, ['team_id' => $team]);
            foreach ($groups as $g) {
                foreach ($fac[$g] as $n => $fid) {
                    $res = '정상';
                    $note = null;
                    foreach ($issueNear[$n] ?? [] as [$ed, $what]) {
                        $diff = (strtotime($date) - strtotime($ed)) / 86400;
                        if ($diff >= -6 && $diff <= 0) [$res, $note] = ['점검필요', '이상 징후: ' . $what];
                        elseif ($diff > 0 && $diff <= 7) [$res, $note] = [demo_pick(['수리요청', '조치완료']), $what];
                    }
                    if ($res === '정상') {
                        if ($n === '공원 데크(폭포 앞)' && demo_chance(0.15)) [$res, $note] = ['점검필요', '데크 판재 부식·들뜸, 라바콘 설치'];
                        elseif ($n === '테라스·데크' && demo_chance(0.08)) [$res, $note] = ['점검필요', '데크 난간 흔들림'];
                        elseif ($n === '발목풀장' && in_array($mon, [6, 7, 8], true) && demo_chance(0.2)) [$res, $note] = ['점검필요', '타일 들뜸'];
                        elseif ($n === '욕실 배수·위생설비' && demo_chance(0.15)) [$res, $note] = ['점검필요', '배수 불량 객실 있음'];
                        elseif ($n === '치유숲길' && in_array($mon, [7, 8, 12, 1], true) && demo_chance(0.15)) [$res, $note] = ['수리요청', '쓰러진 나무 (강풍·폭설)'];
                        elseif (demo_chance(0.02)) [$res, $note] = ['점검필요', '경미한 이상, 다음 주 재확인'];
                    }
                    demo_ins('facility_items', ['journal_id' => $fj, 'facility_id' => $fid, 'area' => $g, 'facility' => $n, 'result' => $res, 'note' => $note]);
                }
            }
        }
    }

    /* ── 오늘 청소관리: 어제 묵은 객실 = 오늘 퇴실 ── */
    $now = time();
    foreach ($soldYesterday as $k => $r) {
        $out = $k % 3 !== 2 ? date('Y-m-d H:i:s', strtotime($today . ' 10:' . sprintf('%02d', 5 + $k * 2))) : null;
        $clean = $out && $k % 3 === 0 ? date('Y-m-d H:i:s', strtotime($out) + 3600 + $k * 120) : null;
        if ($out && strtotime($out) > $now) $out = $clean = null; // 아직 오전이면 퇴실 전
        if ($clean && strtotime($clean) > $now) $clean = null;
        demo_ins('room_clean', ['work_date' => $today, 'product_id' => $r['id'], 'out_at' => $out, 'out_by' => $out ? $keeper['id'] : null,
            'clean_at' => $clean, 'clean_by' => $clean ? $U['staff0']['id'] : null]);
    }

    /* ── 결재 대기 문서 (역할별 결재함 체험용) ── */
    $pj = demo_journal('purchase', date('Y-m-d', strtotime('-1 day')), $worker, 'pending', $byRank, ['content' => '동절기 대비 객실 온수보일러 부품 구매']);
    demo_ins('purchase_meta', ['journal_id' => $pj, 'vendor' => '○○설비자재', 'pay_method' => 'card', 'total' => 0]);
    foreach ([['온수보일러 히터봉', '3kW', 10, 38000], ['누전차단기', '30A', 6, 12000], ['배관 보온재', '20mm', 20, 3500]] as $k => [$n, $spec, $q, $p]) {
        demo_ins('purchase_items', ['journal_id' => $pj, 'sort_no' => $k + 1, 'name' => $n, 'spec' => $spec, 'qty' => $q, 'unit_price' => $p, 'amount' => $q * $p]);
    }
    db()->prepare('UPDATE purchase_meta SET total = (SELECT SUM(amount) FROM purchase_items WHERE journal_id = ?) WHERE journal_id = ?')->execute([$pj, $pj]);
    $leave = date('Y-m-d', strtotime('+7 days'));
    $aj = demo_journal('attendance', date('Y-m-d', strtotime('-1 day')), $keeper, 'pending', $byRank, ['content' => '개인 사유 연차']);
    demo_ins('attendance', ['journal_id' => $aj, 'user_id' => $keeper['id'], 'kind' => 'annual', 'start_date' => $leave, 'end_date' => $leave, 'days' => 1]);

    /* ── 상품권 (금고 입고·점검·불출) ── */
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
        'body' => "직원·민원·판매 자료는 모두 가상입니다. 객실·시설 구성과 고장·민원의 흐름은 실제 쉬자파크 기록의 모양을 본떴습니다.\n마음껏 눌러 보세요. 바꾼 내용은 매일 밤 처음 상태로 돌아갑니다."]);
    demo_ins('notices', ['title' => '동절기 대비 객실 온수보일러 일제 점검', 'author_id' => $officer['id'], 'created_at' => date('Y-m-d H:i:s', strtotime('-3 days')),
        'body' => '11월 첫째 주 전 객실 온수보일러 히터봉·차단기 점검, 초가원 보일러실 순환펌프 점검을 진행합니다.']);
    demo_ins('notices', ['title' => '주말 만실 대비 청소 인력 배치', 'author_id' => $worker['id'], 'created_at' => date('Y-m-d H:i:s', strtotime('-6 days')),
        'body' => '금·토 만실 예상 — 일요일 오전 청소 2인 추가 배치합니다. 2층 바닥·식탁 끈적임 확인 부탁드립니다.']);
    foreach ([[2, 2, '가을 숲 음악회', 'event', '야외무대'], [9, 11, '객실 온수보일러 일제 점검', 'construction', '치유의집'], [5, 5, '○○초 숲체험 단체', 'rental', '산림교육센터'],
              [16, 16, '제설기 동절기 사전점검', 'etc', '장비창고'], [-4, -4, '소방 합동훈련', 'etc', '치유센터']] as [$a, $b, $title, $cat, $loc]) {
        demo_ins('events', ['title' => $title, 'category' => $cat, 'start_date' => date('Y-m-d', strtotime("$a days")), 'end_date' => date('Y-m-d', strtotime("$b days")),
            'location' => $loc, 'author_id' => $officer['id']]);
    }
    $y = date('Y');
    foreach ([[3, '검은색 우산', '바비큐장', 'received'], [8, '어린이 운동화', '발목풀장', 'contacted'], [15, '반려견 간식 가방', '치유숲길', 'shipped'],
              [26, '보조배터리', '구름C', 'returned']] as $k => [$ago, $n, $place, $st]) {
        demo_ins('lost_items', ['reg_no' => sprintf('%s-%04d', $y, $k + 1), 'name' => $n, 'found_date' => date('Y-m-d', strtotime("-$ago days")), 'place' => $place,
            'finder' => $keeper['name'], 'status' => $st, 'author_id' => $keeper['id']]);
    }
    demo_seed_supplies($roomNights, $worker, $officer, $byRank);
}

/*
 * 객실 소모품·비품 구매 이력 (실제 2025~2026 견적서 8건 중 객실 관리에 쓰는 품목만, 업체명·브랜드는 일반 명칭)
 *  - 물품구매 문서(결재완료·지출완료)로 남기고, 소모품은 소모품관리 재고(입고 = 구매일, 출고 = 판매 객실 수에 비례)로 만든다.
 *  - 'b' = 비품(재고 관리 안 함), 그 밖의 값 = 소모품 이름
 */
const DEMO_PURCHASES = [
    ['2025-01-09', '○○상사', '객실 비품·소모품 연초 구매', [
        ['헤어드라이기 (접이식)', '', 5, 27000, 'b'], ['제습제 (32개입)', '옷장용', 2, 49000, '제습제'], ['갈대 빗자루', '', 10, 5000, 'b'],
        ['청소용 고무장갑 (얇은형)', 'M', 200, 390, '청소용 장갑'], ['빨아쓰는 행주 (60매)', '', 45, 8500, '행주'], ['페달 휴지통', '20L', 10, 30000, 'b'],
        ['일회용 비누 10g (1000개)', '', 5, 64000, '일회용 비누']]],
    ['2025-04-27', '장애인생산품 판매시설', '성수기 전 세탁·청소 세제', [
        ['액체 세탁세제', '14L', 6, 28000, '세탁세제'], ['섬유유연제', '14L', 5, 28000, '섬유유연제'], ['락스 (대용량)', '', 4, 26400, '락스']]],
    ['2025-04-28', '○○상사', '객실 주방용품 교체·보충', [
        ['디스펜서 공병', '550ml', 30, 3150, 'b'], ['주방세제 (거품형)', '350ml', 10, 6800, '주방세제'], ['원목 헤어브러시', '', 30, 6450, 'b'],
        ['티스푼·포크 세트 (10P)', '', 20, 15500, 'b'], ['수저 세트', '스텐', 100, 900, 'b'], ['기름때 제거 클리너', '750ml', 5, 13000, '청소 세정제'],
        ['곰팡이 제거 클리너', '750ml', 10, 12300, '청소 세정제'], ['주방 고무장갑', 'L', 100, 2350, '주방 고무장갑'], ['실리콘 냄비 집게 (2개입)', '', 30, 1200, 'b'],
        ['실리콘 냄비받침', '', 25, 2400, 'b'], ['스텐 채반', '', 20, 5500, 'b'], ['머그컵', '320ml', 150, 1850, 'b'], ['주방 도마', 'TPU', 30, 11300, 'b'],
        ['프라이팬', '28cm', 25, 17300, 'b'], ['커튼 묶음끈', '', 50, 2400, 'b'], ['모기약 에어졸 (30개입)', '500ml', 2, 79500, '모기약'], ['전동 칼갈이', '', 1, 82200, 'b']]],
    ['2025-11-03', '장애인생산품 판매시설', '동절기 세제·화장지', [
        ['섬유유연제', '14L', 3, 27500, '섬유유연제'], ['액체 세탁세제', '14L', 7, 27500, '세탁세제'], ['화장지 50m (100롤)', '2겹', 5, 53000, '화장지'],
        ['점보롤 화장지 (16롤)', '300m', 3, 42000, '점보롤']]],
    ['2025-11-03', '○○상사', '욕실 세정제·쓰레기봉투', [
        ['욕실용 락스 세제 (750ml×4)', '', 10, 4000, '욕실 세정제'], ['재활용 봉투 100L (50매)', '', 25, 3000, '쓰레기봉투'], ['평판 봉투 (100장)', '', 5, 11000, '쓰레기봉투'],
        ['청소기 원형솔', '32mm', 2, 12000, 'b']]],
    ['2026-07-03', '장애인생산품 판매시설', '성수기 세제·화장지', [
        ['액체 세탁세제', '14L', 6, 33600, '세탁세제'], ['섬유유연제', '14L', 5, 29700, '섬유유연제'], ['락스 (2L×6)', '18L', 5, 28000, '락스'],
        ['점보롤 케이스', '', 10, 4500, 'b'], ['화장지 50m (100롤)', '2겹', 10, 54000, '화장지'], ['점보롤 화장지 (16롤)', '300m', 4, 43000, '점보롤']]],
    ['2026-07-09', '○○상사', '성수기 객실 청소·주방 보충 (배수 세정제 포함)', [
        ['배관 세척제 (배수구 기름때)', '18L', 1, 142700, '배수 세정제'], ['극세사 대걸레 세트', '', 4, 14800, 'b'], ['재활용 봉투 20L (80매)', '', 15, 8400, '쓰레기봉투'],
        ['재활용 봉투 120L (30매)', '', 15, 12600, '쓰레기봉투'], ['거실 벽시계', '', 2, 31450, 'b'], ['붙박이장 도어캐치', '', 120, 375, 'b'], ['원형 밥주걱', '', 20, 2600, 'b'],
        ['방향제 용액', '', 10, 10900, '방향제'], ['자동 방향제 기기', '', 4, 29175, 'b'], ['욕실 환풍기', '20cm', 4, 19100, 'b'], ['싱크대 칼꽂이', '', 30, 2400, 'b'],
        ['배수구 세정제 (2L×6)', '', 5, 28600, '배수 세정제'], ['스텐 국자', '', 20, 2700, 'b'], ['욕실용 락스 세제 (750ml×4)', '', 15, 12900, '욕실 세정제'],
        ['스텐 수세미 (30개입)', '', 2, 124500, '수세미'], ['청소기 연장 파이프', '38mm', 1, 29900, 'b']]],
    ['2026-10-06', '○○상사', '동절기 전 객실 비품 교체·보충', [
        ['건전지 AA (40알)', '도어락·리모컨', 10, 20000, '건전지'], ['배수구 세정제 (1kg×12)', '', 1, 78000, '배수 세정제'], ['키친타올 행주 (50매)', '', 9, 16000, '행주'],
        ['객실 휴지통', '25L', 19, 19000, 'b'], ['파리채', '', 19, 1500, 'b'], ['치약 (125g×4)', '', 15, 9600, '치약'], ['재활용 봉투 55L (100장)', '', 20, 7300, '쓰레기봉투'],
        ['현관문 도어스토퍼 (말발굽)', '특대', 19, 5000, 'b']]],
];

/** 소모품 [이름 => [규격, 분류, 단위, 적정재고]] */
const DEMO_SUPPLIES = [
    '세탁세제' => ['14L', '세탁', '통', 2], '섬유유연제' => ['14L', '세탁', '통', 2], '락스' => ['대용량', '세탁', '통', 1],
    '화장지' => ['50m 100롤', '욕실용품', '박스', 2], '점보롤' => ['16롤', '욕실용품', '박스', 1], '일회용 비누' => ['10g 1000개', '욕실용품', '박스', 1],
    '치약' => ['125g×4', '욕실용품', '팩', 4], '욕실 세정제' => ['750ml×4', '청소용품', '팩', 3], '배수 세정제' => ['', '청소용품', '통', 2],
    '청소 세정제' => ['750ml', '청소용품', '개', 3], '수세미' => ['30개입', '청소용품', '박스', 1], '쓰레기봉투' => ['', '청소용품', '묶음', 5],
    '청소용 장갑' => ['M', '청소용품', '켤레', 30], '주방 고무장갑' => ['L', '주방용품', '켤레', 15], '주방세제' => ['350ml', '주방용품', '개', 5],
    '행주' => ['60매', '주방용품', '팩', 5], '방향제' => ['용액', '객실 관리', '개', 2], '모기약' => ['30개입', '객실 관리', '박스', 1],
    '제습제' => ['32개입', '객실 관리', '박스', 1], '건전지' => ['AA 40알', '객실 관리', '팩', 2],
];

function demo_seed_supplies(array $roomNights, array $worker, array $officer, array $byRank): void
{
    // 1) 물품구매 문서 (결재완료·지출완료)
    $ins = []; // 소모품 => [날짜 => 수량]
    foreach (DEMO_PURCHASES as [$real, $vendor, $title, $items]) {
        $date = demo_shift($real);
        $jid = demo_journal('purchase', $date, $worker, 'approved', $byRank, ['content' => $title]);
        $total = 0;
        foreach ($items as $k => [$n, $spec, $q, $p, $supply]) {
            demo_ins('purchase_items', ['journal_id' => $jid, 'sort_no' => $k + 1, 'name' => $n, 'spec' => $spec ?: null, 'qty' => $q, 'unit_price' => $p, 'amount' => $q * $p]);
            $total += $q * $p;
            if ($supply !== 'b') $ins[$supply][$date] = ($ins[$supply][$date] ?? 0) + $q;
        }
        demo_ins('purchase_meta', ['journal_id' => $jid, 'vendor' => $vendor, 'pay_method' => 'card', 'total' => $total,
            'paid_at' => date('Y-m-d', strtotime("$date +7 days")), 'paid_by' => $officer['id']]);
    }

    // 2) 소모품 재고: 첫 구매 전 재고 + 구매 입고, 매주 일요일 사용량(판매 객실 수 비례) 출고
    $from = demo_shift('2025-01-01');
    $today = date('Y-m-d');
    $avg = $roomNights ? array_sum($roomNights) / count($roomNights) : 8; // 자료가 없는 옛날 날짜는 평균으로
    $rn = fn(string $d) => $roomNights[$d] ?? $avg;
    $totalRn = 0;
    for ($t = strtotime($from); $t <= strtotime($today); $t += 86400) $totalRn += $rn(date('Y-m-d', $t));
    $k = 0;
    foreach (DEMO_SUPPLIES as $name => [$spec, $cat, $unit, $safe]) {
        $sid = demo_ins('supplies', ['name' => $name, 'spec' => $spec ?: null, 'category' => $cat, 'unit' => $unit, 'safety_stock' => $safe, 'sort_order' => $k++]);
        $bought = $ins[$name] ?? [];
        $opening = (int) max($safe, round((reset($bought) ?: $safe) / 2));
        $rate = (array_sum($bought) + $opening * 0.6) / max(1, $totalRn); // 기간 끝에는 조금 남도록
        $moves = [$from => ['in' => $opening, 'out' => 0, 'note' => '기초 재고']];
        foreach ($bought as $d => $q) $moves[$d] = ['in' => ($moves[$d]['in'] ?? 0) + $q, 'out' => 0, 'note' => '물품구매 입고'];
        // 주간 사용량
        $stock = 0;
        $acc = 0.0;
        $week = 0.0;
        for ($t = strtotime($from); $t <= strtotime($today); $t += 86400) {
            $d = date('Y-m-d', $t);
            $stock += $moves[$d]['in'] ?? 0;
            $week += $rn($d) * $rate;
            if ((int) date('w', $t) === 0 || $d === $today) {
                $acc += $week;
                $week = 0.0;
                $out = min($stock, (int) floor($acc));
                if ($out > 0) {
                    $acc -= $out;
                    $stock -= $out;
                    $moves[$d] = ['in' => $moves[$d]['in'] ?? 0, 'out' => $out, 'note' => isset($moves[$d]['note']) && $moves[$d]['in'] ? $moves[$d]['note'] . ' · 객실 지급' : '객실 지급'];
                }
            }
        }
        ksort($moves);
        foreach ($moves as $d => $m) {
            if ($m['in'] || $m['out']) demo_ins('supply_moves', ['supply_id' => $sid, 'move_date' => $d, 'in_qty' => $m['in'], 'out_qty' => $m['out'], 'note' => $m['note'], 'user_id' => $worker['id']]);
        }
    }
}
