<?php
/**
 * 설정 › 시설물 가져오기 (최고관리자): 시설물·장비 목록 엑셀을 한 번에 등록
 *   엑셀: '시설물' 시트(내년 담당 팀·구역(건물)·세부시설·층·위치·점검할 것·메모·상태)와
 *         '장비' 시트(내년 담당 팀·분류·장비 이름·구역·놓인 곳·설비번호·제조사·규격·상태·지금까지 보수 기록·메모).
 *         머리글 줄('내년 담당 팀'으로 시작)을 찾아 열 이름으로 읽는다 — 2026-10 시설물 등록 초안 양식.
 *   1) 올리기 → 2) 엑셀 팀마다 사이트 팀 고르기 → 3) 미리보기 → 4) 가져오기
 *   구역·장비 분류는 (팀, 이름)이 같으면 있는 것을 쓰고, 같은 구역의 같은 이름 세부시설·같은 이름 장비는 건너뛴다.
 *   시설물 시트에 '점검 주기'(일일·주간·월간·분기·위탁·안 함) 칸이 있으면 새 세부시설에 넣고, 이미 있는 세부시설은 주기만 바꾼다
 *   ('세부시설 주기' 시트만 있는 점검 주기 엑셀도 그대로 올릴 수 있다).
 *   보수 기록은 ' · ' 로 나눠 날짜(2025.6.18 / 2024.3)로 시작하는 것만 장비 이력(수리·점검·관리·기타)으로, 나머지는 장비 메모로.
 */
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/xlsx_read.php';

$user = require_admin();
$pdo = db();
const IMA_KEY = 'assets_import';
const IMA_NEW_TEAM = -1; // '새 팀 만들기'
$imp = $_SESSION[IMA_KEY] ?? null;

/** 시트 → 머리글 이름으로 읽은 줄 목록 (머리글 = 첫 칸이 '내년 담당 팀' 또는 '담당 팀') */
function ima_rows(array $rows): array
{
    $rows = array_values($rows); // 빈 줄이 빠지면 키가 줄 번호라 순서로 다시 센다
    $head = null;
    foreach ($rows as $ri => $r) {
        if ($ri > 20) break;
        if (in_array(trim((string) ($r[0] ?? '')), ['내년 담당 팀', '담당 팀', '팀'], true)) { $head = $ri; break; }
    }
    if ($head === null) return [];
    $cols = [];
    foreach ($rows[$head] as $ci => $v) if (trim((string) $v) !== '') $cols[trim((string) $v)] = $ci;
    $out = [];
    foreach (array_slice($rows, $head + 1) as $r) {
        $row = [];
        foreach ($cols as $name => $ci) $row[$name] = trim((string) ($r[$ci] ?? ''));
        if (implode('', $row) !== '') $out[] = $row;
    }
    return $out;
}

/** 보수 기록 → [[날짜, 구분, 내용], ...], 날짜 없는 조각 */
function ima_history(string $text): array
{
    $logs = $rest = [];
    foreach (preg_split('/\s+·\s+/u', $text) as $seg) {
        $seg = trim($seg);
        if ($seg === '') continue;
        if (preg_match('/^(\d{4})\.(\d{1,2})(?:\.(\d{1,2}))?/', $seg, $m) && checkdate((int) $m[2], (int) ($m[3] ?? 1), (int) $m[1])) {
            $kind = match (true) {
                (bool) preg_match('/점검|확인/u', $seg) && !preg_match('/고장|수리|교체|파손/u', $seg) => 'inspect',
                (bool) preg_match('/청소|보온|세척/u', $seg) => 'maintain',
                (bool) preg_match('/고장|수리|교체|파손|누수|누전|파열|불가|화재|AS/u', $seg) => 'repair',
                default => 'other',
            };
            $logs[] = [sprintf('%04d-%02d-%02d', $m[1], $m[2], (int) ($m[3] ?? 1)), $kind, $seg];
        } else {
            $rest[] = $seg;
        }
    }
    return [$logs, $rest];
}

