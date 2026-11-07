<?php
/**
 * 개인업무 › 출퇴근 (내용은 app/clock.php)
 *   clock.php?p=비밀값       휴게실 QR을 찍으면 여는 화면: [출근] · [퇴근] (로그인한 본인, 자동 로그인 권장)
 *   clock.php?date=…         오늘 현황 (공무직 이상) — 사원은 내 기록
 *   clock.php?view=month&ym= 월별 기록 (공무직 이상: 모든 사원, 사원: 본인)
 *   clock.php?view=setup     휴게실 QR 인쇄 · 현장 인터넷 등록 (공무직 이상)
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
$pdo = db();
$manage = clock_can_manage($user);
$today = date('Y-m-d');
$date = valid_date($_GET['date'] ?? '') ? $_GET['date'] : $today;
$view = in_array($_GET['view'] ?? '', ['month', 'setup'], true) ? $_GET['view'] : 'day';
$ym = preg_match('/^\d{4}-\d{2}$/', $_GET['ym'] ?? '') && valid_date(($_GET['ym'] ?? '') . '-01') ? $_GET['ym'] : substr($date, 0, 7);
clock_purge_ips();

/* ───────────── QR 화면: 출근·퇴근 ───────────── */
$token = (string) ($_GET['p'] ?? '');
if ($token !== '') {
    $place = clock_place_by_token($token);
    $ip = clock_ip();
    $onsite = clock_onsite($ip);
    if ($place && is_post()) {
        csrf_verify();
        $kind = isset(CLOCK_KINDS[post('kind')]) ? post('kind') : null;
        if ($kind) {
            [$row, $new] = clock_record((int) $user['id'], $kind, (int) $place['id'], $onsite, $ip);
            $hm = date('H:i', strtotime($row['at']));
            flash($new ? "{$hm} " . CLOCK_KINDS[$kind] . '을 기록했습니다.' . ($onsite ? '' : ' (현장 밖)') : "{$hm}에 이미 " . CLOCK_KINDS[$kind] . '을 기록했습니다.', $new ? 'success' : 'info');
        }
        redirect('clock.php?p=' . $token);
    }
    $st = $pdo->prepare('SELECT l.*, p.name AS place_name FROM clock_logs l LEFT JOIN clock_places p ON p.id = l.place_id
                          WHERE l.user_id = ? AND l.deleted_at IS NULL AND (l.work_date = ? OR DATE(l.at) = ?) ORDER BY l.at');
    $st->execute([$user['id'], $today, $today]);
    $mine = $st->fetchAll();
    layout_header('출퇴근', 'clock');
    ?>
<section class="card clock-punch">
  <?php if (!$place): ?>
    <h1>QR을 확인해 주세요</h1>
    <p>이 QR은 쓸 수 없습니다. 휴게실에 새로 붙인 QR을 찍거나 공무직에게 알려 주세요.</p>
  <?php else: ?>
    <div class="clock-place"><?= e($place['name']) ?></div>
    <div class="clock-who"><b><?= e($user['name']) ?></b>님</div>
    <div class="clock-now" data-clock-now><?= e(date('H:i')) ?></div>
    <div class="clock-date"><?= e(date('n월 j일', strtotime($today))) ?> (<?= weekday_ko($today) ?>)</div>
    <?php if ($onsite): ?>
      <p class="clock-net ok">현장 인터넷에 연결되어 있습니다.</p>
    <?php else: ?>
      <p class="clock-net warn">와이파이에 연결되어 있지 않아 <b>'현장 밖'</b>으로 표시됩니다.<br>가능하면 휴게실 와이파이에 연결한 뒤 찍어 주세요. (출근은 그대로 기록됩니다)</p>
    <?php endif ?>
    <form method="post" class="clock-buttons">
      <?= csrf_field() ?>
      <button class="btn clock-in" name="kind" value="in">출근</button>
      <button class="btn clock-out" name="kind" value="out">퇴근</button>
    </form>
    <?php if ($mine): ?>
    <ul class="clock-today">
      <?php foreach ($mine as $m): ?>
        <li><b><?= CLOCK_KINDS[$m['kind']] ?></b> <?= e(date('H:i', strtotime($m['at']))) ?> <span class="muted"><?= e($m['place_name'] ?? '직접 입력') ?><?= $m['method'] === 'qr' && !$m['onsite'] ? ' · 현장 밖' : '' ?><?= $m['work_date'] !== $today ? ' · ' . e(date('n/j', strtotime($m['work_date']))) . ' 근무' : '' ?></span></li>
      <?php endforeach ?>
    </ul>
    <?php endif ?>
    <p class="muted small">위치(GPS)는 쓰지 않습니다. 이름 · 시각 · 휴게실 · 현장 인터넷 여부만 남습니다.<br>
      처음 로그인할 때 <b>자동 로그인</b>을 켜 두면 다음부터 QR만 찍으면 됩니다. · <a href="<?= e(url('clock.php')) ?>">내 출퇴근 기록</a></p>
  <?php endif ?>
</section>
<script>
(function () {
  const el = document.querySelector('[data-clock-now]');
  if (!el) return;
  const skew = <?= time() * 1000 ?> - Date.now(); // 휴대폰 시계가 틀려도 서버(기록되는) 시각을 보여 준다
  const tick = () => { const d = new Date(Date.now() + skew + <?= (int) date('Z') * 1000 ?>); el.textContent = String(d.getUTCHours()).padStart(2, '0') + ':' + String(d.getUTCMinutes()).padStart(2, '0'); };
  tick(); setInterval(tick, 10000);
  // 두 번 눌러 두 번 기록되지 않게
  document.querySelector('.clock-buttons')?.addEventListener('submit', (ev) => { ev.target.querySelectorAll('button').forEach((b) => setTimeout(() => { b.disabled = true; })); });
})();
</script>
    <?php
    layout_footer();
    exit;
}

// 근태관리 메뉴 권한과 상관없이: 사원은 본인 기록만 보고, 현황·설정은 공무직 이상
if ($view === 'setup' && !$manage) abort(403, '출퇴근 설정은 공무직 이상이 합니다.');

/* ───────────── 공무직 이상: 직접 입력·지우기·설정 ───────────── */
if (is_post()) {
    csrf_verify();
    if (!$manage) abort(403, '공무직 이상만 고칠 수 있습니다.');
    $act = post('act');
    $back = post('back') !== '' && str_starts_with(post('back'), 'clock.php') ? post('back') : 'clock.php';
    if ($act === 'manual') {
        $uid = (int) post('user_id');
        $wd = post('work_date');
        $at = str_replace('T', ' ', post('at'));
        $kind = isset(CLOCK_KINDS[post('kind')]) ? post('kind') : '';
        $note = trim(post('note'));
        $ok = $uid && valid_date($wd) && $kind && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $at) && valid_date(substr($at, 0, 10)) && $note !== '';
        if (!$ok) { flash('근무자 · 구분 · 시각 · 사유를 모두 넣어 주세요.', 'error'); redirect($back); }
        if (abs(strtotime(substr($at, 0, 10)) - strtotime($wd)) > 86400) { flash('시각은 근무일 당일이나 다음 날이어야 합니다.', 'error'); redirect($back); }
        clock_add_manual($uid, $wd, $kind, "$at:00", $note, (int) $user['id']);
        flash(CLOCK_KINDS[$kind] . ' ' . substr($at, 11) . ' 을(를) 넣었습니다.', 'success');
        redirect($back);
    }
    if ($act === 'delete') {
        $note = trim(post('note'));
        if ($note === '') { flash('지우는 사유를 적어 주세요.', 'error'); redirect($back); }
        clock_delete((int) post('id'), $note, (int) $user['id']);
        flash('기록을 지웠습니다 (지운 기록과 사유는 남습니다).', 'success');
        redirect($back);
    }
    if ($act === 'place_save') {
        $name = mb_substr(trim(post('name')), 0, 50);
        if ($name === '') { flash('휴게실 이름을 적어 주세요.', 'error'); redirect($back); }
        if ($id = (int) post('id')) {
            $pdo->prepare('UPDATE clock_places SET name = ?, is_active = ? WHERE id = ?')->execute([$name, post('is_active') === '1' ? 1 : 0, $id]);
        } else {
            $pdo->prepare('INSERT INTO clock_places (name, token, sort_order) VALUES (?, ?, (SELECT COALESCE(MAX(c.sort_order), 0) + 10 FROM clock_places c))')
                ->execute([$name, bin2hex(random_bytes(12))]);
        }
        flash("'{$name}' 저장했습니다.", 'success');
        redirect($back);
    }
    if ($act === 'place_token') {
        $pdo->prepare('UPDATE clock_places SET token = ? WHERE id = ?')->execute([bin2hex(random_bytes(12)), (int) post('id')]);
        flash('QR을 새로 만들었습니다. 예전 QR은 이제 쓸 수 없으니 새 QR을 인쇄해 붙여 주세요.', 'success');
        redirect($back);
    }
    if ($act === 'net_add') {
        $ip = trim(post('ip'));
        if (!filter_var($ip, FILTER_VALIDATE_IP)) { flash('인터넷 주소(IP)를 확인해 주세요.', 'error'); redirect($back); }
        $pdo->prepare('INSERT IGNORE INTO clock_nets (ip, label, created_by) VALUES (?, ?, ?)')->execute([$ip, mb_substr(trim(post('label')), 0, 50) ?: null, $user['id']]);
        // 이 인터넷에서 찍었던 '현장 밖' 기록도 현장으로 바꾼다
        $pdo->prepare("UPDATE clock_logs SET onsite = 1 WHERE ip = ? AND method = 'qr'")->execute([$ip]);
        flash("{$ip} 을(를) 현장 인터넷으로 등록했습니다.", 'success');
        redirect($back);
    }
    if ($act === 'net_delete') {
        $pdo->prepare('DELETE FROM clock_nets WHERE id = ?')->execute([(int) post('id')]);
        flash('현장 인터넷에서 뺐습니다. (이미 남은 기록은 그대로)', 'success');
        redirect($back);
    }
    redirect($back);
}

