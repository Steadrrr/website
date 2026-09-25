<?php
/** 결재함: 내 결재 차례 문서 + 내가 올린 문서 진행현황 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();

// 일괄 결재: 체크한 문서를 한 번에 승인 또는 전결
if (is_post()) {
    csrf_verify();
    $action = post('action');
    $ids = array_unique(array_map('intval', (array) ($_POST['ids'] ?? [])));
    if (!in_array($action, ['approve', 'delegate'], true) || !$ids) {
        flash('결재할 문서를 체크하세요.', 'error');
        redirect('approvals.php');
    }
    $comment = mb_substr(post('comment'), 0, 500);
    $done = 0;
    $skipped = [];
    foreach ($ids as $id) {
        $j = journal_find($id);
        $label = $j ? journal_type_label($j) . ' ' . $j['work_date'] : "문서 $id";
        $step = $j ? approvable_step($j, $user) : null;
        if (!$step) { $skipped[] = "$label (결재 차례 아님)"; continue; }
        if ($action === 'delegate' && !can_delegate($user, $step)) { $skipped[] = "$label (전결 불가)"; continue; }
        try {
            journal_decide($j, $user, $action, $comment);
            $done++;
        } catch (RuntimeException $e) {
            $skipped[] = "$label (" . $e->getMessage() . ')';
        }
    }
    $verb = $action === 'delegate' ? '전결' : '승인';
    if ($done) flash("{$done}건을 {$verb}했습니다.", 'success');
    if ($skipped) flash('처리하지 못한 문서 ' . count($skipped) . '건: ' . implode(', ', $skipped), 'error');
    redirect('approvals.php');
}

$waiting = waiting_for_user($user);
// 이 사용자가 전결할 수 있는 문서 (전결권한 주무관 + 뒤에 팀장 결재가 남은 것)
$delegatable = [];
foreach ($waiting as $j) {
    if (can_delegate($user, approvable_step($j, $user))) $delegatable[(int) $j['id']] = true;
}

$st = db()->prepare(
    "SELECT j.* FROM journals j
      WHERE j.author_id = ? AND j.status IN ('draft', 'pending', 'rejected')
      ORDER BY j.work_date DESC, j.id DESC LIMIT 50"
);
$st->execute([$user['id']]);
$mine = $st->fetchAll();

layout_header('결재함', 'approval');
?>
<form method="post" class="card" id="bulkForm">
  <?= csrf_field() ?>
  <div class="card-head">
    <h1>결재 대기 <small class="muted">(<?= e(rank_name($user['rank_level'])) ?> 결재 차례 · <?= count($waiting) ?>건)</small></h1>
  </div>
  <?php if ($waiting): ?>
  <div class="bulk-bar no-print">
    <button type="button" class="btn small" data-select-all>전체선택</button>
    <span class="muted small" data-selected-count>0건 선택</span>
    <input name="comment" maxlength="500" placeholder="결재 의견 (선택, 체크한 문서 모두에 남음)" class="bulk-comment">
    <button class="btn primary" name="action" value="approve" data-bulk
            onclick="return confirm('체크한 ' + this.form.querySelectorAll('[name=\'ids[]\']:checked').length + '건을 한 번에 승인할까요?')">선택 문서 승인</button>
    <?php if ($delegatable): ?>
      <button class="btn" name="action" value="delegate" data-bulk
              onclick="return confirm('체크한 문서를 팀장 결재 없이 전결로 최종 완료합니다. 진행할까요?\n(전결할 수 없는 문서는 건너뜁니다)')">선택 문서 전결</button>
    <?php endif ?>
  </div>
  <?php endif ?>
  <table class="table">
    <thead><tr><th class="check-col"><input type="checkbox" data-check-all title="전체선택" <?= $waiting ? '' : 'disabled' ?>></th><th>일자</th><th>구분</th><th>작성자</th><th>상신일시</th><?php if ($delegatable): ?><th>전결</th><?php endif ?></tr></thead>
    <tbody>
    <?php foreach ($waiting as $j): ?>
      <tr class="clickable" onclick="if (!event.target.closest('.check-col')) location.href='<?= e(url('view.php?id=' . $j['id'])) ?>'">
        <td class="check-col"><input type="checkbox" name="ids[]" value="<?= (int) $j['id'] ?>" data-row-check></td>
        <td><?= e($j['work_date']) ?></td>
        <td><?= e(journal_type_label($j)) ?><?= $j['revision'] ? ' <span class="badge st-edited">수정됨</span>' : '' ?>
          <?= $j['type'] === 'rooms' && vault_day_issue($j['work_date'], (int) $j['id']) ? ' <span class="badge st-rejected" title="불출 − 지급 − 반납 차이가 있습니다">⚠ 상품권 대조</span>' : '' ?></td>
        <td><?= e($j['author_name']) ?> <small class="muted"><?= e(rank_name($j['author_rank'])) ?></small></td>
        <td><?= e($j['submitted_at']) ?></td>
        <?php if ($delegatable): ?><td class="small"><?= isset($delegatable[(int) $j['id']]) ? '가능' : '<span class="muted">-</span>' ?></td><?php endif ?>
      </tr>
    <?php endforeach ?>
    <?php if (!$waiting): ?><tr><td colspan="6" class="center muted">결재할 문서가 없습니다.</td></tr><?php endif ?>
    </tbody>
  </table>
  <?php if ($waiting): ?><p class="muted small no-print">체크박스로 여러 문서를 골라 한 번에 승인할 수 있습니다. 반려는 사유가 필요하므로 문서를 열어서 하나씩 처리하세요.</p><?php endif ?>
</form>
<script>
(function () {
  const form = document.getElementById('bulkForm');
  if (!form) return;
  const rows = [...form.querySelectorAll('[data-row-check]')];
  const all = form.querySelector('[data-check-all]');
  const btnAll = form.querySelector('[data-select-all]');
  const sync = () => {
    const n = rows.filter((c) => c.checked).length;
    const cnt = form.querySelector('[data-selected-count]');
    if (cnt) cnt.textContent = n + '건 선택';
    all.checked = n > 0 && n === rows.length;
    all.indeterminate = n > 0 && n < rows.length;
    form.querySelectorAll('[data-bulk]').forEach((b) => { b.disabled = n === 0; });
    if (btnAll) btnAll.textContent = n === rows.length && n ? '선택 해제' : '전체선택';
  };
  const setAll = (v) => { rows.forEach((c) => { c.checked = v; }); sync(); };
  all.addEventListener('change', () => setAll(all.checked));
  if (btnAll) btnAll.addEventListener('click', () => setAll(!(rows.length && rows.every((c) => c.checked))));
  rows.forEach((c) => c.addEventListener('change', sync));
  sync();
})();
</script>

<section class="card">
  <h2>내가 작성한 문서 (진행중·반려·임시저장)</h2>
  <table class="table">
    <thead><tr><th>일자</th><th>구분</th><th>상태</th></tr></thead>
    <tbody>
    <?php foreach ($mine as $j): ?>
      <tr class="clickable" onclick="location.href='<?= e(url('view.php?id=' . $j['id'])) ?>'">
        <td><?= e($j['work_date']) ?></td>
        <td><?= e(journal_type_label($j)) ?></td>
        <td><?= journal_badges($j) ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$mine): ?><tr><td colspan="3" class="center muted">없음</td></tr><?php endif ?>
    </tbody>
  </table>
</section>
<?php layout_footer();
