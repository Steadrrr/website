<?php
defined('APP_ROOT') || exit;

/*
 * 객실 청소관리 (웹앱 clean.php · clean_api.php)
 *   퇴실 객실: 퇴실대기 → [퇴실처리] 청소가능 → [청소완료] 입실가능. 퇴실처리·청소완료는 모든 앱 사용자에게 알림,
 *   퇴실 객실이 모두 청소완료되면 '전객실 입실준비완료' 알림.
 *   연박 객실(오늘도 같은 손님): 청소 대상이 아니고 [비품지급] 여부만 기록 (알림 없음).
 * 객실 목록은 입퇴실현황과 같은 계산(turnover_lists, 일일객실판매 기준).
 */

const CLEAN_STATUS = ['wait' => '퇴실대기', 'dirty' => '청소가능', 'ready' => '입실가능'];

/** 그 날 객실별 기록 [객실 id => room_clean 줄 + 처리한 사람 이름] */
function clean_rows(string $date): array
{
    $st = db()->prepare('SELECT c.*, uo.name AS out_name, uc.name AS clean_name, us.name AS supply_name
                           FROM room_clean c LEFT JOIN users uo ON uo.id = c.out_by LEFT JOIN users uc ON uc.id = c.clean_by LEFT JOIN users us ON us.id = c.supply_by
                          WHERE c.work_date = ?');
    $st->execute([$date]);
    $out = [];
    foreach ($st as $r) $out[(int) $r['product_id']] = $r;
    return $out;
}

/** 앱 화면에 쓰는 그 날 상태 (JSON) */
function clean_state(string $date): array
{
    $lists = turnover_lists($date);
    $rows = clean_rows($date);
    $hm = fn(?string $t) => $t ? substr($t, 11, 5) : null;
    $room = fn(array $p) => ['id' => (int) $p['id'], 'name' => (string) $p['name'], 'type' => room_type_name($p['room_type_id'] ? (int) $p['room_type_id'] : null)];
    $state = ['date' => $date, 'rooms' => [], 'stays' => [], 'arrivals' => []];
    foreach ($lists['out'] as $pid => $r) {
        $c = $rows[$pid] ?? [];
        if ($r['stay']) { // 연박 중: 청소 없음, 비품지급
            $state['stays'][] = $room($r['p']) + ['nights' => $r['nights'], 'supply_at' => $hm($c['supply_at'] ?? null), 'supply_by' => $c['supply_name'] ?? null];
            continue;
        }
        $status = empty($c['out_at']) ? 'wait' : (empty($c['clean_at']) ? 'dirty' : 'ready');
        $state['rooms'][] = $room($r['p']) + ['status' => $status, 'in_today' => isset($lists['in'][$pid]) && !$lists['in'][$pid]['stay'],
            'out_at' => $hm($c['out_at'] ?? null), 'out_by' => $c['out_name'] ?? null, 'clean_at' => $hm($c['clean_at'] ?? null), 'clean_by' => $c['clean_name'] ?? null];
    }
    foreach ($lists['in'] as $pid => $r) { // 어젯밤 빈 객실에 오늘 입실 (청소 대상 아님)
        if (!$r['stay'] && !isset($lists['out'][$pid])) $state['arrivals'][] = $room($r['p']);
    }
    $cnt = fn(string $s) => count(array_filter($state['rooms'], fn($r) => $r['status'] === $s));
    $state['count'] = ['total' => count($state['rooms']), 'wait' => $cnt('wait'), 'dirty' => $cnt('dirty'), 'ready' => $cnt('ready'),
        'stays' => count($state['stays']), 'supplied' => count(array_filter($state['stays'], fn($r) => $r['supply_at'] !== null))];
    $state['all_ready'] = $state['count']['total'] > 0 && $state['count']['ready'] === $state['count']['total'];
    return $state;
}

/**
 * 처리: out 퇴실처리 · clean 청소완료 · supply 비품지급 · undo_out · undo_clean · undo_supply
 * [오류 메시지('' = 성공), 알림을 보낼지]
 */
function clean_act(string $date, int $pid, string $act, array $user): array
{
    $pdo = db();
    $lists = turnover_lists($date);
    $o = $lists['out'][$pid] ?? null;
    if (!$o) return ['이 날 퇴실·연박 객실이 아닙니다. 화면을 새로고침하세요.', false];
    $isStay = $o['stay'];
    if (in_array($act, ['supply', 'undo_supply'], true) !== $isStay) return [$isStay ? '연박 객실은 청소 대상이 아니라 비품지급만 기록합니다.' : '비품지급은 연박 객실만 합니다.', false];

    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT IGNORE INTO room_clean (work_date, product_id) VALUES (?, ?)')->execute([$date, $pid]);
        $st = $pdo->prepare('SELECT * FROM room_clean WHERE work_date = ? AND product_id = ? FOR UPDATE');
        $st->execute([$date, $pid]);
        $c = $st->fetch();
        $uid = (int) $user['id'];
        $set = fn(string $sql) => $pdo->prepare("UPDATE room_clean SET $sql WHERE work_date = ? AND product_id = ?");
        $event = null;
        $err = '';
        switch ($act) {
            case 'out':
                if ($c['out_at']) { $err = '이미 퇴실처리된 객실입니다.'; break; }
                $set('out_at = NOW(), out_by = ?')->execute([$uid, $date, $pid]);
                $event = 'out';
                break;
            case 'clean':
                if (!$c['out_at']) { $err = '먼저 퇴실처리를 해야 청소완료를 할 수 있습니다.'; break; }
                if ($c['clean_at']) { $err = '이미 청소완료된 객실입니다.'; break; }
                $set('clean_at = NOW(), clean_by = ?')->execute([$uid, $date, $pid]);
                $event = 'clean';
                break;
            case 'undo_out':
                if (!$c['out_at'] || $c['clean_at']) { $err = $c['clean_at'] ? '청소완료를 먼저 되돌리세요.' : '퇴실처리되지 않은 객실입니다.'; break; }
                $set('out_at = NULL, out_by = NULL')->execute([$date, $pid]);
                break;
            case 'undo_clean':
                if (!$c['clean_at']) { $err = '청소완료되지 않은 객실입니다.'; break; }
                $set('clean_at = NULL, clean_by = NULL')->execute([$date, $pid]);
                break;
            case 'supply':
                if ($c['supply_at']) { $err = '이미 비품지급한 객실입니다.'; break; }
                $set('supply_at = NOW(), supply_by = ?')->execute([$uid, $date, $pid]);
                break;
            case 'undo_supply':
                $set('supply_at = NULL, supply_by = NULL')->execute([$date, $pid]);
                break;
            default:
                $err = '알 수 없는 처리입니다.';
        }
        if ($err) { $pdo->rollBack(); return [$err, false]; }
        $ins = $pdo->prepare('INSERT INTO room_clean_events (work_date, product_id, kind, user_id) VALUES (?, ?, ?, ?)');
        if ($event) $ins->execute([$date, $pid, $event, $uid]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    // 마지막 객실 청소완료 → 전객실 입실준비완료
    if ($event === 'clean' && clean_state($date)['all_ready']) {
        $pdo->prepare("INSERT INTO room_clean_events (work_date, product_id, kind, user_id) VALUES (?, NULL, 'all', ?)")->execute([$date, (int) $user['id']]);
    }
    return ['', $event !== null];
}

/** 알림 문구 [제목, 내용] */
function clean_event_text(array $ev): array
{
    $who = trim(($ev['user_name'] ?? '') . ' ' . substr((string) $ev['created_at'], 11, 5));
    $room = (string) ($ev['room_name'] ?? '');
    return match ($ev['kind']) {
        'out'   => ["퇴실 · $room", "퇴실처리되어 청소가능 상태입니다. ($who)"],
        'clean' => ["입실가능 · $room", "청소완료되어 입실가능 상태입니다. ($who)"],
        'all'   => ['전객실 입실준비완료', date('n월 j일', strtotime((string) $ev['work_date'])) . ' 퇴실 객실 청소를 모두 마쳤습니다. (' . $who . ')'],
        default => ['객실 청소관리', '새 알림이 있습니다.'],
    };
}

/** 알림을 받은 기기에 보여 줄 새 알림들 (보낸 사람 것 제외, 1시간 안). 새 것이 없으면 마지막 하나(같은 tag 라 겹쳐서 바뀜) */
function clean_events_for(array $sub): array
{
    $sql = 'SELECT e.*, u.name AS user_name, p.name AS room_name FROM room_clean_events e
              JOIN users u ON u.id = e.user_id LEFT JOIN products p ON p.id = e.product_id
             WHERE e.user_id <> ? AND e.created_at >= NOW() - INTERVAL 1 HOUR';
    $st = db()->prepare("$sql AND e.id > ? ORDER BY e.id LIMIT 10");
    $st->execute([(int) $sub['user_id'], (int) $sub['last_event_id']]);
    $evs = $st->fetchAll();
    if ($evs) {
        db()->prepare('UPDATE push_subs SET last_event_id = ? WHERE id = ?')->execute([(int) end($evs)['id'], (int) $sub['id']]);
    } else {
        $st = db()->prepare("$sql ORDER BY e.id DESC LIMIT 1");
        $st->execute([(int) $sub['user_id']]);
        $evs = $st->fetchAll();
    }
    $out = array_map(function ($ev) {
        [$title, $body] = clean_event_text($ev);
        return ['id' => (int) $ev['id'], 'kind' => $ev['kind'], 'title' => $title, 'body' => $body];
    }, $evs);
    // 알림 테스트 요청 (5분 안)
    if (!empty($sub['test_at']) && strtotime((string) $sub['test_at']) >= time() - 300) {
        db()->prepare('UPDATE push_subs SET test_at = NULL WHERE id = ?')->execute([(int) $sub['id']]);
        $out = [['id' => -(int) $sub['id'], 'kind' => 'test', 'title' => '알림 테스트', 'body' => '이 휴대폰에 알림이 정상으로 옵니다.']];
    }
    return $out;
}
