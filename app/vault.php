<?php
defined('APP_ROOT') || exit;

/*
 * 상품권 금고 관리
 *   금고        = 입고 − 불출 + 반납 (+ 금고점검 조정)
 *   담당자 보유 = 불출 − 고객 지급(매출보고) − 반납     … 하루가 끝나면 0 이어야 정상
 *   전체 재고   = 금고 + 담당자 보유 = voucher_stock()
 * 금고 관리를 시작한 날(settings vault_start) 전의 매출보고 지급분은 금고에서 바로 나간 것으로 본다.
 */

const VAULT_REASONS = ['cancel' => '입실 취소', 'unpaid' => '미지급'];

/** 금고 관리 시작일 */
function vault_start(): string
{
    return setting('vault_start') ?? date('Y-m-d');
}

/** 불출·반납을 입력·수정할 수 있는가: 공무직 이상과 최고관리자 (사원은 권한을 받을 수 없음) */
function can_vault(array $u): bool
{
    return !empty($u['is_admin']) || (int) $u['rank_level'] >= RANK_WORKER;
}

/** 결재완료된 금고점검의 권종별 조정 매수 (실제 − 장부). $before 를 주면 그 날짜 전까지 */
function vault_check_adjust(?string $before = null): array
{
    $out = [];
    try {
        $st = db()->prepare("SELECT c.denom, SUM(c.actual_qty - c.book_qty) AS n FROM voucher_checks c JOIN journals j ON j.id = c.journal_id
                              WHERE j.status = 'approved'" . ($before ? ' AND j.work_date < ?' : '') . ' GROUP BY c.denom');
        $st->execute($before ? [$before] : []);
        foreach ($st as $r) $out[(int) $r['denom']] = (int) $r['n'];
    } catch (PDOException) {
        // 업그레이드 전
    }
    return $out;
}

/** 권종별 불출(issue) 또는 반납(return) 합계. $from ≤ 일자 < $before */
function vault_moves(string $kind, ?string $before = null, ?string $from = null, ?string $reason = null): array
{
    $out = array_fill_keys(voucher_denoms(), 0);
    $sql = 'SELECT i.denom, SUM(i.qty) AS n FROM voucher_issue_items i JOIN voucher_issues v ON v.id = i.issue_id WHERE v.kind = ?';
    $args = [$kind];
    if ($before) { $sql .= ' AND v.work_date < ?'; $args[] = $before; }
    if ($from) { $sql .= ' AND v.work_date >= ?'; $args[] = $from; }
    if ($reason) { $sql .= ' AND v.reason = ?'; $args[] = $reason; }
    $st = db()->prepare($sql . ' GROUP BY i.denom');
    $st->execute($args);
    foreach ($st as $r) $out[(int) $r['denom']] = (int) $r['n'];
    return $out;
}

/** 매출보고 고객 지급(객실 상품권 환급) 합계. $from ≤ 일자 < $before, 임시저장 제외 ($includeId 는 임시저장이라도 포함) */
function vault_paid(?string $before = null, ?string $from = null, int $includeId = 0): array
{
    $out = array_fill_keys(voucher_denoms(), 0);
    $sql = "SELECT m.denom, SUM(m.qty) AS n FROM voucher_moves m JOIN journals j ON j.id = m.journal_id
             WHERE " . SALE_DOC_SQL . " AND m.direction = 'out' AND (j.status <> 'draft' OR j.id = ?)";
    $args = [$includeId];
    if ($before) { $sql .= ' AND j.work_date < ?'; $args[] = $before; }
    if ($from) { $sql .= ' AND j.work_date >= ?'; $args[] = $from; }
    $st = db()->prepare($sql . ' GROUP BY m.denom');
    $st->execute($args);
    foreach ($st as $r) $out[(int) $r['denom']] = (int) $r['n'];
    return $out;
}

/** 담당자 보유 매수 (불출 − 지급 − 반납). $before 를 주면 그 날짜 전까지 */
function vault_holder(?string $before = null): array
{
    $issue = vault_moves('issue', $before);
    $ret = vault_moves('return', $before);
    $paid = vault_paid($before, vault_start());
    $out = [];
    foreach (voucher_denoms() as $d) $out[$d] = $issue[$d] - $paid[$d] - $ret[$d];
    return $out;
}

/** 금고 재고 매수 = 전체 재고 − 담당자 보유 */
function vault_stock(?string $before = null): array
{
    $total = voucher_stock($before);
    $holder = vault_holder($before);
    $out = [];
    foreach (voucher_denoms() as $d) $out[$d] = ($total[$d] ?? 0) - $holder[$d];
    return $out;
}

/**
 * 하루 상품권 대조: 불출 − 고객 지급 − 반납(취소·미지급) = 차이 (0 이면 정상)
 * @return array{active: bool, issue: array, paid: array, cancel: array, unpaid: array, diff: array, ok: bool, has: bool,
 *               records: array, mismatch: array}
 */
function vault_day(string $date, int $includeJournalId = 0): array
{
    $next = date('Y-m-d', strtotime("$date +1 day"));
    $r = [
        'active' => $date >= vault_start(),
        'issue'  => vault_moves('issue', $next, $date),
        'paid'   => vault_paid($next, $date, $includeJournalId),
        'cancel' => vault_moves('return', $next, $date, 'cancel'),
        'unpaid' => vault_moves('return', $next, $date, 'unpaid'),
    ];
    $r['diff'] = [];
    foreach (voucher_denoms() as $d) $r['diff'][$d] = $r['issue'][$d] - $r['paid'][$d] - $r['cancel'][$d] - $r['unpaid'][$d];
    $r['ok'] = !array_filter($r['diff']);
    $r['has'] = (bool) array_filter([...$r['issue'], ...$r['paid'], ...$r['cancel'], ...$r['unpaid']]);
    $r['records'] = vault_records($date, $date);
    // 매출보고에서 환급액이 기준과 다른 객실 (기록 실수인지 실제로 못 준 것인지 확인용)
    $st = db()->prepare("SELECT l.id, l.name, l.refund_expected, COALESCE(SUM(m.denom * m.qty), 0) AS refund
                           FROM sales_lines l JOIN journals j ON j.id = l.journal_id
                           LEFT JOIN voucher_moves m ON m.line_id = l.id AND m.direction = 'out'
                          WHERE " . SALE_DOC_SQL . " AND j.work_date = ? AND l.grp = 'room' AND l.refund_expected IS NOT NULL AND (j.status <> 'draft' OR j.id = ?)
                          GROUP BY l.id HAVING refund <> l.refund_expected");
    $st->execute([$date, $includeJournalId]);
    $r['mismatch'] = $st->fetchAll();
    return $r;
}

/** 기간의 불출·반납 기록 (권종별 매수 포함) */
function vault_records(string $from, string $to): array
{
    $st = db()->prepare('SELECT v.*, h.name AS holder_name, c.name AS created_name, u.name AS updated_name
                           FROM voucher_issues v
                           LEFT JOIN users h ON h.id = v.holder_id LEFT JOIN users c ON c.id = v.created_by LEFT JOIN users u ON u.id = v.updated_by
                          WHERE v.work_date BETWEEN ? AND ? ORDER BY v.work_date, v.kind = \'return\', v.id');
    $st->execute([$from, $to]);
    $rows = [];
    foreach ($st as $r) { $r['qty'] = array_fill_keys(voucher_denoms(), 0); $rows[(int) $r['id']] = $r; }
    if ($rows) {
        $items = db()->query('SELECT * FROM voucher_issue_items WHERE issue_id IN (' . implode(',', array_keys($rows)) . ')');
        foreach ($items as $i) $rows[(int) $i['issue_id']]['qty'][(int) $i['denom']] = (int) $i['qty'];
    }
    return $rows;
}

function vault_log(?int $issueId, string $date, array $user, string $action, string $note = ''): void
{
    db()->prepare('INSERT INTO voucher_issue_logs (issue_id, work_date, user_id, action, note) VALUES (?, ?, ?, ?, ?)')
        ->execute([$issueId, $date, $user['id'], $action, mb_substr($note, 0, 500) ?: null]);
}

/** "5천원권 3 · 1만원권 2" */
function vault_qty_text(array $qty): string
{
    $parts = [];
    foreach ($qty as $d => $n) if ($n) $parts[] = denom_label((int) $d) . ' ' . number_format($n);
    return $parts ? implode(' · ', $parts) : '0';
}

/** 마지막 결재완료 금고점검 (없으면 null) */
function vault_last_check(): ?array
{
    try {
        $r = db()->query("SELECT id, work_date FROM journals WHERE type = 'vcheck' AND status = 'approved' ORDER BY work_date DESC, id DESC LIMIT 1")->fetch();
    } catch (PDOException) {
        return null;
    }
    return $r ?: null;
}

/* ───────────── 매출보고 보기·결재 화면의 상품권 대조 ───────────── */

function vault_day_html(string $date, int $journalId = 0, bool $compact = false): void
{
    $v = vault_day($date, $journalId);
    if (!$v['active'] || (!$v['has'] && !$v['mismatch'])) return;
    $denoms = voucher_denoms();
    $noIssue = !array_sum($v['issue']) && array_sum($v['paid']);
    $unpaidRooms = array_filter(array_map(fn($r) => $r['reason'] === 'unpaid' ? trim(($r['room'] ?: '객실 미기재') . ' ' . vault_qty_text($r['qty'])) : null, $v['records']));
    ?>
<section class="vault-check <?= $v['ok'] && !$noIssue ? 'ok' : 'bad' ?>">
  <h3>상품권 대조 <small class="muted"><?= e($date) ?> · 불출 − 고객 지급 − 반납 = 차이</small>
    <?= $v['ok'] && !$noIssue ? '<span class="badge st-approved">일치</span>' : '<span class="badge st-rejected">확인 필요</span>' ?></h3>
  <div class="table-scroll">
  <table class="table vault-table">
    <thead><tr><th></th><?php foreach ($denoms as $d): ?><th class="right"><?= e(denom_label($d)) ?></th><?php endforeach ?><th class="right">금액</th></tr></thead>
    <tbody>
    <?php foreach (['issue' => '불출 (금고 → 담당자)', 'paid' => '고객 지급 (매출보고)', 'cancel' => '반납 · 입실 취소', 'unpaid' => '반납 · 미지급'] as $k => $label):
        if (in_array($k, ['cancel', 'unpaid'], true) && !array_sum($v[$k])) continue; ?>
      <tr><td><?= e($label) ?></td><?php foreach ($denoms as $d): ?><td class="right"><?= $v[$k][$d] ? number_format($v[$k][$d]) : '' ?></td><?php endforeach ?>
        <td class="right"><?= number_format(voucher_amount($v[$k])) ?></td></tr>
    <?php endforeach ?>
    </tbody>
    <tfoot><tr class="<?= $v['ok'] ? '' : 'issue' ?>"><th>차이 <small class="muted">(0 이면 정상)</small></th>
      <?php foreach ($denoms as $d): ?><th class="right <?= $v['diff'][$d] ? 'warn' : '' ?>"><?= $v['diff'][$d] ? sprintf('%+d', $v['diff'][$d]) : '0' ?></th><?php endforeach ?>
      <th class="right <?= $v['ok'] ? '' : 'warn' ?>"><?= e(won(voucher_amount($v['diff']))) ?></th></tr></tfoot>
  </table>
  </div>
  <?php if ($noIssue): ?><p class="warn small">⚠ 이 날 불출 기록이 없습니다. 운영관리 › 상품권관리에서 불출을 입력했는지 확인하세요.</p><?php endif ?>
  <?php if (!$v['ok'] && !$noIssue): ?><p class="warn small">⚠ <?= array_sum($v['diff']) > 0 ? '불출한 상품권 중 지급·반납으로 확인되지 않은 것이 있습니다 (담당자 보유 또는 반납 누락).' : '불출보다 많이 지급·반납되었습니다 (불출 입력 누락 또는 매출보고 환급 입력 확인).' ?></p><?php endif ?>
  <?php if ($unpaidRooms): ?><p class="small">미지급 반납: <b><?= e(implode(', ', $unpaidRooms)) ?></b></p><?php endif ?>
  <?php if ($v['mismatch']): ?><p class="small">매출보고 환급액이 기준과 다른 객실:
    <?= e(implode(', ', array_map(fn($m) => $m['name'] . ' (' . number_format($m['refund']) . ' / 기준 ' . number_format($m['refund_expected']) . ')', $v['mismatch']))) ?></p><?php endif ?>
  <?php if (!$compact): ?><p class="muted small no-print"><a href="<?= e(url('voucher.php?date=' . $date)) ?>">이 날 불출·반납 기록 보기 ›</a></p><?php endif ?>
</section>
    <?php
}

/** 결재함 표시용: 그 날 대조에 차이가 있는가 */
function vault_day_issue(string $date, int $journalId = 0): bool
{
    $v = vault_day($date, $journalId);
    return $v['active'] && $v['has'] && (!$v['ok'] || (!array_sum($v['issue']) && array_sum($v['paid'])));
}

/* ───────────── 상품권 금고점검 보고서 (journals type vcheck) ───────────── */

function vcheck_load(int $journalId): array
{
    $out = [];
    $st = db()->prepare('SELECT * FROM voucher_checks WHERE journal_id = ?');
    $st->execute([$journalId]);
    foreach ($st as $r) $out[(int) $r['denom']] = ['book' => (int) $r['book_qty'], 'actual' => (int) $r['actual_qty']];
    return $out;
}

/** 점검일(그 날 불출·반납·지급까지 반영) 장부상 금고 매수. 이 보고서 자신의 조정은 빼고 계산 */
function vcheck_book(string $date, int $journalId = 0): array
{
    $book = vault_stock(date('Y-m-d', strtotime("$date +1 day")));
    if ($journalId) {
        $st = db()->prepare("SELECT c.denom, c.actual_qty - c.book_qty AS n FROM voucher_checks c JOIN journals j ON j.id = c.journal_id
                              WHERE c.journal_id = ? AND j.status = 'approved' AND j.work_date <= ?");
        $st->execute([$journalId, $date]);
        foreach ($st as $r) $book[(int) $r['denom']] -= (int) $r['n'];
    }
    return $book;
}

function vcheck_form(array $payload, string $workDate, ?array $journal): void
{
    $book = vcheck_book($workDate, (int) ($journal['id'] ?? 0));
    $holder = vault_holder(date('Y-m-d', strtotime("$workDate +1 day")));
    $last = vault_last_check();
    ?>
<p class="muted small">점검일의 불출·반납·매출보고 지급까지 반영한 <b>장부상 금고 매수</b>입니다. 금고의 상품권을 권종별로 세어 <b>실제 매수</b>를 입력하세요.
  결재가 끝나면 차이(실제 − 장부)만큼 재고가 맞춰집니다.
  <?= $last ? '마지막 점검: <a href="' . e(url('view.php?id=' . $last['id'])) . '">' . e($last['work_date']) . '</a>' : '아직 결재완료된 점검이 없습니다.' ?></p>
<?php if (!$journal): ?><script>document.addEventListener('change', (ev) => { if (ev.target.name === 'work_date' && ev.target.value) location.href = '?type=vcheck&date=' + ev.target.value; });</script><?php endif ?>
<div class="table-scroll">
<table class="table vcheck-table" data-vcheck>
  <thead><tr><th>권종</th><th class="right">장부상 금고</th><th>실제 매수</th><th class="right">차이</th><th class="right">차이 금액</th><th class="right muted">담당자 보유</th></tr></thead>
  <tbody>
  <?php foreach (voucher_denoms() as $d): $a = $payload['checks'][$d]['actual'] ?? null; ?>
    <tr data-denom="<?= $d ?>" data-book="<?= $book[$d] ?>">
      <td><?= e(denom_label($d)) ?></td>
      <td class="right"><?= number_format($book[$d]) ?>매</td>
      <td><input name="vc_actual[<?= $d ?>]" value="<?= e(is_post() ? (string) ($_POST['vc_actual'][$d] ?? '') : ($a === null ? '' : (string) $a)) ?>" class="num short" inputmode="numeric" required data-actual></td>
      <td class="right" data-diff></td><td class="right" data-diff-amt></td>
      <td class="right muted"><?= $holder[$d] ? number_format($holder[$d]) . '매' : '-' ?></td>
    </tr>
  <?php endforeach ?>
  </tbody>
  <tfoot><tr><th>합계</th><th class="right"><?= e(won(voucher_amount($book))) ?></th><th data-actual-amt></th><th></th><th class="right" data-diff-total></th><th></th></tr></tfoot>
</table>
</div>
<script>
(function () {
  const t = document.querySelector('[data-vcheck]');
  const n = (v) => parseInt(String(v).replace(/\D/g, ''), 10) || 0;
  const f = (v) => v.toLocaleString('ko-KR');
  function calc() {
    let amt = 0, dAmt = 0;
    t.querySelectorAll('tbody tr').forEach((tr) => {
      const inp = tr.querySelector('[data-actual]'), d = n(tr.dataset.denom), book = n(tr.dataset.book);
      const filled = inp.value.trim() !== '', diff = n(inp.value) - book;
      tr.querySelector('[data-diff]').textContent = filled ? (diff > 0 ? '+' : '') + f(diff) : '';
      tr.querySelector('[data-diff-amt]').textContent = filled && diff ? f(diff * d) + '원' : '';
      tr.classList.toggle('issue', filled && diff !== 0);
      amt += n(inp.value) * d; dAmt += filled ? diff * d : 0;
    });
    t.querySelector('[data-actual-amt]').textContent = f(amt) + '원';
    t.querySelector('[data-diff-total]').textContent = dAmt ? f(dAmt) + '원' : '0';
  }
  t.addEventListener('input', calc); calc();
})();
</script>
    <?php
}

function vcheck_view(array $journal, array $checks): void
{
    $diffAmt = 0;
    $bookQ = array_map(fn($c) => $c['book'], $checks);
    $actQ = array_map(fn($c) => $c['actual'], $checks);
    ?>
<div class="table-scroll">
<table class="table vcheck-table">
  <thead><tr><th>권종</th><th class="right">장부상 금고</th><th class="right">실제 매수</th><th class="right">차이</th><th class="right">차이 금액</th></tr></thead>
  <tbody>
  <?php foreach ($checks as $d => $c): $diff = $c['actual'] - $c['book']; $diffAmt += $diff * $d; ?>
    <tr class="<?= $diff ? 'issue' : '' ?>"><td><?= e(denom_label($d)) ?></td><td class="right"><?= number_format($c['book']) ?>매</td>
      <td class="right"><b><?= number_format($c['actual']) ?>매</b></td>
      <td class="right <?= $diff ? 'warn' : '' ?>"><?= $diff ? sprintf('%+d', $diff) : '0' ?></td><td class="right"><?= $diff ? e(won($diff * $d)) : '' ?></td></tr>
  <?php endforeach ?>
  </tbody>
  <tfoot><tr><th>합계</th><th class="right"><?= e(won(voucher_amount($bookQ))) ?></th>
    <th class="right"><?= e(won(voucher_amount($actQ))) ?></th><th></th>
    <th class="right <?= $diffAmt ? 'warn' : '' ?>"><?= $diffAmt ? e(won($diffAmt)) : '일치' ?></th></tr></tfoot>
</table>
</div>
<p class="muted small"><?= $journal['status'] === 'approved'
    ? ($diffAmt ? '결재완료 — 차이만큼 상품권 재고(금고)에 반영되었습니다.' : '결재완료 — 장부와 실제가 일치합니다.')
    : '결재가 끝나면 차이(실제 − 장부)만큼 재고(금고)가 맞춰집니다.' ?></p>
    <?php
}
