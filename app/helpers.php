<?php
defined('APP_ROOT') || exit;

// 직급 (숫자가 클수록 상위)
const RANK_KEEPER  = 1; // 관리원
const RANK_WORKER  = 2; // 공무직
const RANK_OFFICER = 3; // 주무관
const RANK_LEADER  = 4; // 팀장
const RANKS = [
    RANK_KEEPER  => '관리원',
    RANK_WORKER  => '공무직',
    RANK_OFFICER => '주무관',
    RANK_LEADER  => '팀장',
];

const JOURNAL_TYPES = [
    'daily'    => '일일업무일지',
    'sales'    => '일일매출보고',
    'facility' => '시설물관리일지',
];

const JOURNAL_STATUS = [
    'draft'    => '임시저장',
    'pending'  => '결재중',
    'approved' => '결재완료',
    'rejected' => '반려',
];

function config(string $key, mixed $default = null): mixed
{
    return $GLOBALS['CONFIG'][$key] ?? $default;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'] ?? 3306, $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '+09:00'");
    }
    return $pdo;
}

function e(mixed $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim((string) config('base_url', ''), '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function flash(string $msg, string $type = 'info'): void
{
    $_SESSION['flash'][] = ['msg' => $msg, 'type' => $type];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** POST 요청이면 CSRF 토큰을 검사한다. */
function csrf_verify(): void
{
    if (is_post() && !hash_equals(csrf_token(), (string) ($_POST['_csrf'] ?? ''))) {
        http_response_code(400);
        exit('잘못된 요청입니다. 페이지를 새로고침한 뒤 다시 시도하세요.');
    }
}

function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function valid_date(?string $d): bool
{
    if (!$d) return false;
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    return $dt !== false && $dt->format('Y-m-d') === $d;
}

/** "1,200,000" 같은 입력을 정수로 */
function to_int(mixed $v): int
{
    $digits = preg_replace('/\D/', '', (string) $v);
    return $digits === '' ? 0 : (int) $digits;
}

function won(int|float|string|null $n): string
{
    return number_format((float) $n) . '원';
}

function rank_name(int|string $r): string
{
    return RANKS[(int) $r] ?? '-';
}

function status_badge(string $status): string
{
    return '<span class="badge st-' . e($status) . '">' . e(JOURNAL_STATUS[$status] ?? $status) . '</span>';
}

function weekday_ko(string $date): string
{
    return ['일', '월', '화', '수', '목', '금', '토'][(int) date('w', strtotime($date))];
}

function abort(int $code, string $msg): never
{
    http_response_code($code);
    layout_header('오류');
    echo '<div class="card"><h2>' . e($msg) . '</h2><p><a class="btn" href="' . e(url('index.php')) . '">처음으로</a></p></div>';
    layout_footer();
    exit;
}
