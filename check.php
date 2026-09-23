<?php
/**
 * 서버 점검 페이지 — 사이트가 "HTTP ERROR 500" 등으로 열리지 않을 때 원인을 찾는다.
 * 사용법: 브라우저에서 https://도메인/check.php 접속. 점검이 끝나면 이 파일은 지워도 된다.
 *
 * ※ 이 파일은 오래된 PHP(5.6·7.x)에서도 열리도록 일부러 옛 문법만 사용한다.
 *   (PHP 버전이 낮아서 사이트가 안 열리는 경우에도 원인을 보여주기 위해)
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: text/html; charset=utf-8');
define('APP_ROOT', __DIR__);

$results = array();
function add_result(&$results, $ok, $title, $detail, $fix)
{
    $results[] = array('ok' => $ok, 'title' => $title, 'detail' => $detail, 'fix' => $fix);
}
function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

// 1) PHP 버전
$phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
add_result($results, $phpOk, 'PHP 버전', 'PHP ' . PHP_VERSION . ' (필요: 8.1 이상, 권장 8.4)',
    '호스팅 관리화면에서 PHP 버전을 8.4로 바꾸세요. 파일을 전부 지울 때 PHP 버전 설정이 담긴 .htaccess가 함께 지워졌을 수 있습니다.');

// 2) PHP 확장
foreach (array('pdo_mysql' => '필수 (DB 연결)', 'mbstring' => '필수 (한글 처리)', 'fileinfo' => '필수 (사진 업로드)',
               'zip' => '선택 (엑셀 다운로드, 없으면 CSV)', 'gd' => '선택') as $ext => $why) {
    $loaded = extension_loaded($ext);
    $required = strpos($why, '필수') === 0;
    add_result($results, $loaded || !$required, "PHP 확장: $ext", ($loaded ? '있음' : '없음') . " — $why",
        $required ? '호스팅 업체에 해당 PHP 확장을 켜 달라고 요청하세요.' : '');
}

// 3) 필수 파일
$missing = array();
foreach (array('index.php', 'login.php', '.htaccess', 'app/bootstrap.php', 'app/helpers.php', 'app/migrate.php',
               'app/config.sample.php', 'sql/schema.sql', 'assets/style.css', 'assets/app.js') as $f) {
    if (!is_file(APP_ROOT . '/' . $f)) $missing[] = $f;
}
add_result($results, !$missing, '사이트 파일', $missing ? '없는 파일: ' . implode(', ', $missing) : '주요 파일이 모두 있습니다.',
    'GitHub에서 받은 파일을 이 check.php 와 같은 폴더에 올렸는지 확인하세요. ZIP으로 받으면 website-main 폴더 "안의" 파일들을 올려야 합니다. (.htaccess 는 숨김파일이라 빠지기 쉽습니다)');

// 4) config.php
$cfgFile = APP_ROOT . '/app/config.php';
$config = null;
if (!is_file($cfgFile)) {
    add_result($results, false, 'app/config.php', '파일이 없습니다.',
        'app/config.sample.php 를 복사해 이름을 config.php 로 바꾸고, DB 정보를 넣어 app 폴더에 올리세요.');
} else {
    $raw = file_get_contents($cfgFile);
    $bomOrSpace = substr($raw, 0, 3) === "\xEF\xBB\xBF" || !preg_match('/^<\?php/', $raw);
    if ($bomOrSpace) {
        add_result($results, false, 'app/config.php 시작 부분', '파일 맨 앞에 <?php 이전 문자(빈 줄, 공백 또는 BOM)가 있습니다.',
            '메모장에서 저장할 때 인코딩을 "UTF-8"(BOM 없음)로 고르고, 파일이 <?php 로 바로 시작하게 하세요.');
    }
    try {
        $config = include $cfgFile;
        if (!is_array($config) || !isset($config['db']) || !is_array($config['db'])) {
            add_result($results, false, 'app/config.php 내용', '설정 배열을 읽지 못했습니다 (return [ ... ]; 형식이 깨졌거나 db 항목이 없음).',
                'config.sample.php 를 다시 복사해서 DB 정보만 바꿔 보세요.');
            $config = null;
        } else {
            add_result($results, true, 'app/config.php', '파일을 정상적으로 읽었습니다.', '');
        }
    } catch (ParseError $e) {
        add_result($results, false, 'app/config.php 문법 오류', $e->getLine() . '번째 줄 근처: ' . $e->getMessage(),
            '따옴표(\')나 쉼표(,)가 빠지지 않았는지 확인하세요. 예: \'pass\' => \'비밀번호\',');
    } catch (Throwable $e) {
        add_result($results, false, 'app/config.php 오류', $e->getMessage(), 'config.sample.php 를 다시 복사해서 DB 정보만 바꿔 보세요.');
    }
}

// 5) DB 연결
if ($config && extension_loaded('pdo_mysql')) {
    $db = $config['db'];
    $host = isset($db['host']) ? $db['host'] : 'localhost';
    $port = isset($db['port']) ? (int) $db['port'] : 3306;
    $name = isset($db['name']) ? $db['name'] : '';
    try {
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4",
            isset($db['user']) ? $db['user'] : '', isset($db['pass']) ? $db['pass'] : '',
            array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        add_result($results, true, 'DB 연결', "접속 성공 (DB: $name)", '');
        try {
            $users = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $journals = (int) $pdo->query('SELECT COUNT(*) FROM journals')->fetchColumn();
            $ver = '';
            try { $ver = (string) $pdo->query("SELECT value FROM settings WHERE name = 'db_version'")->fetchColumn(); } catch (Exception $e) {}
            add_result($results, $users > 0, '기존 자료', "회원 {$users}명, 일지 {$journals}건" . ($ver !== '' ? ", DB 버전 $ver" : ''),
                $users > 0 ? '' : '회원이 없습니다. 다른(새) DB에 연결된 것일 수 있습니다. 원래 쓰던 DB 이름인지 확인하세요.');
        } catch (Exception $e) {
            add_result($results, false, '기존 자료', '테이블이 없습니다: ' . $e->getMessage(),
                'DB 이름이 원래 쓰던 DB가 맞는지 확인하세요. 정말 처음 설치하는 경우에만 install.php 를 실행합니다.');
        }
    } catch (Exception $e) {
        $msg = $e->getMessage();
        $fix = 'DB 호스트·이름·아이디·비밀번호를 호스팅 관리화면의 값과 똑같이 넣었는지 확인하세요.';
        if (strpos($msg, 'Access denied') !== false) $fix = 'DB 아이디·비밀번호 또는 DB 이름이 틀렸습니다. ' . $fix;
        elseif (strpos($msg, 'Unknown database') !== false) $fix = 'DB 이름이 틀렸습니다. ' . $fix;
        elseif (strpos($msg, 'No such file') !== false || strpos($msg, 'Connection refused') !== false || strpos($msg, 'getaddrinfo') !== false) {
            $fix = 'DB 호스트 주소가 틀렸습니다. 호스팅에 따라 localhost 가 아닌 별도 주소를 쓰기도 합니다. ' . $fix;
        }
        add_result($results, false, 'DB 연결', '접속 실패: ' . $msg, $fix);
    }
}

// 6) 사진 폴더
$up = APP_ROOT . '/uploads';
add_result($results, is_dir($up) && is_writable($up), 'uploads 폴더',
    is_dir($up) ? (is_writable($up) ? '쓰기 가능' : '쓰기 권한 없음') : '폴더 없음',
    'uploads 폴더를 만들고 FTP에서 권한을 707로 바꾸세요. (사진이 안 올라갈 뿐, 사이트 접속에는 영향 없음)');

// 7) install.php
if (is_file(APP_ROOT . '/install.php')) {
    add_result($results, true, 'install.php', '파일이 있습니다. (기존 자료가 있으면 실행되지 않지만, 점검이 끝나면 지우는 것이 안전합니다)', '');
}

$allOk = true;
foreach ($results as $r) if (!$r['ok']) $allOk = false;
?>
<!doctype html>
<html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>서버 점검</title>
<style>
body { font: 15px/1.6 -apple-system, "Malgun Gothic", sans-serif; background: #f4f6f3; color: #1f2d24; margin: 0; padding: 1rem; }
.wrap { max-width: 820px; margin: 0 auto; }
h1 { font-size: 1.3rem; }
.item { background: #fff; border: 1px solid #dfe5df; border-left: 5px solid #2f7d4f; border-radius: 8px; padding: .7rem .9rem; margin-bottom: .6rem; }
.item.bad { border-left-color: #c0392b; background: #fff8f7; }
.item b { display: block; }
.fix { color: #8e2a20; margin-top: .3rem; }
.sum { padding: .8rem 1rem; border-radius: 8px; font-weight: 700; margin-bottom: 1rem; }
.sum.ok { background: #e6f4ea; } .sum.bad { background: #fbeaea; color: #8e2a20; }
</style></head>
<body><div class="wrap">
<h1>서버 점검</h1>
<div class="sum <?php echo $allOk ? 'ok' : 'bad'; ?>">
  <?php echo $allOk ? '문제가 발견되지 않았습니다. 사이트 첫 화면(index.php)을 다시 열어 보세요.' : '빨간색 항목을 아래 안내대로 고친 뒤 이 페이지를 새로고침하세요.'; ?>
</div>
<?php foreach ($results as $r): ?>
  <div class="item <?php echo $r['ok'] ? '' : 'bad'; ?>">
    <b><?php echo $r['ok'] ? '✔' : '✘'; ?> <?php echo h($r['title']); ?></b>
    <?php echo h($r['detail']); ?>
    <?php if (!$r['ok'] && $r['fix']): ?><div class="fix">→ <?php echo h($r['fix']); ?></div><?php endif; ?>
  </div>
<?php endforeach; ?>
<p style="color:#6b7a70;font-size:.85rem">비밀번호는 화면에 표시하지 않습니다. 점검이 끝나면 check.php 는 서버에서 지워도 됩니다.</p>
</div></body></html>
