<?php
/** 공지사항: 목록 notices.php?page=2 / 보기 notices.php?id=3 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
$pdo = db();
$id = (int) ($_GET['id'] ?? 0);

if ($id) {
    $st = $pdo->prepare('SELECT n.*, u.name AS author_name, u.rank_level FROM notices n JOIN users u ON u.id = n.author_id WHERE n.id = ?');
    $st->execute([$id]);
    $n = $st->fetch() ?: abort(404, '공지를 찾을 수 없습니다.');

    layout_header($n['title'], 'notices');
    ?>
<article class="card notice-view">
  <p class="muted small crumbs"><a href="<?= e(url('notices.php')) ?>">공지사항</a></p>
  <h1><?= $n['is_pinned'] ? '<span class="badge pin">중요</span> ' : '' ?><?= e($n['title']) ?></h1>
  <p class="muted small"><?= e($n['author_name']) ?> <?= e(rank_name($n['rank_level'])) ?> · <?= e(date('Y-m-d H:i', strtotime($n['created_at']))) ?>
    <?= $n['updated_at'] ? ' · 수정 ' . e(date('Y-m-d H:i', strtotime($n['updated_at']))) : '' ?></p>
  <div class="notice-body"><?= nl2br(e($n['body'])) ?></div>
</article>
<div class="actions no-print">
  <a class="btn ghost" href="<?= e(url('notices.php')) ?>">목록</a>
  <button class="btn ghost" onclick="window.print()">인쇄</button>
  <?php if (can_write_notice($user)): ?><a class="btn" href="<?= e(url('notice_edit.php?id=' . $n['id'])) ?>">수정</a><?php endif ?>
</div>
    <?php
    layout_footer();
    exit;
}

$perPage = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));
$total = (int) $pdo->query('SELECT COUNT(*) FROM notices')->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));
$st = $pdo->prepare(
    'SELECT n.id, n.title, n.is_pinned, n.created_at, u.name AS author_name FROM notices n JOIN users u ON u.id = n.author_id
      ORDER BY n.is_pinned DESC, n.created_at DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage)
);
$st->execute();
$list = $st->fetchAll();

layout_header('공지사항', 'notices');
?>
<section class="card">
  <div class="card-head">
    <h1>공지사항</h1>
    <?php if (can_write_notice($user)): ?><a class="btn primary" href="<?= e(url('notice_edit.php')) ?>">+ 공지 쓰기</a><?php endif ?>
  </div>
  <table class="table">
    <thead><tr><th>제목</th><th>작성자</th><th>등록일</th></tr></thead>
    <tbody>
    <?php foreach ($list as $n): ?>
      <tr class="clickable <?= $n['is_pinned'] ? 'pinned' : '' ?>" onclick="location.href='<?= e(url('notices.php?id=' . $n['id'])) ?>'">
        <td><?= $n['is_pinned'] ? '<span class="badge pin">중요</span> ' : '' ?><?= e($n['title']) ?><?= is_new($n['created_at']) ? ' <span class="new-dot">N</span>' : '' ?></td>
        <td class="nowrap"><?= e($n['author_name']) ?></td>
        <td class="nowrap"><?= e(date('Y-m-d', strtotime($n['created_at']))) ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$list): ?><tr><td colspan="3" class="center muted">등록된 공지가 없습니다.</td></tr><?php endif ?>
    </tbody>
  </table>
  <?php if ($pages > 1): ?>
    <div class="pager">
      <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a href="<?= e(url('notices.php?page=' . $i)) ?>" class="<?= $i === $page ? 'on' : '' ?>"><?= $i ?></a>
      <?php endfor ?>
    </div>
  <?php endif ?>
</section>
<?php layout_footer();
