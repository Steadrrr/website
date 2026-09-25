<?php
defined('APP_ROOT') || exit;

/*
 * 일지 수정 이력.
 * 상신된 일지는 누구나 고칠 수 있고, 고치면 결재가 처음부터 다시 진행된다.
 * 고치기 전·후 내용을 사람이 읽을 수 있는 형태로 비교해 journal_revisions 에 남긴다.
 */

/** 작성·수정 기록 남기기 (결재와 별개) */
function journal_log(int $journalId, ?array $user, string $action, string $note = ''): void
{
    db()->prepare('INSERT INTO journal_logs (journal_id, user_id, action, note) VALUES (?, ?, ?, ?)')
        ->execute([$journalId, $user['id'] ?? null, $action, $note !== '' ? mb_substr($note, 0, 500) : null]);
}

function journal_logs(int $journalId): array
{
    $st = db()->prepare('SELECT l.*, u.name AS user_name, u.rank_level FROM journal_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.journal_id = ? ORDER BY l.id');
    $st->execute([$journalId]);
    return $st->fetchAll();
}

/** 작성·수정 기록 표 */
function render_journal_logs(int $journalId): void
{
    $logs = journal_logs($journalId);
    if (!$logs) return;
    ?>
<section class="card journal-logs">
  <h2>작성·수정 기록 <small class="muted">결재와 별개로 누가 언제 작성·저장했는지 남습니다</small></h2>
  <table class="table">
    <thead><tr><th>일시</th><th>직원</th><th>내용</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
      <tr><td class="nowrap"><?= e(date('Y-m-d H:i:s', strtotime($l['created_at']))) ?></td>
        <td class="nowrap"><?= e($l['user_name'] ?? '-') ?> <small class="muted"><?= $l['rank_level'] ? e(rank_name($l['rank_level'])) : '' ?></small></td>
        <td><b><?= e($l['action']) ?></b><?= $l['note'] ? ' <span class="muted">· ' . e($l['note']) . '</span>' : '' ?></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
</section>
    <?php
}

/** 일지 내용을 비교하기 쉬운 형태로: [항목 => 문자열 | 문자열 목록] */
function journal_snapshot(array $journal): array
{
    $p = items_load($journal);
    $snap = ['일자' => $journal['work_date']];
    if (!in_array($journal['type'], NO_WEATHER_TYPES, true)) $snap['날씨'] = (string) $journal['weather'];
    $contentLabel = ['daily' => '업무내용', 'sales' => '메모', 'rooms' => '메모', 'voucher' => '적요'][$journal['type']] ?? '내용';
    $snap[$contentLabel] = (string) $journal['content'];
    $snap['특이사항'] = (string) $journal['remarks'];

    $vouchersText = fn(array $v) => implode(', ', array_filter(array_map(
        fn($d, $q) => $q ? denom_label((int) $d) . '×' . $q : null, array_keys($v), $v
    )));

    if ($journal['type'] === 'daily') {
        $snap['민원'] = cpl_snapshot((int) $journal['id']);
    }
    if (is_program_type($journal['type'])) {
        $snap += program_snapshot($journal);
    } elseif (in_array($journal['type'], SALE_DOC_TYPES, true)) {
        $lines = [];
        foreach ($p['lines'] as $l) {
            if ($l['grp'] === 'rental') {
                $lines[] = "{$l['name']} · " . rental_desc($l['rent_time'] ?? null, !empty($l['night'])) . ' × ' . $l['qty'] . '건 = ' . number_format($l['amount']) . '원';
            } elseif ($l['grp'] === 'lodge') {
                $lines[] = "{$l['name']} · " . $l['qty'] . '실 × ' . number_format($l['unit_price']) . ' = ' . number_format($l['amount']) . '원';
            } elseif ($l['grp'] === 'program') {
                $lines[] = "프로그램 {$l['name']} · " . ($l['sessions'] ?? 0) . '회 · ' . $l['qty'] . '명 = ' . number_format($l['amount']) . '원' . (!empty($l['auto']) ? ' (운영보고 자동)' : '');
            } elseif ($l['grp'] === 'ticket') {
                $lines[] = "{$l['name']} " . number_format($l['qty']) . '매 × ' . number_format($l['unit_price']) . ' = ' . number_format($l['amount']) . '원';
            } else {
                $refund = $vouchersText($l['vouchers'] ?? []);
                $lines[] = "{$l['name']} · " . (RATE_TYPES[$l['rate']] ?? '') . ($l['discounted'] ? ' · 할인' : '')
                    . ' · ' . $l['guests'] . '명 · ' . number_format($l['amount']) . '원' . ($refund ? " · 환급 $refund" : '');
            }
        }
        $snap['판매 내역'] = $lines;
        if ($journal['type'] === 'sales') {
            $snap['입장권 현금'] = number_format($p['ticket_cash']) . '원';
            $rule = $p['rent_dc_rule'] ?? null;
            $snap['대관 통합 할인'] = $rule ? (RENT_DC_RULES[$rule][0] ?? $rule) . ' ' . (int) $p['rent_dc_pct'] . '%' : '없음';
        }
        $snap[$journal['type'] === 'rooms' ? '객실 매출 합계' : '매출 합계'] = number_format(array_sum(array_column($p['lines'], 'amount'))) . '원';
        if (array_sum($p['vouchers']) > 0) $snap['객실 미지정 환급'] = $vouchersText($p['vouchers']);
    } elseif ($journal['type'] === 'facility') {
        $snap['관리팀'] = team_name($p['team_id']);
        $snap['점검 내역'] = array_map(
            fn($f) => ($f['area'] ? "[{$f['area']}] " : '') . "{$f['facility']}: {$f['result']}" . ($f['note'] !== '' && $f['note'] !== null ? " ({$f['note']})" : ''),
            $p['facility']
        );
    } elseif ($journal['type'] === 'voucher') {
        $snap['입고'] = $vouchersText($p['vouchers']);
    } elseif ($journal['type'] === 'vcheck') {
        $snap['실제 매수'] = implode(', ', array_map(fn($d, $c) => denom_label((int) $d) . ' ' . $c['actual'] . '매 (장부 ' . $c['book'] . ')', array_keys($p['checks']), $p['checks']));
    }
    return $snap;
}

