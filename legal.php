<?php
/**
 * 시설·안전 › 법정점검: legal.php?y=2026
 *   이번 기간 현황(했음 ✔ / 아직 ✗) · 기록하기(날짜·업체·메모·업체 일지 파일은 선택) · 연간 표 · 그 해 기록 · 점검 목록 고치기
 *   시설관리 메뉴를 쓰는 사람 누구나 기록하고 목록을 고친다. 내용은 app/legal.php
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
require_menu($user, 'fac');
$pdo = db();
$today = date('Y-m-d');
$y = preg_match('/^\d{4}$/', $_GET['y'] ?? '') && (int) $_GET['y'] >= 2000 && (int) $_GET['y'] <= 2100 ? (int) $_GET['y'] : (int) date('Y');

if (is_post()) {
    csrf_verify();
    $act = post('act');
    $back = 'legal.php?y=' . (int) post('y', (string) $y);
    if ($act === 'log_add') {
        $cid = (int) post('check_id');
        $date = post('done_date');
        if (!$pdo->query('SELECT 1 FROM legal_checks WHERE id = ' . $cid)->fetchColumn() || !valid_date($date) || $date > $today) {
            flash('점검과 날짜(오늘까지)를 확인해 주세요.', 'error');
            redirect($back);
        }
        [$path, $err] = legal_store_file($_FILES['file'] ?? []);
        if ($err) { flash($err, 'error'); redirect($back); }
        $pdo->prepare('INSERT INTO legal_check_logs (check_id, done_date, vendor, note, file_path, file_name, user_id) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$cid, $date, mb_substr(post('vendor'), 0, 100) ?: null, mb_substr(post('note'), 0, 500) ?: null, $path,
                       $path ? mb_substr((string) $_FILES['file']['name'], 0, 200) : null, $user['id']]);
        if (post('vendor') !== '') $pdo->prepare('UPDATE legal_checks SET vendor = ? WHERE id = ? AND (vendor IS NULL OR vendor = \'\')')->execute([mb_substr(post('vendor'), 0, 100), $cid]);
        flash('점검 기록을 저장했습니다.', 'success');
        redirect('legal.php?y=' . substr($date, 0, 4));
    }
    if ($act === 'log_delete') {
        $st = $pdo->prepare('SELECT file_path FROM legal_check_logs WHERE id = ?');
        $st->execute([(int) post('log_id')]);
        $fp = $st->fetchColumn();
        $pdo->prepare('DELETE FROM legal_check_logs WHERE id = ?')->execute([(int) post('log_id')]);
        if ($fp && str_starts_with((string) $fp, 'uploads/legal/')) @unlink(APP_ROOT . '/' . $fp);
        flash('기록을 지웠습니다.', 'success');
        redirect($back);
    }
    if ($act === 'check_save') {
        $name = mb_substr(post('name'), 0, 100);
        $cycle = isset(LEGAL_CYCLES[post('cycle')]) ? post('cycle') : 'month';
        if ($name === '') { flash('점검 이름을 적어 주세요.', 'error'); redirect($back); }
        $row = [$name, $cycle, mb_substr(post('vendor'), 0, 100) ?: null, mb_substr(post('target'), 0, 200) ?: null, mb_substr(post('basis'), 0, 200) ?: null, post('is_active') === '1' ? 1 : 0];
        if ($id = (int) post('id')) {
            $pdo->prepare('UPDATE legal_checks SET name = ?, cycle = ?, vendor = ?, target = ?, basis = ?, is_active = ? WHERE id = ?')->execute([...$row, $id]);
        } else {
            $pdo->prepare('INSERT INTO legal_checks (name, cycle, vendor, target, basis, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, (SELECT COALESCE(MAX(c.sort_order), 0) + 10 FROM legal_checks c))')->execute($row);
        }
        flash("'{$name}' 저장했습니다.", 'success');
        redirect($back);
    }
    redirect($back);
}

$checks = legal_checks($today);
$all = legal_checks($today, false);
// 그 해 기록
$st = $pdo->prepare('SELECT l.*, u.name AS user_name FROM legal_check_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.done_date BETWEEN ? AND ? ORDER BY l.done_date DESC, l.id DESC');
$st->execute(["$y-01-01", "$y-12-31"]);
$logs = $st->fetchAll();
$byMonth = []; // [점검][월] => 기록들
foreach ($logs as $l) $byMonth[(int) $l['check_id']][(int) substr($l['done_date'], 5, 2)][] = $l;
[$done, $total] = [count(array_filter($checks, fn($c) => $c['done'])), count($checks)];
$curMonth = $y === (int) date('Y') ? (int) date('n') : ($y < (int) date('Y') ? 12 : 0);

layout_header('법정점검', 'legal');
?>
<section class="card">
  <div class="card-head">
    <h1>법정점검 <small class="muted">위탁 업체 점검 · 정기 검사 기록</small></h1>
    <div class="actions no-margin no-print"><button class="btn ghost" onclick="window.print()">인쇄</button></div>
  </div>
  <p>이번 기간 <b><?= $done ?></b> / <?= $total ?> 완료<?= $total > $done ? ' · <span class="warn">남은 ' . ($total - $done) . '</span>' : ' ✔' ?>
    <span class="muted small">— 업체 일지는 있으면 첨부하고, 없으면 날짜·업체만 적어도 됩니다.</span></p>
  <div class="table-scroll">
  <table class="table legal-now">
    <thead><tr><th>점검</th><th>주기</th><th>대상 · 업체</th><th>이번 기간</th><th>마지막 기록</th><th class="no-print"></th></tr></thead>
    <tbody>
    <?php foreach ($checks as $c): [, , $plabel] = $c['period']; ?>
      <tr class="<?= $c['done'] ? '' : 'issue' ?>">
        <td><b><?= e($c['name']) ?></b><?php if ($c['basis']): ?><br><small class="muted"><?= e($c['basis']) ?></small><?php endif ?></td>
        <td class="nowrap"><?= e(LEGAL_CYCLES[$c['cycle']]) ?></td>
        <td class="small"><?= e((string) $c['target']) ?><?php if ($c['vendor']): ?><br><span class="muted"><?= e($c['vendor']) ?></span><?php endif ?></td>
        <td class="nowrap"><?= e($plabel) ?> <?= $c['done'] ? '<b class="ok">✔ ' . e(date('n/j', strtotime($c['done']['done_date']))) . '</b>' : '<b class="warn">✗ 아직</b>' ?></td>
        <td class="small nowrap"><?= $c['last'] ? e(date('Y.n.j', strtotime($c['last']['done_date']))) . ($c['last']['file_path'] ? ' 📎' : '') : '<span class="muted">없음</span>' ?></td>
        <td class="no-print">
          <details class="legal-add"><summary class="btn small <?= $c['done'] ? '' : 'primary' ?>">기록하기</summary>
            <form method="post" enctype="multipart/form-data" class="legal-form">
              <?= csrf_field() ?><input type="hidden" name="act" value="log_add"><input type="hidden" name="check_id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="y" value="<?= $y ?>">
              <label>점검한 날<input type="date" name="done_date" value="<?= e($today) ?>" max="<?= e($today) ?>" required></label>
              <label>업체<input name="vendor" value="<?= e((string) $c['vendor']) ?>" maxlength="100"></label>
              <label>메모<input name="note" maxlength="500" placeholder="예: 지적사항 없음, 10월분 일지 받음"></label>
              <label>업체 일지 <small class="muted">(선택 · PDF·사진)</small><input type="file" name="file" accept=".pdf,image/*"></label>
              <button class="btn primary">저장</button>
            </form>
          </details>
        </td>
      </tr>
    <?php endforeach ?>
    <?php if (!$checks): ?><tr><td colspan="6" class="center muted">점검 목록이 없습니다. 아래 '점검 목록 고치기'에서 더하세요.</td></tr><?php endif ?>
    </tbody>
  </table>
  </div>
</section>

<section class="card">
  <div class="card-head">
    <h2><?= $y ?>년 한눈에 <small class="muted">✔ = 그 달에 기록 있음</small></h2>
    <div class="actions no-margin no-print">
      <a class="btn small" href="<?= e(url('legal.php?y=' . ($y - 1))) ?>">‹ <?= $y - 1 ?></a>
      <a class="btn small" href="<?= e(url('legal.php?y=' . ($y + 1))) ?>"><?= $y + 1 ?> ›</a>
    </div>
  </div>
  <div class="table-scroll">
  <table class="table legal-year">
    <thead><tr><th>점검</th><?php foreach (range(1, 12) as $m): ?><th class="center"><?= $m ?>월</th><?php endforeach ?></tr></thead>
    <tbody>
    <?php foreach ($checks as $c): $cid = (int) $c['id']; ?>
      <tr><td class="nowrap"><?= e($c['name']) ?> <small class="muted"><?= e(LEGAL_CYCLES[$c['cycle']]) ?></small></td>
        <?php foreach (range(1, 12) as $m): $hit = $byMonth[$cid][$m] ?? []; $miss = !$hit && $c['cycle'] === 'month' && $m <= $curMonth; ?>
          <td class="center <?= $hit ? 'ok' : ($miss ? 'warn' : 'muted') ?>" title="<?= e(implode(', ', array_map(fn($l) => $l['done_date'] . ' ' . ($l['vendor'] ?? ''), $hit))) ?>"><?= $hit ? '✔' : ($miss ? '✗' : '·') ?></td>
        <?php endforeach ?></tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
</section>

<section class="card">
  <h2><?= $y ?>년 기록 <small class="muted"><?= count($logs) ?>건</small></h2>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>날짜</th><th>점검</th><th>업체</th><th>메모</th><th>업체 일지</th><th>기록</th><th class="no-print"></th></tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
      <tr><td class="nowrap"><?= e($l['done_date']) ?></td><td><?= e($all[(int) $l['check_id']]['name'] ?? '') ?></td><td><?= e((string) $l['vendor']) ?></td><td><?= e((string) $l['note']) ?></td>
        <td><?= $l['file_path'] ? '<a href="' . e(url($l['file_path'])) . '" target="_blank">📎 ' . e(mb_strimwidth((string) $l['file_name'], 0, 30, '…')) . '</a>' : '<span class="muted small">없음</span>' ?></td>
        <td class="small muted nowrap"><?= e($l['user_name'] ?? '') ?></td>
        <td class="no-print"><form method="post" onsubmit="return confirm('이 기록을 지울까요?')"><?= csrf_field() ?><input type="hidden" name="act" value="log_delete"><input type="hidden" name="log_id" value="<?= (int) $l['id'] ?>"><input type="hidden" name="y" value="<?= $y ?>"><button class="btn small ghost">지우기</button></form></td></tr>
    <?php endforeach ?>
    <?php if (!$logs): ?><tr><td colspan="7" class="center muted">이 해 기록이 없습니다.</td></tr><?php endif ?>
    </tbody>
  </table>
  </div>
</section>

<section class="card no-print">
  <details><summary class="btn">점검 목록 고치기</summary>
    <p class="muted small">해당 없는 점검은 '사용'을 끄면 목록에서 빠집니다 (기록은 남음). 주기: 매월·분기·반기·매년은 달력 기간, 2년·3년마다는 오늘부터 거슬러 그 기간 안에 기록이 있으면 완료입니다.</p>
    <?php foreach ([...array_values($all), ['id' => 0, 'name' => '', 'cycle' => 'month', 'vendor' => '', 'target' => '', 'basis' => '', 'is_active' => 1]] as $c): ?>
      <form method="post" class="row legal-edit <?= $c['id'] ? '' : 'new' ?>">
        <?= csrf_field() ?><input type="hidden" name="act" value="check_save"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="y" value="<?= $y ?>">
        <label>점검<input name="name" value="<?= e($c['name']) ?>" maxlength="100" <?= $c['id'] ? 'required' : 'placeholder="새 점검 이름"' ?>></label>
        <label>주기<select name="cycle"><?php foreach (LEGAL_CYCLES as $k => $v): ?><option value="<?= $k ?>" <?= $c['cycle'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach ?></select></label>
        <label>대상<input name="target" value="<?= e((string) $c['target']) ?>" maxlength="200"></label>
        <label>업체<input name="vendor" value="<?= e((string) $c['vendor']) ?>" maxlength="100"></label>
        <label>근거·메모<input name="basis" value="<?= e((string) $c['basis']) ?>" maxlength="200"></label>
        <label class="inline-check"><input type="checkbox" name="is_active" value="1" <?= $c['is_active'] ? 'checked' : '' ?>> 사용</label>
        <button class="btn small <?= $c['id'] ? '' : 'primary' ?>"><?= $c['id'] ? '저장' : '+ 더하기' ?></button>
      </form>
    <?php endforeach ?>
  </details>
</section>
<?php layout_footer();
