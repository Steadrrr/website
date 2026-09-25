<?php
/**
 * 지역상품권 재고·금고·수불부 (운영관리 › 상품권)
 *   voucher.php?date=2026-09-25&ym=2026-09
 *   - 재고 현황: 금고 / 담당자 보유 / 전체
 *   - 일일 불출·반납: 공무직이 금고에서 꺼내 담당자에게 주는 것(불출), 입실 취소·미지급분을 돌려받는 것(반납).
 *     입력·수정·삭제는 공무직 이상만 (사원은 권한 없음). 그 날의 대조(불출 − 매출보고 지급 − 반납)를 함께 보여준다.
 *   - 수불부: 입고·불출·지급·반납·금고점검 조정과 금고·담당자·전체 잔액
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
require_menu($user, 'ops');
$pdo = db();
$denoms = voucher_denoms();
$canVault = can_vault($user);

$date = valid_date($_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
$ym = $_GET['ym'] ?? substr($date, 0, 7);
if (!preg_match('/^\d{4}-\d{2}$/', $ym) || !valid_date("$ym-01")) $ym = substr($date, 0, 7);
$self = fn(array $q = []) => 'voucher.php?' . http_build_query(array_merge(['date' => $date, 'ym' => $ym], $q));

/** 불출·반납 한 건 (없으면 null) */
function vault_record(int $id): ?array
{
    $st = db()->prepare('SELECT work_date FROM voucher_issues WHERE id = ?');
    $st->execute([$id]);
    $d = $st->fetchColumn();
    return $d ? (vault_records($d, $d)[$id] ?? null) : null;
}

