<?php
defined('APP_ROOT') || exit;

/*
 * 출퇴근 (개인업무 › 출퇴근): 휴게실마다 붙인 QR을 본인 휴대폰으로 찍고 [출근]·[퇴근]
 *  - clock_places: 휴게실 (QR 한 장 = 비밀값 하나). 어느 휴게실에서 찍어도 출근으로 인정
 *  - clock_nets:   현장 인터넷(휴게실 공유기 회선)의 공인 IP. 여기서 찍으면 '현장', 아니면 '현장 밖'으로 표시만 한다 (막지 않음)
 *                  위치(GPS)는 쓰지 않는다. IP 는 현장 인터넷을 등록할 때 보려고 두고 60일이 지나면 지운다
 *  - clock_logs:   기록. 퇴근은 아직 퇴근하지 않은 출근(20시간 안)의 근무일로 묶는다 — 야간경비·당직의 다음 날 아침 퇴근
 *  - 빠진 기록은 공무직 이상이 사유와 함께 넣고, 지울 때도 사유를 남긴다 (지운 기록은 남겨 둠)
 */

const CLOCK_KINDS = ['in' => '출근', 'out' => '퇴근'];
const CLOCK_IP_DAYS = 60;      // IP 보관 일수
const CLOCK_SHIFT_HOURS = 20;  // 퇴근을 앞선 출근에 묶는 최대 시간
const CLOCK_DUP_MINUTES = 3;   // 같은 버튼을 이 시간 안에 다시 누르면 한 번으로

/** 출퇴근 현황·직접 입력·설정: 공무직 이상·최고관리자 (근태 관리자와 같음) */
function clock_can_manage(array $u): bool
{
    return att_is_manager($u);
}

function clock_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** 등록된 현장 인터넷에서 접속했는가 (맞으면 마지막 확인 시각을 남긴다) */
function clock_onsite(string $ip): bool
{
    if ($ip === '') return false;
    $st = db()->prepare('UPDATE clock_nets SET last_seen_at = NOW() WHERE ip = ?');
    $st->execute([$ip]);
    if ($st->rowCount()) return true;
    $st = db()->prepare('SELECT 1 FROM clock_nets WHERE ip = ?'); // 같은 분 안에 다시 확인하면 rowCount 0
    $st->execute([$ip]);
    return (bool) $st->fetchColumn();
}

