<?php
defined('APP_ROOT') || exit;

/*
 * AI 분석 › 지난 예측 되돌아보기
 *  - 고른 날(기준일)까지의 기록만 보고 AI 분석 화면과 같은 규칙으로 '그다음 4주' 예측을 다시 만든 뒤,
 *    그 4주 동안 실제로 생긴 민원·설비 수리와 비교한다 (맞음 / 비슷함 / 안 일어남 + 놓친 일).
 *  - 그날 발행된 AI 예측이 있으면 그 항목도 실제 기록과 자동으로 맞춰 본다 (설비 이름·객실·분류 낱말로).
 *  - 비교 대상: 민원(단순문의 제외)·설비 수리. 소모품은 실제 기록과 맞춰 볼 기준이 없어 뺀다.
 *  - API 를 부르지 않는다 (비용 없음).
 */

/** 기준일 → 비교 기간 [시작, 끝] = 다음 날부터 4주 */
function air_window(string $basis): array
{
    return [date('Y-m-d', strtotime("$basis +1 day")), date('Y-m-d', strtotime("$basis +" . AI_HORIZON_DAYS . ' days'))];
}

/** 기간 안 민원 (단순문의 제외): [소분류 => [건수, [날짜…]]], [객실|소분류 => …], [중분류 => 건수] */
function air_complaints(string $from, string $to): array
{
    $st = db()->prepare('SELECT work_date, cat2, cat3, place_type, place_name, qty FROM complaints WHERE work_date BETWEEN ? AND ? AND cat1 <> ? ORDER BY work_date');
    $st->execute([$from, $to, CPL_SIMPLE]);
    $by3 = $byRoom = $byRoom2 = $by2 = [];
    foreach ($st as $r) {
        $by3[$r['cat3']][0] = ($by3[$r['cat3']][0] ?? 0) + (int) $r['qty'];
        $by3[$r['cat3']][1][] = $r['work_date'];
        $by2[$r['cat2']] = ($by2[$r['cat2']] ?? 0) + (int) $r['qty'];
        if ($r['place_type'] === 'room' && $r['place_name'] !== null) {
            $byRoom[$r['place_name'] . '|' . $r['cat3']][] = $r['work_date'];
            $byRoom2[$r['place_name'] . '|' . $r['cat2']][] = $r['work_date'] . ' ' . cpl_name($r['cat3']);
            $byRoom2[$r['place_name'] . '|*'][] = $r['work_date'] . ' ' . cpl_name($r['cat3']);
        }
    }
    return ['by3' => $by3, 'by2' => $by2, 'room' => $byRoom, 'room2' => $byRoom2];
}

/** 기간 안 설비 수리: [설비 id => [날짜 내용…]] */
function air_repairs(string $from, string $to): array
{
    $st = db()->prepare("SELECT equipment_id, log_date, content FROM equipment_logs WHERE kind = 'repair' AND log_date BETWEEN ? AND ? ORDER BY log_date");
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st as $r) $out[(int) $r['equipment_id']][] = $r['log_date'] . ' ' . ai_mask(mb_substr((string) $r['content'], 0, 40));
    return $out;
}

/** 그 기준일에 있던 설비 [id => 이름] (그 전에 폐기된 것 제외) */
function air_equipment(string $basis): array
{
    $st = db()->prepare('SELECT id, name FROM equipment WHERE (acquired_on IS NULL OR acquired_on <= ?) AND (disposed_on IS NULL OR disposed_on > ?)');
    $st->execute([$basis, $basis]);
    return array_map('strval', array_column($st->fetchAll(), 'name', 'id'));
}

/** 날짜 목록을 "6/3, 6/17 외 2" 처럼 짧게 */
function air_dates(array $list, int $max = 3): string
{
    $s = array_map(fn($d) => date('n/j', strtotime(substr($d, 0, 10))) . (strlen($d) > 10 ? substr($d, 10) : ''), array_slice($list, 0, $max));
    return implode(', ', $s) . (count($list) > $max ? ' 외 ' . (count($list) - $max) . '건' : '');
}

/**
 * 기준일까지의 기록으로 만든 규칙 예측 vs 실제
 * @return array{basis: string, from: string, to: string, done: int, items: list<array>, missed: list<array>}
 *   items: area, title, why, result(hit|near|miss), actual
 */
