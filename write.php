<?php
/**
 * 일지 작성/수정: write.php?type=daily&date=2026-09-22  또는  write.php?id=12
 * 상신된 일지는 모든 직원이 수정할 수 있고, 수정하면 이력이 남고 결재가 처음부터 다시 진행된다.
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/sales_xls.php';
require __DIR__ . '/app/rooms_xls.php';

$user = require_login();
$pdo = db();

$id = (int) ($_GET['id'] ?? 0);
$journal = null;

if ($id) {
    $journal = journal_find($id) ?? abort(404, '일지를 찾을 수 없습니다.');
    if (!can_edit_journal($journal, $user)) abort(403, $journal['status'] !== 'draft' && !can_submit_journal($user, $journal['type'])
        ? '결재를 올린 업무일지는 공무직 이상만 수정할 수 있습니다.' : '임시저장 문서는 작성자만 수정할 수 있습니다.');
    $type = $journal['type'];
    $workDate = $journal['work_date'];
    $teamId = $journal['team_id'] ? (int) $journal['team_id'] : null;
    $payload = items_load($journal);
} else {
    $type = $_GET['type'] ?? 'daily';
    if ($type === 'attendance') redirect('attendance.php');
    if (!isset(JOURNAL_TYPES[$type])) abort(404, '알 수 없는 일지 종류입니다.');
    $workDate = valid_date($_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
    // 시설점검은 관리팀별로 작성
    $teamId = null;
    if ($type === 'facility') {
        $teamId = (int) ($_GET['team'] ?? 0);
        if (!facility_teams()) abort(403, '시설점검을 쓰는 팀이 없습니다. 설정 › 조직 구성에서 팀의 시설점검·시설물 사용을 켜 주세요.');
        if (!isset(facility_teams()[$teamId])) $teamId = (int) array_key_first(facility_teams());
    }
    if ($type === 'arwork') $workDate = substr($workDate, 0, 7) . '-01'; // AR 사용보고는 월 단위 (그 달 1일)
    $payload = items_default($type, $teamId ?: null);
    // 일일업무일지는 하루 1건: 그 날 일지가 있으면 그 문서로
    if ($type === 'daily' && !is_post() && ($exist = daily_find_by_date($workDate))) {
        if (can_edit_journal($exist, $user)) redirect('write.php?id=' . (int) $exist['id']);
        flash(date('n월 j일', strtotime($workDate)) . ' 업무일지는 이미 있고 결재를 올린 뒤라 공무직 이상만 고칠 수 있습니다.', 'info');
        redirect('view.php?id=' . (int) $exist['id']);
    }
}
$dailyLegacy = $type === 'daily' && daily_is_legacy($journal); // 이 기능 전에 쓴 일지는 예전처럼 업무내용 한 칸

if ($menu = journal_menu($type)) require_menu($user, $menu);
if (!can_write_type($user, $type)) abort(403, match ($type) {
    'vcheck' => '상품권 금고점검 보고서는 공무직 이상이 작성합니다.',
    'arwork' => 'AR 사용보고는 공무직 이상이 작성합니다.',
    default  => '상품권 입고 등록·수정은 공무직 이상이 합니다.',
});
// 매출보고: '매표 엑셀로 채우기'로 올린 그 날 판매를 입력칸에 채움 (저장 전)
$fillRep = null;
if ($type === 'sales' && !is_post() && !empty($_GET['fill']) && ($fill = $_SESSION[SALES_FILL_KEY] ?? null) && $fill['date'] === $workDate) {
    [$payload, $fillRep] = sales_fill_apply($payload, $fill, $workDate);
}
// 일일객실판매: '예약 엑셀로 채우기' (개인정보 없이 객실별 인원·할인만)
if ($type === 'rooms' && !is_post() && !empty($_GET['fill']) && ($fill = $_SESSION[ROOMS_FILL_KEY] ?? null) && $fill['date'] === $workDate) {
    [$payload, $fillRep] = rooms_fill_apply($payload, $fill, $workDate);
}
$revision = $journal && is_revision_edit($journal); // 상신된 적 있는 일지 수정 → 이력 + 결재 초기화
$errors = [];

if (is_post()) {
    csrf_verify();
    $workDate = $type === 'arwork' ? (preg_match('/^\d{4}-\d{2}$/', post('ar_month')) ? post('ar_month') . '-01' : '') : post('work_date');
    $weather  = in_array($type, NO_WEATHER_TYPES, true) ? '' : mb_substr(post('weather'), 0, 30);
    $content  = post('content');
    $remarks  = post('remarks');
    $canSubmit = can_submit_journal($user, $type);
    $submit   = $revision || (post('action') === 'submit' && $canSubmit); // 업무일지: 사원은 임시저장만
    $reason   = mb_substr(post('edit_reason'), 0, 500);
    if ($revision) {
        $before = journal_snapshot($journal);
        $prevApproval = approval_summary($journal);
    }

    if (!valid_date($workDate)) $errors[] = '일자를 확인하세요.';
    if ($type === 'daily') {
        if ($dailyLegacy) {
            if ($content === '') $errors[] = '업무내용을 입력하세요.';
        } else {
            $dailyPosted = daily_parse_post();
            if (!daily_count_after((int) $id, $user, $dailyPosted)) $errors[] = "업무내용을 하나 이상 적으세요. 시간대의 '업무내용추가'를 누르면 적을 수 있습니다.";
            $content = (string) ($journal['content'] ?? ''); // 시간대 내용을 모아 저장 후 다시 만든다
        }
        if (valid_date($workDate) && ($dup = daily_find_by_date($workDate, (int) $id))) $errors[] = date('n월 j일', strtotime($workDate)) . ' 업무일지가 이미 있습니다 (문서번호 ' . (int) $dup['id'] . '). 업무일지는 하루에 하나입니다.';
    }

    diag_trace("write $type #$id 입력 확인");
    [$payload, $itemErrors] = items_parse($type, valid_date($workDate) ? $workDate : date('Y-m-d'), $id);
    $errors = [...$errors, ...$itemErrors];
    diag_trace('입력 확인 끝 · 오류 ' . count($errors) . '건' . ($errors ? ': ' . implode(' / ', array_slice($errors, 0, 3)) : ''));

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $teamId = $payload['team_id'] ?? null;
            if ($id) {
                $pdo->prepare('UPDATE journals SET work_date = ?, team_id = ?, weather = ?, content = ?, remarks = ? WHERE id = ?')
                    ->execute([$workDate, $teamId, $weather ?: null, $content, $remarks, $id]);
            } else {
                $pdo->prepare("INSERT INTO journals (type, team_id, work_date, author_id, weather, content, remarks, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft')")
                    ->execute([$type, $teamId, $workDate, $user['id'], $weather ?: null, $content, $remarks]);
                $id = (int) $pdo->lastInsertId();
            }
            diag_trace("문서 #$id 저장 중");
            items_save($id, $type, $payload);
            if ($type === 'daily' && !$dailyLegacy) daily_save($id, $user, $dailyPosted); // 내 시간대 업무내용 (다른 사람 것은 그대로)
            diag_trace('내용 저장 끝');
            // 운영보고 날짜를 바꿨으면 원래 날짜의 매출보고 프로그램 판매도 다시 맞춘다
            if ($journal && is_program_type($type) && $journal['work_date'] !== $workDate) sales_sync_programs($journal['work_date'], PROGRAM_TYPES[$type] . " 운영보고 날짜 변경 {$journal['work_date']} → $workDate (문서 $id)");
            $pdo->commit();
            diag_trace('DB 저장 완료');
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        foreach ($payload['warnings'] ?? [] as $w) flash('확인 필요 · ' . $w, 'error');
        if ($revision) {
            $fresh = journal_find($id);
            $changes = snapshot_diff($before, journal_snapshot($fresh));
            if (!$changes && $reason === '') {
                flash('바뀐 내용이 없어 결재 상태를 그대로 두었습니다.', 'info');
                redirect('view.php?id=' . $id);
            }
            revision_record($journal, $user, $changes, $prevApproval, $reason);
            journal_submit($fresh, (int) $user['rank_level']); // 결재선은 수정한 사람 기준으로 처음부터
            flash('수정했습니다. 결재 상태가 초기화되어 처음부터 다시 결재가 진행됩니다.', 'success');
        } elseif ($submit) {
            journal_submit(journal_find($id), (int) $user['rank_level']); // 결재선은 결재를 올린 사람 기준
            flash('결재를 올렸습니다.', 'success');
        } else {
            flash($canSubmit ? '임시저장했습니다. 결재 올리기를 눌러야 결재가 진행됩니다.' : '임시저장했습니다. 결재 올리기는 공무직 이상이 합니다.', 'info');
        }
        // 작성·수정 기록 (결재와 별개)
        $cplCount = count($payload['complaints'] ?? []);
        $notes = array_filter([$revision && $reason !== '' ? "사유: $reason" : '', $type === 'daily' && $cplCount ? "민원 {$cplCount}건" : '']);
        journal_log($id, $user, $revision ? '수정 (결재 다시 올림)' : ($journal ? ($submit ? '수정 후 결재 올리기' : '임시저장 수정') : ($submit ? '작성 · 결재 올리기' : '작성 (임시저장)')),
            implode(' · ', $notes));
        if ($type === 'sales') unset($_SESSION[SALES_FILL_KEY]);
        if ($type === 'rooms') unset($_SESSION[ROOMS_FILL_KEY]);
        diag_trace('완료 → 보기 화면으로');
        redirect('view.php?id=' . $id);
    }
}

$v = fn(string $k) => e(is_post() ? post($k) : ($journal[$k] ?? ''));
$line = approval_line_for((int) $user['rank_level'], $type);
$canSubmit = can_submit_journal($user, $type);

layout_header(JOURNAL_TYPES[$type] . ($journal ? ' 수정' : ' 작성'), journal_nav_key($type));
if ($type === 'sales') sales_fill_panel($workDate, $journal, $fillRep);
if ($type === 'rooms') rooms_fill_panel($workDate, $journal, $fillRep);
?>
<form method="post" class="card" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <div class="card-head">
    <h1><?= e(JOURNAL_TYPES[$type]) ?> <?= $journal ? '수정' : '작성' ?></h1>
    <?php if (!$canSubmit): ?>
    <span class="muted small">결재 올리기는 공무직 이상이 합니다 (임시저장까지)</span>
    <?php else: ?>
    <span class="muted small">결재선: 작성(<?= e(rank_name($user['rank_level'])) ?>)<?php foreach ($line as $r): ?> → <?= e(rank_name($r)) ?><?php endforeach ?><?= $line ? '' : ' (결재 생략)' ?></span>
    <?php endif ?>
  </div>
  <?php foreach ($errors as $err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endforeach ?>

  <?php if ($revision): ?>
    <div class="flash flash-warn">
      <b>수정 시 결재 상태가 초기화됩니다.</b> 저장하면 지금까지의 결재(<?= e(approval_summary($journal)) ?>)가 취소되고
      수정한 사람(<?= e($user['name']) ?> <?= e(rank_name($user['rank_level'])) ?>) 기준으로 처음부터 다시 결재가 올라갑니다.
      수정 전·후 내용은 <b>수정 이력</b>에 남습니다.
      <?php if ((int) $journal['author_id'] !== (int) $user['id']): ?><br>작성자: <?= e($journal['author_name']) ?><?php endif ?>
    </div>
  <?php endif ?>

  <div class="row">
    <?php if ($type === 'facility'): ?>
      <label>관리팀
        <?php if ($journal): ?>
          <input type="hidden" name="team_id" value="<?= (int) $teamId ?>"><input value="<?= e(team_name($teamId)) ?>" disabled>
        <?php else: ?>
          <select name="team_id" onchange="location.href='?type=facility&date=' + this.form.work_date.value + '&team=' + this.value">
            <?php foreach (facility_teams() as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $t['id'] === (int) ($payload['team_id'] ?? 0) ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach ?>
          </select>
        <?php endif ?>
      </label>
    <?php endif ?>
    <?php if ($type === 'arwork'): ?>
      <label>사용 월<input type="month" name="ar_month" value="<?= e(substr($workDate, 0, 7)) ?>" required
        <?= $journal ? '' : "onchange=\"if (this.value) location.href='?type=arwork&date=' + this.value + '-01'\"" ?>></label>
    <?php else: ?>
    <label><?= $type === 'purchase' ? '구매일' : '일자' ?><input type="date" name="work_date" value="<?= e($workDate) ?>" required></label>
    <?php endif ?>
    <?php if (!in_array($type, NO_WEATHER_TYPES, true)): ?>
    <label>날씨<input name="weather" value="<?= $v('weather') ?>" placeholder="맑음 / 18℃" list="weathers"></label>
    <datalist id="weathers"><option>맑음</option><option>구름많음</option><option>흐림</option><option>비</option><option>눈</option></datalist>
    <?php endif ?>
  </div>

  <?php if ($type === 'daily'): ?>
    <?php if ($dailyLegacy): ?>
    <label>업무내용<textarea name="content" rows="10" required placeholder="- 09:00 입장객 안내&#10;- 10:30 산책로 순찰"><?= $v('content') ?></textarea></label>
    <?php else: daily_form($journal, $user); endif ?>
    <label>특이사항<textarea name="remarks" rows="4" placeholder="사고, 인수인계 사항 등"><?= $v('remarks') ?></textarea></label>
    <?php cpl_form($payload['complaints'] ?? [], $workDate) ?>
  <?php else: ?>
    <?php items_form($type, $payload, $workDate, $journal) ?>
    <?php if ($type === 'sales'): ?>
      <label>메모<textarea name="content" rows="3" placeholder="환불, 정산 차이 등"><?= $v('content') ?></textarea></label>
    <?php elseif ($type === 'rooms'): ?>
      <label>메모<textarea name="content" rows="3" placeholder="입실 취소, 상품권 미지급 객실, 정산 차이 등"><?= $v('content') ?></textarea></label>
    <?php elseif ($type === 'voucher'): ?>
      <label>적요<textarea name="content" rows="3" placeholder="구입처, 구입일, 기초재고 등록 등"><?= $v('content') ?></textarea></label>
    <?php elseif ($type === 'purchase'): ?>
      <label>구매 사유 · 메모<textarea name="content" rows="2" placeholder="예: 객실 전구 교체용, 긴급 구매"><?= $v('content') ?></textarea></label>
    <?php elseif ($type === 'vcheck'): ?>
      <label>차이 사유 <small class="muted">(장부와 실제 매수가 다르면 필수)</small><textarea name="remarks" rows="3" placeholder="예: 5/12 불출 입력 누락 확인"><?= $v('remarks') ?></textarea></label>
      <label>점검 메모<textarea name="content" rows="3" placeholder="점검 시각, 입회자, 점검 방법 등"><?= $v('content') ?></textarea></label>
    <?php else: ?>
      <label>특이사항<textarea name="remarks" rows="4"><?= $v('remarks') ?></textarea></label>
    <?php endif ?>
  <?php endif ?>

  <div class="actions">
    <a class="btn ghost" href="<?= e(url($journal ? 'view.php?id=' . $journal['id'] : journal_list_url($type, $workDate))) ?>">취소</a>
    <?php if ($revision): ?>
      <input name="edit_reason" value="<?= e(post('edit_reason')) ?>" placeholder="수정 사유 (선택)" class="reason-input" maxlength="500">
      <button class="btn primary" name="action" value="submit" onclick="return confirm('결재 상태가 초기화되고 처음부터 다시 결재를 받습니다. 저장할까요?')">
        수정 저장 · <?= $line ? '결재 다시 올리기' : '결재완료' ?></button>
    <?php else: ?>
      <button class="btn" name="action" value="save">임시저장</button>
      <?php if ($canSubmit): ?><button class="btn primary" name="action" value="submit"><?= $line ? '결재 올리기' : '저장(결재완료)' ?></button><?php endif ?>
    <?php endif ?>
  </div>
</form>
<?php if ($journal) render_journal_logs((int) $journal['id']); ?>
<?php layout_footer();
