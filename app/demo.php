<?php
defined('APP_ROOT') || exit;

/*
 * 경진대회 체험용 데모 사이트 (config 'demo' => true 일 때만 동작)
 *  - 가상 직원·객실·시설·민원 등 약 6개월치 자료를 만든다. 날짜는 '오늘' 기준이라 언제 열어도 최근 자료가 보인다.
 *  - 날짜가 바뀐 뒤 첫 접속 때 전부 지우고 다시 만든다 (매일 밤 초기화). 체험하며 바꾼 내용은 다음 날 사라진다.
 *  - 운영 사이트와 같은 DB를 쓰되 표는 demo_ 로 시작하는 것만 쓴다 (app/demo_db.php).
 *  - 안전장치: 데모 표시(settings.demo_db)가 없는데 자료가 있으면 초기화하지 않는다.
 */

const DEMO_PASSWORD = 'demo1234';

/** 역할별 체험 계정 [아이디 => [이름, 직급, 최고관리자, 설명]] — 로그인 화면 버튼 */
const DEMO_ACCOUNTS = [
    'demo_keeper'  => ['김숲',   RANK_KEEPER,  0, '현장 직원 — 업무일지·청소관리·민원 입력'],
    'demo_worker'  => ['이나무', RANK_WORKER,  0, '중간관리 — 1차 결재·상품권 불출·객실 운영'],
    'demo_officer' => ['박하늘', RANK_OFFICER, 0, '담당 공무원 — 결재·통계·AI 예측 확인'],
    'demo_leader'  => ['최산림', RANK_LEADER,  1, '팀장(관리자) — 최종 결재·설정 전체'],
];

/** uploads 폴더 보호 파일 (배포가 uploads 를 올리지 않아 체험판 폴더에는 없을 수 있다) */
const DEMO_UPLOADS_HTACCESS = "# 사진 업로드 폴더: 목록 보기와 스크립트 실행 금지\nOptions -Indexes\n<FilesMatch \"\\.(?i:php\\d?|phtml|phar|pl|py|cgi|sh|html?)$\">\n  <IfModule mod_authz_core.c>\n    Require all denied\n  </IfModule>\n</FilesMatch>\n";

/** 데모 사이트면 날짜가 바뀐 뒤 첫 접속 때 자료를 새로 만든다 (bootstrap 에서 호출) */
function demo_maybe_reset(): void
{
    if (!is_demo()) return;
    $ht = APP_ROOT . '/uploads/.htaccess';
    if (!is_file($ht)) {
        @mkdir(dirname($ht), 0755, true);
        @file_put_contents($ht, DEMO_UPLOADS_HTACCESS);
    }
    $today = demo_seed_mark();
    if (setting('demo_seeded_on') === $today) return;
    $pdo = db();
    $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($users > 0 && setting('demo_db') !== '1') {
        setup_error("이 DB에는 이미 자료가 있지만 데모용 표시가 없습니다.\n운영 DB를 잘못 연결했을 수 있어 초기화하지 않았습니다. app/config.php 의 DB 정보를 확인하세요.");
    }
    // 방금 실패했으면 10분 동안은 다시 시도하지 않는다 (매 접속마다 지우고 다시 만들지 않게)
    $busy = "체험판 자료를 새로 만드는 중이거나 잠시 문제가 있습니다.\n잠시 뒤 다시 열어 주세요.";
    if (time() - (int) setting('demo_seed_fail_at', '0') < 600) setup_error($busy);
    // 여러 사람이 동시에 열어도 한 번만 만든다 (잠금 이름은 DB 마다 따로)
    if ((int) $pdo->query("SELECT GET_LOCK(CONCAT(DATABASE(), '_demo_reset'), 30)")->fetchColumn() !== 1) setup_error($busy);
    try {
        $st = $pdo->query("SELECT value FROM settings WHERE name = 'demo_seeded_on'");
        if ($st->fetchColumn() !== $today) demo_seed();
    } catch (Throwable $e) {
        error_log('forestlog demo seed: ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
        setting_set('demo_seed_fail_at', (string) time());
        setup_error($busy);
    } finally {
        $pdo->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), '_demo_reset'))");
    }
}

/** 가상 자료 판 번호 — demo_seed.php 의 자료를 바꾸면 1 올린다 (배포 후 첫 접속 때 오늘 자료를 새로 만든다) */
const DEMO_SEED_VERSION = 2;

/** 오늘 + 자료 판 번호 (날짜가 바뀌거나 판 번호가 바뀌면 다시 만든다) */
function demo_seed_mark(): string
{
    return date('Y-m-d') . '#' . DEMO_SEED_VERSION;
}

