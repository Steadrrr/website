<?php
defined('APP_ROOT') || exit;

/** $opt['shell'] = 탭 화면(shell.php): 메뉴 + 탭 틀만 그린다 */
function layout_header(string $title, string $active = '', array $opt = []): void
{
    $shell = !empty($opt['shell']);
    $GLOBALS['HELP_KEY'] = $active !== '' ? $active : basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''), '.php'); // '?' 버튼이 찾아갈 도움말
    $user = current_user();
    $site = config('site_name', '휴양림 업무일지');
    $waiting = $user ? count(waiting_for_user($user)) : 0;
    $groups = nav_groups($user);
    $current = null; // 지금 페이지가 속한 메인메뉴
    foreach ($groups as $gkey => $g) {
        if ($active === $gkey || isset($g['items'][$active])) $current = $gkey;
    }
    ?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($site) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
<?php if ($user && !$shell): // 탭 모드: 탭 안에서는 메뉴를 숨기고, 탭 밖에서 열리면 탭 화면(shell.php)으로 ?>
<script>(function () {
  var d = document.documentElement, inTab = false;
  try { inTab = window.top !== window.self && !!window.top.FORESTLOG_TABS; } catch (e) {}
  if (inTab) { d.classList.add('in-frame'); return; }
  <?php if (!is_post()): ?>var on = true;
  try { on = localStorage.getItem('forestlog.tabs') !== '0'; } catch (e) {}
  if (on && window.top === window.self && window.innerWidth >= 900) {
    location.replace(<?= json_encode(url('shell.php')) ?> + '#' + encodeURIComponent(location.pathname + location.search + location.hash));
  }<?php endif ?>
})();</script>
<?php elseif (!$user): // 로그인이 풀려 탭 안에 로그인 화면이 뜨면 전체 화면으로 ?>
<script>try { if (window.top !== window.self && window.top.FORESTLOG_TABS) window.top.location.href = location.href; } catch (e) {}</script>
<?php endif ?>
</head>
<body class="<?= $shell ? 'tab-shell' : '' ?>">
<header class="topbar">
  <a class="brand" href="<?= e(url('index.php')) ?>"><?= e($site) ?></a>
  <?php if ($user): ?>
  <button class="nav-toggle" type="button" aria-label="메뉴" onclick="document.body.classList.toggle('nav-open')">☰</button>
  <nav class="nav">
    <?php foreach ($groups as $gkey => $g):
        $badge = $gkey === 'personal' && $waiting > 0 ? ' <span class="count">' . $waiting . '</span>' : '';
        if (empty($g['items'])): ?>
      <a href="<?= e(url($g['href'])) ?>" class="nav-main <?= $current === $gkey ? 'on' : '' ?>"><?= e($g['label']) ?></a>
    <?php else: ?>
      <div class="nav-group <?= $current === $gkey ? 'on' : '' ?>">
        <button type="button" class="nav-main"><?= e($g['label']) ?><?= $badge ?> <span class="caret">▾</span></button>
        <div class="nav-sub">
          <?php foreach ($g['items'] as $key => [$href, $label]): ?>
            <a href="<?= e(url($href)) ?>" class="<?= $active === $key ? 'on' : '' ?>"><?= e($label) ?><?php
              if ($key === 'approval' && $waiting > 0): ?> <span class="count"><?= $waiting ?></span><?php endif ?></a>
          <?php endforeach ?>
        </div>
      </div>
    <?php endif; endforeach ?>
  </nav>
  <div class="me">
    <?php if (!empty($user['is_admin'])): ?><a href="<?= e(url('settings.php')) ?>" class="me-settings <?= $active === 'settings' ? 'on' : '' ?>" title="설정 (최고관리자)">⚙ 설정</a><?php endif ?>
    <a href="<?= e(url('mypage.php')) ?>"><?= e($user['name']) ?> <small><?= e(rank_name($user['rank_level'])) ?></small></a>
    <a href="<?= e(url('logout.php')) ?>" class="muted">로그아웃</a>
  </div>
  <?php endif ?>
