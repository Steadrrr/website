<?php
defined('APP_ROOT') || exit;

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) return $user;

    $user = null;
    if (empty($_SESSION['uid'])) remember_login(); // 자동 로그인 쿠키가 있으면 세션을 다시 만든다
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

/** 회원별로 켜고 끌 수 있는 메인메뉴 (회원관리에서 설정) */
const MENU_OPTIONAL = ['att' => '근태관리', // 개인업무 › 근태 달력·개인 월간 근태표
     'ops' => '운영관리', 'room' => '객실관리', 'prog' => '프로그램', 'stat' => '통계'];

/** 이 메인메뉴를 볼 수 있는가. 최고관리자·공무직 이상과 설정 전(NULL) 회원은 전부 (메뉴 권한은 사원에게만 적용) */
function can_menu(?array $u, string $group): bool
{
    if (!$u || !isset(MENU_OPTIONAL[$group]) || !empty($u['is_admin']) || (int) $u['rank_level'] >= RANK_WORKER || !isset($u['menu_access'])) return true;
    return in_array($group, explode(',', (string) $u['menu_access']), true);
}

/** 메뉴 권한이 없으면 403 (결재 문서 보기·결재는 막지 않음) */
function require_menu(array $u, string $group): void
{
    if (!can_menu($u, $group)) abort(403, "'" . MENU_OPTIONAL[$group] . "' 메뉴 사용 권한이 없습니다. 관리자에게 회원관리 › 메뉴 권한을 요청하세요.");
}

/** AR(아르바이트) 사용관리·보고서: 공무직 이상 */
function can_ar(?array $u): bool
{
    return $u && (!empty($u['is_admin']) || (int) $u['rank_level'] >= RANK_WORKER);
}

/** 물품구매(시설관리 › 물품구매): 공무직 이상 */
function can_purchase(?array $u): bool
{
    return $u && (!empty($u['is_admin']) || (int) $u['rank_level'] >= RANK_WORKER);
}

/** 물품구매 지출 완료 처리: 주무관 이상·최고관리자 */
function can_purchase_pay(?array $u): bool
{
    return $u && (!empty($u['is_admin']) || (int) $u['rank_level'] >= RANK_OFFICER);
}

/** 일지 종류가 속한 메인메뉴 (권한 확인용) */
function journal_menu(string $type): ?string
{
    return match (true) {
        in_array($type, ['daily', 'sales', 'voucher', 'vcheck'], true) => 'ops',
        in_array($type, ['rooms', 'arwork'], true) => 'room',
        isset(PROGRAM_TYPES[$type]) => 'prog',
        $type === 'attendance' => 'att',
        default => null,
    };
}

/** 설정 메뉴(상품·조직·분류 등): 최고관리자만 */
function require_admin(): array
{
    $u = require_login();
    if (empty($u['is_admin'])) abort(403, '설정은 최고관리자만 할 수 있습니다.');
    return $u;
}

/* ───────────── ID 저장 · 자동 로그인 ───────────── */
const REMEMBER_COOKIE = 'FORESTLOG_AUTO'; // 자동 로그인 (selector:validator)
const SAVED_ID_COOKIE = 'FORESTLOG_ID';   // ID 저장
const REMEMBER_DAYS = 30;

function auth_cookie(string $name, string $value, int $expires): void
{
    if (headers_sent()) return;
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    setcookie($name, $value, [
        'expires' => $expires, 'path' => config('base_url') === '' ? '/' : config('base_url') . '/',
        'secure' => $https, 'httponly' => true, 'samesite' => 'Lax',
    ]);
    if ($expires > time()) $_COOKIE[$name] = $value; else unset($_COOKIE[$name]);
}

/** 저장된 ID (없으면 '') */
function saved_login_id(): string
{
    return mb_substr((string) ($_COOKIE[SAVED_ID_COOKIE] ?? ''), 0, 50);
}

function save_login_id(?string $username): void
{
    $username ? auth_cookie(SAVED_ID_COOKIE, $username, time() + 365 * 86400) : auth_cookie(SAVED_ID_COOKIE, '', time() - 3600);
}

/** 자동 로그인 토큰 발급 (이 기기·브라우저, REMEMBER_DAYS 일) */
function remember_issue(int $userId): void
{
    $pdo = db();
    $pdo->exec('DELETE FROM auth_tokens WHERE expires_at < NOW()');
    $selector = bin2hex(random_bytes(9));
    $validator = bin2hex(random_bytes(32));
    $pdo->prepare('INSERT INTO auth_tokens (user_id, selector, token_hash, user_agent, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))')
        ->execute([$userId, $selector, hash('sha256', $validator), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), REMEMBER_DAYS]);
    auth_cookie(REMEMBER_COOKIE, "$selector:$validator", time() + REMEMBER_DAYS * 86400);
}

/** @return ?array{0: string, 1: string} 쿠키의 [selector, validator] */
function remember_cookie_parts(): ?array
{
    $c = (string) ($_COOKIE[REMEMBER_COOKIE] ?? '');
    return preg_match('/^([0-9a-f]{18}):([0-9a-f]{64})$/', $c, $m) ? [$m[1], $m[2]] : null;
}

/** 자동 로그인 쿠키로 로그인 (성공하면 세션에 uid). 잘못되거나 만료된 쿠키는 지운다 */
function remember_login(): void
{
    if (!isset($_COOKIE[REMEMBER_COOKIE])) return;
    $parts = remember_cookie_parts();
    $row = null;
    if ($parts) {
        $st = db()->prepare("SELECT t.*, u.status FROM auth_tokens t JOIN users u ON u.id = t.user_id WHERE t.selector = ? AND t.expires_at > NOW()");
        $st->execute([$parts[0]]);
        $row = $st->fetch() ?: null;
    }
    if (!$row || !hash_equals($row['token_hash'], hash('sha256', $parts[1])) || $row['status'] !== 'active') {
        if ($row && $row['status'] !== 'active') db()->prepare('DELETE FROM auth_tokens WHERE id = ?')->execute([$row['id']]);
        auth_cookie(REMEMBER_COOKIE, '', time() - 3600);
        return;
    }
    db()->prepare('UPDATE auth_tokens SET last_used_at = NOW() WHERE id = ?')->execute([$row['id']]);
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$row['user_id']]);
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $row['user_id'];
}

/** 이 기기의 자동 로그인 해제 (로그아웃) */
function remember_forget(): void
{
    if ($parts = remember_cookie_parts()) db()->prepare('DELETE FROM auth_tokens WHERE selector = ?')->execute([$parts[0]]);
    auth_cookie(REMEMBER_COOKIE, '', time() - 3600);
}

/** 그 사람의 모든 기기 자동 로그인 해제 (비밀번호 변경·초기화). $keepCurrent 면 지금 기기는 남긴다 */
function remember_forget_user(int $userId, bool $keepCurrent = false): void
{
    $parts = $keepCurrent ? remember_cookie_parts() : null;
    db()->prepare('DELETE FROM auth_tokens WHERE user_id = ?' . ($parts ? ' AND selector <> ?' : ''))->execute($parts ? [$userId, $parts[0]] : [$userId]);
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
