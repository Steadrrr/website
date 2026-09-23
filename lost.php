<?php
/**
 * 유실물관리 (운영관리 › 유실물관리)
 *   lost.php[?status=received&q=지갑]   액자식 갤러리 (상태별·검색)
 *   lost.php?id=3                        상세 (상태 바로 변경)
 *   lost.php?edit=3 | ?edit=new           등록·수정 (사진은 저해상도로 줄여서 저장)
 * 모든 직원이 등록·수정할 수 있고, 삭제는 등록자·주무관 이상·최고관리자.
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
$pdo = db();
const LOST_PHOTO_MAX = 800; // 사진 긴 변(px)

function lost_find(int $id): ?array
{
    $st = db()->prepare('SELECT l.*, a.name AS author_name, b.name AS editor_name FROM lost_items l
                           JOIN users a ON a.id = l.author_id LEFT JOIN users b ON b.id = l.updated_by WHERE l.id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function lost_can_delete(array $item, array $user): bool
{
    return (int) $item['author_id'] === (int) $user['id'] || (int) $user['rank_level'] >= RANK_OFFICER || !empty($user['is_admin']);
}

function lost_badge(string $status): string
{
    [$label, $color] = LOST_STATUS[$status] ?? [$status, '#666'];
    return '<span class="badge lost-badge" style="--c: ' . $color . '">' . e($label) . '</span>';
}

function lost_photo(array $item, string $class = ''): string
{
    if ($item['photo'] && is_file(APP_ROOT . '/' . $item['photo'])) {
        return '<img class="' . e($class) . '" src="' . e(url($item['photo'])) . '" alt="' . e($item['name']) . '" loading="lazy">';
    }
    return '<span class="' . e($class) . ' lost-nophoto">사진 없음</span>';
}

/* ───────────── 저장 · 상태 변경 · 삭제 ───────────── */
if (is_post()) {
    csrf_verify();
    $id = (int) post('id');
    $item = $id ? (lost_find($id) ?? abort(404, '유실물을 찾을 수 없습니다.')) : null;
    $action = post('action');

    if ($action === 'delete' && $item) {
        if (!lost_can_delete($item, $user)) abort(403, '삭제는 등록한 사람 또는 주무관 이상만 할 수 있습니다.');
        $pdo->prepare('DELETE FROM lost_items WHERE id = ?')->execute([$id]);
        if ($item['photo'] && str_starts_with($item['photo'], 'uploads/lost/')) @unlink(APP_ROOT . '/' . $item['photo']);
        flash("'{$item['name']}' 유실물을 삭제했습니다.", 'success');
        redirect('lost.php');
    }

    if ($action === 'status' && $item) {
        $status = post('status');
        if (!isset(LOST_STATUS[$status])) abort(400, '잘못된 상태입니다.');
        $pdo->prepare('UPDATE lost_items SET status = ?, updated_by = ?, updated_at = NOW() WHERE id = ?')->execute([$status, $user['id'], $id]);
        flash("'{$item['name']}' 상태를 " . LOST_STATUS[$status][0] . '(으)로 바꿨습니다.', 'success');
        redirect('lost.php?id=' . $id);
    }

    // 등록·수정
    $name = mb_substr(post('name'), 0, 100);
    $date = post('found_date');
    $status = post('status');
    $errors = [];
    if ($name === '') $errors[] = '물품명을 입력하세요.';
    if (!valid_date($date)) $errors[] = '습득일을 확인하세요.';
    if (!isset(LOST_STATUS[$status])) $errors[] = '상태를 고르세요.';
    $photo = $item['photo'] ?? null;
    $newPhoto = null;
    if (!$errors) {
        $f = $_FILES['photo'] ?? null;
        [$newPhoto, $upErr] = $f ? store_uploaded_image((string) $f['name'], (string) $f['tmp_name'], (int) $f['error'], 'lost') : [null, null];
        if ($upErr) $errors[] = $upErr;
    }
    if ($errors) {
        foreach ($errors as $m) flash($m, 'error');
        redirect('lost.php?edit=' . ($id ?: 'new'));
    }
    if ($newPhoto) {
        image_downscale(APP_ROOT . '/' . $newPhoto, LOST_PHOTO_MAX); // 브라우저에서 못 줄였을 때 대비
        if ($photo && str_starts_with($photo, 'uploads/lost/')) @unlink(APP_ROOT . '/' . $photo);
        $photo = $newPhoto;
    } elseif (post('remove_photo') === '1' && $photo) {
        if (str_starts_with($photo, 'uploads/lost/')) @unlink(APP_ROOT . '/' . $photo);
        $photo = null;
    }
    $row = [$name, $date, mb_substr(post('place'), 0, 100) ?: null, mb_substr(post('finder'), 0, 50) ?: null, post('memo') ?: null, $status, $photo];
    if ($item) {
        $pdo->prepare('UPDATE lost_items SET name = ?, found_date = ?, place = ?, finder = ?, memo = ?, status = ?, photo = ?, updated_by = ?, updated_at = NOW() WHERE id = ?')
            ->execute([...$row, $user['id'], $id]);
    } else {
        $pdo->prepare('INSERT INTO lost_items (name, found_date, place, finder, memo, status, photo, author_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([...$row, $user['id']]);
        $id = (int) $pdo->lastInsertId();
    }
    flash("'{$name}' 유실물을 저장했습니다.", 'success');
    redirect('lost.php?id=' . $id);
}

/* ═════════════ 등록 · 수정 ═════════════ */
if (isset($_GET['edit'])) {
    $item = $_GET['edit'] === 'new' ? null : (lost_find((int) $_GET['edit']) ?? abort(404, '유실물을 찾을 수 없습니다.'));
    layout_header($item ? '유실물 수정' : '유실물 등록', 'lost');
    $hasPhoto = $item && $item['photo'] && is_file(APP_ROOT . '/' . $item['photo']);
    ?>
<form method="post" enctype="multipart/form-data" class="card narrow lost-form">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($item['id'] ?? 0) ?>">
  <div class="card-head">
    <h1><?= $item ? '유실물 수정' : '유실물 등록' ?></h1>
    <a class="btn ghost" href="<?= e(url($item ? 'lost.php?id=' . $item['id'] : 'lost.php')) ?>">취소</a>
  </div>
  <div class="lost-form-grid">
    <div class="lost-photo-pick">
      <div class="lost-frame lost-frame-lg">
        <img id="lostPreview" src="<?= $hasPhoto ? e(url($item['photo'])) : '' ?>" alt="" <?= $hasPhoto ? '' : 'hidden' ?>>
        <?php if (!$hasPhoto): ?><span class="lost-nophoto" data-nophoto>사진을 골라 주세요</span><?php endif ?>
      </div>
      <label class="btn small">📷 사진 <?= $hasPhoto ? '바꾸기' : '선택' ?>
        <input type="file" name="photo" accept="image/*" data-resize="<?= LOST_PHOTO_MAX ?>" data-quality="0.7" data-preview="#lostPreview" hidden
               onchange="var n=document.querySelector('[data-nophoto]'); if(n) n.hidden=true">
      </label>
      <?php if ($hasPhoto): ?><label class="inline-check small"><input type="checkbox" name="remove_photo" value="1"> 사진 삭제</label><?php endif ?>
      <p class="muted tiny-text">사진은 긴 변 <?= LOST_PHOTO_MAX ?>px 저해상도로 줄여서 올라갑니다. 휴대폰으로 바로 찍어도 됩니다.</p>
    </div>
    <div>
      <label>물품명<input name="name" value="<?= e($item['name'] ?? '') ?>" maxlength="100" placeholder="예: 검은색 반지갑" required></label>
      <div class="row">
        <label>습득일<input type="date" name="found_date" value="<?= e($item['found_date'] ?? date('Y-m-d')) ?>" required></label>
        <label>습득장소<input name="place" value="<?= e($item['place'] ?? '') ?>" maxlength="100" placeholder="예: 숲속의집 102호"></label>
      </div>
      <label>습득자<input name="finder" value="<?= e($item['finder'] ?? $user['name']) ?>" maxlength="50"></label>
      <label>상태</label>
      <div class="ev-cats">
        <?php foreach (LOST_STATUS as $k => [$label, $color]): ?>
          <label style="--c: <?= $color ?>"><input type="radio" name="status" value="<?= $k ?>" <?= ($item['status'] ?? 'received') === $k ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
        <?php endforeach ?>
      </div>
      <label>메모<textarea name="memo" rows="4" placeholder="예: 신분증 있음, 소유자 연락처, 택배 송장번호 등"><?= e($item['memo'] ?? '') ?></textarea></label>
    </div>
  </div>
  <div class="actions"><button class="btn primary">저장</button></div>
</form>
<?php
    layout_footer();
    exit;
}

/* ═════════════ 상세 ═════════════ */
if (isset($_GET['id'])) {
    $item = lost_find((int) $_GET['id']) ?? abort(404, '유실물을 찾을 수 없습니다.');
    layout_header('유실물 · ' . $item['name'], 'lost');
    ?>
<article class="card lost-detail">
  <div class="lost-form-grid">
    <div class="lost-frame lost-frame-lg"><?= lost_photo($item) ?></div>
    <div>
      <div class="card-head">
        <h1><?= e($item['name']) ?> <?= lost_badge($item['status']) ?></h1>
      </div>
      <table class="table">
        <tr><th>습득일</th><td><?= e($item['found_date']) ?> (<?= weekday_ko($item['found_date']) ?>)</td></tr>
        <tr><th>습득장소</th><td><?= e($item['place'] ?: '-') ?></td></tr>
        <tr><th>습득자</th><td><?= e($item['finder'] ?: '-') ?></td></tr>
        <tr><th>등록</th><td><?= e($item['author_name']) ?> <small class="muted"><?= e(date('Y-m-d H:i', strtotime($item['created_at']))) ?></small></td></tr>
        <?php if ($item['updated_at']): ?><tr><th>마지막 수정</th><td><?= e($item['editor_name']) ?> <small class="muted"><?= e(date('Y-m-d H:i', strtotime($item['updated_at']))) ?></small></td></tr><?php endif ?>
      </table>
      <?php if ($item['memo']): ?><h3>메모</h3><div class="pre"><?= e($item['memo']) ?></div><?php endif ?>

      <form method="post" class="lost-status-form no-print">
        <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="action" value="status">
        <b class="small">상태 바꾸기</b>
        <div class="ev-cats">
          <?php foreach (LOST_STATUS as $k => [$label, $color]): ?>
            <label style="--c: <?= $color ?>"><input type="radio" name="status" value="<?= $k ?>" <?= $item['status'] === $k ? 'checked' : '' ?> onchange="this.form.submit()"><span><?= e($label) ?></span></label>
          <?php endforeach ?>
        </div>
      </form>
    </div>
  </div>
</article>
<div class="actions no-print">
  <a class="btn ghost" href="<?= e(url('lost.php')) ?>">목록</a>
  <button class="btn ghost" onclick="window.print()">인쇄</button>
  <a class="btn" href="<?= e(url('lost.php?edit=' . $item['id'])) ?>">수정</a>
  <?php if (lost_can_delete($item, $user)): ?>
    <form method="post" onsubmit="return confirm('이 유실물을 삭제할까요?')">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><button class="btn danger" name="action" value="delete">삭제</button>
    </form>
  <?php endif ?>
</div>
<?php
    layout_footer();
    exit;
}

/* ═════════════ 갤러리 ═════════════ */
$status = $_GET['status'] ?? '';
if (!isset(LOST_STATUS[$status]) && $status !== 'open') $status = '';
$q = trim((string) ($_GET['q'] ?? ''));
$where = ['1 = 1'];
$args = [];
if ($status === 'open') $where[] = "l.status <> 'returned'"; // 보관 중 (본인수령 제외)
elseif ($status) { $where[] = 'l.status = ?'; $args[] = $status; }
if ($q !== '') {
    $where[] = '(l.name LIKE ? OR l.place LIKE ? OR l.finder LIKE ? OR l.memo LIKE ?)';
    array_push($args, ...array_fill(0, 4, '%' . $q . '%'));
}
$st = $pdo->prepare('SELECT l.* FROM lost_items l WHERE ' . implode(' AND ', $where) . ' ORDER BY l.found_date DESC, l.id DESC LIMIT 300');
$st->execute($args);
$items = $st->fetchAll();
$counts = array_column($pdo->query('SELECT status, COUNT(*) AS n FROM lost_items GROUP BY status')->fetchAll(), 'n', 'status');
$total = array_sum($counts);
$open = $total - (int) ($counts['returned'] ?? 0);
$tab = fn(string $s) => 'lost.php?' . http_build_query(array_filter(['status' => $s, 'q' => $q]));

layout_header('유실물관리', 'lost');
?>
<section class="card">
  <div class="card-head">
    <h1>유실물관리 <small class="muted">보관 중 <?= $open ?>건 · 전체 <?= $total ?>건</small></h1>
    <a class="btn primary" href="<?= e(url('lost.php?edit=new')) ?>">+ 유실물 등록</a>
  </div>
  <div class="lost-toolbar">
    <div class="lost-tabs">
      <a href="<?= e(url($tab(''))) ?>" class="<?= $status === '' ? 'on' : '' ?>">전체 <small><?= $total ?></small></a>
      <a href="<?= e(url($tab('open'))) ?>" class="<?= $status === 'open' ? 'on' : '' ?>">보관 중 <small><?= $open ?></small></a>
      <?php foreach (LOST_STATUS as $k => [$label]): ?>
        <a href="<?= e(url($tab($k))) ?>" class="<?= $status === $k ? 'on' : '' ?>"><?= e($label) ?> <small><?= (int) ($counts[$k] ?? 0) ?></small></a>
      <?php endforeach ?>
    </div>
    <form method="get" class="lost-search">
      <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif ?>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="물품명·장소·습득자·메모 검색">
      <button class="btn small">검색</button>
    </form>
  </div>

  <?php if (!$items): ?>
    <p class="muted center">등록된 유실물이 없습니다.</p>
  <?php else: ?>
  <div class="lost-grid">
    <?php foreach ($items as $it): ?>
      <a class="lost-card <?= $it['status'] === 'returned' ? 'done' : '' ?>" href="<?= e(url('lost.php?id=' . $it['id'])) ?>">
        <div class="lost-frame"><?= lost_photo($it) ?></div>
        <div class="lost-caption">
          <b><?= e($it['name']) ?></b>
          <span class="lost-meta"><?= lost_badge($it['status']) ?> <small class="muted"><?= e(date('n/j', strtotime($it['found_date']))) ?><?= $it['place'] ? ' · ' . e($it['place']) : '' ?></small></span>
        </div>
      </a>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</section>
<?php layout_footer();