function ai_lookback(string $basis): array
{
    $pdo = db();
    [$from, $to] = air_window($basis);
    $actualTo = min($to, date('Y-m-d', strtotime('-1 day'))); // 오늘 기록은 아직 다 안 들어왔다
    $act = air_complaints($from, $actualTo);
    $rep = air_repairs($from, $actualTo);
    $items = [];
    $predicted3 = $predictedEq = [];

    // 1) 작년 같은 4주에 2건 이상 있었던 분류 → 올해도 생긴다
    $st = $pdo->prepare('SELECT cat2, cat3, SUM(qty) n FROM complaints WHERE work_date BETWEEN ? AND ? AND cat1 <> ? GROUP BY cat2, cat3 HAVING n >= 2 ORDER BY n DESC LIMIT 10');
    $st->execute([ai_minus_year($from), ai_minus_year($to), CPL_SIMPLE]);
    foreach ($st as $r) {
        $predicted3[$r['cat3']] = true;
        $a = $act['by3'][$r['cat3']] ?? null;
        $near = $act['by2'][$r['cat2']] ?? 0;
        $items[] = ['area' => '민원', 'title' => cpl_name($r['cat3']) . ' 민원 예상', 'why' => "작년 같은 4주 {$r['n']}건",
            'result' => $a ? 'hit' : ($near ? 'near' : 'miss'),
            'actual' => $a ? "{$a[0]}건 (" . air_dates($a[1]) . ')' : ($near ? "같은 중분류(" . cpl_name($r['cat2']) . ") {$near}건" : '')];
    }

    // 2) 같은 객실·같은 소분류가 최근 120일 2번 이상 → 또 생긴다
    $st = $pdo->prepare("SELECT place_name, cat2, cat3, COUNT(*) n, MAX(work_date) last FROM complaints
                          WHERE place_type = 'room' AND cat1 <> ? AND work_date BETWEEN ? AND ? GROUP BY place_name, cat2, cat3 HAVING n >= 2 ORDER BY n DESC, last DESC LIMIT 10");
    $st->execute([CPL_SIMPLE, date('Y-m-d', strtotime("$basis -119 days")), $basis]);
    foreach ($st as $r) {
        $predicted3[$r['cat3']] = true;
        $a = $act['room'][$r['place_name'] . '|' . $r['cat3']] ?? null;
        $near = $act['room2'][$r['place_name'] . '|' . $r['cat2']] ?? null;
        $items[] = ['area' => '민원', 'title' => "{$r['place_name']} '" . cpl_name($r['cat3']) . "' 재발", 'why' => "그 전 120일 {$r['n']}건, 마지막 " . date('n/j', strtotime($r['last'])),
            'result' => $a ? 'hit' : ($near ? 'near' : 'miss'),
            'actual' => $a ? count($a) . '건 (' . air_dates($a) . ')' : ($near ? '같은 객실 비슷한 민원: ' . air_dates($near, 2) : '')];
    }

    // 3) 설비: 그 전 1년 수리 2회 이상, 또는 작년 이맘때(±2주) 수리
    $eqs = air_equipment($basis);
    $r90To = min(date('Y-m-d', strtotime("$basis +90 days")), date('Y-m-d', strtotime('-1 day')));
    $rep90 = $r90To > $actualTo ? air_repairs(date('Y-m-d', strtotime("$actualTo +1 day")), $r90To) : [];
    $st = $pdo->prepare("SELECT equipment_id, log_date FROM equipment_logs WHERE kind = 'repair' AND log_date BETWEEN ? AND ? ORDER BY log_date");
    $st->execute([date('Y-m-d', strtotime(ai_minus_year($basis) . ' -14 days')), $basis]);
    $past = [];
    foreach ($st as $r) $past[(int) $r['equipment_id']][] = $r['log_date'];
    [$lyFrom, $lyTo] = [date('Y-m-d', strtotime(ai_minus_year($from) . ' -14 days')), date('Y-m-d', strtotime(ai_minus_year($to) . ' +14 days'))];
    $y1 = date('Y-m-d', strtotime("$basis -364 days"));
    foreach ($past as $id => $dates) {
        if (!isset($eqs[$id])) continue;
        $n365 = count(array_filter($dates, fn($d) => $d >= $y1));
        $ly = array_values(array_filter($dates, fn($d) => $d >= $lyFrom && $d <= $lyTo));
        if ($n365 < 2 && !$ly) continue;
        $predictedEq[$id] = true;
        $a = $rep[$id] ?? null;
        $later = $a ? null : ($rep90[$id] ?? null); // 4주 안엔 없었지만 석 달 안에 고장 → 미리 점검했으면 좋았을 설비
        $items[] = ['area' => '시설', 'title' => $eqs[$id] . ($n365 >= 2 ? ' 반복 고장' : ' 작년 이맘때 고장'),
            'why' => $n365 >= 2 ? "그 전 1년 수리 {$n365}회" : '작년 ' . air_dates($ly) . ' 수리',
            'result' => $a ? 'hit' : ($later ? 'near' : 'miss'), 'actual' => $a ? air_dates($a, 2) : ($later ? '4주 뒤 ' . air_dates($later, 1) . ' 수리' : '')];
    }

    $rank = ['hit' => 0, 'near' => 1, 'miss' => 2];
    usort($items, fn($a, $b) => [$a['area'], $rank[$a['result']]] <=> [$b['area'], $rank[$b['result']]]);

    // 놓친 일: 예측에 없던 분류의 민원, 예측에 없던 설비 수리
    $missed = [];
    foreach ($act['by3'] as $c3 => [$n, $dates]) {
        if (!isset($predicted3[$c3])) $missed[] = ['area' => '민원', 'title' => cpl_name($c3), 'actual' => "{$n}건 (" . air_dates($dates) . ')'];
    }
    foreach ($rep as $id => $list) {
        if (!isset($predictedEq[$id])) $missed[] = ['area' => '시설', 'title' => $eqs[$id] ?? ('설비 #' . $id), 'actual' => air_dates($list, 2)];
    }
    return ['basis' => $basis, 'from' => $from, 'to' => $to, 'done' => max(0, ai_days($from, $actualTo)), 'items' => $items, 'missed' => $missed];
}

/**
 * 그때 발행된 AI 예측 항목을 실제 기록과 자동 대조 (제목 속 설비 이름·객실 이름·분류 낱말)
 *  result: hit | near | miss | skip(자동 비교 불가)
 */
function ai_lookback_ai(array $insight): array
{
    [$from, $to] = [$insight['period_from'], min($insight['period_to'], date('Y-m-d', strtotime('-1 day')))];
    $act = air_complaints($from, $to);
    $rep = air_repairs($from, $to);
    $eqs = air_equipment(date('Y-m-d', strtotime("$from -1 day")));
    uasort($eqs, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a)); // 긴 이름부터 (예: '해B 2층 에어컨' 이 '에어컨' 보다 먼저)
    $rooms = [];
    foreach (products_all() as $p) if ($p['grp'] === 'room') $rooms[] = $p['name'];
    usort($rooms, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    $words = air_cat_words();
    $data = json_decode((string) $insight['result'], true) ?: [];
    $out = [];
    foreach ($data['items'] ?? [] as $it) {
        if (!is_array($it)) continue;
        $t = (string) ($it['title'] ?? '');
        $row = ['area' => (string) ($it['area'] ?? ''), 'title' => $t, 'result' => 'skip', 'actual' => ''];
        if ($row['area'] === '시설') {
            foreach ($eqs as $id => $name) {
                if ($name === '' || mb_strpos($t, $name) === false) continue;
                $row['result'] = isset($rep[$id]) ? 'hit' : 'miss';
                $row['actual'] = isset($rep[$id]) ? air_dates($rep[$id], 2) : '';
                break;
            }
        }
        if ($row['result'] === 'skip' && in_array($row['area'], ['민원', '시설'], true)) {
            $room = null;
            foreach ($rooms as $rn) if (mb_strpos($t, $rn) !== false) { $room = $rn; break; }
            $c3 = null;
            foreach ($words as $w => $code) if (mb_strpos($t, $w) !== false) { $c3 = $code; break; }
            if ($room !== null && $c3 !== null) {
                $a = $act['room'][$room . '|' . $c3] ?? null;
                $near = $act['room2'][$room . '|*'] ?? null;
                $row['result'] = $a ? 'hit' : ($near ? 'near' : 'miss');
                $row['actual'] = $a ? air_dates($a) : ($near ? '같은 객실 다른 민원: ' . air_dates($near, 2) : '');
            } elseif ($c3 !== null) {
                $a = $act['by3'][$c3] ?? null;
                $row['result'] = $a ? 'hit' : 'miss';
                $row['actual'] = $a ? "{$a[0]}건 (" . air_dates($a[1]) . ')' : '';
            } elseif ($room !== null) {
                $near = $act['room2'][$room . '|*'] ?? null;
                $row['result'] = $near ? 'hit' : 'miss';
                $row['actual'] = $near ? air_dates($near, 2) : '';
            }
        }
        $out[] = $row;
    }
    return $out;
}

/** 제목에서 분류를 알아볼 낱말 => 소분류 코드 (단순문의 제외, 긴 낱말부터) */
function air_cat_words(): array
{
    static $w = null;
    if ($w !== null) return $w;
    $w = ['에어컨' => '3-2-1', '난방' => '3-2-1', '보일러' => '3-2-2', '히터' => '3-2-2', '벌집' => '4-1-1', '제설' => '4-4-1', '폭설' => '4-4-1', '정전' => '4-3-1', '단수' => '4-3-2', '청소' => '3-1-1', '청결' => '3-1-1', 'TV' => '3-2-4', '와이파이' => '3-2-4', '인터넷' => '3-2-4'];
    foreach (cpl_tree()['names'] as $code => $name) {
        if (substr_count($code, '-') !== 2 || $code[0] === CPL_SIMPLE) continue;
        foreach (preg_split('/[·,()\s]+/u', $name) as $part) {
            if (mb_strlen($part) >= 2 && !in_array($part, ['기타', '객실', '불량', '부족', '파손', '고장', '상태', '시설', '대응', '실수', '오류', '변경', '추가', '등'], true)) $w[$part] ??= $code;
        }
    }
    uksort($w, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    return $w;
}

/** 지난 AI 발행본 목록 (최근 것부터) */
function ai_insights_list(int $limit = 30): array
{
    $st = db()->prepare("SELECT id, period_from, period_to, created_at FROM ai_insights WHERE kind = 'forecast' ORDER BY id DESC LIMIT $limit");
    $st->execute();
    return $st->fetchAll();
}
