<?php
defined('APP_ROOT') || exit;

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) return $user;

    $user = null;
    if (!empty($_SESSION['uid'])) {
        $st = db()->prepare("SELECT * FROM users WHERE id = ? AND status = 'active'");
        $st->execute([$_SESSION['uid']]);
        $user = $st->fetch() ?: null;
        if ($user === null) {
            unset($_SESSION['uid']); // 비활성화된 계정은 즉시 로그아웃
        }
    }
    return $user;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        $_SESSION['after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        redirect('login.php');
    }
    return $u;
}

/** 회원 승인/직급 지정 권한: 최고관리자 또는 팀장 */
function can_manage_users(array $u): bool
{
    return (bool) $u['is_admin'] || (int) $u['rank_level'] >= RANK_LEADER;
}

function require_manager(): array
{
    $u = require_login();
    if (!can_manage_users($u)) abort(403, '권한이 없습니다.');
    return $u;
}

/** @return array|string 성공 시 사용자 배열, 실패 시 오류 메시지 */
function attempt_login(string $username, string $password): array|string
{
    $st = db()->prepare('SELECT * FROM users WHERE username = ?');
    $st->execute([$username]);
    $u = $st->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) {
        return '아이디 또는 비밀번호가 올바르지 않습니다.';
    }
    if ($u['status'] === 'pending') return '관리자 승인 대기 중인 계정입니다.';
    if ($u['status'] !== 'active') return '사용이 중지된 계정입니다. 관리자에게 문의하세요.';

    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);

    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id'];
    return $u;
}