$workers = $manage ? att_workers() : [$user];
$tabs = function () use ($manage, $view, $date): void { ?>
  <div class="tabs no-print">
    <a class="<?= $view === 'day' ? 'on' : '' ?>" href="<?= e(url('clock.php?date=' . $date)) ?>"><?= $manage ? '오늘 현황' : '오늘' ?></a>
    <a class="<?= $view === 'month' ? 'on' : '' ?>" href="<?= e(url('clock.php?view=month&ym=' . substr($date, 0, 7))) ?>">월별 기록</a>
    <?php if ($manage): ?><a class="<?= $view === 'setup' ? 'on' : '' ?>" href="<?= e(url('clock.php?view=setup')) ?>">QR · 현장 인터넷</a><?php endif ?>
  </div>
<?php };

/* ───────────── 설정: 휴게실 QR · 현장 인터넷 ───────────── */
if ($view === 'setup') {
    $places = clock_places(false);
    $nets = $pdo->query('SELECT n.*, u.name AS user_name FROM clock_nets n LEFT JOIN users u ON u.id = n.created_by ORDER BY n.id')->fetchAll();
    $unknown = clock_unknown_nets();
    $myIp = clock_ip();
    $myOnsite = clock_onsite($myIp);
    layout_header('출퇴근 설정', 'clock');
    ?>
<section class="card no-print">
  <div class="card-head"><h1>출퇴근 <small class="muted">QR · 현장 인터넷</small></h1>
    <button type="button" class="btn primary" onclick="window.print()">QR 인쇄 (휴게실마다 한 장)</button></div>
  <?php $tabs() ?>
  <p class="small">휴게실마다 QR을 한 장씩 붙입니다. 근무자는 <b>본인 휴대폰 카메라로 QR을 찍고 [출근]·[퇴근]</b>을 누릅니다. 어느 휴게실에서 찍어도 출근으로 인정합니다.
    QR 사진이 밖으로 돌면 <b>'QR 새로 만들기'</b>로 바꾸세요 (예전 QR은 바로 못 씀).</p>
  <div class="table-scroll">
  <table class="table clock-places">
    <thead><tr><th>QR</th><th>휴게실</th><th>사용</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($places as $p): ?>
      <tr class="<?= $p['is_active'] ? '' : 'inactive' ?>">
        <td><div class="clock-qr-thumb" data-qr="<?= e(clock_place_url($p)) ?>"></div></td>
        <td><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="act" value="place_save"><input type="hidden" name="back" value="clock.php?view=setup"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
          <input name="name" value="<?= e($p['name']) ?>" maxlength="50" required>
          <label class="inline-check"><input type="checkbox" name="is_active" value="1" <?= $p['is_active'] ? 'checked' : '' ?>> 사용</label>
          <button class="btn small">저장</button></form></td>
        <td class="small muted"><?= $p['is_active'] ? '사용 중' : '안 씀' ?></td>
        <td><form method="post" class="inline" onsubmit="return confirm('<?= e($p['name']) ?> QR을 새로 만들까요? 지금 붙어 있는 QR은 바로 못 쓰게 됩니다.')"><?= csrf_field() ?><input type="hidden" name="act" value="place_token"><input type="hidden" name="back" value="clock.php?view=setup"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn small ghost">QR 새로 만들기</button></form></td>
      </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
  <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="act" value="place_save"><input type="hidden" name="back" value="clock.php?view=setup">
    <input name="name" maxlength="50" placeholder="휴게실 이름 (예: 산림교육센터 2층)" required><button class="btn">+ 휴게실 더하기</button></form>
</section>

<section class="card no-print">
  <h2>현장 인터넷 <small class="muted">여기서 찍으면 '현장', 아니면 '현장 밖'으로 표시 (막지는 않음)</small></h2>
  <p class="small">휴게실 공유기(KT 회선 ipTIME)의 인터넷 주소를 등록합니다. <b>휴게실 와이파이에 연결한 휴대폰이나 PC로 이 화면을 열고</b> 아래 '지금 이 인터넷 등록'을 누르면 됩니다.
    <span class="warn">공공와이파이는 등록하지 마세요</span> — 여러 곳이 같은 주소를 써서 공원 밖에서도 '현장'이 될 수 있습니다.
    가정용 회선은 공유기를 껐다 켜면 주소가 바뀔 수 있는데, 그러면 아래 '최근 현장 밖으로 찍힌 인터넷'에 새 주소가 나오니 확인하고 등록하세요.</p>
  <div class="clock-mynet <?= $myOnsite ? 'ok' : '' ?>">지금 이 기기의 인터넷: <b><?= e($myIp ?: '알 수 없음') ?></b> — <?= $myOnsite ? '현장 인터넷으로 등록되어 있습니다.' : '등록되지 않았습니다.' ?>
    <?php if (!$myOnsite && $myIp): ?>
    <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="act" value="net_add"><input type="hidden" name="back" value="clock.php?view=setup"><input type="hidden" name="ip" value="<?= e($myIp) ?>">
      <input name="label" maxlength="50" placeholder="어디 인터넷인지 (예: 공원관리소 KT)" required><button class="btn primary">지금 이 인터넷 등록</button></form>
    <?php endif ?></div>
  <table class="table">
    <thead><tr><th>인터넷 주소</th><th>어디</th><th>등록</th><th>마지막으로 찍힘</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($nets as $n): ?>
      <tr><td><?= e($n['ip']) ?></td><td><?= e($n['label'] ?? '') ?></td><td class="small muted"><?= e($n['user_name'] ?? '') ?> <?= e(date('Y-m-d', strtotime($n['created_at']))) ?></td>
        <td class="small"><?= $n['last_seen_at'] ? e(date('m/d H:i', strtotime($n['last_seen_at']))) : '-' ?></td>
        <td><form method="post" class="inline" onsubmit="return confirm('현장 인터넷에서 뺄까요?')"><?= csrf_field() ?><input type="hidden" name="act" value="net_delete"><input type="hidden" name="back" value="clock.php?view=setup"><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><button class="btn small danger">빼기</button></form></td></tr>
    <?php endforeach ?>
    <?php if (!$nets): ?><tr><td colspan="5" class="center muted">아직 없습니다. 등록 전에는 모든 기록이 '현장 밖'으로 표시됩니다.</td></tr><?php endif ?>
    </tbody>
  </table>
  <?php if ($unknown): ?>
  <h3>최근 '현장 밖'으로 찍힌 인터넷 <small class="muted">(IP는 <?= CLOCK_IP_DAYS ?>일 뒤 지움)</small></h3>
  <table class="table">
    <thead><tr><th>인터넷 주소</th><th class="right">기록</th><th class="right">사람</th><th>찍은 휴게실</th><th>마지막</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($unknown as $u): ?>
      <tr><td><?= e($u['ip']) ?></td><td class="right"><?= (int) $u['n'] ?></td><td class="right"><?= (int) $u['people'] ?></td><td class="small"><?= e($u['places'] ?? '') ?></td><td class="small"><?= e(date('m/d H:i', strtotime($u['last_at']))) ?></td>
        <td><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="act" value="net_add"><input type="hidden" name="back" value="clock.php?view=setup"><input type="hidden" name="ip" value="<?= e($u['ip']) ?>">
          <input name="label" maxlength="50" placeholder="어디 인터넷" value="<?= e(explode(', ', (string) $u['places'])[0] ?? '') ?>"><button class="btn small">현장으로 등록</button></form></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <p class="muted small">여러 사람이 같은 휴게실에서 찍은 주소는 휴게실 공유기일 가능성이 큽니다. 한 사람만 찍은 주소는 휴대폰 데이터(LTE)일 수 있으니 등록하지 마세요.</p>
  <?php endif ?>
</section>

<div class="clock-qr-sheets print-only">
  <?php foreach ($places as $p): if (!$p['is_active']) continue; ?>
  <div class="clock-qr-sheet">
    <div class="cq-site"><?= e(config('site_name', '')) ?></div>
    <div class="cq-title">출퇴근 QR</div>
    <div class="cq-place"><?= e($p['name']) ?></div>
    <div class="cq-code" data-qr="<?= e(clock_place_url($p)) ?>" data-qr-size="8"></div>
    <ol class="cq-how">
      <li>휴게실 <b>와이파이</b>에 연결합니다.</li>
      <li>휴대폰 <b>카메라</b>로 QR을 찍고, 뜨는 주소를 누릅니다.</li>
      <li><b>[출근]</b> 또는 <b>[퇴근]</b>을 누릅니다.</li>
    </ol>
    <p class="cq-note">처음 한 번은 로그인 — '자동 로그인'을 켜 두면 다음부터 바로 열립니다. 위치(GPS)는 쓰지 않습니다.</p>
  </div>
  <?php endforeach ?>
</div>
<script src="<?= e(asset_url('assets/qrcode.js')) ?>"></script>
<script>
document.querySelectorAll('[data-qr]').forEach(function (el) {
  var q = qrcode(0, 'M');
  q.addData(el.dataset.qr);
  q.make();
  el.innerHTML = q.createImgTag(Number(el.dataset.qrSize || 3), 4, el.dataset.qr);
});
</script>
    <?php
    layout_footer();
    exit;
}

