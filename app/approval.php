<?php
defined('APP_ROOT') || exit;

/**
 * 작성자 직급에 따른 결재선.
 *   사원   → 공무직 → 주무관 → 팀장
 *   공무직 → 주무관 → 팀장
 *   주무관 → 팀장
 *   팀장   → (결재 없이 바로 완료)
 */
function approval_line_for(int $authorRank): array
{
    return match (true) {
        $authorRank >= RANK_LEADER   => [],
        $authorRank === RANK_OFFICER => [RANK_LEADER],
        $authorRank === RANK_WORKER  => [RANK_OFFICER, RANK_LEADER],
        default                      => [RANK_WORKER, RANK_OFFICER, RANK_LEADER],
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

/**
 * 결재 상신(재상신 포함). 기존 결재선은 지우고 처음부터 새로 만든다.
 * $rank: 결재선을 정할 직급 (기본 = 작성자. 다른 사람이 수정했으면 수정한 사람의 직급)
 */
function journal_submit(array $journal, ?int $rank = null): void
{
    $pdo = db();
    $line = approval_line_for($rank ?? (int) $journal['author_rank']);

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

/**
 * 전결 가능 여부: 전결 권한이 있는 주무관이(금고점검은 모든 주무관), 자기 차례의 결재 뒤에 팀장 결재가 남아 있을 때.
 * (팀장 부재 시 주무관 결재로 문서를 최종 완료)
 */
function can_delegate(array $user, ?array $step): bool
{
    if (!$step || (int) $user['rank_level'] !== RANK_OFFICER) return false;
    if (empty($user['can_delegate']) && !in_array(journal_type_of((int) $step['journal_id']), DELEGATE_ANY_OFFICER_TYPES, true)) return false;
    $st = db()->prepare("SELECT COUNT(*) FROM approvals WHERE journal_id = ? AND status = 'waiting' AND step_order > ?");
    $st->execute([$step['journal_id'], $step['step_order']]);
    return (int) $st->fetchColumn() > 0;
}

/** 문서관리 › 문서조회및수정: 공무직 이상·최고관리자는 모든 문서를 조회·수정 (삭제는 최고관리자만) */
function can_manage_docs(array $user): bool
{
    return !empty($user['is_admin']) || (int) $user['rank_level'] >= RANK_WORKER;
}

/** 문서 삭제 (첨부·사진·다음 날 퇴실 인원 정리 포함) */
function journal_delete(array $journal): void
{
    $id = (int) $journal['id'];
    $att = $journal['type'] === 'attendance' ? att_find($id) : null;
    db()->prepare('DELETE FROM journals WHERE id = ?')->execute([$id]);
    if ($att && $att['attachment'] && is_file(APP_ROOT . '/' . $att['attachment'])) @unlink(APP_ROOT . '/' . $att['attachment']);
    if (is_program_type($journal['type'])) { photos_delete_all('program', $id); sales_sync_programs($journal['work_date']); } // 매출보고 프로그램 판매도 다시 맞춤
    if ($journal['type'] === 'rooms') stay_sync_rooms($journal['work_date']); // 그 날 입실·다음 날 퇴실 인원 다시 계산
}

/** 전결 권한 설정과 관계없이 주무관이면 전결할 수 있는 문서 (상품권 금고점검) */
const DELEGATE_ANY_OFFICER_TYPES = ['vcheck'];

function journal_type_of(int $id): string
{
    static $cache = [];
    if (!isset($cache[$id])) {
        $st = db()->prepare('SELECT type FROM journals WHERE id = ?');
        $st->execute([$id]);
        $cache[$id] = (string) $st->fetchColumn();
    }
    return $cache[$id];
}

/** @param string $action approve | reject | delegate(전결) */
function journal_decide(array $journal, array $user, string $action, string $comment): void
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
        if ($action === 'delegate' && !can_delegate($user, $step)) {
            throw new RuntimeException('전결 권한이 없습니다.');
        }

        $pdo->prepare('UPDATE approvals SET status = ?, approver_id = ?, comment = ?, acted_at = NOW() WHERE id = ?')
            ->execute([$action === 'reject' ? 'rejected' : 'approved', $user['id'], $comment ?: null, $step['id']]);

        if ($action === 'reject') {
            $pdo->prepare("UPDATE journals SET status = 'rejected' WHERE id = ?")->execute([$journal['id']]);
        } else {
            if ($action === 'delegate') {
                // 남은 상위 결재(팀장)는 '전결'로 생략 처리
                $pdo->prepare("UPDATE approvals SET status = 'skipped', approver_id = ?, acted_at = NOW() WHERE journal_id = ? AND status = 'waiting'")
                    ->execute([$user['id'], $journal['id']]);
            }
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
