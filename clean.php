<?php
/**
 * 객실 청소관리 웹앱 (휴대폰 '홈 화면에 추가'로 앱처럼 사용) — clean.php[?date=YYYY-MM-DD]
 *   퇴실 객실: 퇴실대기 → 퇴실처리(청소가능) → 청소완료(입실가능), 연박 객실: 비품지급
 *   데이터는 clean_api.php, 알림은 clean-sw.js (app/clean.php · app/webpush.php)
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
require_menu($user, 'room');
$date = valid_date($_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
try {
    $vapid = webpush_keys()['public'];
} catch (Throwable $e) {
    $vapid = ''; // 서버에서 알림 키를 못 만들면 알림만 끈다
}
$site = config('site_name', '휴양림 업무일지');
$boot = [
    'state' => clean_state($date), 'today' => date('Y-m-d'), 'csrf' => csrf_token(), 'vapid' => $vapid, 'me' => $user['name'],
    'api' => url('clean_api.php'), 'sw' => url('clean-sw.js'), 'scope' => url(''), 'self' => url('clean.php'),
];
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>객실 청소관리 · <?= e($site) ?></title>
<meta name="theme-color" content="#1f5a38">
<link rel="manifest" href="<?= e(url('clean_manifest.php')) ?>">
<link rel="icon" href="<?= e(url('assets/app/clean-192.png')) ?>">
<link rel="apple-touch-icon" href="<?= e(url('assets/app/clean-180.png')) ?>">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="청소관리">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="stylesheet" href="<?= e(asset_url('assets/clean.css')) ?>">
</head>
<body>
<header class="cl-bar">
  <div class="cl-title"><b>객실 청소관리</b><small><?= e($user['name']) ?></small></div>
  <div class="cl-bar-actions">
    <button type="button" class="cl-icon" data-reload title="새로고침" aria-label="새로고침"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12a8 8 0 1 1-2.34-5.66"/><path d="M20 4v5h-5"/></svg></button>
    <button type="button" class="cl-icon" data-push title="알림">🔔</button>
    <a class="cl-icon" href="<?= e(url('index.php')) ?>" title="업무일지 사이트">⌂</a>
  </div>
</header>
<nav class="cl-date">
  <button type="button" data-day="-1" aria-label="전날">‹</button>
  <b data-date-label></b>
  <button type="button" data-day="1" aria-label="다음날">›</button>
  <button type="button" class="cl-today" data-today>오늘</button>
</nav>
<main class="cl-main">
  <section class="cl-summary">
    <div class="cl-progress"><i data-progress></i></div>
    <div class="cl-progress-text" data-progress-text></div>
    <div class="cl-chips" data-chips></div>
  </section>
  <div class="cl-allready" data-allready hidden>✓ 전객실 입실준비완료</div>
  <div class="cl-notice" data-push-notice hidden></div>
  <div data-list></div>
  <p class="cl-foot">객실 목록은 객실판매관리의 일일객실판매(입퇴실현황과 같은 기준)에서 가져옵니다. 화면은 15초마다 저절로 새로 고치고, 위쪽 새로고침 버튼으로 바로 고칠 수 있습니다.</p>
</main>
<div class="cl-toast" data-toast hidden></div>
<script>window.CLEAN = <?= json_encode($boot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= e(asset_url('assets/clean.js')) ?>"></script>
</body>
</html>
