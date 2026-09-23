<?php
/** 장비 상세: 정보·사진·이력, 점검/수리/관리 기록 추가, 불용처리 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
$id = (int) ($_GET['id'] ?? 0);
$e = equipment_find($id) ?? abort(404, '장비를 찾을 수 없습니다.');
$canManage = can_manage_assets($user);
$pdo = db();

if (is_post()) {
    csrf_verify();
    $action = post('action');
    $date = valid_date(post('log_date')) ? post('log_date') : date('Y-m-d');

    if ($action === 'log') {
        // 점검·수리·관리 기록은 모든 직원이 입력 가능
        $kind = post('kind');
        $content = post('content');
        $newStatus = post('status_after');
        if (!in_array($kind, EQUIPMENT_LOG_INPUT, true) || $content === '') {
            flash('구분과 내용을 입력하세요.', 'error');
            redirect("equipment_view.php?id=$id");
        }
        if ($e['status'] === 'disposed') abort(400, '불용 처리된 장비입니다.');
        $newStatus = in_array($newStatus, ['active', 'repair'], true) && $newStatus !== $e['status'] ? $newStatus : null;
        $pdo->beginTransaction();
        equipment_log($id, $kind, $date, (int) $user['id'], $content, to_int(post('cost')) ?: null, mb_substr(post('vendor'), 0, 100), $newStatus);
        if ($newStatus) $pdo->prepare('UPDATE equipment SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
        $pdo->commit();
        flash('이력을 기록했습니다.', 'success');
    } elseif ($action === 'dispose' && $canManage && $e['status'] !== 'disposed') {
        $reason = mb_substr(post('reason'), 0, 500);
        if ($reason === '') {
            flash('불용 사유를 입력하세요.', 'error');
            redirect("equipment_view.php?id=$id");
        }
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE equipment SET status = 'disposed', disposed_on = ?, dispose_reason = ? WHERE id = ?")->execute([$date, $reason, $id]);
        equipment_log($id, 'dispose', $date, (int) $user['id'], $reason, null, null, 'disposed');
        $pdo->commit();
        flash('불용 처리했습니다.', 'success');
    } elseif ($action === 'restore' && $canManage && $e['status'] === 'disposed') {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE equipment SET status = 'active', disposed_on = NULL, dispose_reason = NULL WHERE id = ?")->execute([$id]);
        equipment_log($id, 'restore', date('Y-m-d'), (int) $user['id'], post('reason') ?: '불용 해제', null, null, 'active');
        $pdo->commit();
        flash('불용을 해제했습니다.', 'success');
    } elseif ($action === 'delete_log' && $canManage) {
        $pdo->prepare("DELETE FROM equipment_logs WHERE id = ? AND equipment_id = ? AND kind IN ('inspect','repair','maintain','other')")
            ->execute([(int) post('log_id'), $id]);
        flash('이력을 삭제했습니다.', 'success');
    }
    redirect("equipment_view.php?id=$id");
}

$st = $pdo->prepare('SELECT l.*, u.name AS user_name FROM equipment_logs l JOIN users u ON u.id = l.user_id WHERE l.equipment_id = ? ORDER BY l.log_date DESC, l.id DESC');
$st->execute([$id]);
$logs = $st->fetchAll();
$repairCost = array_sum(array_column($logs, 'cost'));

layout_header($e['name'], 'equipment');
?>
<article class="card">
  <div class="card-head">
    <div>
      <p class="muted small crumbs"><?= e($e['team_name']) ?> › <?= e($e['group_name']) ?></p>
      <h1><?= e($e['name']) ?> <?= equipment_badge($e['status']) ?></h1>
    </div>
    <div class="actions no-margin no-print">
      <a class="btn ghost" href="<?= e(url('equipment.php?team=' . $e['team_id'])) ?>">목록</a>
      <button class="btn ghost" onclick="window.print()">인쇄</button>
      <?php if ($canManage): ?><a class="btn" href="<?= e(url('equipment_edit.php?id=' . $id)) ?>">정보 수정</a><?php endif ?>
    </div>
  </div>

  <?php render_gallery(photos_for('equipment', $id)) ?>

  <table class="table info-table">
    <tr><th>모델</th><td><?= e($e['model']) ?></td><th>일련번호</th><td><?= e($e['serial_no']) ?></td></tr>
    <tr><th>취득일</th><td><?= e($e['acquired_on']) ?></td><th>취득금액</th><td><?= $e['acquired_cost'] ? e(won($e['acquired_cost'])) : '' ?></td></tr>
    <tr><th>보관 위치</th><td><?= e($e['location']) ?></td><th>누적 수리·관리비</th><td><?= e(won($repairCost)) ?></td></tr>
    <?php if ($e['status'] === 'disposed'): ?>
      <tr><th>불용일</th><td><?= e($e['disposed_on']) ?></td><th>불용 사유</th><td><?= e($e['dispose_reason']) ?></td></tr>
    <?php endif ?>
  </table>
  <?php if ($e['spec']): ?><h3>규격·스펙</h3><div class="pre"><?= e($e['spec']) ?></div><?php endif ?>
  <?php if ($e['note']): ?><h3>비고</h3><div class="pre"><?= e($e['note']) ?></div><?php endif ?>
</article>

<?php if ($e['status'] !== 'disposed'): ?>
<form method="post" class="card no-print">
  <?= csrf_field() ?>
  <h2>점검·수리·관리 기록</h2>
  <div class="row">
    <label>일자<input type="date" name="log_date" value="<?= e(date('Y-m-d')) ?>" required></label>
    <label>구분<select name="kind"><?php foreach (EQUIPMENT_LOG_INPUT as $k): ?><option value="<?= $k ?>"><?= e(EQUIPMENT_LOG_KINDS[$k]) ?></option><?php endforeach ?></select></label>
    <label>비용(원)<input name="cost" inputmode="numeric" class="num" data-money placeholder="0"></label>
    <label>업체<input name="vendor" placeholder="수리 업체 (선택)"></label>
  </div>
  <label>내용<textarea name="content" rows="2" required placeholder="예: 날 교체, 엔진오일 보충 / 시동 불량으로 수리 의뢰"></textarea></label>
  <div class="row">
    <label>상태 변경
      <select name="status_after">
        <option value="">변경 없음 (현재: <?= e(EQUIPMENT_STATUS[$e['status']]) ?>)</option>
        <option value="repair">수리중으로 변경</option>
        <option value="active">사용중으로 변경 (수리 완료)</option>
      </select>
    </label>
  </div>
  <div class="actions"><button class="btn primary" name="action" value="log">기록 추가</button></div>
</form>
<?php endif ?>

<section class="card">
  <h2>이력 <small class="muted"><?= count($logs) ?>건</small></h2>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>일자</th><th>구분</th><th>내용</th><th class="right">비용</th><th>업체</th><th>기록자</th><?php if ($canManage): ?><th class="no-print"></th><?php endif ?></tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
      <tr class="log-<?= e($l['kind']) ?>">
        <td class="nowrap"><?= e($l['log_date']) ?></td>
        <td class="nowrap"><b><?= e(EQUIPMENT_LOG_KINDS[$l['kind']] ?? $l['kind']) ?></b>
          <?= $l['status_after'] && !in_array($l['kind'], ['register', 'dispose', 'restore'], true) ? '<br><small class="muted">→ ' . e(EQUIPMENT_STATUS[$l['status_after']]) . '</small>' : '' ?></td>
        <td><?= nl2br(e($l['content'])) ?></td>
        <td class="right"><?= $l['cost'] ? number_format($l['cost']) : '' ?></td>
        <td><?= e($l['vendor']) ?></td>
        <td><?= e($l['user_name']) ?></td>
        <?php if ($canManage): ?><td class="no-print">
          <?php if (in_array($l['kind'], EQUIPMENT_LOG_INPUT, true)): ?>
            <form method="post" onsubmit="return confirm('이 이력을 삭제할까요?')"><?= csrf_field() ?><input type="hidden" name="log_id" value="<?= (int) $l['id'] ?>"><button class="btn small ghost danger" name="action" value="delete_log">삭제</button></form>
          <?php endif ?>
        </td><?php endif ?>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
</section>

<?php if ($canManage): ?>
<form method="post" class="card no-print danger-zone">
  <?= csrf_field() ?>
  <?php if ($e['status'] !== 'disposed'): ?>
    <h2>불용처리</h2>
    <p class="muted small">고장·노후 등으로 더 이상 사용하지 않는 장비를 불용 처리합니다. 목록에서는 '불용'으로 분류되고 이력은 그대로 남습니다.</p>
    <div class="row">
      <label>불용일<input type="date" name="log_date" value="<?= e(date('Y-m-d')) ?>"></label>
      <label>불용 사유<input name="reason" placeholder="예: 내용연수 경과, 수리비 과다" required></label>
    </div>
    <div class="actions"><button class="btn danger" name="action" value="dispose" onclick="return confirm('불용 처리할까요?')">불용처리</button></div>
  <?php else: ?>
    <h2>불용 해제</h2>
    <p class="muted small">잘못 불용 처리한 경우 다시 '사용중'으로 되돌립니다.</p>
    <input type="hidden" name="reason" value="불용 해제">
    <div class="actions"><button class="btn" name="action" value="restore" onclick="return confirm('불용을 해제할까요?')">불용 해제</button></div>
  <?php endif ?>
</form>
<?php endif ?>
<?php layout_footer();