/** 데모 자료 전체 다시 만들기 */
function demo_seed(): void
{
    if (!is_demo()) throw new RuntimeException('데모 사이트에서만 실행할 수 있습니다.');
    $pdo = db();
    @set_time_limit(120);
    mt_srand(20261030); // 매번 같은 모양의 자료 (날짜만 오늘 기준으로 이동)

    // 1) 비우기
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach (array_keys(demo_known_tables()) as $t) {
        // settings(DB 버전·기본값)와 ai_insights(발행한 AI 예측 — 발행 안 한 날도 최근 발행본을 보여 준다)는 남긴다
        if (!in_array($t, ['settings', 'ai_insights'], true)) $pdo->exec("TRUNCATE TABLE `$t`"); // DemoPDO 가 demo_ 표로 바꾼다 (운영 표는 건드릴 수 없음)
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    demo_clear_uploads();
    setting_set('demo_db', '1');

    require_once __DIR__ . '/demo_seed.php'; // 가상 자료 내용 (초기화할 때만 읽음)
    $pdo->beginTransaction();
    try {
        demo_seed_data();
        setting_set('demo_seeded_on', demo_seed_mark());
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** 체험하며 올린 사진 등 지우기 (데모 사이트의 uploads 폴더만, _diag 제외) */
function demo_clear_uploads(): void
{
    if (!is_demo() || setting('demo_db') !== '1') return;
    $root = APP_ROOT . '/uploads';
    if (!is_dir($root)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $rel = substr($f->getPathname(), strlen($root) + 1);
        if (str_starts_with($rel, '_diag') || in_array($rel, ['.htaccess', 'index.html'], true)) continue;
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
}

/** 한 줄 넣고 id 돌려주기 */
function demo_ins(string $table, array $row): int
{
    $cols = array_keys($row);
    db()->prepare("INSERT INTO `$table` (`" . implode('`,`', $cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
        ->execute(array_values($row));
    return (int) db()->lastInsertId();
}

function demo_pick(array $a): mixed
{
    return $a[mt_rand(0, count($a) - 1)];
}

/** 확률(0~1) */
function demo_chance(float $p): bool
{
    return mt_rand() / mt_getrandmax() < $p;
}

/**
 * 문서 하나 + 결재선. $state: approved(결재완료) / pending(첫 결재 대기) / draft
 * @return int journal id
 */
function demo_journal(string $type, string $date, array $author, string $state, array $byRank, array $extra = []): int
{
    $line = approval_line_for((int) $author['rank_level'], $type);
    $at = $date . ' 17:' . sprintf('%02d', mt_rand(0, 59)) . ':00';
    $status = $state === 'draft' ? 'draft' : ($state === 'pending' && $line ? 'pending' : 'approved');
    $id = demo_ins('journals', $extra + [
        'type' => $type, 'work_date' => $date, 'author_id' => $author['id'], 'status' => $status,
        'submitted_at' => $state === 'draft' ? null : $at, 'submitted_by' => $state === 'draft' ? null : $author['id'],
        'completed_at' => $status === 'approved' ? date('Y-m-d H:i:s', strtotime($at) + 3600 * 15) : null,
        'created_at' => $date . ' 09:10:00',
    ]);
    if ($state === 'draft') return $id;
    foreach ($line as $i => $rank) {
        $done = $status === 'approved';
        demo_ins('approvals', [
            'journal_id' => $id, 'step_order' => $i + 1, 'required_rank' => $rank,
            'approver_id' => $done ? $byRank[$rank]['id'] : null, 'status' => $done ? 'approved' : 'waiting',
            'acted_at' => $done ? date('Y-m-d H:i:s', strtotime($at) + 3600 * (14 + $i)) : null,
        ]);
    }
    return $id;
}

/** 체험 계정으로 바로 로그인 (비밀번호 없이, 데모 사이트에서만) */
function demo_login(string $username): ?array
{
    if (!is_demo() || !isset(DEMO_ACCOUNTS[$username])) return null;
    $st = db()->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
    $st->execute([$username]);
    $u = $st->fetch() ?: null;
    if (!$u) return null;
    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$u['id']]);
    session_login((int) $u['id']);
    return $u;
}

/** 데모에서 막는 저장: 체험 계정 자체를 바꾸면 다른 체험자가 로그인할 수 없어진다 */
function demo_block_post(string $what): void
{
    if (!is_demo() || !is_post()) return;
    flash("체험판에서는 $what 변경이 막혀 있습니다. 다른 메뉴는 자유롭게 써 보세요.", 'error');
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?? url('index.php'))); // 같은 화면으로 (GET)
    exit;
}

/**
 * 체험판에서 운영 사이트 최고관리자 확인 (AI 키 설정처럼 운영자만 할 일).
 * 체험판 DB 연결은 모든 표를 demo_ 로 바꾸므로, 운영 users 표는 별도 연결로 '읽기만' 한다.
 * 5번 틀리면 1시간 잠금 (demo_settings.main_admin_fail).
 * @return ?string 오류 문구 (맞으면 null)
 */
function demo_verify_main_admin(string $username, string $password): ?string
{
    if (!is_demo()) return '체험판에서만 쓰는 확인입니다.';
    // 동시에 여러 번 보내 횟수 제한을 넘지 못하게 확인 전체를 잠근다
    if ((int) db()->query("SELECT GET_LOCK(CONCAT(DATABASE(), '_main_admin'), 15)")->fetchColumn() !== 1) return '잠시 뒤 다시 해 주세요.';
    try {
        return demo_verify_main_admin_locked($username, $password);
    } finally {
        db()->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), '_main_admin'))");
    }
}