/** 엑셀 팀 이름 → 사이트 팀: 같은 이름 → '운영'·띄어쓰기를 빼고 같은 이름(휴양림운영팀 = 휴양림팀) → 시설점검을 쓰는 첫 팀 */
function ima_guess(string $name): int
{
    $norm = fn(string $s) => str_replace([' ', '운영'], '', trim($s));
    foreach (teams_all() as $id => $t) if (trim($t['name']) === $name) return $id;
    foreach (teams_all() as $id => $t) if ($norm($t['name']) === $norm($name)) return $id;
    return (int) (array_key_first(facility_teams()) ?? array_key_first(teams_all()) ?? IMA_NEW_TEAM);
}

/** 가져올 내용 계산 (미리보기·가져오기 공통). $map: 엑셀 팀 => 사이트 팀 id (IMA_NEW_TEAM 이면 아직 없는 팀) */
function ima_plan(array $imp): array
{
    $pdo = db();
    $groups = []; // "kind|팀id|이름" => 있는 id
    foreach ($pdo->query('SELECT id, kind, team_id, name FROM asset_groups') as $g) $groups["{$g['kind']}|{$g['team_id']}|" . trim($g['name'])] = (int) $g['id'];
    $facs = []; // "구역id|이름" => [id, 주기]
    foreach ($pdo->query('SELECT id, group_id, name, check_cycle FROM facilities') as $f) $facs["{$f['group_id']}|" . trim($f['name'])] = [(int) $f['id'], $f['check_cycle']];
    $eqs = array_flip(array_map('trim', $pdo->query("SELECT name FROM equipment WHERE status <> 'disposed'")->fetchAll(PDO::FETCH_COLUMN)));
    if (!empty($imp['cycle_only'])) return ima_cycle_plan($imp);
    $team = fn(string $t) => (int) ($imp['map'][$t] ?? IMA_NEW_TEAM);
    $gkey = fn(string $kind, string $t, string $name) => $kind . '|' . ($team($t) === IMA_NEW_TEAM ? 'new:' . $t : $team($t)) . '|' . $name;

    $plan = ['groups' => [], 'fac' => [], 'fac_skip' => [], 'fac_cycle' => [], 'eq' => [], 'eq_skip' => [], 'logs' => 0];
    $seenF = $seenE = [];
    foreach ($imp['fac'] as $r) {
        $k = $gkey('facility', $r['team'], $r['area']);
        if (!isset($groups[$k])) $plan['groups'][$k] ??= ['kind' => 'facility', 'team' => $r['team'], 'name' => $r['area']];
        $old = isset($groups[$k]) ? ($facs["{$groups[$k]}|{$r['name']}"] ?? null) : null;
        $dup = $old || isset($seenF["$k|{$r['name']}"]);
        $seenF["$k|{$r['name']}"] = true;
        if ($old && ($r['cycle'] ?? '') !== '' && $r['cycle'] !== $old[1]) $plan['fac_cycle'][] = $r + ['id' => $old[0], 'from' => $old[1]];
        $plan[$dup ? 'fac_skip' : 'fac'][] = $r + ['gkey' => $k];
    }
    foreach ($imp['eq'] as $r) {
        $k = $gkey('equipment', $r['team'], $r['kind']);
        if (!isset($groups[$k])) $plan['groups'][$k] ??= ['kind' => 'equipment', 'team' => $r['team'], 'name' => $r['kind']];
        $dup = isset($eqs[$r['name']]) || isset($seenE[$r['name']]);
        $seenE[$r['name']] = true;
        if (!$dup) $plan['logs'] += count(ima_history($r['hist'])[0]);
        $plan[$dup ? 'eq_skip' : 'eq'][] = $r + ['gkey' => $k];
    }
    $plan['existing'] = $groups;
    return $plan;
}

