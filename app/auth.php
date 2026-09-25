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

/** 회원별로 켜고 끌 수 있는 메인메뉴 (회원관리에서 설정) */
const MENU_OPTIONAL = ['att' => '근태관리', 'ops' => '운영관리', 'room' => '객실관리', 'prog' => '프로그램', 'stat' => '통계'];

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