/** @return array<int,array> id => 휴게실 */
function clock_places(bool $activeOnly = true): array
{
    $out = [];
    foreach (db()->query('SELECT * FROM clock_places' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY is_active DESC, sort_order, id') as $p) $out[(int) $p['id']] = $p;
    return $out;
}

function clock_place_by_token(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{24}$/', $token)) return null;
    $st = db()->prepare('SELECT * FROM clock_places WHERE token = ? AND is_active = 1');
    $st->execute([$token]);
    return $st->fetch() ?: null;
}

/** QR에 넣는 주소 (사이트 주소 + clock.php?p=비밀값) */
function clock_place_url(array $p): string
{
    $https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';
    $host = preg_replace('/[^a-z0-9.\-:]/i', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return ($https ? 'https' : 'http') . '://' . $host . url('clock.php?p=' . $p['token']);
}

/** 퇴근의 근무일: 아직 퇴근하지 않은 출근이 20시간 안에 있으면 그 출근의 날, 없으면 찍은 날 */
function clock_out_work_date(int $userId, string $at): string
{
    $st = db()->prepare("SELECT work_date, at FROM clock_logs WHERE user_id = ? AND kind = 'in' AND deleted_at IS NULL AND at <= ? AND at >= ? ORDER BY at DESC LIMIT 1");
    $st->execute([$userId, $at, date('Y-m-d H:i:s', strtotime($at) - CLOCK_SHIFT_HOURS * 3600)]);
    $in = $st->fetch();
    if (!$in) return substr($at, 0, 10);
    $st = db()->prepare("SELECT 1 FROM clock_logs WHERE user_id = ? AND kind = 'out' AND deleted_at IS NULL AND at > ? AND at <= ? LIMIT 1");
    $st->execute([$userId, $in['at'], $at]);
    return $st->fetchColumn() ? substr($at, 0, 10) : $in['work_date'];
}

/**
 * 출근·퇴근 한 번 기록. 같은 버튼을 3분 안에 다시 누르면 새로 남기지 않고 앞의 기록을 돌려준다.
 * @return array{0: array, 1: bool} [기록, 새로 남겼는가]
 */
function clock_record(int $userId, string $kind, ?int $placeId, bool $onsite, ?string $ip): array
{
    $pdo = db();
    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare('SELECT * FROM clock_logs WHERE user_id = ? AND kind = ? AND deleted_at IS NULL AND at >= ? ORDER BY at DESC LIMIT 1');
    $st->execute([$userId, $kind, date('Y-m-d H:i:s', time() - CLOCK_DUP_MINUTES * 60)]);
    if ($dup = $st->fetch()) return [$dup, false];
    $workDate = $kind === 'out' ? clock_out_work_date($userId, $now) : substr($now, 0, 10);
    $pdo->prepare("INSERT INTO clock_logs (user_id, work_date, kind, at, place_id, onsite, ip, method, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, 'qr', ?)")
        ->execute([$userId, $workDate, $kind, $now, $placeId, (int) $onsite, $ip ?: null, $userId]);
    $st = $pdo->prepare('SELECT * FROM clock_logs WHERE id = ?');
    $st->execute([(int) $pdo->lastInsertId()]);
    return [$st->fetch(), true];
}

/** 공무직 이상이 빠진 기록을 넣는다 (근무일·시각·사유 필수) */
function clock_add_manual(int $userId, string $workDate, string $kind, string $at, string $note, int $by): void
{
    db()->prepare("INSERT INTO clock_logs (user_id, work_date, kind, at, onsite, method, note, created_by) VALUES (?, ?, ?, ?, 0, 'manual', ?, ?)")
        ->execute([$userId, $workDate, $kind, $at, mb_substr($note, 0, 200), $by]);
}

function clock_delete(int $id, string $note, int $by): void
{
    db()->prepare('UPDATE clock_logs SET deleted_at = NOW(), deleted_by = ?, delete_note = ? WHERE id = ? AND deleted_at IS NULL')
        ->execute([$by, mb_substr($note, 0, 200), $id]);
}

/**
 * 기간의 기록 [user_id][근무일] => ['in' => 첫 출근, 'out' => 마지막 퇴근, 'all' => 모든 기록(시각 순), 'offsite' => 현장 밖 QR 기록 있음]
 */
function clock_days(string $from, string $to, ?int $userId = null): array
{
    $sql = 'SELECT l.*, p.name AS place_name, c.name AS created_name FROM clock_logs l LEFT JOIN clock_places p ON p.id = l.place_id LEFT JOIN users c ON c.id = l.created_by
             WHERE l.deleted_at IS NULL AND l.work_date BETWEEN ? AND ?' . ($userId ? ' AND l.user_id = ?' : '') . ' ORDER BY l.at, l.id';
    $st = db()->prepare($sql);
    $st->execute($userId ? [$from, $to, $userId] : [$from, $to]);
    $out = [];
    foreach ($st as $r) {
        $d = &$out[(int) $r['user_id']][$r['work_date']];
        $d ??= ['in' => null, 'out' => null, 'all' => [], 'offsite' => false];
        $d['all'][] = $r;
        if ($r['kind'] === 'in' && !$d['in']) $d['in'] = $r;
        if ($r['kind'] === 'out') $d['out'] = $r;
        if ($r['method'] === 'qr' && !$r['onsite']) $d['offsite'] = true;
        unset($d);
    }
    return $out;
}

/** 기간의 전일 근태(연차·병가·공가·결근, 결재중·완료) [user_id][날짜] => 근태 이름 */
function clock_leaves(string $from, string $to): array
{
    $out = [];
    foreach (att_records(['from' => $from, 'to' => $to]) as $a) {
        if (att_is_time_kind($a['kind'])) continue;
        for ($d = max($from, $a['start_date']); $d <= min($to, $a['end_date']); $d = date('Y-m-d', strtotime("$d +1 day"))) {
            $out[(int) $a['user_id']][$d] = att_kind_name($a['kind']);
        }
    }
    return $out;
}

/**
 * 하루 상태 [이름, css] — 휴무 / 근태 / 미출근 / 출근 전 / 지각 N분 / 근무 중 / 퇴근 기록 없음 / 정상
 * (지각은 근무자 설정의 근무 시작 시각 기준 — 날마다 근무 시간이 다르면 참고만)
 */
function clock_status(array $u, string $date, ?array $day, ?string $leave): array
{
    $today = date('Y-m-d');
    if (!$day) {
        if ($leave) return [$leave, 'cs-leave'];
        if (att_is_rest_day($u, $date)) return ['휴무', 'cs-rest'];
        if ($date > $today) return ['', ''];
        [$start] = att_work_hours($u);
        if ($date === $today && date('H:i') < $start) return ['출근 전', 'cs-wait'];
        return ['미출근', 'cs-miss'];
    }
    if (!$day['in']) return ['출근 기록 없음', 'cs-miss'];
    $late = '';
    if (!empty($u['work_start'])) {
        $min = (int) floor((strtotime($day['in']['at']) - strtotime($date . ' ' . substr($u['work_start'], 0, 5))) / 60);
        if ($min > 0 && $min < 12 * 60) $late = "지각 {$min}분";
    }
    if (!$day['out']) {
        $over = time() - strtotime($day['in']['at']) > CLOCK_SHIFT_HOURS * 3600;
        return $over ? ['퇴근 기록 없음' . ($late ? " · $late" : ''), 'cs-miss'] : ['근무 중' . ($late ? " · $late" : ''), $late ? 'cs-late' : 'cs-work'];
    }
    return $late ? [$late, 'cs-late'] : ['정상', 'cs-ok'];
}

/** IP 는 60일이 지나면 지운다 (현장 인터넷 등록에만 씀) */
function clock_purge_ips(): void
{
    db()->exec('UPDATE clock_logs SET ip = NULL WHERE ip IS NOT NULL AND at < DATE_SUB(NOW(), INTERVAL ' . CLOCK_IP_DAYS . ' DAY)');
}

/** 최근 '현장 밖'으로 찍힌 인터넷 (현장 인터넷 등록 후보) — [ip, 건수, 사람 수, 휴게실들, 마지막] */
function clock_unknown_nets(): array
{
    return db()->query("SELECT l.ip, COUNT(*) AS n, COUNT(DISTINCT l.user_id) AS people, GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS places, MAX(l.at) AS last_at
                          FROM clock_logs l LEFT JOIN clock_places p ON p.id = l.place_id
                         WHERE l.ip IS NOT NULL AND l.onsite = 0 AND l.method = 'qr' AND l.ip NOT IN (SELECT ip FROM clock_nets)
                         GROUP BY l.ip ORDER BY last_at DESC LIMIT 20")->fetchAll();
}

function clock_hm(?array $row): string
{
    return $row ? date('H:i', strtotime($row['at'])) . (substr($row['at'], 0, 10) !== $row['work_date'] ? '<small>(+1)</small>' : '') : '';
}
