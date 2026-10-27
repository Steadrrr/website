<?php
defined('APP_ROOT') || exit;

/*
 * 시설물·장비 공통: 관리팀(대분류) > 구역·건물 / 장비분류(중분류) > 세부시설·장비, 사진
 */

const ASSET_KINDS = ['facility' => '구역·건물', 'equipment' => '장비 분류'];

const EQUIPMENT_STATUS = ['active' => '사용중', 'repair' => '수리중', 'disposed' => '불용'];
const EQUIPMENT_LOG_KINDS = [
    'register' => '등록', 'inspect' => '점검', 'repair' => '수리', 'maintain' => '관리·정비',
    'other' => '기타', 'dispose' => '불용처리', 'restore' => '불용해제',
];
// 사용자가 직접 입력할 수 있는 이력 구분
const EQUIPMENT_LOG_INPUT = ['inspect', 'repair', 'maintain', 'other'];

/** 시설물·장비 등록/수정/불용 권한: 최고관리자, 팀장, 주무관 */
function can_manage_assets(array $u): bool
{
    return (bool) $u['is_admin'] || (int) $u['rank_level'] >= RANK_OFFICER;
}

/** @return array<int,array> id => 팀 */
function teams_all(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT * FROM teams ORDER BY sort_order, id') as $t) $cache[(int) $t['id']] = $t;
    }
    return $cache;
}

/** 시설점검·시설물을 쓰는 팀 (설정 › 조직 구성의 '시설점검·시설물' 체크). 프로그램만 하는 팀 등은 빠진다 */
function facility_teams(): array
{
    return array_filter(teams_all(), fn($t) => !isset($t['use_facility']) || (int) $t['use_facility'] === 1);
}

function team_name(?int $id): string
{
    return $id ? (teams_all()[$id]['name'] ?? '') : '';
}

/** @return array<int,array> id => 반 (+ team_name), 팀 순서 → 반 순서 */
function squads_all(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT s.*, t.name AS team_name FROM squads s JOIN teams t ON t.id = s.team_id ORDER BY t.sort_order, t.id, s.sort_order, s.id') as $s) {
            $cache[(int) $s['id']] = $s;
        }
    }
    return $cache;
}

function squad_name(?int $id): string
{
    return $id ? (squads_all()[$id]['name'] ?? '') : '';
}

/** 회원의 소속 한 줄: 휴양림팀 · 숙박반 반장 */
function member_affiliation(array $u): string
{
    $parts = array_filter([team_name($u['team_id'] ? (int) $u['team_id'] : null), squad_name($u['squad_id'] ? (int) $u['squad_id'] : null)]);
    return implode(' · ', $parts) . (!empty($u['is_squad_leader']) && $u['squad_id'] ? ' 반장' : '');
}

/** @return array<int,array> id => 중분류 (팀 순서 → 분류 순서) */
function asset_groups(string $kind, bool $activeOnly = false): array
{
    $st = db()->prepare(
        'SELECT g.*, t.name AS team_name FROM asset_groups g JOIN teams t ON t.id = g.team_id
          WHERE g.kind = ?' . ($activeOnly ? ' AND g.is_active = 1' : '') . '
          ORDER BY t.sort_order, t.id, g.sort_order, g.id'
    );
    $st->execute([$kind]);
    $out = [];
    foreach ($st as $g) $out[(int) $g['id']] = $g;
    return $out;
}

/** <select> 옵션: 팀별 optgroup */
function group_options(string $kind, ?int $selected): string
{
    $html = '';
    $team = null;
    foreach (asset_groups($kind) as $g) {
        // 시설 구역은 시설점검·시설물을 쓰는 팀 것만 (이미 고른 구역은 그대로 보임)
        if ($kind === 'facility' && !isset(facility_teams()[(int) $g['team_id']]) && (int) $g['id'] !== $selected) continue;
        if ($team !== $g['team_id']) {
            $html .= ($team === null ? '' : '</optgroup>') . '<optgroup label="' . e($g['team_name']) . '">';
            $team = $g['team_id'];
        }
        $html .= '<option value="' . (int) $g['id'] . '"' . ((int) $g['id'] === $selected ? ' selected' : '') . '>'
            . e($g['name']) . ($g['is_active'] ? '' : ' (사용안함)') . '</option>';
    }
    return $html . ($team === null ? '' : '</optgroup>');
}

