<?php
/**
 * 시설관리 › 물품구매 › 검수사진 저장 — purchase_photos.php?from=&to=[&vendor=&pay=&st=][&zip=1]
 *   보고서와 같은 조건(기간·구매처·결제·상태)의 검수사진을 구매일 → 구매처 순으로 쭉 보여 주고,
 *   '전체 다운로드'(zip=1)를 누르면 모두 zip 하나로 받는다. zip 안 이름: 구매일_구매처_번호.jpg
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/zip.php';

$user = require_login();
require_menu($user, 'fac');
if (!can_purchase($user)) abort(403, '물품구매는 공무직 이상만 사용할 수 있습니다.');

$f = purchase_report_filter();
$rows = purchase_report_rows($f);

// 구매별 검수사진 + zip 안 이름 (같은 날 같은 구매처는 번호를 이어서)
$photos = [];
if ($rows) {
    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $st = db()->prepare("SELECT * FROM photos WHERE owner_type = 'purchase_check' AND owner_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
    $st->execute($ids);
    foreach ($st as $p) if (is_file(APP_ROOT . '/' . $p['path'])) $photos[(int) $p['owner_id']][] = $p;
}
$seq = [];
$files = [];
$bytes = 0;
foreach ($rows as &$r) {
    $r['photos'] = [];
    foreach ($photos[(int) $r['id']] ?? [] as $p) {
        $key = $r['work_date'] . '_' . zip_safe_name((string) $r['vendor']);
        $seq[$key] = ($seq[$key] ?? 0) + 1;
        $ext = strtolower(pathinfo($p['path'], PATHINFO_EXTENSION)) ?: 'jpg';
        $p['zip_name'] = $key . '_' . $seq[$key] . '.' . $ext;
        $r['photos'][] = $p;
        $files[] = ['name' => $p['zip_name'], 'path' => APP_ROOT . '/' . $p['path'], 'time' => strtotime($p['created_at'])];
        $bytes += filesize(APP_ROOT . '/' . $p['path']);
    }
}
unset($r);

if (!empty($_GET['zip'])) {
    if (!$files) abort(404, '받을 검수사진이 없습니다.');
    zip_send('검수사진_' . $f['from'] . '_' . $f['to'] . ($f['vendor'] !== '' ? '_' . zip_safe_name($f['vendor']) : '') . '.zip', $files);
}

$withPhoto = count(array_filter($rows, fn($r) => $r['photos']));
layout_header('검수사진 저장', 'purchase');
purchase_report_form($f, 'purchase_photos.php');
?>
<section class="card">
  <div class="card-head">
    <h1>검수사진 저장 <small class="muted"><?= e(date('Y. n. j.', strtotime($f['from']))) ?> ~ <?= e(date('Y. n. j.', strtotime($f['to']))) ?> · <?= e(purchase_report_cond_text($f)) ?></small></h1>
    <div class="actions no-margin">
      <a class="btn ghost" href="<?= e(url('purchase_report.php?' . purchase_report_query($f))) ?>">보고서</a>
      <?php if ($files): ?><a class="btn primary" href="<?= e(url('purchase_photos.php?' . purchase_report_query($f, ['zip' => '1']))) ?>">전체 다운로드 (zip)</a><?php endif ?>
    </div>
  </div>
  <p>구매 <b><?= count($rows) ?>건</b> · 검수사진 <b><?= count($files) ?>장</b> <span class="muted">(약 <?= e($bytes >= 1048576 ? number_format($bytes / 1048576, 1) . 'MB' : max(1, (int) round($bytes / 1024)) . 'KB') ?>)</span>
    <?php if (count($rows) > $withPhoto): ?><span class="warn small"> · 사진 없는 구매 <?= count($rows) - $withPhoto ?>건</span><?php endif ?></p>
  <p class="muted small">구매일 → 구매처 순입니다. zip 안의 파일 이름은 <b>구매일_구매처_번호</b>(예: 2026-09-05_철물점_1.jpg)라 그 순서대로 정렬됩니다. 임시저장·반려된 구매는 넣지 않습니다.</p>
</section>

<?php foreach ($rows as $r): ?>
<section class="card pc-photo-row">
  <h3><?= e(date('n/j', strtotime($r['work_date']))) ?> (<?= weekday_ko($r['work_date']) ?>) · <?= e($r['vendor']) ?>
    <small class="muted"><?= e((string) $r['items']) ?> · <?= e(won((int) $r['total'])) ?></small>
    <a class="small" href="<?= e(url('view.php?id=' . (int) $r['id'])) ?>">문서 보기 ›</a></h3>
  <?php if (!$r['photos']): ?>
    <p class="muted small">검수사진이 없습니다.</p>
  <?php else: ?>
    <div class="gallery">
      <?php foreach ($r['photos'] as $p): ?>
        <figure class="pc-photo"><a class="thumb" href="<?= e(url($p['path'])) ?>" target="_blank"><img src="<?= e(url($p['path'])) ?>" alt="" loading="lazy"></a>
          <figcaption><a href="<?= e(url($p['path'])) ?>" download="<?= e($p['zip_name']) ?>" title="이 사진만 받기"><?= e($p['zip_name']) ?></a></figcaption></figure>
      <?php endforeach ?>
    </div>
  <?php endif ?>
</section>
<?php endforeach ?>
<?php if (!$rows): ?><section class="card"><p class="muted">조건에 맞는 구매 내역이 없습니다.</p></section><?php endif ?>
<?php layout_footer();
