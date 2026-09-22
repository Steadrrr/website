<?php
defined('APP_ROOT') || exit;

function layout_header(string $title, string $active = ''): void
{
    $user = current_user();
    $site = config('site_name', '휴양림 업무일지');
    $waiting = $user ? count(waiting_for_user($user)) : 0;
    $nav = [
        'home'     => ['index.php', '대시보드'],
        'daily'    => ['journal.php?type=daily', '업무일지'],
        'sales'    => ['journal.php?type=sales', '매출보고'],
        'facility' => ['journal.php?type=facility', '시설물관리'],
        'approval' => ['approvals.php', '결재함'],
    ];
    ?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e($site) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/style.css')) ?>">
</head>
<body>
<header class="topbar">
  <a class="brand" href="<?= e(url('index.php')) ?>"><?= e($site) ?></a>
  <?php if ($user): ?>
  <nav class="nav">
    <?php foreach ($nav as $key => [$href, $label]): ?>
      <a href="<?= e(url($href)) ?>" class="<?= $active === $key ? 'on' : '' ?>"><?= e($label) ?><?php
        if ($key === 'approval' && $waiting > 0): ?> <span class="count"><?= $waiting ?></span><?php endif ?></a>
    <?php endforeach ?>
    <?php if (can_manage_users($user)): ?>
      <a href="<?= e(url('admin/users.php')) ?>" class="<?= $active === 'admin' ? 'on' : '' ?>">회원관리</a>
    <?php endif ?>
  </nav>
  <div class="me">
    <a href="<?= e(url('mypage.php')) ?>"><?= e($user['name']) ?> <small><?= e(rank_name($user['rank_level'])) ?></small></a>
    <a href="<?= e(url('logout.php')) ?>" class="muted">로그아웃</a>
  </div>
  <?php endif ?>
</header>
<main class="container">
<?php foreach (take_flashes() as $f): ?>
  <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach ?>
<?php
}

function layout_footer(array $scripts = []): void
{
    ?>
</main>
<footer class="footer"><?= e(config('site_name', '')) ?></footer>
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
        <?php else: ?><small>대기</small><?php endif ?>
      </td>
    <?php endforeach ?>
  </tr>
</table>
<?php
}