/** 세부시설 목록 (+ 구역·팀 이름) */
function facilities_list(?int $teamId = null, bool $activeOnly = true): array
{
    $sql = 'SELECT f.*, g.name AS area, g.team_id, t.name AS team_name
              FROM facilities f
              JOIN asset_groups g ON g.id = f.group_id
              JOIN teams t ON t.id = g.team_id
             WHERE 1 = 1' . ($teamId ? ' AND g.team_id = ?' : '') . ($activeOnly ? ' AND f.is_active = 1 AND g.is_active = 1' : '') . '
             ORDER BY t.sort_order, t.id, g.sort_order, g.id, f.sort_order, f.id';
    $st = db()->prepare($sql);
    $st->execute($teamId ? [$teamId] : []);
    $out = [];
    foreach ($st as $f) $out[(int) $f['id']] = $f;
    return $out;
}

function facility_find(int $id): ?array
{
    $st = db()->prepare(
        'SELECT f.*, g.name AS area, g.team_id, t.name AS team_name
           FROM facilities f JOIN asset_groups g ON g.id = f.group_id JOIN teams t ON t.id = g.team_id
          WHERE f.id = ?'
    );
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function normal_result(): string
{
    return config('facility_results', ['정상'])[0];
}

/* ───────────────────────── 점검 주기 ───────────────────────── */

/** 세부시설 점검 주기. 위탁·안 함은 점검일지에 나오지 않는다 (위탁 = 업체가 법정 점검 후 일지를 줌) */
const FACILITY_CYCLES = ['day' => '일일', 'week' => '주간', 'month' => '월간', 'quarter' => '분기', 'outsourced' => '위탁', 'none' => '안 함'];
const FACILITY_CYCLE_DUE = ['day', 'week', 'month', 'quarter']; // 점검일지에 나오는 주기

/** 그 날이 들어 있는 주기 기간 [시작, 끝] — 주간은 월~일, 분기는 1~3월·4~6월… */
function cycle_period(string $cycle, string $date): array
{
    $t = strtotime($date);
    return match ($cycle) {
        'week' => [date('Y-m-d', strtotime('monday this week', $t)), date('Y-m-d', strtotime('sunday this week', $t))],
        'month' => [date('Y-m-01', $t), date('Y-m-t', $t)],
        'quarter' => (function () use ($t) {
            $q0 = intdiv((int) date('n', $t) - 1, 3) * 3 + 1;
            $from = sprintf('%s-%02d-01', date('Y', $t), $q0);
            return [$from, date('Y-m-t', strtotime("$from +2 months"))];
        })(),
        default => [$date, $date],
    };
}

/** 주기 기간 이름: '이번 주', '11월', '4분기' */
function cycle_period_label(string $cycle, string $date): string
{
    return match ($cycle) {
        'week' => date('n/j', strtotime(cycle_period('week', $date)[0])) . '~' . date('n/j', strtotime(cycle_period('week', $date)[1])),
        'month' => date('n월', strtotime($date)),
        'quarter' => (intdiv((int) date('n', strtotime($date)) - 1, 3) + 1) . '분기',
        default => date('n/j', strtotime($date)),
    };
}

/**
 * 세부시설별 점검한 날짜들 (반려 제외, 남의 임시저장 포함 — 누가 쓰는 중이어도 했으면 한 것)
 * @return array<int, string[]> facility_id => [날짜, ...]
 */
function facility_checks(string $from, string $to, ?int $exceptJournalId = null): array
{
    $st = db()->prepare("SELECT fi.facility_id, j.work_date FROM facility_items fi JOIN journals j ON j.id = fi.journal_id
                          WHERE j.type = 'facility' AND j.status <> 'rejected' AND fi.facility_id IS NOT NULL AND j.work_date BETWEEN ? AND ? AND j.id <> ?");
    $st->execute([$from, $to, (int) $exceptJournalId]);
    $out = [];
    foreach ($st as $r) $out[(int) $r['facility_id']][] = $r['work_date'];
    return $out;
}

/**
 * 그 날 기준 관리팀의 주기별 점검 현황
 * @return array<string, array{label: string, period: array, items: array, done: array<int,string>}> 주기 => 할 것(items)·이미 한 것(done: facility_id => 날짜)
 */
function facility_due(int $teamId, string $date, ?int $exceptJournalId = null): array
{
    $facs = facilities_list($teamId);
    $q = cycle_period('quarter', $date);
    $w = cycle_period('week', $date);
    $checks = facility_checks(min($q[0], $w[0]), max($q[1], $w[1]), $exceptJournalId);
    $out = [];
    foreach (FACILITY_CYCLE_DUE as $c) $out[$c] = ['label' => cycle_period_label($c, $date), 'period' => cycle_period($c, $date), 'items' => [], 'done' => []];
    foreach ($facs as $fid => $f) {
        $c = $f['check_cycle'] ?? 'day';
        if (!isset($out[$c])) continue; // 위탁·안 함
        $out[$c]['items'][$fid] = $f;
        if ($c === 'day') continue;
        [$from, $to] = $out[$c]['period'];
        foreach ($checks[$fid] ?? [] as $d) if ($d >= $from && $d <= $to) $out[$c]['done'][$fid] = max($out[$c]['done'][$fid] ?? '', $d);
    }
    return $out;
}

/**
 * 그 날 진행 중인 이상: facility_id => 이슈 (같은 시설에 여럿이면 가장 늦게 시작한 것)
 * @param int[]|null $facilityIds 이 시설들만 (null = 전체)
 */
function facility_open_issues(string $date, ?array $facilityIds = null): array
{
    if ($facilityIds === []) return [];
    $in = $facilityIds === null ? '' : ' AND facility_id IN (' . implode(',', array_map('intval', $facilityIds)) . ')';
    $st = db()->prepare("SELECT * FROM facility_issues WHERE start_date <= ? AND (end_date IS NULL OR end_date >= ?)$in ORDER BY start_date, id");
    $st->execute([$date, $date]);
    $out = [];
    foreach ($st as $r) $out[(int) $r['facility_id']] = $r;
    return $out;
}

/**
 * 점검일지 저장 뒤 진행 중 이상 맞추기 (그 날 기준)
 *  - 이상 + '해결될 때까지 계속' → 진행 중 이상이 없으면 새로 (그 날부터), 있으면 결과·내용만 고침
 *  - 이상인데 '계속' 해제 → 그 날까지로 끝냄
 *  - 정상 → 전날까지로 끝냄 (그 날 시작한 것이면 지움), 조치완료 → 그 날까지로 끝냄
 */
function facility_issue_sync(string $date, array $rows, int $userId): void
{
    $normal = normal_result();
    $ids = array_values(array_filter(array_map(fn($r) => (int) ($r['facility_id'] ?? 0), $rows)));
    $open = facility_open_issues($date, $ids);
    $pdo = db();
    foreach ($rows as $r) {
        $fid = (int) ($r['facility_id'] ?? 0);
        if (!$fid) continue;
        $iss = $open[$fid] ?? null;
        $bad = $r['result'] !== $normal && $r['result'] !== '조치완료';
        if ($bad && !empty($r['keep'])) {
            if ($iss) $pdo->prepare('UPDATE facility_issues SET result = ?, note = ? WHERE id = ?')->execute([$r['result'], $r['note'] !== '' ? $r['note'] : $iss['note'], $iss['id']]);
            else $pdo->prepare('INSERT INTO facility_issues (facility_id, result, note, start_date, created_by) VALUES (?, ?, ?, ?, ?)')->execute([$fid, $r['result'], $r['note'] !== '' ? $r['note'] : null, $date, $userId]);
        } elseif ($iss) {
            $end = $r['result'] === $normal ? date('Y-m-d', strtotime("$date -1 day")) : $date;
            if ($end < $iss['start_date']) $pdo->prepare('DELETE FROM facility_issues WHERE id = ?')->execute([$iss['id']]);
            else $pdo->prepare('UPDATE facility_issues SET end_date = ? WHERE id = ?')->execute([$end, $iss['id']]);
        }
    }
}

/** 시설점검 진행률: 관리팀마다 그 날이 들어 있는 주·달·분기에 점검한 수 / 전체, 남은 시설 */
function facility_progress(array $teamIds, string $date): void
{
    $outs = db()->query("SELECT g.team_id, COUNT(*) FROM facilities f JOIN asset_groups g ON g.id = f.group_id
                          WHERE f.check_cycle = 'outsourced' AND f.is_active = 1 AND g.is_active = 1 GROUP BY g.team_id")->fetchAll(PDO::FETCH_KEY_PAIR);
    ?>
<div class="fac-progress">
  <?php foreach ($teamIds as $tid): $due = facility_due((int) $tid, $date); if (!array_filter(array_column($due, 'items')) && empty($outs[$tid])) continue; ?>
    <div class="fac-progress-team">
      <?php if (count($teamIds) > 1): ?><b><?= e(team_name((int) $tid)) ?></b><?php endif ?>
      <?php foreach (['week', 'month', 'quarter'] as $c): $d = $due[$c]; $total = count($d['items']); if (!$total) continue;
          $done = count($d['done']); $left = array_diff_key($d['items'], $d['done']); ?>
        <details class="fac-bar">
          <summary><span class="fac-bar-label"><?= e(FACILITY_CYCLES[$c]) ?> <small class="muted"><?= e($d['label']) ?></small></span>
            <span class="ar-bar"><i class="used" style="width: <?= round($done / $total * 100) ?>%"></i></span>
            <span class="nowrap"><b><?= $done ?></b> / <?= $total ?><?= $left ? ' · <span class="warn">남은 ' . count($left) . '</span>' : ' ✔' ?></span></summary>
          <?php if ($left): ?><p class="small">남은 시설: <?= e(implode(', ', array_map(fn($f) => $f['area'] . ' › ' . $f['name'], $left))) ?></p><?php endif ?>
        </details>
      <?php endforeach ?>
      <?php if ($n = count($due['day']['items'])): ?><span class="muted small">일일 <?= $n ?>곳은 매일</span><?php endif ?>
      <?php if ($oi = facility_open_issues($date, array_keys(facilities_list((int) $tid)))): ?><span class="small warn"> · 진행 중 이상 <?= count($oi) ?>곳</span><?php endif ?>
      <?php if (!empty($outs[$tid])): ?><span class="muted small"> · 위탁 <?= (int) $outs[$tid] ?>곳은 업체 일지</span><?php endif ?>
    </div>
  <?php endforeach ?>
</div>
    <?php
}

/* ───────────────────────── 사진 ───────────────────────── */

function photos_for(string $type, int $ownerId): array
{
    $st = db()->prepare('SELECT * FROM photos WHERE owner_type = ? AND owner_id = ? ORDER BY id');
    $st->execute([$type, $ownerId]);
    // 파일이 없어진 사진은 목록에서 뺀다 (깨진 그림 방지)
    return array_values(array_filter($st->fetchAll(), fn($p) => is_file(APP_ROOT . '/' . $p['path'])));
}

/** 목록용 대표사진: owner_id => path */
function photo_thumbs(string $type): array
{
    $st = db()->prepare('SELECT owner_id, MIN(id) AS id FROM photos WHERE owner_type = ? GROUP BY owner_id');
    $st->execute([$type]);
    $ids = array_column($st->fetchAll(), 'id');
    if (!$ids) return [];
    $rows = db()->query('SELECT owner_id, path FROM photos WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')->fetchAll();
    return array_column(array_filter($rows, fn($r) => is_file(APP_ROOT . '/' . $r['path'])), 'path', 'owner_id');
}

/**
 * 업로드된 사진 1장 저장 → uploads/{종류}/{년월}/임의이름.확장자
 * 브라우저에서 미리 줄여서 올리므로(app.js) 서버는 형식·크기만 검사한다.
 * @return array{0: ?string, 1: ?string} [저장 경로, 오류 메시지]  (파일이 없으면 [null, null])
 */
/**
 * 서버에서 사진을 긴 변 $max px JPEG로 줄인다 (GD 확장이 있을 때만. 없으면 브라우저에서 줄인 그대로 둔다)
 */
function image_downscale(string $absPath, int $max, int $quality = 70): void
{
    if (!function_exists('imagecreatefromstring')) return;
    $info = @getimagesize($absPath);
    if (!$info || ($info[0] <= $max && $info[1] <= $max && filesize($absPath) < 300 * 1024)) return;
    // 아주 큰 사진(휴대폰 5천만 화소 등)은 GD 가 메모리를 많이 쓴다: 필요하면 한도를 올리고, 그래도 모자라면 줄이지 않고 그대로 둔다
    $scale0 = min(1, $max / max($info[0], $info[1]));
    $need = (int) (($info[0] * $info[1] + $info[0] * $scale0 * $info[1] * $scale0) * 5.5) + memory_get_usage() + 16 * 1024 * 1024;
    if ($need > ini_bytes((string) ini_get('memory_limit'))) {
        @ini_set('memory_limit', (string) max($need, 256 * 1024 * 1024));
        if ($need > ini_bytes((string) ini_get('memory_limit'))) return;
    }
    $src = @imagecreatefromstring((string) file_get_contents($absPath));
    if (!$src) return;
    $scale = min(1, $max / max($info[0], $info[1]));
    $w = max(1, (int) round($info[0] * $scale));
    $h = max(1, (int) round($info[1] * $scale));
    $dst = imagecreatetruecolor($w, $h);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // 투명 PNG 배경은 흰색
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
    imagejpeg($dst, $absPath, $quality); // 확장자가 png여도 브라우저는 내용으로 표시
    imagedestroy($src);
    imagedestroy($dst);
}

/** php.ini 크기 값(128M, 1G, -1)을 바이트로 */
function ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') return PHP_INT_MAX;
    $n = (int) $v;
    return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
}

function store_uploaded_image(string $name, string $tmp, int $err, string $type): array
{
    if ($err === UPLOAD_ERR_NO_FILE) return [null, null];
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) return [null, "{$name}: 파일이 너무 큽니다."];
    if ($err !== UPLOAD_ERR_OK) return [null, "{$name}: 업로드 실패 (오류 {$err})"];

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!isset($allowed[$mime]) || @getimagesize($tmp) === false) {
        return [null, "{$name}: 사진 파일(jpg, png, gif, webp)만 올릴 수 있습니다."];
    }
    $dirRel = 'uploads/' . $type . '/' . date('Ym');
    $dirAbs = APP_ROOT . '/' . $dirRel;
    if (!is_dir($dirAbs) && !@mkdir($dirAbs, 0755, true)) {
        return [null, 'uploads 폴더를 만들 수 없습니다. FTP에서 uploads 폴더 권한을 707로 설정하세요.'];
    }
    $file = bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($tmp, "$dirAbs/$file")) {
        return [null, "{$name}: 저장 실패. uploads 폴더 쓰기 권한(707)을 확인하세요."];
    }
    return ["$dirRel/$file", null];
}