/* ───────────── 월별 기록 ───────────── */
if ($view === 'month') {
    $first = new DateTimeImmutable("$ym-01");
    [$from, $to] = [$first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')];
    $days = clock_days($from, $to, $manage ? null : (int) $user['id']);
    $leaves = clock_leaves($from, $to);
    $dates = [];
    for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) $dates[] = $d;
    layout_header('출퇴근 월별 기록', 'clock');
    ?>
<section class="card">
  <div class="card-head"><h1>출퇴근 월별 기록</h1>
    <div class="cal-nav no-print">
      <a class="btn" href="<?= e(url('clock.php?view=month&ym=' . $first->modify('-1 month')->format('Y-m'))) ?>">‹</a>
      <strong><?= e($first->format('Y년 n월')) ?></strong>
      <a class="btn" href="<?= e(url('clock.php?view=month&ym=' . $first->modify('+1 month')->format('Y-m'))) ?>">›</a>
      <button class="btn ghost" onclick="window.print()">인쇄</button>
    </div></div>
  <?php $tabs() ?>
  <p class="muted small no-print">칸마다 출근 / 퇴근 시각. <span class="cs-miss">빨강</span> = 근무일인데 기록 없음 · <span class="cs-late">주황</span> = 지각(근무 시작 시각 기준) · ⚑ = 현장 밖 · ✎ = 직접 입력. 날짜를 누르면 그 날 현황.</p>
  <div class="table-scroll">
  <table class="table clock-month">
    <thead><tr><th>근무자</th><?php foreach ($dates as $d): $w = (int) date('w', strtotime($d)); ?><th class="<?= $w === 0 ? 'sun' : ($w === 6 ? 'sat' : '') ?>"><a href="<?= e(url('clock.php?date=' . $d)) ?>"><?= (int) substr($d, 8) ?></a><br><small><?= weekday_ko($d) ?></small></th><?php endforeach ?><th>출근일</th></tr></thead>
    <tbody>
    <?php foreach ($workers as $w): $cnt = 0; ?>
      <tr><th class="nowrap"><?= e($w['name']) ?><?php if ($manage): ?><br><small class="muted"><?= e(team_name($w['team_id'] ? (int) $w['team_id'] : null)) ?></small><?php endif ?></th>
      <?php foreach ($dates as $d): $day = $days[(int) $w['id']][$d] ?? null; [$label, $cls] = clock_status($w, $d, $day, $leaves[(int) $w['id']][$d] ?? null); if ($day && $day['in']) $cnt++; ?>
        <td class="<?= e($cls) ?>"><?php if ($day): ?><?= clock_hm($day['in']) ?: '-' ?><br><?= clock_hm($day['out']) ?: '-' ?><?= $day['offsite'] ? ' ⚑' : '' ?><?= array_filter($day['all'], fn($r) => $r['method'] === 'manual') ? ' ✎' : '' ?>
          <?php else: ?><small><?= e(match ($label) { '휴무' => '휴', '미출근' => '✕', default => mb_substr($label, 0, 2) }) ?></small><?php endif ?></td>
      <?php endforeach ?>
        <td class="right"><?= $cnt ?>일</td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
</section>
    <?php
    layout_footer();
    exit;
}

