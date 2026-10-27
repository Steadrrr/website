<?php
/**
 * 설정 › 시설물 가져오기 › 점검일지 (최고관리자): 지난 점검일지를 엑셀로 한꺼번에 만든다
 *   엑셀: '점검일지' 시트 — 날짜 · 구역(건물) · 세부시설 · 점검결과 · 내용 (한 줄 = 그 날 그 시설 점검 한 번)
 *         '진행 중 이상' 시트(선택) — 구역(건물) · 세부시설 · 결과 · 내용 · 시작일 · 끝나는 날(비우면 진행 중)
 *   세부시설은 구역 이름 + 세부시설 이름으로 찾고, 그 시설의 관리팀 일지로 묶는다 (날짜 × 관리팀 = 일지 한 장).
 *   그 날 그 팀 시설점검일지가 이미 있으면 건너뛴다. 진행 중 이상은 (시설, 시작일)이 같은 것이 있으면 건너뛴다.
 *   1) 올리기 → 2) 미리보기 · 등록 상태(결재완료/임시저장) → 3) 가져오기
 *   읽은 내용은 서버 임시 폴더의 파일에 두고(세션에는 경로만), 가져오거나 다른 파일을 올리면 지운다.
 */
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/xlsx_read.php';

$user = require_admin();
$pdo = db();
const IFL_KEY = 'facility_logs_import';
$imp = $_SESSION[IFL_KEY] ?? null;
$data = $imp && is_file($imp['path']) ? json_decode((string) file_get_contents($imp['path']), true) : null;
if ($imp && !$data) { unset($_SESSION[IFL_KEY]); $imp = null; }

/** 시트 → 머리글 이름으로 읽은 줄 (머리글 = 첫 칸이 $first) */
function ifl_rows(array $rows, string $first): array
{
    $rows = array_values($rows);
    foreach ($rows as $ri => $r) {
        if ($ri > 20) break;
        if (trim((string) ($r[0] ?? '')) !== $first) continue;
        $cols = [];
        foreach ($r as $ci => $v) if (trim((string) $v) !== '') $cols[trim((string) $v)] = $ci;
        $out = [];
        foreach (array_slice($rows, $ri + 1) as $x) {
            $row = [];
            foreach ($cols as $name => $ci) $row[$name] = $x[$ci] ?? '';
            if (implode('', array_map('strval', $row)) !== '') $out[] = $row;
        }
        return $out;
    }
    return [];
}

/** 구역|세부시설 → 시설 (사용안함 포함) */
function ifl_facilities(): array
{
    $out = [];
    foreach (facilities_list(null, false) as $f) $out[trim($f['area']) . '|' . trim($f['name'])] = $f;
    return $out;
}

/** 미리보기·가져오기 계획 */
function ifl_plan(array $data): array
{
    $facs = ifl_facilities();
    $have = [];
    foreach (db()->query("SELECT work_date, team_id FROM journals WHERE type = 'facility' AND status <> 'rejected'") as $j) $have[$j['work_date'] . '|' . (int) $j['team_id']] = true;
    $plan = ['docs' => [], 'skip_docs' => [], 'missing' => [], 'rows' => 0, 'bad' => 0, 'issues' => [], 'issue_skip' => 0, 'teams' => []];
    foreach ($data['logs'] as $r) {
        $f = $facs[$r['area'] . '|' . $r['name']] ?? null;
        if (!$f) { $plan['missing'][$r['area'] . ' › ' . $r['name']] = true; continue; }
        $k = $r['date'] . '|' . (int) $f['team_id'];
        if (isset($have[$k])) { $plan['skip_docs'][$k] = true; continue; }
        $plan['docs'][$k][] = ['facility_id' => (int) $f['id'], 'area' => $f['area'], 'facility' => $f['name'], 'result' => $r['result'], 'note' => $r['note']];
        $plan['rows']++;
        if ($r['result'] !== normal_result()) $plan['bad']++;
        $plan['teams'][(int) $f['team_id']] = ($plan['teams'][(int) $f['team_id']] ?? 0);
    }
    foreach (array_keys($plan['docs']) as $k) $plan['teams'][(int) explode('|', $k)[1]]++;
    ksort($plan['docs']);
    $old = [];
    foreach (db()->query('SELECT facility_id, start_date FROM facility_issues') as $i) $old[$i['facility_id'] . '|' . $i['start_date']] = true;
    foreach ($data['issues'] as $r) {
        $f = $facs[$r['area'] . '|' . $r['name']] ?? null;
        if (!$f) { $plan['missing'][$r['area'] . ' › ' . $r['name']] = true; continue; }
        if (isset($old[$f['id'] . '|' . $r['start']])) { $plan['issue_skip']++; continue; }
        $plan['issues'][] = $r + ['facility_id' => (int) $f['id']];
    }
    return $plan;
}