/** 점검 주기 엑셀('세부시설 주기' 시트만): 팀과 관계없이 구역 이름 + 세부시설 이름이 같은 세부시설의 주기만 바꾼다 (새로 만들지 않음) */
function ima_cycle_plan(array $imp): array
{
    $byName = [];
    foreach (db()->query('SELECT f.id, f.name, f.check_cycle, g.name AS area FROM facilities f JOIN asset_groups g ON g.id = f.group_id') as $f) {
        $byName[trim($f['area']) . '|' . trim($f['name'])][] = $f;
    }
    $plan = ['groups' => [], 'fac' => [], 'fac_skip' => [], 'fac_cycle' => [], 'eq' => [], 'eq_skip' => [], 'logs' => 0, 'missing' => [], 'existing' => []];
    foreach ($imp['fac'] as $r) {
        if (($r['cycle'] ?? '') === '') continue;
        $found = $byName[$r['area'] . '|' . $r['name']] ?? [];
        if (!$found) { $plan['missing'][] = $r; continue; }
        foreach ($found as $f) if ($f['check_cycle'] !== $r['cycle']) $plan['fac_cycle'][] = $r + ['id' => (int) $f['id'], 'from' => $f['check_cycle']];
    }
    return $plan;
}

/* ───────────── 처리 ───────────── */
if (is_post()) {
    csrf_verify();
    $act = post('act');
    if ($act === 'reset') {
        unset($_SESSION[IMA_KEY]);
        redirect('admin/import_assets.php');
    }
    if ($act === 'upload') {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) { flash('파일을 올리지 못했습니다. 다시 골라 주세요.', 'error'); redirect('admin/import_assets.php'); }
        try {
            $sheets = xlsx_sheets($f['tmp_name'], 0, fn($n) => in_array(trim($n), ['시설물', '장비', '세부시설 주기'], true));
        } catch (Throwable $e) {
            flash($e->getMessage(), 'error');
            redirect('admin/import_assets.php');
        }
        $fac = $eq = $warn = [];
        foreach (ima_rows($sheets['시설물'] ?? $sheets['세부시설 주기'] ?? []) as $i => $r) {
            $row = ['team' => $r['내년 담당 팀'] ?? $r['담당 팀'] ?? '', 'area' => $r['구역(건물)'] ?? $r['구역'] ?? '', 'name' => $r['세부시설'] ?? '',
                    'where' => $r['층·위치'] ?? '', 'memo' => $r['점검할 것·메모'] ?? $r['메모'] ?? '', 'status' => $r['상태'] ?? '',
                    'cycle' => (string) (array_search($r['점검 주기'] ?? '', FACILITY_CYCLES, true) ?: '')];
            if (($r['점검 주기'] ?? '') !== '' && $row['cycle'] === '') $warn[] = "'{$row['name']}' 점검 주기 '{$r['점검 주기']}'를 몰라 일일로 둠";
            if ($row['name'] === '' || $row['area'] === '' || $row['team'] === '') { $warn[] = '시설물 ' . ($i + 1) . '번째 줄: 팀·구역·세부시설 중 빈 칸이 있어 건너뜀'; continue; }
            $fac[] = $row;
        }
        foreach (ima_rows($sheets['장비'] ?? []) as $i => $r) {
            $row = ['team' => $r['내년 담당 팀'] ?? $r['담당 팀'] ?? '', 'kind' => $r['분류'] ?? '', 'name' => $r['장비 이름'] ?? '', 'area' => $r['구역'] ?? '',
                    'where' => $r['놓인 곳(세부시설)'] ?? $r['놓인 곳'] ?? '', 'no' => $r['설비번호'] ?? '', 'maker' => $r['제조사·규격'] ?? '',
                    'status' => $r['상태'] ?? '', 'hist' => $r['지금까지 보수 기록'] ?? '', 'memo' => $r['메모'] ?? ''];
            if ($row['name'] === '' || $row['team'] === '') { $warn[] = '장비 ' . ($i + 1) . '번째 줄: 팀·장비 이름 중 빈 칸이 있어 건너뜀'; continue; }
            if ($row['kind'] === '') $row['kind'] = '기타';
            $eq[] = $row;
        }
        if (!$fac && !$eq) { flash("가져올 줄이 없습니다. '시설물'(또는 '세부시설 주기')·'장비' 시트에 '내년 담당 팀'으로 시작하는 머리글 줄이 있는지 확인하세요.", 'error'); redirect('admin/import_assets.php'); }
        foreach ([...$fac, ...$eq] as $r) foreach (['name', 'area', 'kind', 'where'] as $k) {
            if (mb_strlen($r[$k] ?? '') > 100) $warn[] = "'" . mb_substr($r[$k], 0, 30) . "…' 이 100자를 넘어 잘립니다";
        }
        $teams = array_values(array_unique(array_column([...$fac, ...$eq], 'team')));
        $_SESSION[IMA_KEY] = ['file' => (string) $f['name'], 'fac' => $fac, 'eq' => $eq, 'warn' => array_slice($warn, 0, 20),
            'map' => array_combine($teams, array_map('ima_guess', $teams)), 'cycle_only' => !isset($sheets['시설물']) && !isset($sheets['장비']) && isset($sheets['세부시설 주기'])];
        redirect('admin/import_assets.php');
    }
    if (!$imp) redirect('admin/import_assets.php');
    foreach (array_keys($imp['map']) as $i => $t) {
        $v = (int) ($_POST['map'][$i] ?? IMA_NEW_TEAM);
        $imp['map'][$t] = isset(teams_all()[$v]) ? $v : IMA_NEW_TEAM;
    }
    $_SESSION[IMA_KEY] = $imp;
    if ($act === 'run') {
        $plan = ima_plan($imp);
        $cut = fn(string $s, int $n = 100) => mb_substr($s, 0, $n);
        $pdo->beginTransaction();
        try {
            // 새 팀
            $teamId = [];
            foreach (empty($imp['cycle_only']) ? $imp['map'] : [] as $t => $id) {
                if ($id !== IMA_NEW_TEAM) { $teamId[$t] = $id; continue; }
                $pdo->prepare('INSERT INTO teams (name, sort_order, use_facility) VALUES (?, (SELECT COALESCE(MAX(t.sort_order), 0) + 1 FROM teams t), 1)')->execute([$cut($t, 50)]);
                $teamId[$t] = (int) $pdo->lastInsertId();
            }
            // 구역·장비 분류
            $gid = $plan['existing'];
            $order = [];
            foreach ($plan['groups'] as $k => $g) {
                $order[$g['kind']] = ($order[$g['kind']] ?? (int) $pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM asset_groups WHERE kind = '{$g['kind']}'")->fetchColumn()) + 10;
                $pdo->prepare('INSERT INTO asset_groups (team_id, name, sort_order, is_active, kind) VALUES (?, ?, ?, 1, ?)')
                    ->execute([$teamId[$g['team']], $cut($g['name']), $order[$g['kind']], $g['kind']]);
                $gid[$k] = (int) $pdo->lastInsertId();
            }
            // 세부시설 (엑셀 순서대로)
            $ins = $pdo->prepare('INSERT INTO facilities (group_id, name, spec, note, sort_order, is_active, check_cycle) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $sort = [];
            foreach ($plan['fac'] as $r) {
                $g = $gid[$r['gkey']];
                $sort[$g] = ($sort[$g] ?? (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) FROM facilities WHERE group_id = ' . $g)->fetchColumn()) + 10;
                $note = trim($r['memo'] . (in_array($r['status'], ['', '운영중'], true) ? '' : "\n상태: {$r['status']}"));
                $ins->execute([$g, $cut($r['name']), $r['where'] !== '' ? $r['where'] : null, $note !== '' ? $note : null, $sort[$g], $r['status'] === '폐쇄' ? 0 : 1, ($r['cycle'] ?? '') ?: 'day']);
            }
            // 이미 있는 세부시설: 점검 주기만 바꿈
            $upd = $pdo->prepare('UPDATE facilities SET check_cycle = ? WHERE id = ?');
            foreach ($plan['fac_cycle'] as $r) {
                $upd->execute([$r['cycle'], $r['id']]);
            }
            // 장비 + 이력
            $ins = $pdo->prepare('INSERT INTO equipment (group_id, name, model, serial_no, spec, acquired_on, acquired_cost, location, status, note) VALUES (?, ?, NULL, NULL, ?, NULL, NULL, ?, ?, ?)');
            foreach ($plan['eq'] as $r) {
                [$logs, $rest] = ima_history($r['hist']);
                $note = implode("\n", array_filter([
                    in_array($r['status'], ['', '운영중', '고장'], true) ? '' : "상태: {$r['status']}",
                    $r['no'] !== '' ? "설비번호: {$r['no']}" : '',
                    $r['memo'],
                    $rest ? '예전 기록: ' . implode(' · ', $rest) : '',
                ]));
                $loc = implode(' › ', array_filter([$r['area'], $r['where']]));
                $status = $r['status'] === '고장' ? 'repair' : 'active';
                $ins->execute([$gid[$r['gkey']], $cut($r['name']), $r['maker'] !== '' ? $r['maker'] : null, $loc !== '' ? $cut($loc) : null, $status, $note !== '' ? $note : null]);
                $id = (int) $pdo->lastInsertId();
                equipment_log($id, 'register', date('Y-m-d'), (int) $user['id'], '시설물 가져오기로 등록 (' . $imp['file'] . ')', null, null, $status);
                foreach ($logs as [$date, $kind, $text]) equipment_log($id, $kind, $date, (int) $user['id'], '예전 기록: ' . $text);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        unset($_SESSION[IMA_KEY]);
        flash('시설물 ' . count($plan['fac']) . '줄 · 장비 ' . count($plan['eq']) . '대 · 예전 보수 이력 ' . $plan['logs'] . '건을 가져왔습니다.'
            . ($plan['fac_cycle'] ? ' 세부시설 ' . count($plan['fac_cycle']) . '곳의 점검 주기를 바꿨습니다.' : '')
            . (count($plan['fac_skip']) + count($plan['eq_skip']) ? ' 이미 있던 ' . (count($plan['fac_skip']) + count($plan['eq_skip'])) . '줄은 건너뛰었습니다.' : ''), 'success');
        redirect('facilities.php');
    }
    redirect('admin/import_assets.php' . ($act === 'preview' ? '#preview' : ''));
}

layout_header('시설물 가져오기', 'settings');
settings_nav('asset_import');
?>
<div class="tabs team-tabs no-print">
  <a href="<?= e(url('admin/import_assets.php')) ?>" class="on">시설물·장비·점검 주기</a>
  <a href="<?= e(url('admin/import_facility_logs.php')) ?>">지난 점검일지</a>
</div>
<?php
if (!$imp): ?>
<section class="card">
  <h1>시설물 가져오기 <small class="muted">시설물·장비 목록 엑셀 → 시설 구역·세부시설·장비 한 번에 등록</small></h1>
  <ul class="small">
    <li><b>'시설물'</b> 시트와 <b>'장비'</b> 시트가 있는 엑셀(.xlsx)입니다. <b>점검 주기 엑셀</b>('세부시설 주기' 시트)을 올리면 이미 있는 세부시설의 <b>점검 주기</b>만 바꿉니다. 각 시트에서 <b>'내년 담당 팀'</b>으로 시작하는 머리글 줄을 찾아 읽습니다.</li>
    <li>시설물 시트: 내년 담당 팀 · 구역(건물) · 세부시설 · 층·위치 · 점검할 것·메모 · 상태 → <b>시설 구역 › 세부시설</b> (상태 '폐쇄'는 사용 안 함으로).</li>
    <li>장비 시트: 내년 담당 팀 · 분류 · 장비 이름 · 구역 · 놓인 곳 · 설비번호 · 제조사·규격 · 상태 · 지금까지 보수 기록 · 메모 → <b>장비 분류 › 장비</b>.
      보수 기록 중 날짜로 시작하는 것(예: 2025.6.18 …)은 <b>장비 이력</b>으로 들어가 AI 분석의 '같은 설비 반복 고장'에 쓰입니다.</li>
    <li>올린 뒤 엑셀의 팀마다 <b>사이트 팀</b>을 고르고, 미리보기를 본 다음 가져옵니다. 올리기만 해서는 저장되지 않습니다.
      <b>같은 구역의 같은 이름 세부시설, 같은 이름 장비는 건너뜁니다</b> (두 번 올려도 겹치지 않음).</li>
  </ul>
  <form method="post" enctype="multipart/form-data" class="actions" style="justify-content:flex-start">
    <?= csrf_field() ?><input type="hidden" name="act" value="upload">
    <input type="file" name="file" accept=".xlsx" required>
    <button class="btn primary">올리기</button>
  </form>
</section>
<?php layout_footer(); exit; endif;

$plan = ima_plan($imp);
$teamLabel = fn(string $t) => ($imp['map'][$t] ?? IMA_NEW_TEAM) === IMA_NEW_TEAM ? "새 팀 '{$t}'" : team_name($imp['map'][$t]);
$newGroups = ['facility' => [], 'equipment' => []];
foreach ($plan['groups'] as $g) $newGroups[$g['kind']][] = $teamLabel($g['team']) . ' › ' . $g['name'];
// 고른 팀에 이미 있는 구역 (엑셀에 없는 것) — 예전에 만들다 만 구역과 겹치지 않는지 보라고
$fileAreas = [];
foreach ($imp['fac'] as $r) if (($imp['map'][$r['team']] ?? IMA_NEW_TEAM) !== IMA_NEW_TEAM) $fileAreas[$imp['map'][$r['team']] . '|' . $r['area']] = true;
$oldAreas = [];
if (!empty($imp['cycle_only'])) $fileAreas = $imp['map'] = []; // 주기만 바꿀 때는 팀을 고르지 않는다
foreach (asset_groups('facility') as $g) {
    if (!in_array((int) $g['team_id'], $imp['map'], true) || isset($fileAreas[$g['team_id'] . '|' . trim($g['name'])])) continue;
    $n = (int) $pdo->query('SELECT COUNT(*) FROM facilities WHERE group_id = ' . (int) $g['id'])->fetchColumn();
    $oldAreas[] = $g['team_name'] . ' › ' . $g['name'] . " (세부시설 {$n})";
}
?>
<section class="card">
  <div class="card-head">
    <h1>시설물 가져오기 <small class="muted"><?= e($imp['file']) ?></small></h1>
    <form method="post"><?= csrf_field() ?><button class="btn ghost" name="act" value="reset">다른 파일 올리기</button></form>
  </div>
  <div class="kpis k4">
    <div class="kpi"><span>새 구역·장비 분류</span><b><?= count($plan['groups']) ?>개</b><small class="muted">구역 <?= count($newGroups['facility']) ?> · 장비 분류 <?= count($newGroups['equipment']) ?></small></div>
    <div class="kpi total"><span>새 세부시설</span><b><?= count($plan['fac']) ?>줄</b><small class="muted"><?= $plan['fac_skip'] ? '이미 있어 건너뜀 ' . count($plan['fac_skip']) : '겹치는 것 없음' ?></small></div>
    <div class="kpi total"><span>새 장비</span><b><?= count($plan['eq']) ?>대</b><small class="muted"><?= $plan['eq_skip'] ? '이미 있어 건너뜀 ' . count($plan['eq_skip']) : '겹치는 것 없음' ?></small></div>
    <div class="kpi"><span>예전 보수 이력 · 주기 변경</span><b><?= $plan['logs'] ?>건</b><small class="muted">점검 주기 바꿀 세부시설 <?= count($plan['fac_cycle']) ?>곳</small></div>
  </div>
  <?php if ($imp['warn']): ?><div class="flash flash-warn"><?= e(implode(' / ', $imp['warn'])) ?></div><?php endif ?>
</section>
<form method="post" class="card">
  <?= csrf_field() ?>
  <?php if (!empty($imp['cycle_only'])): ?>
  <h2>점검 주기만 바꿉니다 <small class="muted">'세부시설 주기' 시트 — 새로 만드는 것은 없습니다</small></h2>
  <p class="small">구역 이름과 세부시설 이름이 같은 세부시설을 찾아 점검 주기만 바꿉니다 (팀과 관계없음).</p>
  <?php if ($plan['missing']): ?><div class="flash flash-warn">사이트에서 찾지 못한 세부시설 <?= count($plan['missing']) ?>곳 (이름이 다르면 시설물 화면에서 직접 바꾸세요): <?= e(implode(', ', array_map(fn($r) => $r['area'] . ' › ' . $r['name'], $plan['missing']))) ?></div><?php endif ?>
  <?php else: ?>
  <h2>1. 엑셀 팀마다 사이트 팀 <small class="muted">비슷한 이름을 자동으로 골라 두었습니다 — 확인하세요</small></h2>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>엑셀 팀</th><th class="right">세부시설</th><th class="right">장비</th><th>사이트 팀</th></tr></thead>
    <tbody>
    <?php $i = 0; foreach ($imp['map'] as $t => $cur): ?>
      <tr><td><b><?= e($t) ?></b></td>
        <td class="right"><?= count(array_filter($imp['fac'], fn($r) => $r['team'] === $t)) ?></td>
        <td class="right"><?= count(array_filter($imp['eq'], fn($r) => $r['team'] === $t)) ?></td>
        <td><select name="map[<?= $i ?>]">
          <?php foreach (teams_all() as $id => $team): ?><option value="<?= $id ?>" <?= $cur === $id ? 'selected' : '' ?>><?= e($team['name']) ?><?= (int) ($team['use_facility'] ?? 1) ? '' : ' (시설점검 안 씀)' ?></option><?php endforeach ?>
          <option value="<?= IMA_NEW_TEAM ?>" <?= $cur === IMA_NEW_TEAM ? 'selected' : '' ?>>새 팀 '<?= e($t) ?>' 만들기</option>
        </select></td></tr>
    <?php $i++; endforeach ?>
    </tbody>
  </table>
  </div>
  <p class="muted small">지금은 한 팀이 모두 맡고 있으면 그 팀을 고르세요. 나중에 팀이 나뉘면 설정 › 시설 구역·건물(장비 분류)에서 구역마다 팀만 바꾸면 됩니다.
    '새 팀 만들기'는 설정 › 조직 구성에 그 팀을 새로 만듭니다.</p>
  <div class="actions"><button class="btn" name="act" value="preview">사이트 팀 저장 · 미리보기 다시 계산</button></div>
  <?php endif ?>

  <h2 id="preview">2. 미리보기 <small class="muted">아직 저장되지 않았습니다</small></h2>
  <?php if ($oldAreas): ?><div class="flash flash-warn">고른 팀에 이미 있는 다른 구역: <?= e(implode(', ', $oldAreas)) ?> — 예전에 만들다 만 구역이면 가져온 뒤 설정 › 시설 구역·건물에서 '사용 안 함'으로 돌리세요.</div><?php endif ?>
  <details><summary>새 구역 <?= count($newGroups['facility']) ?>개 · 새 장비 분류 <?= count($newGroups['equipment']) ?>개</summary>
    <p class="small"><?= e(implode(', ', $newGroups['facility'])) ?: '없음' ?></p>
    <p class="small"><?= e(implode(', ', $newGroups['equipment'])) ?: '없음' ?></p></details>
  <details><summary>새 세부시설 <?= count($plan['fac']) ?>줄</summary>
    <div class="table-scroll"><table class="table small">
      <thead><tr><th>팀</th><th>구역</th><th>세부시설</th><th>층·위치</th><th>상태</th></tr></thead>
      <tbody><?php foreach ($plan['fac'] as $r): ?><tr><td class="nowrap"><?= e($teamLabel($r['team'])) ?></td><td class="nowrap"><?= e($r['area']) ?></td><td><b><?= e($r['name']) ?></b></td><td><?= e($r['where']) ?></td><td class="nowrap"><?= e($r['status']) ?></td></tr><?php endforeach ?></tbody>
    </table></div></details>
  <details><summary>새 장비 <?= count($plan['eq']) ?>대 (예전 보수 이력 <?= $plan['logs'] ?>건)</summary>
    <div class="table-scroll"><table class="table small">
      <thead><tr><th>팀</th><th>분류</th><th>장비</th><th>놓인 곳</th><th>상태</th><th class="right">이력</th></tr></thead>
      <tbody><?php foreach ($plan['eq'] as $r): ?><tr><td class="nowrap"><?= e($teamLabel($r['team'])) ?></td><td class="nowrap"><?= e($r['kind']) ?></td><td><b><?= e($r['name']) ?></b></td>
        <td><?= e(implode(' › ', array_filter([$r['area'], $r['where']]))) ?></td><td class="nowrap"><?= e($r['status']) ?></td><td class="right"><?= count(ima_history($r['hist'])[0]) ?: '' ?></td></tr><?php endforeach ?></tbody>
    </table></div></details>
  <?php if ($plan['fac_cycle']): $cn = array_count_values(array_column($plan['fac_cycle'], 'cycle')); ?>
  <details><summary>점검 주기 바꿀 세부시설 <?= count($plan['fac_cycle']) ?>곳 (<?= e(implode(' · ', array_map(fn($c, $n) => FACILITY_CYCLES[$c] . " $n", array_keys($cn), $cn))) ?>)</summary>
    <p class="small"><?= e(implode(', ', array_map(fn($r) => $r['area'] . ' › ' . $r['name'] . ' ' . FACILITY_CYCLES[$r['from']] . '→' . FACILITY_CYCLES[$r['cycle']], $plan['fac_cycle']))) ?></p></details>
  <?php endif ?>
  <?php if ($plan['fac_skip'] || $plan['eq_skip']): ?>
  <details><summary class="warn">이미 있어 건너뛸 것 <?= count($plan['fac_skip']) + count($plan['eq_skip']) ?>줄</summary>
    <p class="small"><?= e(implode(', ', [...array_map(fn($r) => $r['area'] . ' › ' . $r['name'], $plan['fac_skip']), ...array_map(fn($r) => $r['name'], $plan['eq_skip'])])) ?></p></details>
  <?php endif ?>

  <h2>3. 가져오기</h2>
  <p class="small">구역 <?= count($newGroups['facility']) ?>개 · 세부시설 <?= count($plan['fac']) ?>줄 · 장비 분류 <?= count($newGroups['equipment']) ?>개 · 장비 <?= count($plan['eq']) ?>대 · 이력 <?= $plan['logs'] ?>건을 등록하고, 점검 주기 <?= count($plan['fac_cycle']) ?>곳을 바꿉니다.</p>
  <div class="actions"><button class="btn primary" name="act" value="run" onclick="return confirm('시설물·장비를 사이트에 등록할까요?')">가져오기</button></div>
</form>
<?php layout_footer();