/** 시설물·장비 사진 여러 장 저장 ($_FILES['photos'][]) @return string[] 오류 메시지 */
function photos_save_uploaded(string $type, int $ownerId, int $userId, ?int $max = null, string $field = 'photos'): array
{
    $files = $_FILES[$field] ?? null;
    if (!$files || !is_array($files['name'])) return [];
    $errors = [];
    foreach ($files['name'] as $i => $name) {
        [$path, $error] = store_uploaded_image($name, $files['tmp_name'][$i], $files['error'][$i], $type);
        if ($error) $errors[] = $error;
        if ($path) {
            if ($max) image_downscale(APP_ROOT . '/' . $path, $max); // 저해상도 (브라우저에서 못 줄였을 때 대비)
            db()->prepare('INSERT INTO photos (owner_type, owner_id, path, user_id) VALUES (?, ?, ?, ?)')
                ->execute([$type, $ownerId, $path, $userId]);
        }
    }
    return $errors;
}

/** 선택한 사진 삭제 (해당 대상의 사진만) */
function photos_delete(string $type, int $ownerId, array $photoIds): void
{
    foreach ($photoIds as $pid) {
        $st = db()->prepare('SELECT * FROM photos WHERE id = ? AND owner_type = ? AND owner_id = ?');
        $st->execute([(int) $pid, $type, $ownerId]);
        if ($p = $st->fetch()) {
            @unlink(APP_ROOT . '/' . $p['path']);
            db()->prepare('DELETE FROM photos WHERE id = ?')->execute([$p['id']]);
        }
    }
}

