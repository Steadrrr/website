<?php
defined('APP_ROOT') || exit;

// 직급 (숫자가 클수록 상위)
const RANK_KEEPER  = 1; // 사원
const RANK_WORKER  = 2; // 공무직
const RANK_OFFICER = 3; // 주무관
const RANK_LEADER  = 4; // 팀장
const RANKS = [
    RANK_KEEPER  => '사원',
    RANK_WORKER  => '공무직',
    RANK_OFFICER => '주무관',
    RANK_LEADER  => '팀장',
];

const JOURNAL_TYPES = [
    'daily'    => '일일업무일지',
    'sales'    => '일일매출보고',
    'facility' => '시설물관리일지',
    'voucher'  => '상품권입고',
    'attendance' => '근태',
    'healing'    => '산림치유센터 운영보고',
    'kidsforest' => '유아숲체험원 운영보고',
    'guide'      => '숲해설 운영보고',
    'kidsdirect' => '유아숲(직영) 운영보고',
    'vcheck'     => '상품권 금고점검',
    'rooms'      => '일일객실판매',
];
// 프로그램 운영보고 분야 (journals.type)
const PROGRAM_TYPES = ['healing' => '산림치유센터', 'kidsforest' => '유아숲체험원', 'kidsdirect' => '유아숲(직영)', 'guide' => '숲해설'];
// 참여 인원 연령대 (program_sessions 의 m_* / f_* 컬럼)
const PROGRAM_AGES = ['infant' => '유아', 'elem' => '초등', 'teen' => '중고등', 'adult' => '성인', 'senior' => '65세이상'];

// 임시저장을 여러 직원이 함께 보고 이어서 고치는 문서 (업무일지·매출보고)
const SHARED_DRAFT_TYPES = ['daily', 'sales', 'rooms'];

// 날씨 입력란이 없는 문서 (상품권입고, 일일매출보고)
const NO_WEATHER_TYPES = ['voucher', 'sales', 'vcheck', 'rooms'];

// 대시보드 '오늘 일지 현황'에 표시하는 매일 쓰는 일지
const DAILY_TYPES = ['daily', 'sales', 'rooms', 'facility'];

// 판매 내역(sales_lines)을 가진 문서: 매출보고(입장권·시설대관) + 일일객실판매(객실·상품권 환급)
const SALE_DOC_TYPES = ['sales', 'rooms'];
const SALE_DOC_SQL = "j.type IN ('sales', 'rooms')";

const PRODUCT_GROUPS = ['ticket' => '입장권', 'room' => '객실', 'rental' => '시설대관', 'lodge' => '대관 숙박시설'];
// 유실물 상태 => [이름, 색]
const LOST_STATUS = [
    'received'  => ['접수', '#e8710a'],
    'contacted' => ['연락완료', '#1a73e8'],
    'shipped'   => ['택배발송', '#8e24aa'],
    'returned'  => ['본인수령', '#0b8043'],
];
const SEASON_GROUPS = ['ticket' => '입장권', 'room' => '객실']; // 기간요금 대상
// 시설대관 시간 구분 => [이름, products 요금 컬럼]
const RENT_TIMES = ['2h' => ['2시간', 'price_2h'], '4h' => ['4시간', 'price_4h'], 'day' => ['4시간 이상(18시까지)', 'price_day']];
const RENT_NIGHT_LABEL = '야간(18~21시)';
// 시설대관 + 대관 숙박시설 합계에 적용하는 통합 할인 => [이름, 할인율]
const RENT_DC_RULES = [
    'lodge5'  => ['대관 숙박시설 5실 이상', 10],
    'lodge9'  => ['대관 숙박시설 9실 이상', 20],
    'youth20' => ['초등·청소년 20명 이상', 30], // 체크박스 (체크하면 숙박 실 수 할인보다 우선)
];
// 객실 요금구분
const RATE_TYPES = ['weekday' => '비수기 평일', 'weekend' => '비수기 주말', 'peak' => '성수기'];
const RATE_DC_DEFAULT = ['weekday' => 30, 'weekend' => 10, 'peak' => 10]; // 할인율(%) 기본값

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

/** settings 테이블 값 (요청마다 한 번 읽음) */
function setting(string $name, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = array_column(db()->query('SELECT name, value FROM settings')->fetchAll(), 'value', 'name');
        } catch (PDOException) {
            $cache = [];
        }
    }
    return $cache[$name] ?? $default;
}

function setting_set(string $name, string $value): void
{
    db()->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')->execute([$name, $value]);
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

/** 공지사항 작성 권한: 최고관리자, 팀장, 주무관, 공무직 (사원 제외) */
function can_write_notice(array $u): bool
{
    return (bool) $u['is_admin'] || (int) $u['rank_level'] >= RANK_WORKER;
}

/** 최근 3일 안에 올라온 글 */
function is_new(string $datetime): bool
{
    return strtotime($datetime) >= strtotime('-3 days');
}

/** 회원 사진 (없으면 이름 첫 글자) */
function avatar(array $u, string $class = 'avatar'): string
{
    // 서버에서 사진 파일이 지워졌으면(예: FTP로 전체 삭제) 이름 첫 글자로 대신 표시
    if (!empty($u['photo']) && is_file(APP_ROOT . '/' . $u['photo'])) {
        return '<img class="' . e($class) . '" src="' . e(url($u['photo'])) . '" alt="' . e($u['name']) . '">';
    }
    return '<span class="' . e($class) . ' avatar-empty">' . e(mb_substr((string) $u['name'], 0, 1)) . '</span>';
}

/** 결재 상태 + 수정됨 표시 */
function journal_badges(array $j): string
{
    return status_badge($j['status']) . (!empty($j['revision'])
        ? ' <span class="badge st-edited" title="상신 후 ' . (int) $j['revision'] . '회 수정됨">수정됨' . ((int) $j['revision'] > 1 ? ' ' . (int) $j['revision'] : '') . '</span>'
        : '');
}

/** 이 사용자가 이 일지를 수정할 수 있는가: 임시저장은 작성자만, 상신된 일지는 모든 직원 */
function can_edit_journal(array $journal, array $user): bool
{
    if (in_array($journal['type'], ['voucher', 'vcheck'], true) && !can_vault($user)) return false; // 상품권 입고·금고점검은 공무직 이상
    if ($journal['type'] === 'attendance') return false; // 근태는 수정 대신 취소 후 다시 입력
    if ($journal['status'] === 'draft' && in_array($journal['type'], SHARED_DRAFT_TYPES, true)) return true; // 공유 임시저장
    return $journal['status'] !== 'draft' || (int) $journal['author_id'] === (int) $user['id'];
}

/** 상신된 적 있는 일지를 고치는 것인가 (수정 이력 남기고 결재 초기화) */
function is_revision_edit(array $journal): bool
{
    return $journal['submitted_at'] !== null;
}

/** 수정 버튼 (결재 초기화 확인창 포함) */
function edit_button(array $journal, string $class = 'btn'): string
{
    $confirm = is_revision_edit($journal)
        ? ' onclick="event.stopPropagation(); return confirm(\'수정하면 결재 상태가 초기화되고 처음부터 다시 결재를 받아야 합니다.\n수정할까요?\')"'
        : ' onclick="event.stopPropagation()"';
    return '<a class="' . e($class) . '" href="' . e(url('write.php?id=' . (int) $journal['id'])) . '"' . $confirm . '>수정</a>';
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
