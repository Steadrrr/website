<?php
/** 세부시설 상세 + 점검 이상 이력: facility.php?id=3[&all=1][&from=..&to=..] */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
$f = facility_find((int) ($_GET['id'] ?? 0)) ?? abort(404, '시설을 찾을 수 없습니다.');
$showAll = !empty($_GET['all']);
$from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : '';
$to = valid_date($_GET['to'] ?? '') ? $_GET['to'] : '';
$normal = normal_result();

$sql = "SELECT fi.*, j.work_date, j.id AS journal_id, j.status, u.name AS author_name
          FROM facility_items fi
          JOIN journals j ON j.id = fi.journal_id
          JOIN users u ON u.id = j.author_id
         WHERE fi.facility_id = ? AND j.status <> 'draft'"
     . ($showAll ? '' : ' AND fi.result <> ?')
     . ($from ? ' AND j.work_date >= ?' : '') . ($to ? ' AND j.work_date <= ?' : '')
     . ' ORDER BY j.work_date DESC, fi.id DESC';
$params = [$f['id']];
if (!$showAll) $params[] = $normal;
if ($from) $params[] = $from;
if ($to) $params[] = $to;
$st = db()->prepare($sql);
$st->execute($params);
$logs = $st->fetchAll();

// 결과별 건수 (전체 기간)
$st = db()->prepare("SELECT fi.result, COUNT(*) AS n FROM facility_items fi JOIN journals j ON j.id = fi.journal_id
                      WHERE fi.facility_id = ? AND j.status <> 'draft' GROUP BY fi.result");
$st->execute([$f['id']]);
$counts = array_column($st->fetchAll(), 'n', 'result');

$q = fn(array $extra) => 'facility.php?' . http_build_query(array_filter(['id' => $f['id'], 'all' => $showAll ? 1 : null, 'from' => $from, 'to' => $to, ...$extra]));

layout_header($f['name'], 'facilities');
?>
<article class="card">
  <div class="card-head">
    <div>
      <p class="muted small crumbs"><?= e($f['team_name']) ?> › <?= e($f['area']) ?></p>
      <h1><?= e($f['name']) ?> <?= $f['is_active'] ? '' : '<span class="badge">사용안함</span>' ?></h1>
    </div>
    <div class="actions no-margin no-print">
      <a class="btn ghost" href="<?= e(url('facilities.php?team=' . $f['team_id'])) ?>">목록</a>
      <button class="btn ghost" onclick="window.print()">인쇄</button>
      <?php if (can_manage_assets($user)): ?><a class="btn" href="<?= e(url('facility_edit.php?id=' . $f['id'])) ?>">수정</a><?php endif ?>
    </div>
  </div>

  <?php render_gallery(photos_for('facility', (int) $f['id'])) ?>

  <?php if ($f['spec']): ?><h3>규격·스펙</h3><div class="pre"><?= e($f['spec']) ?></div><?php endif ?>
  <?php if ($f['note']): ?><h3>비고</h3><div class="pre"><?= e($f['note']) ?></div><?php endif ?>

  <div class="kpis k<?= min(count(config('facility_results', [])), 4) ?> result-counts">
    <?php foreach (config('facility_results', []) as $r): ?>
      <div class="kpi"><span><?= e($r) ?></span><b class="result r-<?= e($r) ?>"><?= number_format($counts[$r] ?? 0) ?>건</b></div>
    <?php endforeach ?>
  </div>
</article>

<section class="card">
  <div class="card-head">
    <h2><?= $showAll ? '전체 점검 기록' : '점검 이상 이력' ?> <small class="muted"><?= count($logs) ?>건</small></h2>
    <div class="tabs no-print">
      <a href="<?= e(url($q(['all' => null]))) ?>" class="<?= $showAll ? '' : 'on' ?>">이상만</a>
      <a href="<?= e(url($q(['all' => 1]))) ?>" class="<?= $showAll ? 'on' : '' ?>">전체</a>
    </div>
  </div>
  <form class="filter no-print" method="get">
    <input type="hidden" name="id" value="<?= (int) $f['id'] ?>"><?php if ($showAll): ?><input type="hidden" name="all" value="1"><?php endif ?>
    <input type="date" name="from" value="<?= e($from) ?>"> ~ <input type="date" name="to" value="<?= e($to) ?>">
    <button class="btn small">조회</button>
  </form>
  <table class="table">
    <thead><tr><th>점검일</th><th>결과</th><th>내용 / 조치사항</th><th>점검자</th><th>문서</th></tr></thead>
    <tbody>
    <?php foreach ($logs as $l): ?>
      <tr class="clickable" onclick="location.href='<?= e(url('view.php?id=' . $l['journal_id'])) ?>'">
        <td class="nowrap"><?= e($l['work_date']) ?> (<?= weekday_ko($l['work_date']) ?>)</td>
        <td><span class="result r-<?= e($l['result']) ?>"><?= e($l['result']) ?></span></td>
        <td><?= e($l['note']) ?></td>
        <td><?= e($l['author_name']) ?></td>
        <td><?= status_badge($l['status']) ?></td>
      </tr>
    <?php endforeach ?>
    <?php if (!$logs): ?><tr><td colspan="5" class="center muted"><?= $showAll ? '점검 기록이 없습니다.' : '이상 이력이 없습니다.' ?></td></tr><?php endif ?>
    </tbody>
  </table>
</section>
<?php layout_footer();
