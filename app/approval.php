<?php
defined('APP_ROOT') || exit;

/**
 * 작성자 직급에 따른 결재선.
 *   관리원·공무직 → 주무관 → 팀장
 *   주무관        → 팀장
 *   팀장          → (결재 없이 바로 완료)
 */
function approval_line_for(int $authorRank): array
{
    return match (true) {
        $authorRank >= RANK_LEADER  => [],
        $authorRank === RANK_OFFICER => [RANK_LEADER],
        default                      => [RANK_OFFICER, RANK_LEADER],
    };
}

function journal_find(int $id): ?array
{
    $st = db()->prepare(
        'SELECT j.*, u.name AS author_name, u.rank_level AS author_rank
           FROM journals j JOIN users u ON u.id = j.author_id
          WHERE j.id = ?'
    );
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function journal_approvals(int $journalId): array
{
    $st = db()->prepare(
        'SELECT a.*, u.name AS approver_name
           FROM approvals a LEFT JOIN users u ON u.id = a.approver_id
          WHERE a.journal_id = ? ORDER BY a.step_order'
    );
    $st->execute([$journalId]);
    return $st->fetchAll();
}

/** 결재 상신(재상신 포함). 기존 결재선은 새로 만든다. */
function journal_submit(array $journal): void
{
    $pdo = db();
    $line = approval_line_for((int) $journal['author_rank']);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM approvals WHERE journal_id = ?')->execute([$journal['id']]);
        if ($line === []) {
            $pdo->prepare("UPDATE journals SET status = 'approved', submitted_at = NOW(), completed_at = NOW() WHERE id = ?")
                ->execute([$journal['id']]);
        } else {
            $ins = $pdo->prepare('INSERT INTO approvals (journal_id, step_order, required_rank) VALUES (?, ?, ?)');
            foreach ($line as $i => $rank) {
                $ins->execute([$journal['id'], $i + 1, $rank]);
            }
            $pdo->prepare("UPDATE journals SET status = 'pending', submitted_at = NOW(), completed_at = NULL WHERE id = ?")
                ->execute([$journal['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** 지금 결재 차례인 단계 (없으면 null) */
function journal_current_step(int $journalId): ?array
{
    $st = db()->prepare("SELECT * FROM approvals WHERE journal_id = ? AND status = 'waiting' ORDER BY step_order LIMIT 1");
    $st->execute([$journalId]);
    return $st->fetch() ?: null;
}

/** 이 사용자가 지금 결재할 수 있으면 해당 단계를 반환 */
function approvable_step(array $journal, array $user): ?array
{
    if ($journal['status'] !== 'pending') return null;
    $step = journal_current_step((int) $journal['id']);
    if ($step && (int) $step['required_rank'] === (int) $user['rank_level']) {
        return $step;
    }
    return null;
}

function journal_decide(array $journal, array $user, bool $approve, string $comment): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // 동시에 두 명이 누르는 경우 대비: 행 잠금 후 다시 확인
        $st = $pdo->prepare("SELECT * FROM approvals WHERE journal_id = ? AND status = 'waiting' ORDER BY step_order LIMIT 1 FOR UPDATE");
        $st->execute([$journal['id']]);
        $step = $st->fetch();
        if (!$step || (int) $step['required_rank'] !== (int) $user['rank_level']) {
            throw new RuntimeException('이미 처리되었거나 결재 권한이 없습니다.');
        }

        $pdo->prepare('UPDATE approvals SET status = ?, approver_id = ?, comment = ?, acted_at = NOW() WHERE id = ?')
            ->execute([$approve ? 'approved' : 'rejected', $user['id'], $comment ?: null, $step['id']]);

        if (!$approve) {
            $pdo->prepare("UPDATE journals SET status = 'rejected' WHERE id = ?")->execute([$journal['id']]);
        } else {
            $left = $pdo->prepare("SELECT COUNT(*) FROM approvals WHERE journal_id = ? AND status = 'waiting'");
            $left->execute([$journal['id']]);
            if ((int) $left->fetchColumn() === 0) {
                $pdo->prepare("UPDATE journals SET status = 'approved', completed_at = NOW() WHERE id = ?")->execute([$journal['id']]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** 내 결재 차례인 문서 목록 */
function waiting_for_user(array $user, int $limit = 100): array
{
    $st = db()->prepare(
        "SELECT j.*, u.name AS author_name, u.rank_level AS author_rank
           FROM journals j
           JOIN users u ON u.id = j.author_id
           JOIN approvals a ON a.journal_id = j.id AND a.status = 'waiting'
          WHERE j.status = 'pending'
            AND a.required_rank = ?
            AND a.step_order = (SELECT MIN(a2.step_order) FROM approvals a2
                                 WHERE a2.journal_id = j.id AND a2.status = 'waiting')
          ORDER BY j.work_date, j.id
          LIMIT " . (int) $limit
    );
    $st->execute([$user['rank_level']]);
    return $st->fetchAll();
}
