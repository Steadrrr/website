<?php
/**
 * 서버 진단 (최고관리자): 저장이 빈 화면으로 멈출 때 원인 찾기
 *   - 최근 저장(POST) 진행 기록 (uploads/_diag/trace.log)
 *   - 서버 환경 (PHP·확장·한도·opcache)
 *   - 프로그램 운영보고 저장 모의 실험 (DB 에 남기지 않고 되돌림)
 *   - 보내기 실험: 운영보고와 같은 모양의 폼(사진 포함)이 서버에 도착하는지
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_admin();
$pdo = db();
$h = fn($v) => e((string) $v);
$traceFile = APP_ROOT . '/uploads/_diag/trace.log';

if (is_post()) {
    csrf_verify();
    if (post('act') === 'opcache' && function_exists('opcache_reset')) {
        flash(@opcache_reset() ? '서버의 PHP 캐시(opcache)를 비웠습니다. 다시 저장해 보세요.' : 'opcache 를 비우지 못했습니다 (호스팅에서 막혀 있음).', 'info');
    } elseif (post('act') === 'clear') {
        @unlink($traceFile);
        flash('저장 기록을 비웠습니다.', 'info');
    } elseif (post('act') === 'echo') {
        $files = [];
        foreach ($_FILES['sp']['name'] ?? [] as $k => $names) foreach ((array) $names as $i => $n) {
            $files[] = "sp[$k][$i] " . ($n === '' ? '(빈 칸)' : $n . ' · ' . number_format((int) $_FILES['sp']['size'][$k][$i]) . 'B · 오류코드 ' . (int) $_FILES['sp']['error'][$k][$i]);
        }
        flash('보내기 실험 성공: 서버가 받았습니다 · 입력 ' . count($_POST, COUNT_RECURSIVE) . '개 · 회차 ' . count((array) ($_POST['s'] ?? [])) . '개 · 파일 ' . ($files ? implode(' / ', $files) : '없음'), 'success');
    }
    redirect('diag.php');
}

layout_header('서버 진단');
?>
<div class="card">
  <h1>서버 진단 <small class="muted">최고관리자</small></h1>
  <p class="muted small">저장하면 빈 화면이 나올 때 이 화면을 캡처해서 개발 담당자에게 보내 주세요. 아래 기록은 웹에서 직접 열 수 없는 파일에 남습니다.</p>
</div>

<section class="card">
  <h2>1. 최근 저장 기록 <small class="muted">최근 것이 위</small></h2>
  <?php $lines = is_file($traceFile) ? array_slice(array_reverse(file($traceFile, FILE_IGNORE_NEW_LINES)), 0, 60) : []; ?>
  <?php if (!$lines): ?><p class="muted">기록이 없습니다. 운영보고를 저장해 본 뒤 이 화면을 새로고침하세요.</p>
  <?php else: ?><pre style="white-space:pre-wrap;font-size:.8rem;max-height:420px;overflow:auto;background:#f6f6f6;padding:.6rem;border-radius:6px"><?= $h(implode("\n", $lines)) ?></pre><?php endif ?>
  <p class="muted small">한 번 저장하면 같은 [번호]로 START → 입력 확인 → 저장 → END 가 남습니다. START 만 있고 END 가 없으면 서버가 처리 중에 멈춘 것이고, START 조차 없으면 요청이 PHP 에 오기 전에 막힌 것입니다.</p>
  <form method="post" class="actions"><?= csrf_field() ?><button class="btn small" name="act" value="clear">기록 비우기</button></form>
</section>

<section class="card">
  <h2>2. 서버 환경</h2>
  <?php
  $oc = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
  $env = [
      'PHP' => PHP_VERSION . ' (' . PHP_SAPI . ')',
      'DB' => (string) $pdo->query('SELECT VERSION()')->fetchColumn() . ' · db_version ' . db_version() . ' / 코드 ' . DB_VERSION,
      '확장' => implode(' · ', array_map(fn($x) => $x . (extension_loaded($x) ? ' ✔' : ' ✘'), ['gd', 'fileinfo', 'mbstring', 'pdo_mysql', 'zip'])),
      '한도' => implode(' · ', array_map(fn($k) => "$k " . ini_get($k), ['memory_limit', 'post_max_size', 'upload_max_filesize', 'max_file_uploads', 'max_input_vars', 'max_multipart_body_parts', 'max_execution_time', 'output_buffering'])),
      'opcache' => $oc ? ('켜짐 · validate_timestamps ' . ini_get('opcache.validate_timestamps') . ' · revalidate_freq ' . ini_get('opcache.revalidate_freq') . 's') : '꺼짐/확인 불가',
      '업로드 임시 폴더' => ($t = ini_get('upload_tmp_dir') ?: sys_get_temp_dir()) . (is_writable($t) ? ' (쓰기 가능)' : ' (쓰기 불가!)'),
      'uploads 폴더' => is_writable(APP_ROOT . '/uploads') ? '쓰기 가능' : '쓰기 불가!',
      '디스크 남은 공간' => ($f = @disk_free_space(APP_ROOT)) ? round($f / 1048576) . 'MB' : '확인 불가',
      '파일 수정 시각' => implode(' · ', array_map(fn($f) => basename($f) . ' ' . date('m-d H:i', (int) @filemtime(APP_ROOT . '/' . $f)), ['write.php', 'app/programs.php', 'app/items.php', 'app/bootstrap.php', 'assets/program.js'])),
  ];
  ?>
  <table class="table"><?php foreach ($env as $k => $v): ?><tr><th class="nowrap"><?= $h($k) ?></th><td class="small"><?= $h($v) ?></td></tr><?php endforeach ?></table>
  <?php if ($oc): ?><form method="post" class="actions"><?= csrf_field() ?><button class="btn small" name="act" value="opcache">PHP 캐시(opcache) 비우기</button></form><?php endif ?>
</section>

<section class="card">
  <h2>3. 프로그램 운영보고 저장 모의 실험 <small class="muted">DB 에 남기지 않고 되돌림</small></h2>
  <?php if (!isset($_GET['sim'])): ?>
    <p><a class="btn primary" href="<?= e(url('diag.php?sim=1')) ?>">모의 실험 실행</a></p>
  <?php else:
    $step = function (string $label, callable $fn) use ($h) {
        echo '<li>' . $h($label) . ' … ';
        @ob_flush(); flush();
        try { $r = $fn(); echo '<b style="color:#2f7d4f">OK</b>' . ($r ? ' <small>' . $h($r) . '</small>' : ''); }
        catch (Throwable $e) { echo '<b style="color:#b3261e">실패</b> <small>' . $h(get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')') . '</small>'; }
        echo "</li>\n"; @ob_flush(); flush();
    };
    $postBak = $_POST;
    foreach (PROGRAM_TYPES as $type => $label):
        echo '<h3>' . $h($label) . '</h3><ol class="small">';
        $date = '2099-12-31';
        $products = program_products($type, $date);
        $pid = (int) array_key_first($products);
        // 새로 작성
        $_POST = ['action' => 'submit', 's' => ['0' => ['id' => '', 'group_name' => '진단', 'staff' => '', 'product_id' => (string) $pid, 'fee_type' => 'paid', 'm_adult' => '2', 'start_time' => '10:00', 'end_time' => '11:00', 'activity' => '진단']],
                  'tk' => ['0' => ['staff' => '진단', 'content' => '진단']]];
        $payload = null;
        $step('새 작성: 입력 확인 (프로그램 ' . count($products) . '개)', function () use (&$payload, $type, $date) {
            [$payload, $errs] = items_parse($type, $date, 0);
            return $errs ? '오류 ' . implode(' / ', $errs) : '금액 ' . number_format(array_sum(array_column($payload['sessions'], 'amount'))) . '원';
        });
        if ($payload) $step('새 작성: 저장 (되돌림)', function () use ($pdo, $type, $date, $payload, $user) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO journals (type, team_id, work_date, author_id, weather, content, remarks, status) VALUES (?, NULL, ?, ?, NULL, '', '', 'draft')")->execute([$type, $date, $user['id']]);
                $id = (int) $pdo->lastInsertId();
                items_save($id, $type, $payload);
                $snap = journal_snapshot(journal_find($id));
                return '문서 ' . $id . ' · 기록 ' . count($snap) . '항목';
            } finally { $pdo->rollBack(); }
        });
        // 기존 보고서 수정
        $st = $pdo->prepare('SELECT * FROM journals WHERE type = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$type]);
        if ($j = $st->fetch()) {
            $j = journal_find((int) $j['id']);
            $old = items_load($j);
            $_POST = ['action' => 'submit', 's' => [], 'tk' => []];
            foreach ($old['sessions'] as $i => $s) { $r = array_map(fn($v) => (string) $v, $s); foreach (['start_time', 'end_time'] as $k) $r[$k] = substr($r[$k], 0, 5); $_POST['s'][(string) $i] = $r; }
            foreach ($old['tasks'] ?? [] as $i => $t) $_POST['tk'][(string) $i] = ['staff' => (string) $t['staff'], 'content' => (string) $t['content']];
            $payload = null;
            $step("수정: 입력 확인 (문서 {$j['id']} · {$j['work_date']})", function () use (&$payload, $type, $j) {
                [$payload, $errs] = items_parse($type, $j['work_date'], (int) $j['id']);
                return $errs ? '오류 ' . implode(' / ', $errs) : '회차 ' . count($payload['sessions']);
            });
            if ($payload) $step('수정: 저장 (되돌림)', function () use ($pdo, $type, $j, $payload) {
                $before = journal_snapshot($j);
                $pdo->beginTransaction();
                try {
                    items_save((int) $j['id'], $type, $payload);
                    $diff = snapshot_diff($before, journal_snapshot(journal_find((int) $j['id'])));
                    return '바뀐 항목 ' . count($diff);
                } finally { $pdo->rollBack(); }
            });
        }
        echo '</ol>';
    endforeach;
    $_POST = $postBak;
    unset($_SESSION['flash']);
    $step = null;
  ?>
    <?php $gd = function () {
        if (!function_exists('imagecreatetruecolor')) return 'GD 없음';
        $dir = APP_ROOT . '/uploads/_diag'; if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $f = "$dir/test.jpg"; $im = imagecreatetruecolor(4000, 3000); imagejpeg($im, $f, 80); imagedestroy($im);
        image_downscale($f, PROGRAM_PHOTO_MAX); $sz = getimagesize($f); @unlink($f);
        return "4000×3000 → {$sz[0]}×{$sz[1]} · 최대메모리 " . round(memory_get_peak_usage() / 1048576, 1) . 'MB';
    }; ?>
    <ol class="small"><li>사진 줄이기(GD) … <?php try { echo '<b style="color:#2f7d4f">OK</b> <small>' . $h($gd()) . '</small>'; } catch (Throwable $e) { echo '<b style="color:#b3261e">실패</b> <small>' . $h($e->getMessage()) . '</small>'; } ?></li></ol>
  <?php endif ?>
</section>

<section class="card">
  <h2>4. 보내기 실험 <small class="muted">운영보고와 같은 모양의 폼이 서버에 도착하는지</small></h2>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="echo">
    <input type="hidden" name="s[0][id]" value=""><input type="hidden" name="s[0][group_name]" value="진단반">
    <input type="hidden" name="s[0][m_adult]" value="2"><input type="hidden" name="s[0][start_time]" value="10:00">
    <input type="hidden" name="tk[0][staff]" value="진단"><input type="hidden" name="tk[0][content]" value="진단 업무">
    <label>사진 (선택, 여러 장 가능)<input type="file" name="sp[0][]" accept="image/*" multiple data-resize="1600"></label>
    <input type="file" name="sp[n1][]" hidden>
    <div class="actions"><button class="btn primary">보내기 실험</button></div>
  </form>
  <p class="muted small">사진 없이 한 번, 사진을 넣고 한 번 눌러 보세요. '보내기 실험 성공'이 뜨면 서버가 받은 것이고, 빈 화면이면 이런 모양의 요청이 서버(호스팅)에서 막히는 것입니다.</p>
</section>
<?php layout_footer();