/** @return array<int,array> [['field' => .., 'before' => .., 'after' => ..] | ['field' => .., 'removed' => [..], 'added' => [..]]] */
function snapshot_diff(array $before, array $after): array
{
    $changes = [];
    foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $k) {
        $b = $before[$k] ?? null;
        $a = $after[$k] ?? null;
        if ($b === $a) continue;
        if (is_array($b) || is_array($a)) {
            $removed = array_values(array_diff((array) $b, (array) $a));
            $added = array_values(array_diff((array) $a, (array) $b));
            if ($removed || $added) $changes[] = ['field' => $k, 'removed' => $removed, 'added' => $added];
        } else {
            $changes[] = ['field' => $k, 'before' => (string) $b, 'after' => (string) $a];
        }
    }
    return $changes;
}

/** 초기화되기 전 결재 진행 내역 한 줄 요약 */
function approval_summary(array $journal): string
{
    $parts = [];
    foreach (journal_approvals((int) $journal['id']) as $a) {
        $act = ['approved' => '승인', 'rejected' => '반려', 'skipped' => '전결', 'waiting' => '대기'][$a['status']] ?? $a['status'];
        $parts[] = rank_name($a['required_rank']) . ' ' . ($a['approver_name'] ? $a['approver_name'] . ' ' : '') . $act
            . ($a['acted_at'] ? ' ' . date('m/d', strtotime($a['acted_at'])) : '');
    }
    return (JOURNAL_STATUS[$journal['status']] ?? $journal['status']) . ($parts ? ' · ' . implode(', ', $parts) : '');
}

function revision_record(array $journal, array $user, array $changes, string $prevApproval, string $reason): void
{
    db()->prepare('INSERT INTO journal_revisions (journal_id, user_id, prev_status, prev_approval, reason, changes) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$journal['id'], $user['id'], $journal['status'], mb_substr($prevApproval, 0, 500), $reason ?: null,
            json_encode($changes, JSON_UNESCAPED_UNICODE)]);
    db()->prepare('UPDATE journals SET revision = revision + 1, last_edited_at = NOW(), last_edited_by = ? WHERE id = ?')
        ->execute([$user['id'], $journal['id']]);
}

function journal_revisions(int $journalId): array
{
    $st = db()->prepare(
        'SELECT r.*, u.name AS user_name, u.rank_level FROM journal_revisions r JOIN users u ON u.id = r.user_id
          WHERE r.journal_id = ? ORDER BY r.id DESC'
    );
    $st->execute([$journalId]);
    return $st->fetchAll();
}

/** 일지 상세 화면의 수정 이력 */
function render_revisions(array $revisions): void
{
    if (!$revisions) return;
    ?>
<section class="card revisions">
  <h2>수정 이력 <small class="muted"><?= count($revisions) ?>회</small></h2>
  <?php foreach ($revisions as $i => $r): $changes = json_decode((string) $r['changes'], true) ?: []; ?>
    <details <?= $i === 0 ? 'open' : '' ?>>
      <summary>
        <b><?= e(date('Y-m-d H:i', strtotime($r['edited_at']))) ?></b>
        <?= e($r['user_name']) ?> <small class="muted"><?= e(rank_name($r['rank_level'])) ?></small> 수정
        · <?= count($changes) ?>개 항목
        <?= $r['reason'] ? ' · 사유: ' . e($r['reason']) : '' ?>
      </summary>
      <p class="muted small">수정 전 결재 상태: <?= e($r['prev_approval'] ?: (JOURNAL_STATUS[$r['prev_status']] ?? $r['prev_status'])) ?> → 초기화 후 처음부터 다시 결재</p>
      <table class="table diff-table">
        <thead><tr><th>항목</th><th>수정 전</th><th>수정 후</th></tr></thead>
        <tbody>
        <?php foreach ($changes as $c): ?>
          <tr>
            <th><?= e($c['field']) ?></th>
            <?php if (isset($c['removed'])): ?>
              <td class="del"><?= $c['removed'] ? implode('<br>', array_map(fn($x) => '− ' . e($x), $c['removed'])) : '<span class="muted">(없음)</span>' ?></td>
              <td class="ins"><?= $c['added'] ? implode('<br>', array_map(fn($x) => '+ ' . e($x), $c['added'])) : '<span class="muted">(없음)</span>' ?></td>
            <?php else: ?>
              <td class="del pre-cell"><?= $c['before'] !== '' ? e($c['before']) : '<span class="muted">(비어 있음)</span>' ?></td>
              <td class="ins pre-cell"><?= $c['after'] !== '' ? e($c['after']) : '<span class="muted">(비어 있음)</span>' ?></td>
            <?php endif ?>
          </tr>
        <?php endforeach ?>
        <?php if (!$changes): ?><tr><td colspan="3" class="muted center">내용 변경 없음</td></tr><?php endif ?>
        </tbody>
      </table>
    </details>
  <?php endforeach ?>
</section>
    <?php
}