</header>
<?php if ($shell): ?>
<nav class="subbar no-print" data-shell-subbar hidden></nav>
<?php elseif ($user && $current && !empty($groups[$current]['items'])): ?>
<nav class="subbar no-print">
  <span class="subbar-title"><?= e($groups[$current]['label']) ?></span>
  <?php foreach ($groups[$current]['items'] as $key => [$href, $label]): ?>
    <a href="<?= e(url($href)) ?>" class="<?= $active === $key ? 'on' : '' ?>"><?= e($label) ?><?php
      if ($key === 'approval' && $waiting > 0): ?> <span class="count"><?= $waiting ?></span><?php endif ?></a>
  <?php endforeach ?>
</nav>
<?php endif ?>
<main class="container">
<div class="print-only print-head"><b><?= e($site) ?></b> · <?= e($title) ?><span>출력 <?= e(date('Y-m-d H:i')) ?><?= $user ? ' · ' . e($user['name']) : '' ?></span></div>
<?php foreach (take_flashes() as $f): ?>
  <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach ?>
<?php
}

/**
 * 상단 메뉴: 메인메뉴 → 서브메뉴. 각 페이지는 layout_header() 두 번째 인자로 서브메뉴 키를 넘긴다.
 * @return array<string,array{label:string, href?:string, items?:array<string,array{0:string,1:string}>}>
 */
function nav_groups(?array $user): array
{
    $groups = [
        'home'     => ['label' => '대시보드', 'href' => 'index.php'],
        'schedule' => ['label' => '일정표', 'href' => 'schedule.php'],
        'personal' => ['label' => '개인업무', 'items' => [
            'attendance' => ['attendance.php', '근태 달력'],
            'att_sheet'  => ['attendance.php?view=sheet', '월간 근태'],
            'approval'   => ['approvals.php', '결재함'],
            'docs'       => ['docs.php', '문서관리'],
        ]],
        'ops'      => ['label' => '운영관리', 'items' => [
            'daily'   => ['journal.php?type=daily', '업무일지'],
            'sales'   => ['journal.php?type=sales', '매출보고'],
            'voucher' => ['voucher.php', '상품권관리'],
            'complaints' => ['complaints.php', '민원관리'],
            'lost'    => ['lost.php', '유실물관리'],
        ]],
        'room'     => ['label' => '객실관리', 'items' => [
            'rooms'    => ['journal.php?type=rooms', '객실판매관리'],
            'supplies' => ['supplies.php', '소모품관리'],
            'ar'       => ['ar.php', 'AR사용관리'],
        ]],
        'prog'     => ['label' => '프로그램', 'items' => [
            'healing'    => ['journal.php?type=healing', '산림치유센터'],
            'kidsforest' => ['journal.php?type=kidsforest', '유아숲체험원'],
            'kidsdirect' => ['journal.php?type=kidsdirect', '유아숲(직영)'],
            'guide'      => ['journal.php?type=guide', '숲해설'],
            'guide2'     => ['journal.php?type=guide2', '숲해설(용문산)'],
        ]],
        'stat'     => ['label' => '통계', 'items' => [
            'stats'      => ['stats.php', '매출통계'],
            'visit_stats' => ['visitor_stats.php', '입장객통계'],
            'room_stats' => ['room_stats.php', '객실이용통계'],
            'cpl_stats'  => ['complaint_stats.php', '민원통계'],
            'prog_stats' => ['program_stats.php', '프로그램 통계'],
        ]],
        'fac'      => ['label' => '시설관리', 'items' => [
            'facility'   => ['journal.php?type=facility', '시설점검'],
            'facilities' => ['facilities.php', '시설물'],
            'equipment'  => ['equipment.php', '장비'],
            'purchase'   => ['purchase.php', '물품구매'],
        ]],
        'etc'      => ['label' => '기타', 'items' => [
            'notices'  => ['notices.php', '공지사항'],
            'org'      => ['org.php', '조직도'],
            'sitemap'  => ['sitemap.php', '사이트맵'],
            'updates'  => ['updates.php', '업데이트'],
            'help'     => ['help.php', '도움말'],
        ]],
    ];
    foreach (array_keys(MENU_OPTIONAL) as $g) {
        if (!can_menu($user, $g)) unset($groups[$g]); // 회원관리에서 끈 메뉴는 숨김
    }
    if ($user && $user['is_admin']) {
        // 설정은 상단바 오른쪽 끝(이름 앞)에 따로 표시 — layout_header
    } elseif ($user && can_manage_users($user)) {
        $groups['etc']['items']['admin'] = ['admin/users.php', '회원관리'];
    }
    if (!can_menu($user, 'att')) unset($groups['personal']['items']['attendance'], $groups['personal']['items']['att_sheet']); // 근태는 메뉴 권한
    if (!$user || !can_manage_docs($user)) unset($groups['personal']['items']['docs']); // 문서관리는 공무직 이상
    if (!$user || !can_ar($user)) unset($groups['room']['items']['ar']); // AR사용관리는 공무직 이상
    if (!$user || !can_purchase($user)) unset($groups['fac']['items']['purchase']); // 물품구매는 공무직 이상
    // 기타는 항상 맨 마지막
    $etc = $groups['etc'];
    unset($groups['etc']);
    $groups['etc'] = $etc;
    return $groups;
}