function demo_verify_main_admin_locked(string $username, string $password): ?string
{
    $st = db()->query("SELECT value FROM settings WHERE name = 'main_admin_fail'"); // 잠금 안에서 최신 값을 다시 읽는다
    $fail = json_decode((string) $st->fetchColumn(), true) ?: ['n' => 0, 't' => 0];
    if ($fail['n'] >= 5 && time() - $fail['t'] < 3600) return '여러 번 틀려 1시간 동안 잠겼습니다. 나중에 다시 해 주세요.';
    if ($fail['n'] >= 5) $fail = ['n' => 0, 't' => 0];
    $c = config('db');
    $main = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'] ?? 3306, $c['name']), $c['user'], $c['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $st = $main->prepare("SELECT password_hash, is_admin, status FROM users WHERE username = ?");
    $st->execute([$username]);
    $u = $st->fetch();
    $main = null;
    if (!$u || !password_verify($password, $u['password_hash']) || empty($u['is_admin']) || $u['status'] !== 'active') {
        setting_set('main_admin_fail', json_encode(['n' => $fail['n'] + 1, 't' => time()]));
        return '운영 사이트 최고관리자 아이디 또는 비밀번호가 맞지 않습니다.';
    }
    setting_set('main_admin_fail', json_encode(['n' => 0, 't' => 0]));
    return null;
}

/*
 * 체험판 방문자 수: 같은 브라우저는 하루 1명 (쿠키), 로봇·자동 점검은 빼고 센다.
 *  settings 에 demo_visit_total(누적)·demo_visit_YYYY-MM-DD(그날) — settings 는 매일 밤 초기화에서도 남는다.
 */
function demo_count_visit(): void
{
    if (!is_demo() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || !empty($_SERVER['HTTP_X_REQUESTED_WITH'])) return;
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|monitor|curl|wget|python|headless|lighthouse/i', $ua)) return;
    if (preg_match('/manifest|sw\.js|\.(css|js|png|jpg|ico)$/i', (string) ($_SERVER['SCRIPT_NAME'] ?? ''))) return;
    $today = date('Y-m-d');
    if (($_COOKIE['FORESTLOG_DEMO_VISIT'] ?? '') === $today) return;
    auth_cookie('FORESTLOG_DEMO_VISIT', $today, strtotime('tomorrow'));
    $st = db()->prepare("INSERT INTO settings (name, value) VALUES (?, '1') ON DUPLICATE KEY UPDATE value = CAST(value AS UNSIGNED) + 1");
    foreach (['demo_visit_total', 'demo_visit_' . $today] as $k) $st->execute([$k]);
}

/** [오늘, 누적, 최근 7일 [날짜 => 명]] */
function demo_visit_counts(): array
{
    $week = [];
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $week[$d] = (int) setting('demo_visit_' . $d, '0');
    }
    return [$week[date('Y-m-d')], (int) setting('demo_visit_total', '0'), $week];
}

/** 화면 아래 구석에 보이는 방문자 수 */
function demo_visit_badge(): string
{
    if (!is_demo()) return '';
    [$today, $total, $week] = demo_visit_counts();
    $tip = '최근 7일: ' . implode(' · ', array_map(fn($d, $n) => date('n/j', strtotime($d)) . ' ' . $n . '명', array_keys($week), $week));
    return '<div class="demo-visits" title="' . e($tip) . '">👀 오늘 ' . number_format($today) . '명 · 누적 ' . number_format($total) . '명</div>';
}
