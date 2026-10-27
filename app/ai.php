<?php
defined('APP_ROOT') || exit;

/*
 * AI 분석 (메인 메뉴 'AI 분석') — 오늘부터 4주 미리 보기: 민원 · 시설 · 소모품
 *  원칙: 숫자 계산은 PHP(아래 ai_facts), 해석·우선순위·할 일 정리는 Claude.
 *   1) ai_facts(): 작년 같은 기간·최근 추세·반복 고장·재고 소진 속도를 숫자로 뽑는다 (사람 이름·연락처·민원 원문은 넣지 않음)
 *   2) ai_rule_items(): 그 숫자만으로 만든 규칙 예측 — API 키가 없어도 보인다
 *   3) ai_forecast_run(): 숫자 + 규칙 예측을 Claude 에 보내 해설·구매 목록·체크리스트를 받고 ai_insights 에 저장
 *  발행: 어제까지의 자료로 오늘부터 4주를 예측해 하루 한 번 발행(주무관 이상), 발행본은 모두가 본다. 발행하지 않은 날은 최근 발행본을 보여 준다.
 *  Claude API 는 PHP curl 로 직접 부른다 (공식 PHP SDK 는 파일이 많아 FTP 배포에 부담 — 사용자 결정 2026-10-22).
 */

const AI_MODEL = 'claude-opus-5-5';
const AI_API_VERSION = '2026-10-21';
const AI_HORIZON_DAYS = 28;  // 예측 기간: 오늘부터 4주 (자료는 어제까지 — 하루 한 번 발행)

/**
 * Claude API 키: config.php 의 'anthropic_api_key', 없으면 app/anthropic_key.txt (키 한 줄만 든 파일 — 설정 파일을 고치다 사이트가 멈출 위험이 없다).
 * 체험판(/demo)은 운영 사이트의 app/anthropic_key.txt 도 읽는다. app 폴더는 웹에서 열 수 없다(.htaccess).
 */
function ai_key(): string
{
    $k = trim((string) config('anthropic_api_key', ''));
    if ($k !== '') return $k;
    foreach ([APP_ROOT . '/app/anthropic_key.txt', dirname(APP_ROOT) . '/app/anthropic_key.txt'] as $f) {
        if ((is_demo() || $f === APP_ROOT . '/app/anthropic_key.txt') && is_file($f)) {
            $k = trim((string) file_get_contents($f));
            if ($k !== '') return $k;
        }
    }
    return '';
}

/** 키 파일 (이 사이트의 app 폴더 — 웹에서 열 수 없음) */
function ai_key_file(): string
{
    return APP_ROOT . '/app/anthropic_key.txt';
}

/** 체험판(/demo 폴더)에 저장된 키 파일 — 운영 사이트에서 '체험판 키 가져오기'에 쓴다 */
function ai_demo_key_file(): string
{
    return APP_ROOT . '/demo/app/anthropic_key.txt';
}

/** 화면 표시용: sk-ant-…abcd */
function ai_key_masked(string $k): string
{
    return $k === '' ? '' : substr($k, 0, 7) . '…' . substr($k, -4);
}

/**
 * 키가 맞는지 Anthropic 에 확인 (모델 목록 조회 — 토큰을 쓰지 않아 비용 없음)
 * @return ?string 오류 문구 (맞으면 null)
 */
function ai_key_verify(string $key): ?string
{
    if (!function_exists('curl_init')) return '서버에 curl 기능이 없어 AI 에 연결할 수 없습니다.';
    if (!preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,}$/', $key)) return "키 모양이 맞지 않습니다. 'sk-ant-' 로 시작하는 키 전체를 붙여 넣어 주세요.";
    $ch = curl_init(rtrim((string) config('anthropic_api_url', 'https://api.anthropic.com'), '/') . '/v1/models?limit=1');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['x-api-key: ' . $key, 'anthropic-version: ' . AI_API_VERSION]]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return '서버에서 Anthropic 에 연결하지 못했습니다. (' . $err . ')';
    return match (true) {
        $code === 200 => null,
        $code === 401 || $code === 403 => '키가 올바르지 않거나 사용이 막혀 있습니다. 콘솔에서 키를 다시 확인해 주세요.',
        default => "확인 중 오류가 났습니다 (HTTP $code). 잠시 뒤 다시 해 주세요.",
    };
}

/** 키 저장 ('' 이면 삭제) */
function ai_key_save(string $key): bool
{
    $f = ai_key_file();
    if ($key === '') return !is_file($f) || @unlink($f);
    $ok = @file_put_contents($f, $key . "\n", LOCK_EX) !== false;
    if ($ok) @chmod($f, 0600);
    return $ok;
}