/* ───────────── 처리 ───────────── */
if (is_post()) {
    csrf_verify();
    $act = post('act');
    if ($act === 'reset') {
        if ($imp) @unlink($imp['path']);
        unset($_SESSION[IFL_KEY]);
        redirect('admin/import_facility_logs.php');
    }
    if ($act === 'upload') {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) { flash('파일을 올리지 못했습니다. 다시 골라 주세요.', 'error'); redirect('admin/import_facility_logs.php'); }
        try {
            $sheets = xlsx_sheets($f['tmp_name'], 0, fn($n) => in_array(trim($n), ['점검일지', '진행 중 이상'], true));
        } catch (Throwable $e) {
            flash($e->getMessage(), 'error');
            redirect('admin/import_facility_logs.php');
        }
        $results = config('facility_results', ['정상']);
        $logs = $issues = $warn = [];
        foreach (ifl_rows($sheets['점검일지'] ?? [], '날짜') as $i => $r) {
            $date = sheet_date($r['날짜'] ?? '') ?? (valid_date(trim((string) ($r['날짜'] ?? ''))) ? trim((string) $r['날짜']) : null);
            $res = trim((string) ($r['점검결과'] ?? '')) ?: $results[0];
            if (!$date || trim((string) ($r['세부시설'] ?? '')) === '') { if (count($warn) < 10) $warn[] = '점검일지 ' . ($i + 1) . '번째 줄: 날짜·세부시설을 읽지 못해 건너뜀'; continue; }
            if (!in_array($res, $results, true)) { if (count($warn) < 10) $warn[] = "점검결과 '$res'를 몰라 '{$results[0]}'로 바꿈"; $res = $results[0]; }
            $logs[] = ['date' => $date, 'area' => trim((string) ($r['구역(건물)'] ?? $r['구역'] ?? '')), 'name' => trim((string) $r['세부시설']), 'result' => $res, 'note' => mb_substr(trim((string) ($r['내용'] ?? '')), 0, 500)];
        }
        foreach (ifl_rows($sheets['진행 중 이상'] ?? [], '구역(건물)') as $r) {
            $start = sheet_date($r['시작일'] ?? '') ?? (valid_date(trim((string) ($r['시작일'] ?? ''))) ? trim((string) $r['시작일']) : null);
            $endRaw = trim((string) ($r['끝나는 날'] ?? ''));
            $end = $endRaw === '' ? null : (sheet_date($r['끝나는 날']) ?? (valid_date($endRaw) ? $endRaw : null));
            $res = trim((string) ($r['결과'] ?? ''));
            if (!$start || !in_array($res, $results, true) || $res === $results[0]) { if (count($warn) < 10) $warn[] = "진행 중 이상 '" . ($r['세부시설'] ?? '') . "': 시작일·결과를 읽지 못해 건너뜀"; continue; }
            $issues[] = ['area' => trim((string) $r['구역(건물)']), 'name' => trim((string) ($r['세부시설'] ?? '')), 'result' => $res, 'note' => mb_substr(trim((string) ($r['내용'] ?? '')), 0, 500), 'start' => $start, 'end' => $end];
        }
        if (!$logs && !$issues) { flash("가져올 줄이 없습니다. '점검일지' 시트(머리글 첫 칸 '날짜')나 '진행 중 이상' 시트(머리글 첫 칸 '구역(건물)')를 확인하세요.", 'error'); redirect('admin/import_facility_logs.php'); }
        if ($imp) @unlink($imp['path']);
        $path = tempnam(sys_get_temp_dir(), 'facl');
        file_put_contents($path, json_encode(['logs' => $logs, 'issues' => $issues], JSON_UNESCAPED_UNICODE));
        $_SESSION[IFL_KEY] = ['file' => (string) $f['name'], 'path' => $path, 'warn' => $warn, 'status' => 'approved'];
        redirect('admin/import_facility_logs.php');
    }
    if (!$imp) redirect('admin/import_facility_logs.php');
    $imp['status'] = post('status') === 'draft' ? 'draft' : 'approved';
    $_SESSION[IFL_KEY] = $imp;
    if ($act === 'run') {
        @set_time_limit(600);
        $plan = ifl_plan($data);
        $note = '일괄 작성: ' . $imp['file'];
        $insJ = $pdo->prepare("INSERT INTO journals (type, team_id, work_date, author_id, weather, content, remarks, status) VALUES ('facility', ?, ?, ?, NULL, '', ?, 'draft')");
        $insI = $pdo->prepare('INSERT INTO facility_items (journal_id, facility_id, area, facility, result, note) VALUES (?, ?, ?, ?, ?, ?)');
        $made = 0;
        $pdo->beginTransaction();
        try {
            foreach ($plan['docs'] as $k => $rows) {
                [$date, $teamId] = explode('|', $k);
                $insJ->execute([(int) $teamId, $date, $user['id'], $note]);
                $id = (int) $pdo->lastInsertId();
                foreach ($rows as $it) $insI->execute([$id, $it['facility_id'], $it['area'], $it['facility'], $it['result'], $it['note'] !== '' ? $it['note'] : null]);
                if ($imp['status'] === 'approved') $pdo->prepare("UPDATE journals SET status = 'approved', submitted_at = NOW(), completed_at = NOW() WHERE id = ?")->execute([$id]);
                journal_log($id, $user, '엑셀 가져오기', $imp['file'] . ($imp['status'] === 'approved' ? ' · 결재완료로 등록' : ' · 임시저장'));
                $made++;
            }
            $ins = $pdo->prepare('INSERT INTO facility_issues (facility_id, result, note, start_date, end_date, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            foreach ($plan['issues'] as $r) $ins->execute([$r['facility_id'], $r['result'], $r['note'] !== '' ? $r['note'] : null, $r['start'], $r['end'], $user['id']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        @unlink($imp['path']);
        unset($_SESSION[IFL_KEY]);
        flash("시설점검일지 {$made}장(점검 {$plan['rows']}줄 · 이상 {$plan['bad']}줄)과 진행 중 이상 " . count($plan['issues']) . '건을 가져왔습니다.'
            . ($plan['skip_docs'] ? ' 이미 일지가 있던 ' . count($plan['skip_docs']) . '장은 건너뛰었습니다.' : ''), 'success');
        redirect('journal.php?type=facility');
    }
    redirect('admin/import_facility_logs.php#preview');
}

layout_header('점검일지 가져오기', 'settings');
settings_nav('asset_import');
?>
<div class="tabs team-tabs no-print">
  <a href="<?= e(url('admin/import_assets.php')) ?>">시설물·장비·점검 주기</a>
  <a href="<?= e(url('admin/import_facility_logs.php')) ?>" class="on">지난 점검일지</a>
</div>
<?php if (!$imp): ?>
<section class="card">
  <h1>점검일지 가져오기 <small class="muted">지난 시설점검일지를 엑셀로 한꺼번에</small></h1>
  <ul class="small">
    <li><b>'점검일지'</b> 시트: 날짜 · 구역(건물) · 세부시설 · 점검결과 · 내용 — 한 줄이 그 날 그 시설 점검 한 번입니다. 같은 날 같은 관리팀 시설은 일지 한 장으로 묶습니다.</li>
    <li><b>'진행 중 이상'</b> 시트(선택): 구역(건물) · 세부시설 · 결과 · 내용 · 시작일 · 끝나는 날(비우면 지금도 진행 중). 시설 상세의 '진행 중 이상'에 들어가고, 진행 중인 것은 앞으로의 일지에 계속 이상으로 나옵니다.</li>
    <li>세부시설은 <b>구역 이름 + 세부시설 이름</b>으로 찾습니다. 그 날 그 팀 시설점검일지가 <b>이미 있으면 건너뜁니다</b>. 올리기만 해서는 저장되지 않습니다.</li>
  </ul>
  <form method="post" enctype="multipart/form-data" class="actions" style="justify-content:flex-start">
    <?= csrf_field() ?><input type="hidden" name="act" value="upload">
    <input type="file" name="file" accept=".xlsx" required>
    <button class="btn primary">올리기</button>
  </form>
</section>
<?php layout_footer(); exit; endif;

$plan = ifl_plan($data);
$dates = array_unique(array_map(fn($k) => explode('|', $k)[0], array_keys($plan['docs'])));
sort($dates);
?>
<section class="card">
  <div class="card-head">
    <h1>점검일지 가져오기 <small class="muted"><?= e($imp['file']) ?></small></h1>
    <form method="post"><?= csrf_field() ?><button class="btn ghost" name="act" value="reset">다른 파일 올리기</button></form>
  </div>
  <div class="kpis k4">
    <div class="kpi total"><span>새로 만들 일지</span><b><?= number_format(count($plan['docs'])) ?>장</b><small class="muted"><?= e(implode(' · ', array_map(fn($t, $n) => team_name($t) . " $n", array_keys($plan['teams']), $plan['teams']))) ?></small></div>
    <div class="kpi"><span>기간</span><b><?= $dates ? e(date('Y.n.j', strtotime(reset($dates)))) . ' ~ ' . e(date('n.j', strtotime(end($dates)))) : '-' ?></b><small class="muted"><?= count($dates) ?>일</small></div>
    <div class="kpi"><span>점검 줄 · 이상</span><b><?= number_format($plan['rows']) ?>줄</b><small class="warn">이상 <?= number_format($plan['bad']) ?>줄</small></div>
    <div class="kpi"><span>진행 중 이상</span><b><?= count($plan['issues']) ?>건</b><small class="muted"><?= $plan['issue_skip'] ? '이미 있어 건너뜀 ' . $plan['issue_skip'] : '' ?></small></div>
  </div>
  <?php if ($imp['warn']): ?><div class="flash flash-warn"><?= e(implode(' / ', $imp['warn'])) ?></div><?php endif ?>
  <?php if ($plan['missing']): ?><div class="flash flash-warn">사이트에서 찾지 못한 세부시설 <?= count($plan['missing']) ?>곳 (이 줄들은 빠집니다): <?= e(implode(', ', array_keys($plan['missing']))) ?></div><?php endif ?>
  <?php if ($plan['skip_docs']): ?><p class="muted small">이미 시설점검일지가 있어 건너뛸 날·팀 <?= count($plan['skip_docs']) ?>장</p><?php endif ?>
</section>
<form method="post" class="card" id="preview">
  <?= csrf_field() ?>
  <h2>등록 상태</h2>
  <div class="imp-status">
    <label><input type="radio" name="status" value="approved" <?= $imp['status'] === 'approved' ? 'checked' : '' ?>> <span><b>결재완료</b>로 등록 (지난 자료 — 결재를 다시 받지 않음)</span></label>
    <label><input type="radio" name="status" value="draft" <?= $imp['status'] === 'draft' ? 'checked' : '' ?>> <span><b>임시저장</b>으로 등록 (하나씩 열어 확인하고 결재 올리기)</span></label>
  </div>
  <p class="muted small">작성자는 <?= e($user['name']) ?>, 특이사항 칸에 '일괄 작성: <?= e($imp['file']) ?>'이 남습니다.</p>
  <?php if ($plan['issues']): ?>
  <details><summary>진행 중 이상 <?= count($plan['issues']) ?>건</summary>
    <div class="table-scroll"><table class="table small">
      <thead><tr><th>세부시설</th><th>기간</th><th>결과</th><th>내용</th></tr></thead>
      <tbody><?php foreach ($plan['issues'] as $r): ?><tr><td><?= e($r['area'] . ' › ' . $r['name']) ?></td><td class="nowrap"><?= e($r['start']) ?> ~ <?= e($r['end'] ?? '진행 중') ?></td><td><?= e($r['result']) ?></td><td><?= e($r['note']) ?></td></tr><?php endforeach ?></tbody>
    </table></div></details>
  <?php endif ?>
  <div class="actions"><button class="btn primary" name="act" value="run" onclick="return confirm('시설점검일지 <?= count($plan['docs']) ?>장을 만들까요?')">가져오기</button></div>
</form>
<?php layout_footer();