function layout_footer(array $scripts = []): void
{
    ?>
</main>
<?php if (current_user() && !in_array($GLOBALS['HELP_KEY'] ?? '', ['help', 'shell'], true)):
    $anchor = help_anchor((string) ($GLOBALS['HELP_KEY'] ?? '')); ?>
<a class="help-fab no-print" href="<?= e(url('help.php') . ($anchor ? '#' . $anchor : '')) ?>" target="_blank" data-help-fab title="이 화면 도움말" aria-label="이 화면 도움말">?</a>
<?php endif ?>
<?php if (current_user()): ?>
<footer class="footer"><?= e(config('site_name', '')) ?></footer>
<?php else: // 로그인·회원가입 화면: 사이트 이름을 크게 + 저작권 문구 ?>
<footer class="footer footer-login">
  <div class="footer-site"><?= e(config('site_name', '')) ?></div>
  <div class="footer-copy">Copyright © 2026 임일래 · Made with Claude.ai</div>
</footer>
<?php endif ?>
<script src="<?= e(url('assets/app.js')) ?>"></script>
<?php foreach ($scripts as $src): ?>
<script src="<?= e($src) ?>"></script>
<?php endforeach ?>
</body>
</html>
<?php
}

/** 결재란(도장칸) 표시 */
function render_approval_box(array $journal, array $approvals): void
{
    ?>
<table class="stampbox">
  <tr>
    <th>작성</th>
    <?php foreach ($approvals as $a): ?><th><?= e(rank_name($a['required_rank'])) ?></th><?php endforeach ?>
  </tr>
  <tr>
    <td><b><?= e($journal['author_name']) ?></b><small><?= e(rank_name($journal['author_rank'])) ?></small></td>
    <?php foreach ($approvals as $a): ?>
      <td class="stamp-<?= e($a['status']) ?>">
        <?php if ($a['status'] === 'approved'): ?><b><?= e($a['approver_name']) ?></b><small>승인 <?= e(date('m/d H:i', strtotime($a['acted_at']))) ?></small>
        <?php elseif ($a['status'] === 'rejected'): ?><b><?= e($a['approver_name']) ?></b><small>반려 <?= e(date('m/d H:i', strtotime($a['acted_at']))) ?></small>
        <?php elseif ($a['status'] === 'skipped'): ?><b class="delegated">전결</b><small><?= e($a['approver_name']) ?> <?= e(date('m/d', strtotime($a['acted_at']))) ?></small>
        <?php else: ?><small>대기</small><?php endif ?>
      </td>
    <?php endforeach ?>
  </tr>
</table>
<?php
}

/** 설정 메뉴의 하위 메뉴 (최고관리자) */
const SETTINGS_MENU = [
    'general'   => ['settings.php?tab=general', '기본 정보'],
    'org'       => ['settings.php?tab=org', '조직 구성'],
    'users'     => ['admin/users.php', '회원관리'],
    'products'  => ['admin/products.php', '상품·요금'],
    'prices'    => ['admin/prices.php', '기간별 가격'],
    'facility'  => ['groups.php?kind=facility', '시설 구역·건물'],
    'equipment' => ['groups.php?kind=equipment', '장비 분류'],
];

function settings_nav(string $active): void
{
    $GLOBALS['HELP_KEY'] = 'settings_' . $active;
    ?>
<nav class="settings-nav no-print">
  <b>⚙ 설정</b>
  <?php foreach (SETTINGS_MENU as $key => [$href, $label]): ?>
    <a href="<?= e(url($href)) ?>" class="<?= $active === $key ? 'on' : '' ?>"><?= e($label) ?></a>
  <?php endforeach ?>
</nav>
<?php
}