function photos_delete_all(string $type, int $ownerId): void
{
    // 파일이 이미 없어진 사진 기록도 함께 지운다 (photos_for 는 파일 없는 것을 빼고 돌려줌)
    $st = db()->prepare('SELECT id FROM photos WHERE owner_type = ? AND owner_id = ?');
    $st->execute([$type, $ownerId]);
    photos_delete($type, $ownerId, $st->fetchAll(PDO::FETCH_COLUMN));
}

/** 사진 편집 영역 (등록/수정 폼 안에서 사용) */
function render_photo_editor(string $type, ?int $ownerId, ?int $max = null, string $field = 'photos', string $label = '사진 추가'): void
{
    $photos = $ownerId ? photos_for($type, $ownerId) : [];
    ?>
<div class="photo-editor">
  <?php if ($photos): ?>
    <div class="gallery">
      <?php foreach ($photos as $p): ?>
        <label class="thumb">
          <img src="<?= e(url($p['path'])) ?>" alt="">
          <span><input type="checkbox" name="delete_photos[]" value="<?= (int) $p['id'] ?>"> 삭제</span>
        </label>
      <?php endforeach ?>
    </div>
  <?php endif ?>
  <label><?= e($label) ?> <small class="muted">(여러 장 선택 가능, 큰 사진은 자동으로 줄여서 올립니다<?= $max ? " — 긴 변 {$max}px" : '' ?>)</small>
    <input type="file" name="<?= e($field) ?>[]" accept="image/*" multiple data-resize<?= $max ? "=\"$max\" data-quality=\"0.7\"" : '' ?>>
  </label>
</div>
    <?php
}