/* ───────────── 하루 현황 (공무직 이상: 모든 사원 · 사원: 나) ───────────── */
$days = clock_days($date, $date);
$leaves = clock_leaves($date, $date);
$rows = [];
$sum = ['work' => 0, 'in' => 0, 'miss' => 0, 'off' => 0];
foreach ($workers as $w) {
    $day = $days[(int) $w['id']][$date] ?? null;
    [$label, $cls] = clock_status($w, $date, $day, $leaves[(int) $w['id']][$date] ?? null);
    $rows[] = [$w, $day, $label, $cls];
    if (!in_array($cls, ['cs-rest', 'cs-leave'], true)) $sum['work']++;
    if ($day && $day['in']) $sum['in']++;
    if ($cls === 'cs-miss') $sum['miss']++;
    if ($day && $day['offsite']) $sum['off']++;
}
$back = 'clock.php?date=' . $date;
layout_header('출퇴근', 'clock');
?>
<section class="card">
  <div class="card-head">
    <h1>출퇴근 <small class="muted"><?= $manage ? '사원 현황' : '내 기록' ?></small></h1>
    <div class="cal-nav no-print">
      <a class="btn" href="<?= e(url('clock.php?date=' . date('Y-m-d', strtotime("$date -1 day")))) ?>">‹</a>
      <strong><?= e(date('n월 j일', strtotime($date))) ?> (<?= weekday_ko($date) ?>)</strong>
      <a class="btn" href="<?= e(url('clock.php?date=' . date('Y-m-d', strtotime("$date +1 day")))) ?>">›</a>
      <form method="get" class="inline"><input type="date" name="date" value="<?= e($date) ?>" onchange="this.form.submit()"></form>
      <a class="btn ghost small" href="<?= e(url('clock.php')) ?>">오늘</a>
    </div>
  </div>
  <?php $tabs() ?>
  <?php if ($manage): ?>
  <div class="kpis k4">
    <div class="kpi"><span>근무 예정</span><b><?= $sum['work'] ?>명</b></div>
    <div class="kpi total"><span>출근</span><b><?= $sum['in'] ?>명</b></div>
    <div class="kpi"><span>미출근 · 기록 빠짐</span><b class="<?= $sum['miss'] ? 'warn' : '' ?>"><?= $sum['miss'] ?>명</b></div>
    <div class="kpi"><span>현장 밖 기록</span><b><?= $sum['off'] ?>명</b></div>
  </div>
  <?php else: ?>
  <p class="small">출퇴근은 <b>휴게실에 붙은 QR</b>을 휴대폰 카메라로 찍어서 합니다. 빠진 기록은 공무직에게 말씀해 주세요.</p>
  <?php endif ?>
  <div class="table-scroll">
  <table class="table clock-day">
    <thead><tr><th>근무자</th><th>근무</th><th>출근</th><th>퇴근</th><th>상태</th><th>기록</th></tr></thead>
    <tbody>
    <?php foreach ($rows as [$w, $day, $label, $cls]): [$ws, $we] = att_work_hours($w); ?>
      <tr>
        <td class="nowrap"><b><?= e($w['name']) ?></b><?php if ($manage): ?><br><small class="muted"><?= e(team_name($w['team_id'] ? (int) $w['team_id'] : null)) ?></small><?php endif ?></td>
        <td class="small nowrap"><?= att_is_rest_day($w, $date) ? '<span class="muted">휴무</span>' : e("$ws~$we") ?></td>
        <td class="nowrap"><?= clock_hm($day['in'] ?? null) ?: '-' ?><?php if (!empty($day['in'])): ?><br><small class="muted"><?= e($day['in']['place_name'] ?? '직접 입력') ?></small><?php endif ?></td>
        <td class="nowrap"><?= clock_hm($day['out'] ?? null) ?: '-' ?><?php if (!empty($day['out'])): ?><br><small class="muted"><?= e($day['out']['place_name'] ?? '직접 입력') ?></small><?php endif ?></td>
        <td><span class="cs <?= e($cls) ?>"><?= e($label) ?></span><?= !empty($day['offsite']) ? ' <span class="badge st-rejected" title="등록한 현장 인터넷이 아닌 곳에서 찍음">현장 밖</span>' : '' ?></td>
        <td class="small"><?php foreach ($day['all'] ?? [] as $r): ?>
          <div class="clock-log"><?= CLOCK_KINDS[$r['kind']] ?> <?= e(date('H:i', strtotime($r['at']))) ?>
            <?= $r['method'] === 'manual' ? '<span class="muted">✎ ' . e($r['created_name'] ?? '') . ' · ' . e($r['note'] ?? '') . '</span>' : '<span class="muted">' . e($r['place_name'] ?? '') . ($r['onsite'] ? '' : ' · 현장 밖') . '</span>' ?>
            <?php if ($manage): ?><form method="post" class="inline no-print" data-clock-del><?= csrf_field() ?><input type="hidden" name="act" value="delete"><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="note"><button class="btn small ghost" title="지우기 (사유 필요)">×</button></form><?php endif ?>
          </div>
        <?php endforeach ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="center muted">사원이 없습니다.</td></tr><?php endif ?>
    </tbody>
  </table>
  </div>
  <?php if ($manage): ?>
  <form method="post" class="clock-manual no-print">
    <?= csrf_field() ?><input type="hidden" name="act" value="manual"><input type="hidden" name="back" value="<?= e($back) ?>"><input type="hidden" name="work_date" value="<?= e($date) ?>">
    <h3>빠진 기록 넣기 <small class="muted"><?= e(date('n/j', strtotime($date))) ?> 근무 · 공무직 이상</small></h3>
    <div class="row">
      <label>근무자<select name="user_id" required><option value="">- 고르기 -</option><?php foreach ($workers as $w): ?><option value="<?= (int) $w['id'] ?>"><?= e($w['name']) ?></option><?php endforeach ?></select></label>
      <label>구분<select name="kind"><?php foreach (CLOCK_KINDS as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach ?></select></label>
      <label>시각<input type="datetime-local" name="at" value="<?= e($date . 'T' . ($date === $today ? date('H:i') : '09:00')) ?>" required></label>
      <label class="grow">사유<input name="note" maxlength="200" required placeholder="예: 휴대폰 두고 옴, 수기 서명부 확인"></label>
      <button class="btn primary">넣기</button>
    </div>
  </form>
  <script>
  document.querySelectorAll('[data-clock-del]').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      var why = prompt('이 기록을 지우는 사유를 적어 주세요 (지운 기록과 사유는 남습니다)');
      if (!why || !why.trim()) { ev.preventDefault(); return; }
      f.querySelector('[name=note]').value = why.trim();
    });
  });
  </script>
  <?php endif ?>
  <p class="muted small">밤에 출근해 다음 날 아침 퇴근하면 출근한 날의 근무로 묶습니다(퇴근 시각 옆 '(+1)'). 지각은 회원관리의 근무 시작 시각 기준이라, 날마다 근무 시간이 다르면 참고만 하세요.
    위치(GPS)는 쓰지 않고, 등록한 현장 인터넷에서 찍었는지만 봅니다.</p>
</section>
<?php layout_footer();