/* ───────────── 불출·반납 저장 / 삭제 (공무직 이상) ───────────── */
if (is_post()) {
    csrf_verify();
    $action = post('action');
    if ($action === 'vault_start') {
        if (empty($user['is_admin'])) abort(403, '최고관리자만 바꿀 수 있습니다.');
        if (valid_date(post('vault_start'))) {
            setting_set('vault_start', post('vault_start'));
            flash('금고 관리 시작일을 ' . post('vault_start') . '로 바꿨습니다.', 'success');
        }
        redirect($self());
    }
    if (!$canVault) abort(403, '상품권 불출·반납은 공무직 이상만 입력할 수 있습니다.');

    if ($action === 'delete_issue') {
        $id = (int) post('id');
        if ($old = vault_record($id)) {
            $pdo->prepare('DELETE FROM voucher_issues WHERE id = ?')->execute([$id]);
            vault_log($id, $old['work_date'], $user, '삭제', ($old['kind'] === 'issue' ? '불출' : '반납') . ' ' . vault_qty_text($old['qty']) . ($old['holder_name'] ? " · {$old['holder_name']}" : ''));
            flash('삭제했습니다.', 'success');
            redirect($self(['date' => $old['work_date']]) . '#day');
        }
        redirect($self());
    }

    if ($action === 'save_issue') {
        $id = (int) post('id');
        $kind = post('kind') === 'return' ? 'return' : 'issue';
        $wd = post('work_date');
        $holder = (int) post('holder_id') ?: null;
        $reason = $kind === 'return' && isset(VAULT_REASONS[post('reason')]) ? post('reason') : null;
        $room = $kind === 'return' ? (mb_substr(trim(post('room')), 0, 100) ?: null) : null;
        $note = mb_substr(trim(post('note')), 0, 300) ?: null;
        $qty = [];
        foreach ($denoms as $d) $qty[$d] = to_int($_POST['qty'][$d] ?? 0);
        $errs = [];
        if (!valid_date($wd)) $errs[] = '일자를 확인하세요.';
        if (!array_sum($qty)) $errs[] = '권종별 매수를 입력하세요.';
        if ($kind === 'issue' && !$holder) $errs[] = '받는 담당자를 고르세요.';
        if ($kind === 'return' && !$reason) $errs[] = '반납 사유(입실 취소 / 미지급)를 고르세요.';
        if ($reason === 'unpaid' && !$room) $errs[] = '미지급 반납은 객실을 입력하세요.';
        if ($errs) {
            flash(implode(' ', $errs), 'error');
            redirect($self(['date' => valid_date($wd) ? $wd : $date] + ($id ? ['edit' => $id] : [])) . '#vault-form');
        }
        $old = $id ? vault_record($id) : null;
        $pdo->beginTransaction();
        if ($old) {
            $pdo->prepare('UPDATE voucher_issues SET work_date = ?, kind = ?, reason = ?, holder_id = ?, room = ?, note = ?, updated_by = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$wd, $kind, $reason, $holder, $room, $note, $user['id'], $id]);
            $pdo->prepare('DELETE FROM voucher_issue_items WHERE issue_id = ?')->execute([$id]);
        } else {
            $pdo->prepare('INSERT INTO voucher_issues (work_date, kind, reason, holder_id, room, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$wd, $kind, $reason, $holder, $room, $note, $user['id']]);
            $id = (int) $pdo->lastInsertId();
        }
        $ins = $pdo->prepare('INSERT INTO voucher_issue_items (issue_id, denom, qty) VALUES (?, ?, ?)');
        foreach ($qty as $d => $q) if ($q > 0) $ins->execute([$id, $d, $q]);
        $pdo->commit();
        $label = $kind === 'issue' ? '불출' : '반납(' . VAULT_REASONS[$reason] . ')';
        vault_log($id, $wd, $user, $old ? '수정' : '입력', "$label " . vault_qty_text($qty)
            . ($old ? ' (이전: ' . vault_qty_text($old['qty']) . ($old['work_date'] !== $wd ? " {$old['work_date']}" : '') . ')' : ''));
        flash("$label " . vault_qty_text($qty) . ' 을(를) 저장했습니다.', 'success');
        redirect($self(['date' => $wd]) . '#day');
    }
    redirect($self());
}

/* ───────────── 현황 ───────────── */
$total = voucher_stock();
$vault = vault_stock();
$holderNow = vault_holder();
$lastCheck = vault_last_check();
$sinceCheck = $lastCheck ? max(0, (int) round((strtotime('today') - strtotime($lastCheck['work_date'])) / 86400)) : null;
$start = vault_start();

// 그 날의 불출·반납 기록
$records = vault_records($date, $date);
$editRow = $canVault && !empty($_GET['edit']) ? vault_record((int) $_GET['edit']) : null;
$users = $pdo->query("SELECT id, name, rank_level FROM users WHERE status = 'active' AND hide_in_org = 0 ORDER BY rank_level, name")->fetchAll();
// 반납 객실 후보: 그 날 매출보고의 객실 + 판매중 객실
$st = $pdo->prepare("SELECT DISTINCT l.name FROM sales_lines l JOIN journals j ON j.id = l.journal_id WHERE " . SALE_DOC_SQL . " AND j.work_date = ? AND l.grp = 'room' ORDER BY l.name");
$st->execute([$date]);
$dayRooms = $st->fetchAll(PDO::FETCH_COLUMN);
$allRooms = array_values(array_map(fn($p) => $p['name'], array_filter(products_all(), fn($p) => $p['grp'] === 'room' && $p['is_active'])));
$st = $pdo->prepare('SELECT g.*, u.name AS user_name FROM voucher_issue_logs g LEFT JOIN users u ON u.id = g.user_id WHERE g.work_date = ? ORDER BY g.id DESC');
$st->execute([$date]);
$logs = $st->fetchAll();

/* ───────────── 수불부 (월) ───────────── */
$first = new DateTimeImmutable("$ym-01");
$last  = $first->modify('last day of this month');
[$f, $l] = [$first->format('Y-m-d'), $last->format('Y-m-d')];
$kindLabel = ['in' => '입고', 'issue' => '불출', 'paid' => '지급', 'return' => '반납', 'check' => '점검조정'];
$ord = array_flip(array_keys($kindLabel));
$zero = array_fill_keys($denoms, 0);
$events = [];
// 입고 · 매출보고 고객 지급
$st = $pdo->prepare('SELECT j.id, j.work_date, j.content, u.name AS author_name, m.direction, m.denom, m.qty
                       FROM voucher_moves m JOIN journals j ON j.id = m.journal_id JOIN users u ON u.id = j.author_id
                      WHERE ' . VOUCHER_COUNTED_SQL . ' AND j.work_date BETWEEN ? AND ?');
$st->execute([$f, $l]);
foreach ($st as $r) {
    $k = $r['direction'] === 'in' ? 'in' : 'paid';
    $key = "j{$r['id']}$k";
    $events[$key] ??= ['kind' => $k, 'date' => $r['work_date'], 'link' => 'view.php?id=' . $r['id'], 'who' => $r['author_name'], 'qty' => $zero,
        'memo' => $k === 'in' ? ($r['content'] ?: '상품권 입고') : '매출보고 고객 지급'];
    $events[$key]['qty'][(int) $r['denom']] += (int) $r['qty'];
}
// 불출 · 반납
foreach (vault_records($f, $l) as $r) {
    $events['v' . $r['id']] = ['kind' => $r['kind'], 'date' => $r['work_date'], 'link' => $self(['date' => $r['work_date']]) . '#day', 'who' => $r['created_name'], 'qty' => $r['qty'],
        'memo' => $r['kind'] === 'issue' ? '→ ' . ($r['holder_name'] ?? '') : VAULT_REASONS[$r['reason']] . ($r['room'] ? " · {$r['room']}" : '') . ($r['holder_name'] ? " ← {$r['holder_name']}" : '')];
}
// 결재완료된 금고점검 조정 (실제 − 장부)
$st = $pdo->prepare("SELECT j.id, j.work_date, u.name AS author_name, c.denom, c.actual_qty - c.book_qty AS n
                       FROM voucher_checks c JOIN journals j ON j.id = c.journal_id JOIN users u ON u.id = j.author_id
                      WHERE j.status = 'approved' AND j.work_date BETWEEN ? AND ?");
$st->execute([$f, $l]);
foreach ($st as $r) {
    $key = "c{$r['id']}";
    $events[$key] ??= ['kind' => 'check', 'date' => $r['work_date'], 'link' => 'view.php?id=' . $r['id'], 'who' => $r['author_name'], 'qty' => $zero, 'memo' => '금고점검'];
    $events[$key]['qty'][(int) $r['denom']] += (int) $r['n'];
}
uasort($events, fn($a, $b) => [$a['date'], $ord[$a['kind']]] <=> [$b['date'], $ord[$b['kind']]]);
$openV = vault_stock($f);
$openH = vault_holder($f);
$openT = voucher_stock($f);

/** 권종별 매수 입력칸 */
$qtyInputs = function (array $qty = []) use ($denoms): void {
    foreach ($denoms as $d): ?>
      <label><?= e(denom_label($d)) ?><input name="qty[<?= $d ?>]" value="<?= !empty($qty[$d]) ? (int) $qty[$d] : '' ?>" class="num short" inputmode="numeric" placeholder="0"></label>
    <?php endforeach;
};
$userSelect = function (string $name, ?int $sel, bool $required) use ($users): void { ?>
  <select name="<?= $name ?>" <?= $required ? 'required' : '' ?>>
    <option value="">- 담당자 -</option>
    <?php foreach ($users as $u): ?><option value="<?= (int) $u['id'] ?>" <?= (int) $u['id'] === $sel ? 'selected' : '' ?>><?= e($u['name']) ?> (<?= e(rank_name($u['rank_level'])) ?>)</option><?php endforeach ?>
  </select>
<?php };

layout_header('상품권 재고', 'voucher');
?>
<section class="card">
  <div class="card-head">
    <h1>지역상품권 재고</h1>
    <div class="actions no-margin no-print">
      <button class="btn ghost" onclick="window.print()">인쇄</button>
      <?php if ($canVault): ?><a class="btn" href="<?= e(url('write.php?type=vcheck')) ?>">금고점검 보고서 작성</a><?php endif ?>
      <?php if ($canVault): ?><a class="btn primary" href="<?= e(url('write.php?type=voucher')) ?>">+ 입고 등록</a><?php endif ?>
    </div>
  </div>
  <div class="table-scroll">
  <table class="table vault-now">
    <thead><tr><th></th><?php foreach ($denoms as $d): ?><th class="right"><?= e(denom_label($d)) ?></th><?php endforeach ?><th class="right">금액</th></tr></thead>
    <tbody>
      <tr><th>🔒 금고</th><?php foreach ($denoms as $d): ?><td class="right"><b><?= number_format($vault[$d]) ?></b>매</td><?php endforeach ?><td class="right"><b><?= e(won(voucher_amount($vault))) ?></b></td></tr>
      <tr class="<?= array_filter($holderNow) ? 'issue' : '' ?>"><th>👤 담당자 보유 <small class="muted">(불출 − 지급 − 반납)</small></th>
        <?php foreach ($denoms as $d): ?><td class="right"><?= $holderNow[$d] ? number_format($holderNow[$d]) . '매' : '-' ?></td><?php endforeach ?><td class="right"><?= e(won(voucher_amount($holderNow))) ?></td></tr>
    </tbody>
    <tfoot><tr><th>전체 재고</th><?php foreach ($denoms as $d): ?><th class="right"><?= number_format($total[$d]) ?>매</th><?php endforeach ?><th class="right"><?= e(won(voucher_amount($total))) ?></th></tr></tfoot>
  </table>
  </div>
  <p class="small <?= $sinceCheck === null || $sinceCheck > 31 ? 'warn' : 'muted' ?>">
    마지막 금고점검: <?= $lastCheck ? '<a href="' . e(url('view.php?id=' . $lastCheck['id'])) . '">' . e($lastCheck['work_date']) . '</a> (' . $sinceCheck . '일 전)' : '없음' ?>
    <?= $sinceCheck === null || $sinceCheck > 31 ? ' — 월 1회 이상 금고의 실제 매수를 확인해 보고하세요.' : '' ?>
    · <a href="<?= e(url('journal.php?type=vcheck')) ?>">금고점검 보고서 목록</a></p>
  <p class="muted small">입고는 상품권입고 문서가 결재완료되면, 고객 지급은 매출보고의 '지역상품권 환급' 입력분이 결재를 올리면 반영됩니다.
    담당자 보유는 하루가 끝나면 0이어야 정상입니다. 금고 관리 시작일(<?= e($start) ?>) 전의 지급분은 금고에서 바로 나간 것으로 봅니다.</p>
  <?php if (!empty($user['is_admin'])): ?>
    <form method="post" class="inline-form no-print"><?= csrf_field() ?><input type="hidden" name="action" value="vault_start">
      <label>금고 관리 시작일 <small class="muted">(최고관리자)</small><input type="date" name="vault_start" value="<?= e($start) ?>"></label><button class="btn small">저장</button></form>
  <?php endif ?>
</section>

<?php
$pending = $pdo->query(
    "SELECT j.id, j.type, j.work_date, j.status, u.name AS author_name,
            (SELECT SUM(m.denom * m.qty) FROM voucher_moves m WHERE m.journal_id = j.id) AS amount
       FROM journals j JOIN users u ON u.id = j.author_id
      WHERE j.type IN ('voucher', 'vcheck') AND j.status IN ('pending', 'rejected')
      ORDER BY j.work_date, j.id"
)->fetchAll();
if ($pending): ?>
<section class="card">
  <h2>결재 대기중인 입고·금고점검</h2>
  <table class="table">
    <thead><tr><th>일자</th><th>구분</th><th>작성자</th><th class="right">금액</th><th>상태</th></tr></thead>
    <tbody>
    <?php foreach ($pending as $p): ?>
      <tr class="clickable" onclick="location.href='<?= e(url('view.php?id=' . $p['id'])) ?>'">
        <td><?= e($p['work_date']) ?></td><td><?= e(JOURNAL_TYPES[$p['type']]) ?></td><td><?= e($p['author_name']) ?></td>
        <td class="right"><?= $p['amount'] ? e(won($p['amount'])) : '' ?></td><td><?= status_badge($p['status']) ?></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</section>
<?php endif ?>

<section class="card" id="day">
  <div class="card-head">
    <h2>일일 불출·반납</h2>
    <div class="cal-nav no-print">
      <a class="btn" href="<?= e(url($self(['date' => date('Y-m-d', strtotime("$date -1 day"))]) . '#day')) ?>">‹</a>
      <strong><?= e(date('n월 j일', strtotime($date))) ?> (<?= weekday_ko($date) ?>)</strong>
      <a class="btn" href="<?= e(url($self(['date' => date('Y-m-d', strtotime("$date +1 day"))]) . '#day')) ?>">›</a>
      <form method="get" class="inline"><input type="hidden" name="ym" value="<?= e($ym) ?>"><input type="date" name="date" value="<?= e($date) ?>" onchange="this.form.submit()"></form>
      <a class="btn ghost small" href="<?= e(url('voucher.php#day')) ?>">오늘</a>
    </div>
  </div>
  <?php vault_day_html($date, 0, true) ?>
  <?php if ($date < $start): ?><p class="muted small">금고 관리 시작일(<?= e($start) ?>) 전 날짜입니다. 이 날의 지급분은 금고에서 바로 나간 것으로 계산됩니다.</p><?php endif ?>

  <div class="table-scroll">
  <table class="table vault-records">
    <thead><tr><th>구분</th><th>담당자</th><th>사유 · 객실</th><th>매수</th><th class="right">금액</th><th>메모</th><th>입력</th><?php if ($canVault): ?><th class="no-print"></th><?php endif ?></tr></thead>
    <tbody>
    <?php foreach ($records as $r): ?>
      <tr class="vk-<?= $r['kind'] ?>">
        <td><b><?= $r['kind'] === 'issue' ? '불출' : '반납' ?></b></td>
        <td><?= e($r['holder_name'] ?? '-') ?></td>
        <td><?= $r['reason'] ? '<span class="badge ' . ($r['reason'] === 'unpaid' ? 'st-rejected' : 'st-pending') . '">' . e(VAULT_REASONS[$r['reason']]) . '</span> ' : '' ?><?= e($r['room'] ?? '') ?></td>
        <td class="small"><?= e(vault_qty_text($r['qty'])) ?></td>
        <td class="right"><?= e(won(voucher_amount($r['qty']))) ?></td>
        <td class="small"><?= e($r['note'] ?? '') ?></td>
        <td class="small muted"><?= e($r['created_name'] ?? '') ?> <?= e(date('H:i', strtotime($r['created_at']))) ?>
          <?= $r['updated_at'] ? '<br>수정 ' . e($r['updated_name'] ?? '') . ' ' . e(date('m/d H:i', strtotime($r['updated_at']))) : '' ?></td>
        <?php if ($canVault): ?><td class="nowrap no-print">
          <a class="btn small" href="<?= e(url($self(['edit' => $r['id']]) . '#vault-form')) ?>">수정</a>
          <form method="post" class="inline" onsubmit="return confirm('이 기록을 삭제할까요?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_issue"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn small danger">삭제</button></form>
        </td><?php endif ?>
      </tr>
    <?php endforeach ?>
    <?php if (!$records): ?><tr><td colspan="8" class="center muted">이 날의 불출·반납 기록이 없습니다.</td></tr><?php endif ?>
    </tbody>
  </table>
  </div>

  <?php if ($canVault): ?>
  <div class="vault-forms no-print" id="vault-form">
    <?php if ($editRow): ?>
      <form method="post" class="vault-form <?= $editRow['kind'] ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_issue"><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>"><input type="hidden" name="kind" value="<?= $editRow['kind'] ?>">
        <h3><?= $editRow['kind'] === 'issue' ? '불출' : '반납' ?> 수정</h3>
        <div class="vault-fields">
          <label>일자<input type="date" name="work_date" value="<?= e($editRow['work_date']) ?>" required></label>
          <label>담당자<?php $userSelect('holder_id', $editRow['holder_id'] ? (int) $editRow['holder_id'] : null, $editRow['kind'] === 'issue') ?></label>
          <?php if ($editRow['kind'] === 'return'): ?>
            <label>사유<select name="reason" required><?php foreach (VAULT_REASONS as $k => $v): ?><option value="<?= $k ?>" <?= $editRow['reason'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach ?></select></label>
            <label>객실<input name="room" value="<?= e($editRow['room'] ?? '') ?>" list="vault-rooms"></label>
          <?php endif ?>
        </div>
        <div class="vault-fields"><?php $qtyInputs($editRow['qty']) ?></div>
        <label>메모<input name="note" value="<?= e($editRow['note'] ?? '') ?>" maxlength="300"></label>
        <div class="actions"><a class="btn ghost" href="<?= e(url($self() . '#day')) ?>">취소</a><button class="btn primary">수정 저장</button></div>
      </form>
    <?php else: ?>
      <form method="post" class="vault-form issue">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_issue"><input type="hidden" name="kind" value="issue"><input type="hidden" name="work_date" value="<?= e($date) ?>">
        <h3>불출 <small class="muted">금고 → 담당자 (<?= e(date('n/j', strtotime($date))) ?>)</small></h3>
        <div class="vault-fields"><label>받는 담당자<?php $userSelect('holder_id', null, true) ?></label></div>
        <div class="vault-fields"><?php $qtyInputs() ?></div>
        <label>메모<input name="note" maxlength="300" placeholder="예: 입실 예정 12실"></label>
        <div class="actions"><button class="btn primary">불출 저장</button></div>
      </form>
      <form method="post" class="vault-form return">
        <?= csrf_field() ?><input type="hidden" name="action" value="save_issue"><input type="hidden" name="kind" value="return"><input type="hidden" name="work_date" value="<?= e($date) ?>">
        <h3>반납 <small class="muted">담당자 → 금고 (<?= e(date('n/j', strtotime($date))) ?>)</small></h3>
        <div class="vault-fields">
          <div class="vault-reason"><span class="label-text">사유</span>
            <span class="reason-opts"><?php foreach (VAULT_REASONS as $k => $v): ?><label class="inline-check"><input type="radio" name="reason" value="<?= $k ?>" required> <?= $v ?></label><?php endforeach ?></span></div>
          <label>객실 <small class="muted">(미지급은 필수)</small><input name="room" list="vault-rooms" placeholder="객실명"></label>
          <label>돌려준 담당자<?php $userSelect('holder_id', null, false) ?></label>
        </div>
        <div class="vault-fields"><?php $qtyInputs() ?></div>
        <label>메모<input name="note" maxlength="300" placeholder="예: 101호 당일 취소"></label>
        <div class="actions"><button class="btn primary">반납 저장</button></div>
      </form>
    <?php endif ?>
    <datalist id="vault-rooms"><?php foreach (array_unique([...$dayRooms, ...$allRooms]) as $rn): ?><option value="<?= e($rn) ?>"><?php endforeach ?></datalist>
  </div>
  <?php else: ?>
    <p class="muted small">불출·반납 입력은 공무직 이상이 합니다.</p>
  <?php endif ?>

  <?php if ($logs): ?>
  <details class="vault-logs"><summary>입력·수정 기록 (<?= count($logs) ?>)</summary>
    <ul><?php foreach ($logs as $g): ?><li><span class="muted"><?= e(date('m/d H:i', strtotime($g['created_at']))) ?></span> <b><?= e($g['user_name'] ?? '') ?></b> <?= e($g['action']) ?> — <?= e($g['note'] ?? '') ?></li><?php endforeach ?></ul>
  </details>
  <?php endif ?>
</section>

<section class="card">
  <div class="card-head">
    <h2>수불부</h2>
    <div class="cal-nav">
      <a class="btn" href="<?= e(url($self(['ym' => $first->modify('-1 month')->format('Y-m')]))) ?>">‹</a>
      <strong><?= e($first->format('Y년 n월')) ?></strong>
      <a class="btn" href="<?= e(url($self(['ym' => $first->modify('+1 month')->format('Y-m')]))) ?>">›</a>
    </div>
  </div>
  <div class="table-scroll">
  <table class="table ledger">
    <thead>
      <tr><th>일자</th><th>구분</th><th>적요</th>
        <?php foreach ($denoms as $d): ?><th class="right"><?= e(denom_label($d)) ?></th><?php endforeach ?>
        <th class="right">금액</th><th class="right">금고</th><th class="right">담당자</th><th class="right">전체 재고</th></tr>
    </thead>
    <tbody>
      <tr class="carry">
        <td><?= e($first->format('m/d')) ?></td><td>이월</td><td>전월 이월 <small class="muted">(전체 재고 매수)</small></td>
        <?php foreach ($denoms as $d): ?><td class="right"><?= number_format($openT[$d]) ?></td><?php endforeach ?>
        <td></td><td class="right"><?= number_format(voucher_amount($openV)) ?></td><td class="right"><?= number_format(voucher_amount($openH)) ?></td><td class="right"><?= number_format(voucher_amount($openT)) ?></td>
      </tr>
      <?php
      [$bV, $bH, $bT] = [$openV, $openH, $openT];
      $sums = array_fill_keys(array_keys($kindLabel), $zero);
      foreach ($events as $ev):
          $k = $ev['kind'];
          $direct = $k === 'paid' && $ev['date'] < $start; // 시작일 전 지급은 금고에서 바로
          foreach ($ev['qty'] as $d => $q) {
              $sums[$k][$d] += $q;
              if (in_array($k, ['in', 'return', 'check'], true)) $bV[$d] += $q;
              if ($k === 'issue' || $direct) $bV[$d] -= $q;
              if ($k === 'issue') $bH[$d] += $q;
              if ($k === 'return' || ($k === 'paid' && !$direct)) $bH[$d] -= $q;
              if (in_array($k, ['in', 'check'], true)) $bT[$d] += $q;
              if ($k === 'paid') $bT[$d] -= $q;
          } ?>
        <tr class="clickable lk-<?= $k ?>" onclick="location.href='<?= e(url($ev['link'])) ?>'">
          <td><?= e(date('m/d', strtotime($ev['date']))) ?></td>
          <td><?= $kindLabel[$k] ?></td>
          <td><?= e(mb_strimwidth((string) $ev['memo'], 0, 40, '…')) ?> <small class="muted"><?= e($ev['who'] ?? '') ?></small></td>
          <?php foreach ($denoms as $d): ?><td class="right"><?= $ev['qty'][$d] ? ($k === 'check' ? sprintf('%+d', $ev['qty'][$d]) : number_format($ev['qty'][$d])) : '' ?></td><?php endforeach ?>
          <td class="right"><?= number_format(voucher_amount($ev['qty'])) ?></td>
          <td class="right"><?= number_format(voucher_amount($bV)) ?></td><td class="right"><?= number_format(voucher_amount($bH)) ?></td><td class="right"><?= number_format(voucher_amount($bT)) ?></td>
        </tr>
      <?php endforeach ?>
      <?php if (!$events): ?><tr><td colspan="<?= count($denoms) + 7 ?>" class="center muted">이 달의 수불 내역이 없습니다.</td></tr><?php endif ?>
    </tbody>
    <tfoot>
      <?php foreach ($kindLabel as $k => $kl): if ($k === 'check' && !array_filter($sums[$k])) continue; ?>
        <tr><th colspan="3"><?= $kl ?> 계</th><?php foreach ($denoms as $d): ?><th class="right"><?= $k === 'check' ? sprintf('%+d', $sums[$k][$d]) : number_format($sums[$k][$d]) ?></th><?php endforeach ?><th class="right"><?= number_format(voucher_amount($sums[$k])) ?></th><th colspan="3"></th></tr>
      <?php endforeach ?>
      <tr class="closing"><th colspan="3">월말 금고</th><?php foreach ($denoms as $d): ?><th class="right"><?= number_format($bV[$d]) ?></th><?php endforeach ?><th></th><th class="right"><?= number_format(voucher_amount($bV)) ?></th><th colspan="2"></th></tr>
      <tr class="closing"><th colspan="3">월말 전체 재고</th><?php foreach ($denoms as $d): ?><th class="right"><?= number_format($bT[$d]) ?></th><?php endforeach ?><th></th><th colspan="2"></th><th class="right"><?= number_format(voucher_amount($bT)) ?></th></tr>
    </tfoot>
  </table>
  </div>
  <p class="muted small">금고 = 입고 − 불출 + 반납 ± 점검조정 · 담당자 = 불출 − 지급 − 반납 · 전체 재고 = 금고 + 담당자.
    처음 사용할 때는 현재 보유 중인 상품권을 '입고 등록'(적요: 기초재고)으로 한 번 올려 결재받으세요.</p>
</section>
<?php layout_footer();