function render_gallery(array $photos): void
{
    if (!$photos) return;
    echo '<div class="gallery">';
    foreach ($photos as $p) {
        echo '<a class="thumb" href="' . e(url($p['path'])) . '" target="_blank"><img src="' . e(url($p['path'])) . '" alt=""></a>';
    }
    echo '</div>';
}

/* ───────────────────────── 장비 ───────────────────────── */

function equipment_find(int $id): ?array
{
    $st = db()->prepare(
        'SELECT e.*, g.name AS group_name, g.team_id, t.name AS team_name
           FROM equipment e JOIN asset_groups g ON g.id = e.group_id JOIN teams t ON t.id = g.team_id
          WHERE e.id = ?'
    );
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function equipment_log(int $equipmentId, string $kind, string $date, int $userId, ?string $content = null,
                       ?int $cost = null, ?string $vendor = null, ?string $statusAfter = null): void
{
    db()->prepare(
        'INSERT INTO equipment_logs (equipment_id, log_date, kind, content, cost, vendor, status_after, user_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([$equipmentId, $date, $kind, $content ?: null, $cost ?: null, $vendor ?: null, $statusAfter, $userId]);
}

function equipment_badge(string $status): string
{
    $cls = ['active' => 'st-approved', 'repair' => 'st-pending', 'disposed' => 'st-draft'][$status] ?? '';
    return '<span class="badge ' . $cls . '">' . e(EQUIPMENT_STATUS[$status] ?? $status) . '</span>';
}
