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

function team_name(?int $id): string
{
    return $id ? (teams_all()[$id]['name'] ?? '') : '';
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

/* ───────────────────────── 사진 ───────────────────────── */

function photos_for(string $type, int $ownerId): array
{
    $st = db()->prepare('SELECT * FROM photos WHERE owner_type = ? AND owner_id = ? ORDER BY id');
    $st->execute([$type, $ownerId]);
    return $st->fetchAll();
}

/** 목록용 대표사진: owner_id => path */
function photo_thumbs(string $type): array
{
    $st = db()->prepare('SELECT owner_id, MIN(id) AS id FROM photos WHERE owner_type = ? GROUP BY owner_id');
    $st->execute([$type]);
    $ids = array_column($st->fetchAll(), 'id');
    if (!$ids) return [];
    $rows = db()->query('SELECT owner_id, path FROM photos WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')')->fetchAll();
    return array_column($rows, 'path', 'owner_id');
}

/**
 * 업로드된 사진 1장 저장 → uploads/{종류}/{년월}/임의이름.확장자
 * 브라우저에서 미리 줄여서 올리므로(app.js) 서버는 형식·크기만 검사한다.
 * @return array{0: ?string, 1: ?string} [저장 경로, 오류 메시지]  (파일이 없으면 [null, null])
 */
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
function photos_save_uploaded(string $type, int $ownerId, int $userId): array
{
    $files = $_FILES['photos'] ?? null;
    if (!$files || !is_array($files['name'])) return [];
    $errors = [];
    foreach ($files['name'] as $i => $name) {
        [$path, $error] = store_uploaded_image($name, $files['tmp_name'][$i], $files['error'][$i], $type);
        if ($error) $errors[] = $error;
        if ($path) {
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
    photos_delete($type, $ownerId, array_column(photos_for($type, $ownerId), 'id'));
}

/** 사진 편집 영역 (등록/수정 폼 안에서 사용) */
function render_photo_editor(string $type, ?int $ownerId): void
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
  <label>사진 추가 <small class="muted">(여러 장 선택 가능, 큰 사진은 자동으로 줄여서 올립니다)</small>
    <input type="file" name="photos[]" accept="image/*" multiple data-resize>
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