/** AI 예측 발행: 주무관 이상·최고관리자 (비용이 드는 버튼, 하루 한 번) */
function ai_can_run(?array $u): bool
{
    return $u && (!empty($u['is_admin']) || (int) $u['rank_level'] >= RANK_OFFICER);
}

/** 예측 기간 [시작, 끝] = 오늘부터 4주 */
function ai_window(): array
{
    return [date('Y-m-d'), date('Y-m-d', strtotime('+' . (AI_HORIZON_DAYS - 1) . ' days'))];
}

/** 자료 기준일 = 어제 (오늘 자료는 아직 다 들어오지 않았으므로) */
function ai_basis(): string
{
    return date('Y-m-d', strtotime('-1 day'));
}

function ai_minus_year(string $d): string
{
    return date('Y-m-d', strtotime("$d -1 year"));
}

/** 판매 객실 수(박) 합계 — 일일객실판매, 반려 제외 */
function ai_room_nights(string $from, string $to): int
{
    $st = db()->prepare("SELECT COALESCE(SUM(l.qty), 0) FROM journals j JOIN sales_lines l ON l.journal_id = j.id
                          WHERE j.type = 'rooms' AND j.status <> 'rejected' AND l.grp = 'room' AND j.work_date BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    return (int) $st->fetchColumn();
}

/** 그 기간 중 일일객실판매가 있는 날의 비율 (0~1) — 작년 자료가 일부만 있으면 '작년 기준'으로 쓰지 않는다 */
function ai_coverage(string $from, string $to): float
{
    $st = db()->prepare("SELECT COUNT(DISTINCT work_date) FROM journals WHERE type = 'rooms' AND status <> 'rejected' AND work_date BETWEEN ? AND ?");
    $st->execute([$from, $to]);
    return (int) $st->fetchColumn() / max(1, ai_days($from, $to));
}

function ai_days(string $from, string $to): int
{
    return (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
}

/**
 * AI에게 줄 통계 근거 (개인정보 없음)
 * @return array{period: array, rooms: array, complaints: array, equipment: array, facility_checks: array, supplies: array, purchases: array}
 */
function ai_facts(): array
{
    $pdo = db();
    $today = ai_basis(); // 아래 '최근' 계산은 모두 어제까지
    [$from, $to] = ai_window();
    [$lyFrom, $lyTo] = [ai_minus_year($from), ai_minus_year($to)];
    $roomCount = count(array_filter(products_all(), fn($p) => $p['grp'] === 'room' && $p['is_active']));

    // 객실 판매 (작년 같은 기간 · 최근 4주)
    $recentFrom = date('Y-m-d', strtotime("$today -27 days"));
    $lyRn = ai_room_nights($lyFrom, $lyTo);
    $recentRn = ai_room_nights($recentFrom, $today);
    $rooms = ['객실 수' => $roomCount, '작년 같은 기간 판매(박)' => $lyRn, '최근 4주 판매(박)' => $recentRn,
              '작년 같은 기간 자료 있음' => ai_coverage($lyFrom, $lyTo) >= 0.6]; // 휴관일(주 1회)을 빼면 약 0.85

    // 민원: 작년 같은 기간 많았던 분류, 최근 8주 vs 작년 같은 8주, 같은 객실 반복
    $st = $pdo->prepare('SELECT cat1, cat2, cat3, SUM(qty) n FROM complaints WHERE work_date BETWEEN ? AND ? GROUP BY cat1, cat2, cat3 ORDER BY n DESC LIMIT 15');
    $st->execute([$lyFrom, $lyTo]);
    $lyTop = [];
    foreach ($st as $r) $lyTop[] = ['코드' => $r['cat3'], '분류' => cpl_name($r['cat1']) . ' › ' . cpl_name($r['cat2']) . ' › ' . cpl_name($r['cat3']), '작년 같은 기간 건수' => (int) $r['n']];
    $byCat1 = function (string $a, string $b) use ($pdo): array {
        $st = $pdo->prepare('SELECT cat1, SUM(qty) n FROM complaints WHERE work_date BETWEEN ? AND ? GROUP BY cat1');
        $st->execute([$a, $b]);
        $out = [];
        foreach ($st as $r) $out[cpl_name($r['cat1'])] = (int) $r['n'];
        return $out;
    };
    $w8 = date('Y-m-d', strtotime("$today -55 days"));
    // 같은 객실에서 같은 소분류(예: 온수, 배수)가 되풀이된 것
    $st = $pdo->prepare("SELECT place_name, cat3, COUNT(*) n, MAX(work_date) last FROM complaints
                          WHERE place_type = 'room' AND cat1 <> ? AND work_date BETWEEN ? AND ? GROUP BY place_name, cat3 HAVING n >= 2 ORDER BY n DESC, last DESC LIMIT 10");
    $st->execute([CPL_SIMPLE, date('Y-m-d', strtotime("$today -119 days")), $today]);
    $repeat = [];
    foreach ($st as $r) $repeat[] = ['객실' => $r['place_name'], '분류' => cpl_name($r['cat3']), '최근 120일 건수' => (int) $r['n'], '마지막' => $r['last']];
    $open = (int) $pdo->query("SELECT COUNT(*) FROM complaints WHERE status <> 'done' AND cat1 <> '" . CPL_SIMPLE . "'")->fetchColumn();
    $complaints = ['작년 같은 기간 많았던 분류' => $lyTop, '최근 8주 대분류별' => $byCat1($w8, $today),
                   '작년 같은 8주 대분류별' => $byCat1(ai_minus_year($w8), ai_minus_year($today)), '같은 객실 반복(최근 120일)' => $repeat, '처리 안 끝난 민원' => $open];

    // 장비·설비: 최근 2년 고장·점검 이력
    $logs = $pdo->prepare("SELECT l.equipment_id, l.log_date, l.kind, l.content, l.cost FROM equipment_logs l WHERE l.kind IN ('repair','inspect') AND l.log_date >= ? ORDER BY l.log_date");
    $logs->execute([date('Y-m-d', strtotime('-730 days'))]);
    $byEq = [];
    foreach ($logs as $l) $byEq[(int) $l['equipment_id']][] = $l;
    $equipment = [];
    $eqs = $pdo->query("SELECT e.id, e.name, e.location, e.acquired_on, e.status, g.name AS grp FROM equipment e JOIN asset_groups g ON g.id = e.group_id WHERE e.status <> 'disposed'")->fetchAll();
    foreach ($eqs as $e) {
        $list = $byEq[(int) $e['id']] ?? [];
        if (!$list) continue;
        $repairs = array_values(array_filter($list, fn($l) => $l['kind'] === 'repair'));
        $r365 = count(array_filter($repairs, fn($l) => $l['log_date'] >= date('Y-m-d', strtotime('-365 days'))));
        $gaps = [];
        for ($i = 1; $i < count($repairs); $i++) $gaps[] = ai_days($repairs[$i - 1]['log_date'], $repairs[$i]['log_date']) - 1;
        $lyHit = array_values(array_filter($repairs, fn($l) => $l['log_date'] >= date('Y-m-d', strtotime("$lyFrom -14 days")) && $l['log_date'] <= date('Y-m-d', strtotime("$lyTo +14 days"))));
        $last = end($list);
        $equipment[] = [
            '설비' => $e['name'], '분류' => $e['grp'], '위치' => $e['location'], '사용 연수' => $e['acquired_on'] ? round((time() - strtotime($e['acquired_on'])) / 31557600, 1) : null,
            '상태' => ['active' => '사용중', 'repair' => '수리중'][$e['status']] ?? $e['status'],
            '최근 1년 수리' => $r365, '최근 2년 수리' => count($repairs), '수리 간격(일)' => $gaps,
            '마지막 기록' => $last['log_date'] . ' ' . mb_substr((string) $last['content'], 0, 60),
            '작년 이맘때(±2주) 수리' => array_map(fn($l) => $l['log_date'] . ' ' . mb_substr((string) $l['content'], 0, 40), $lyHit),
            '고장 난 달' => array_values(array_unique(array_map(fn($l) => (int) substr($l['log_date'], 5, 2), $repairs))),
            '_score' => $r365 * 3 + count($lyHit) * 2 + ($e['status'] === 'repair' ? 5 : 0) + (($last['log_date'] >= date('Y-m-d', strtotime('-60 days'))) ? 1 : 0),
        ];
    }
    usort($equipment, fn($a, $b) => $b['_score'] <=> $a['_score']);
    $equipment = array_slice($equipment, 0, 20);

    // 시설점검: 최근 8주 '정상' 아닌 결과
    $st = $pdo->prepare("SELECT fi.area, fi.facility, fi.result, COUNT(*) n, MAX(j.work_date) last FROM facility_items fi JOIN journals j ON j.id = fi.journal_id
                          WHERE j.type = 'facility' AND j.work_date >= ? AND fi.result <> ? GROUP BY fi.area, fi.facility, fi.result ORDER BY n DESC LIMIT 15");
    $st->execute([$w8, (config('facility_results', ['정상'])[0] ?? '정상')]);
    $checks = [];
    foreach ($st as $r) $checks[] = ['구역' => $r['area'], '시설' => $r['facility'], '결과' => $r['result'], '최근 8주 횟수' => (int) $r['n'], '마지막' => $r['last']];

    // 소모품: 재고 · 최근 8주 사용량 · 다음 8주 예상 사용량
    $stock = supply_stock_before(date('Y-m-d')); // 어제까지의 재고
    $rn8 = ai_room_nights($w8, $today);
    $next8To = date('Y-m-d', strtotime('+55 days'));
    $lyNext8 = ai_coverage(ai_minus_year(date('Y-m-d')), ai_minus_year($next8To)) >= 0.6 ? ai_room_nights(ai_minus_year(date('Y-m-d')), ai_minus_year($next8To)) : 0;
    $rnNext8 = $lyNext8 > 0 ? $lyNext8 : $rn8; // 작년 자료가 없거나 일부만 있으면 최근 8주와 같다고 본다
    $out8 = [];
    $st = $pdo->prepare('SELECT supply_id, SUM(out_qty) n FROM supply_moves WHERE move_date BETWEEN ? AND ? GROUP BY supply_id');
    $st->execute([$w8, $today]);
    foreach ($st as $r) $out8[(int) $r['supply_id']] = (int) $r['n'];
    $lastIn = [];
    foreach ($pdo->query("SELECT supply_id, move_date, in_qty FROM supply_moves WHERE in_qty > 0 AND move_date < CURDATE() ORDER BY move_date") as $r) $lastIn[(int) $r['supply_id']] = [$r['move_date'], (int) $r['in_qty']];
    $supplies = [];
    foreach (supplies_all(true) as $sid => $s) {
        $used = $out8[$sid] ?? 0;
        $now = $stock[$sid] ?? 0;
        $need = $rn8 > 0 ? $used / $rn8 * $rnNext8 : $used;
        $perWeek = $need / 8;
        $supplies[] = [
            '품목' => mb_substr($s['name'] . ($s['spec'] ? " ({$s['spec']})" : ''), 0, 40), '단위' => $s['unit'], '현재 재고' => $now, '적정 재고' => (int) $s['safety_stock'],
            '최근 8주 사용' => $used, '다음 8주 예상 사용' => round($need, 1),
            '남은 기간(주)' => $perWeek > 0 ? round($now / $perWeek, 1) : null,
            '마지막 입고' => isset($lastIn[$sid]) ? $lastIn[$sid][0] . ' ' . $lastIn[$sid][1] . $s['unit'] : null,
            '최근 단가' => ai_last_price($s['name']),
        ];
    }
    usort($supplies, fn($a, $b) => ($a['남은 기간(주)'] ?? 999) <=> ($b['남은 기간(주)'] ?? 999));
    $supplies = array_slice($supplies, 0, 30);

    // 물품구매: 최근 2년 월별 구매액 (구매 시기 패턴)
    $st = $pdo->prepare("SELECT DATE_FORMAT(j.work_date, '%Y-%m') ym, SUM(m.total) t, COUNT(*) n FROM journals j JOIN purchase_meta m ON m.journal_id = j.id
                          WHERE j.type = 'purchase' AND j.status <> 'rejected' AND j.work_date >= ? GROUP BY ym ORDER BY ym");
    $st->execute([date('Y-m-d', strtotime('-730 days'))]);
    $purchases = [];
    foreach ($st as $r) $purchases[] = ['월' => $r['ym'], '구매액' => (int) $r['t'], '건수' => (int) $r['n']];

    return [
        'period' => ['자료 기준(어제까지)' => $today, '예측 시작(오늘)' => $from, '예측 끝' => $to, '작년 같은 기간' => "$lyFrom ~ $lyTo"],
        'rooms' => $rooms, 'complaints' => $complaints, 'equipment' => array_map(fn($e) => array_diff_key($e, ['_score' => 1]), $equipment),
        'facility_checks' => $checks, 'supplies' => $supplies, 'purchases' => $purchases,
        'supply_basis' => ['최근 8주 판매(박)' => $rn8, '다음 8주 예상 판매(박)' => $rnNext8, '예상 근거' => $lyNext8 > 0 ? '작년 같은 기간 판매' : '최근 8주와 같다고 가정'],
    ];
}

/** 소모품 이름이 들어간 가장 최근 물품구매 단가 */
function ai_last_price(string $name): ?int
{
    $st = db()->prepare("SELECT i.unit_price FROM purchase_items i JOIN journals j ON j.id = i.journal_id WHERE j.type = 'purchase' AND i.name LIKE ? ORDER BY j.work_date DESC, i.id DESC LIMIT 1");
    $st->execute(['%' . $name . '%']);
    $v = $st->fetchColumn();
    return $v === false ? null : (int) $v;
}

/** 분류(중분류)별 미리 할 일 — 규칙 예측의 조치 문구 */
const AI_CPL_ACTIONS = [
    '3-1' => '청소 점검표에 해당 항목 추가, 주말 다음 날 재확인',
    '3-2' => '해당 설비 사전 점검, 예비 객실 확보',
    '3-3' => '공원 시설 순찰 강화, 파손 부위 임시 통제',
    '4-1' => '처마·데크·놀이터 벌집 사전 점검과 약제 살포',
    '4-3' => '관정·물탱크·차단기 상태 확인, 비상 연락망 점검',
    '4-4' => '제설 장비·염화칼슘 준비, 진입로 결빙 안내',
    '2-1' => '대여 물품(선풍기·충전기 등) 수량 확인',
];

/**
 * 숫자만으로 만든 규칙 예측 (API 키가 없어도 보이는 기본 예측)
 * @return list<array{area: string, level: string, title: string, why: string, action: string, when: string}>
 */
function ai_rule_items(array $f): array
{
    $items = [];
    $when = '다음 4주';
    foreach ($f['complaints']['작년 같은 기간 많았던 분류'] as $c) {
        $c1 = substr($c['코드'], 0, 1);
        if ($c['작년 같은 기간 건수'] < 2) continue;
        if ($c1 === CPL_SIMPLE) {
            if ($c['작년 같은 기간 건수'] >= 5) $items[] = ['area' => '민원', 'level' => '낮음', 'title' => cpl_name($c['코드']) . ' 문의가 많을 때', 'why' => "작년 같은 기간 {$c['작년 같은 기간 건수']}건", 'action' => '홈페이지·예약 안내 문자에 미리 안내', 'when' => $when];
            continue;
        }
        $items[] = ['area' => '민원', 'level' => $c['작년 같은 기간 건수'] >= 4 ? '높음' : '보통', 'title' => cpl_name($c['코드']) . ' 민원 예상',
            'why' => "작년 같은 기간 {$c['작년 같은 기간 건수']}건 ({$c['분류']})", 'action' => AI_CPL_ACTIONS[substr($c['코드'], 0, 3)] ?? '담당자 사전 확인', 'when' => $when];
    }
    foreach ($f['complaints']['같은 객실 반복(최근 120일)'] as $r) {
        $items[] = ['area' => '민원', 'level' => $r['최근 120일 건수'] >= 3 ? '높음' : '보통', 'title' => "{$r['객실']} '{$r['분류']}' 반복",
            'why' => "최근 120일 {$r['최근 120일 건수']}건, 마지막 {$r['마지막']}", 'action' => '근본 원인 점검(업체 정밀점검) 후 판매', 'when' => '이번 주'];
    }
    foreach ($f['equipment'] as $e) {
        if ($e['상태'] === '수리중') {
            $items[] = ['area' => '시설', 'level' => '높음', 'title' => "{$e['설비']} 수리 중", 'why' => $e['마지막 기록'], 'action' => '수리 일정 확인, 대체 설비 준비', 'when' => '이번 주'];
        } elseif ($e['최근 1년 수리'] >= 2) {
            $items[] = ['area' => '시설', 'level' => '높음', 'title' => "{$e['설비']} 반복 고장", 'why' => "최근 1년 수리 {$e['최근 1년 수리']}회, 마지막 {$e['마지막 기록']}", 'action' => '정밀점검 또는 교체 검토, 예비 부품 확보', 'when' => $when];
        } elseif ($e['작년 이맘때(±2주) 수리']) {
            $items[] = ['area' => '시설', 'level' => '보통', 'title' => "{$e['설비']} 작년 이맘때 고장", 'why' => implode(', ', $e['작년 이맘때(±2주) 수리']), 'action' => '시기 전에 미리 점검', 'when' => $when];
        }
    }
    foreach ($f['supplies'] as $s) {
        $weeks = $s['남은 기간(주)'];
        if ($weeks === null) continue;
        if ($weeks < 4) {
            $items[] = ['area' => '소모품', 'level' => '높음', 'title' => "{$s['품목']} 구매 필요", 'why' => "재고 {$s['현재 재고']}{$s['단위']}, 약 {$weeks}주 분", 'action' => '다음 물품구매에 포함', 'when' => '2주 안'];
        } elseif ($weeks < 8 || $s['현재 재고'] <= $s['적정 재고']) {
            $items[] = ['area' => '소모품', 'level' => '보통', 'title' => "{$s['품목']} 곧 부족", 'why' => "재고 {$s['현재 재고']}{$s['단위']}(적정 {$s['적정 재고']}), 약 {$weeks}주 분", 'action' => '다음 달 구매 목록에 올리기', 'when' => '4주 안'];
        }
    }
    $order = ['높음' => 0, '보통' => 1, '낮음' => 2];
    usort($items, fn($a, $b) => $order[$a['level']] <=> $order[$b['level']]);
    // 구분마다 중요한 것 6개까지만 (너무 많으면 아무것도 안 보인다)
    $per = [];
    return array_values(array_filter($items, function ($it) use (&$per) {
        $per[$it['area']] = ($per[$it['area']] ?? 0) + 1;
        return $per[$it['area']] <= 6;
    }));
}

/** Claude 응답 형식 (structured outputs) */
function ai_forecast_schema(): array
{
    $str = ['type' => 'string'];
    return [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['summary', 'items', 'purchase_list', 'checklist'],
        'properties' => [
            'summary' => $str,
            'items' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['area', 'level', 'title', 'why', 'action', 'when'],
                'properties' => ['area' => ['type' => 'string', 'enum' => ['민원', '시설', '소모품', '객실운영']], 'level' => ['type' => 'string', 'enum' => ['높음', '보통', '낮음']],
                                 'title' => $str, 'why' => $str, 'action' => $str, 'when' => $str],
            ]],
            'purchase_list' => ['type' => 'array', 'items' => [
                'type' => 'object', 'additionalProperties' => false,
                'required' => ['item', 'qty', 'est_cost', 'reason'],
                'properties' => ['item' => $str, 'qty' => $str, 'est_cost' => $str, 'reason' => $str],
            ]],
            'checklist' => ['type' => 'array', 'items' => $str],
        ],
    ];
}

const AI_FORECAST_SYSTEM = <<<'TXT'
당신은 경기도 양평군 공공 휴양시설 '쉬자파크'(객실 19개 숙박동 치유의집, 치유센터, 산림교육센터, 공원·숲길, 관정 급수)의 운영 담당 공무원을 돕는 분석가입니다.
업무 시스템에서 어제까지의 자료로 뽑은 통계 숫자와 규칙으로 만든 예측 후보를 받아, 오늘부터 4주 동안 미리 챙길 일을 정리합니다. 이 정리는 그날 하루 모든 직원이 함께 봅니다.

지켜 주세요:
- 주어진 숫자에 있는 사실만 근거로 씁니다. 숫자를 지어내거나 확률(%)을 만들지 마세요. why 에는 근거가 된 숫자(건수·날짜·재고)를 그대로 적습니다.
- 계절·날씨처럼 상식적인 판단을 더할 때는 why 에 "계절 특성" 이라고 밝힙니다.
- 작년 자료가 없거나 적으면 그렇다고 말하고, 그 항목은 level 을 낮춥니다.
- 읽는 사람은 현장 직원입니다. 쉬운 우리말로 짧게 쓰고, 영어·전문용어를 피합니다.
- items 는 중요한 순서로 최대 8개. 같은 내용은 하나로 묶습니다. action 은 바로 할 수 있는 일 한 문장.
- purchase_list 는 소모품 재고·사용 속도·최근 단가로 다음 4~8주 안에 사야 할 것만. 예상 금액은 최근 단가 × 수량으로 계산하고 단가를 모르면 "단가 확인 필요".
- checklist 는 이번 주에 할 일 3~6개.
- summary 는 3문장 이내로 다음 4주의 핵심.
TXT;

/**
 * Claude 호출 (structured outputs, JSON 응답). $model 기본은 AI 분석용 AI_MODEL (민원 분류 제안은 가벼운 Haiku)
 * 구글 키가 있으면 구글(app/ai_gemini.php)을 먼저 쓰고, 실패하면 여기서 Claude 로 보낸다.
 * @return array{ok: bool, data?: array, error?: string, model?: string, usage?: array}
 */
function ai_claude_json(string $system, string $user, array $schema, string $effort = 'medium', int $maxTokens = 16000, string $model = AI_MODEL): array
{
    $g = ai_gemini_on() ? ai_gemini_json($system, $user, $schema, $maxTokens) : null;
    if ($g && $g['ok']) return $g;
    $key = ai_key();
    if ($key === '') return ['ok' => false, 'error' => $g ? $g['error'] : 'API 키가 설정되지 않았습니다.'];
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => '서버에 curl 기능이 없습니다.'];
    // Haiku 는 서버 쪽 대체 모델(fallbacks)이 없다 — 넣으면 오류가 날 수 있어 빼고, 거절은 아래 refusal 처리로
    $fallback = !str_starts_with($model, 'claude-haiku');
    $body = [
        'model' => $model,
        'max_tokens' => $maxTokens,
        'system' => $system,
        'output_config' => ['effort' => $effort, 'format' => ['type' => 'json_schema', 'schema' => $schema]],
        'messages' => [['role' => 'user', 'content' => $user]],
    ];
    if ($fallback) $body['fallbacks'] = 'default'; // 안전 분류기가 거절하면 서버가 권장 모델로 다시 실행
    @set_time_limit(200);
    $ch = curl_init(rtrim((string) config('anthropic_api_url', 'https://api.anthropic.com'), '/') . '/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 180,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: ' . AI_API_VERSION,
            ...($fallback ? ['anthropic-beta: server-side-fallback-2026-07-01'] : []),
        ],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) return ['ok' => false, 'error' => 'AI 서버에 연결하지 못했습니다. (' . $err . ')'];
    $res = json_decode((string) $raw, true);
    if ($code !== 200 || !is_array($res)) {
        $msg = is_array($res) ? (string) ($res['error']['message'] ?? '') : '';
        $hint = match (true) {
            $code === 401 => 'API 키가 올바르지 않습니다.',
            $code === 429 => '요청이 많아 잠시 막혔습니다. 몇 분 뒤 다시 눌러 주세요.',
            $code >= 500 => 'AI 서버가 잠시 바쁩니다. 잠시 뒤 다시 눌러 주세요.',
            default => 'AI 요청이 거절되었습니다.',
        };
        return ['ok' => false, 'error' => "$hint (HTTP $code" . ($msg !== '' ? ': ' . mb_substr($msg, 0, 200) : '') . ')'];
    }
    if (($res['stop_reason'] ?? '') === 'refusal') return ['ok' => false, 'error' => 'AI가 이 요청에 답하지 않았습니다.'];
    if (($res['stop_reason'] ?? '') === 'max_tokens') return ['ok' => false, 'error' => 'AI 답이 너무 길어 잘렸습니다. 다시 눌러 주세요.'];
    // 안전 분류기 거절 → 다른 모델로 이어서 답한 경우 'fallback' 블록 뒤가 최종 답이다 (앞쪽 일부 답은 버림)
    $text = '';
    foreach ($res['content'] ?? [] as $b) {
        if (($b['type'] ?? '') === 'fallback') $text = '';
        elseif (($b['type'] ?? '') === 'text') $text .= $b['text'];
    }
    $data = json_decode($text, true);
    if (!is_array($data)) return ['ok' => false, 'error' => 'AI 답을 읽지 못했습니다.'];
    // 다른 모델로 이어서 답했으면 사용량은 시도별 합계
    $usage = $res['usage'] ?? [];
    if (!empty($usage['iterations']) && is_array($usage['iterations'])) {
        $usage['input_tokens'] = array_sum(array_map(fn($i) => (int) ($i['input_tokens'] ?? 0), $usage['iterations']));
        $usage['output_tokens'] = array_sum(array_map(fn($i) => (int) ($i['output_tokens'] ?? 0), $usage['iterations']));
    }
    return ['ok' => true, 'data' => $data, 'model' => (string) ($res['model'] ?? $model), 'usage' => $usage];
}

/** 혹시 섞여 들어간 전화번호·이메일 가리기 (통계 근거에는 원래 없지만 한 번 더) */
function ai_mask(string $s): string
{
    $d = '[-\s.\x{2010}-\x{2015}\x{2212}]?'; // 하이픈·공백·점·여러 종류의 대시
    // 휴대폰·지역번호·+82 (앞뒤가 숫자가 아니면 — 한글 바로 옆에 붙어 있어도 가린다)
    $s = preg_replace('/(?<!\d)(?:\+?82' . $d . '0?1[016789]|01[016789])' . $d . '\d{3,4}' . $d . '\d{4}(?!\d)/u', '(전화번호)', $s);
    $s = preg_replace('/(?<!\d)0(?:2|[3-6]\d)' . $d . '\d{3,4}' . $d . '\d{4}(?!\d)/u', '(전화번호)', (string) $s);
    $s = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/u', '(이메일)', (string) $s);
    // 직함·호칭 앞의 이름 (김철수 기사, 이정일주임님, 박 대표 …) — 낱말 첫머리부터 가장 짧게, '고객님·손님'은 그대로
    return (string) preg_replace('/(?<![가-힣])(?!고객|손님)[가-힣]{1,4}?\s?(?=(?:기사|주임|주무관|팀장|과장|소장|사장|대표|반장|선생님|님)님?(?![가-힣]))/u', '○○ ', (string) $s);
}

/** 발행 비용 (원, 대략) — 이스터에그: Opus 5.5 단가 입력 $4·출력 $20 / 100만 토큰, 1달러 ≈ 1,400원 */
function ai_cost_won(array $insight): int
{
    if (str_starts_with((string) ($insight['model'] ?? ''), 'gemini')) return 0; // 구글 충전금으로 발행 — 화면에 '구글 AI'로 표시
    $usd = ((int) $insight['input_tokens'] * 4 + (int) $insight['output_tokens'] * 20) / 1000000;
    return (int) (round($usd * 1400 / 10) * 10);
}

/** 오늘 발행본이 있는가 */
function ai_published_today(): bool
{
    return (bool) db()->query("SELECT 1 FROM ai_insights WHERE kind = 'forecast' AND created_at >= CURDATE() LIMIT 1")->fetchColumn();
}

/**
 * 오늘 예측 발행 (하루 한 번) → ai_insights 저장
 * @return array{ok: bool, id?: int, error?: string}
 */
function ai_forecast_run(array $user): array
{
    // 두 사람이 동시에 눌러도 한 번만 (먼저 누른 사람이 끝날 때까지 기다렸다가 다시 확인)
    if ((int) db()->query("SELECT GET_LOCK(CONCAT('ai_publish_', '" . session_site() . "'), 200)")->fetchColumn() !== 1) return ['ok' => false, 'error' => '다른 사람이 발행 중입니다. 잠시 뒤 다시 확인해 주세요.'];
    try {
        if (ai_published_today()) return ['ok' => false, 'error' => '오늘 예측은 이미 발행되었습니다. 내일 다시 발행할 수 있습니다.'];
        return ai_forecast_publish($user);
    } finally {
        db()->query("SELECT RELEASE_LOCK(CONCAT('ai_publish_', '" . session_site() . "'))");
    }
}

function ai_forecast_publish(array $user): array
{
    $facts = ai_facts();
    $rules = ai_rule_items($facts);
    $prompt = "아래는 쉬자파크 업무 시스템의 어제({$facts['period']['자료 기준(어제까지)']})까지 통계입니다. 오늘부터 4주({$facts['period']['예측 시작(오늘)']} ~ {$facts['period']['예측 끝']})를 미리 챙길 수 있게 정리해 주세요.\n\n"
        . "## 통계 근거\n" . json_encode(array_diff_key($facts, ['period' => 1]), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        . "\n\n## 규칙으로 뽑은 예측 후보 (참고용, 빼거나 합치거나 순서를 바꿔도 됩니다)\n" . json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        . "\n\n## 기간\n" . json_encode($facts['period'], JSON_UNESCAPED_UNICODE);
    // 30초~1분 걸리는 호출: 세션을 잠시 풀어 같은 사람의 다른 탭이 멈추지 않게, 브라우저가 끊겨도 끝까지 저장
    $hadSession = session_status() === PHP_SESSION_ACTIVE;
    if ($hadSession) session_write_close();
    ignore_user_abort(true);
    $res = ai_claude_json(AI_FORECAST_SYSTEM, ai_mask($prompt), ai_forecast_schema());
    if ($hadSession) @session_start(); // 결과 안내(flash)를 남기기 위해 다시 연다
    if (!$res['ok']) return $res;
    [$from, $to] = ai_window();
    db()->prepare('INSERT INTO ai_insights (kind, period_from, period_to, facts, result, model, input_tokens, output_tokens, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute(['forecast', $from, $to, json_encode($facts, JSON_UNESCAPED_UNICODE), json_encode($res['data'], JSON_UNESCAPED_UNICODE), $res['model'],
                   (int) ($res['usage']['input_tokens'] ?? 0), (int) ($res['usage']['output_tokens'] ?? 0), $user['id']]);
    return ['ok' => true, 'id' => (int) db()->lastInsertId()];
}

/** 가장 최근 예측 (없으면 null) */
function ai_latest(string $kind = 'forecast'): ?array
{
    $st = db()->prepare('SELECT a.*, u.name AS user_name FROM ai_insights a LEFT JOIN users u ON u.id = a.created_by WHERE a.kind = ? ORDER BY a.id DESC LIMIT 1');
    $st->execute([$kind]);
    $r = $st->fetch();
    if (!$r) return null;
    $r['data'] = json_decode((string) $r['result'], true) ?: [];
    return $r;
}
