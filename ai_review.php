<?php
/**
 * AI 분석 › 지난 예측 되돌아보기 — "그날 예측했다면 맞았을까?"
 *   ai_review.php?basis=YYYY-MM-DD   그날까지 기록으로 규칙 예측 → 다음 4주 실제와 비교
 *   ai_review.php?id=N               그날 발행된 AI 예측도 함께 비교 (기준일 = 발행 전날)
 * 계산은 app/ai_review.php (API 를 부르지 않음 — 비용 없음)
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
$can = ['민원' => can_menu($user, 'ops'), '시설' => can_menu($user, 'fac')];

$insight = null;
if ($id = (int) ($_GET['id'] ?? 0)) {
    $st = db()->prepare("SELECT a.*, u.name AS user_name FROM ai_insights a LEFT JOIN users u ON u.id = a.created_by WHERE a.id = ? AND a.kind = 'forecast'");
    $st->execute([$id]);
    $insight = $st->fetch() ?: null;
}
$yesterday = date('Y-m-d', strtotime('-1 day'));
$basis = $insight ? date('Y-m-d', strtotime($insight['period_from'] . ' -1 day')) : (string) ($_GET['basis'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $basis) || !strtotime($basis) || $basis >= $yesterday || $basis < '2020-01-01') {
    $basis = date('Y-m-d', strtotime('-' . (AI_HORIZON_DAYS + 1) . ' days')); // 기본: 4주가 다 끝난 가장 최근 기준일
}
$r = ai_lookback($basis);
$items = array_values(array_filter($r['items'], fn($i) => $can[$i['area']]));
$missed = array_values(array_filter($r['missed'], fn($i) => $can[$i['area']]));
$aiRows = $insight ? array_values(array_filter(ai_lookback_ai($insight), fn($i) => $can[$i['area']] ?? false)) : [];
$cnt = fn(array $rows, string $res) => count(array_filter($rows, fn($i) => $i['result'] === $res));
$hit = $cnt($items, 'hit');
$near = $cnt($items, 'near');
$total = AI_HORIZON_DAYS;
$md = fn(string $d) => date('n/j', strtotime($d));
$ymd = fn(string $d) => date('Y.n.j', strtotime($d));
$mark = ['hit' => ['✅', '일어남'], 'near' => ['🟡', '비슷한 일'], 'miss' => ['⬜', '안 일어남'], 'skip' => ['➖', '자동 비교 안 됨']];
$areaIcon = ['민원' => '💬', '시설' => '🛠', '소모품' => '🧴', '객실운영' => '🛏'];
$quick = ['4주 전' => date('Y-m-d', strtotime('-' . ($total + 1) . ' days')), '8주 전' => date('Y-m-d', strtotime('-' . ($total * 2 + 1) . ' days')),
          '작년 이맘때' => ai_minus_year(date('Y-m-d', strtotime('-1 day')))];
$pubs = ai_insights_list();

layout_header('지난 예측 되돌아보기', 'ai');
?>
<div class="card ai-head">
  <div>
    <h1>🔁 지난 예측 되돌아보기 <small class="muted">그날 예측했다면 맞았을까?</small></h1>
    <p class="muted">고른 날까지의 기록만 보고 AI 분석 화면과 <b>같은 규칙</b>으로 '그다음 4주' 예측을 다시 만든 뒤, 그 4주 동안 <b>실제로 생긴 민원·설비 수리</b>와 비교합니다.
      그날 발행된 AI 예측이 있으면 그것도 함께 맞춰 봅니다. AI를 부르지 않으므로 비용이 들지 않습니다.</p>
  </div>
  <a class="btn" href="<?= e(url('ai.php')) ?>">← AI 분석</a>
</div>

<div class="card">
  <form method="get" class="air-pick">
    <span>기준일</span>
    <?php foreach ($quick as $label => $d): ?><a class="btn<?= !$insight && $d === $basis ? ' primary' : '' ?>" href="<?= e(url('ai_review.php?basis=' . $d)) ?>"><?= e($label) ?></a><?php endforeach ?>
    <input type="date" name="basis" value="<?= e($basis) ?>" max="<?= e(date('Y-m-d', strtotime('-2 days'))) ?>">
    <button class="btn">보기</button>
    <?php if ($pubs): ?>
      <select onchange="if(this.value)location.href=this.value" aria-label="지난 AI 발행본">
        <option value="">지난 AI 발행본 고르기…</option>
        <?php foreach ($pubs as $p): ?><option value="<?= e(url('ai_review.php?id=' . $p['id'])) ?>"<?= $insight && (int) $insight['id'] === (int) $p['id'] ? ' selected' : '' ?>><?= e($ymd($p['created_at'])) ?> 발행 (<?= e($md($p['period_from'])) ?>~<?= e($md($p['period_to'])) ?>)</option><?php endforeach ?>
      </select>
    <?php endif ?>
  </form>
  <p class="air-period"><b><?= e($ymd($basis)) ?></b>까지의 기록으로 → <b><?= e($md($r['from'])) ?> ~ <?= e($md($r['to'])) ?></b> 4주를 예측했다면
    <?php if ($r['done'] < $total): ?><span class="chip">아직 진행 중 · <?= (int) $r['done'] ?>/<?= $total ?>일 지남</span><?php endif ?></p>
  <div class="kpis k4">
    <div class="kpi"><span>그때 예측</span><b><?= count($items) ?>건</b></div>
    <div class="kpi"><span>✅ 실제로 일어남</span><b><?= $hit ?>건</b><?php if ($items): ?> <small class="muted"><?= round($hit / count($items) * 100) ?>%</small><?php endif ?></div>
    <div class="kpi"><span>🟡 비슷한 일</span><b><?= $near ?>건</b></div>
    <div class="kpi"><span>❗ 예측에 없던 일</span><b><?= count($missed) ?>건</b></div>
  </div>
  <?php if (!$can['민원'] && !$can['시설']): ?><p class="muted">민원(운영관리)·시설(시설관리) 메뉴 권한이 있는 직원에게 보입니다.</p><?php endif ?>
</div>

<?php if ($insight): ?>
<section class="card ai-result">
  <h2>그날 발행된 AI 예측 <small class="muted"><?= e(date('Y.n.j H:i', strtotime($insight['created_at']))) ?> · <?= e($insight['user_name'] ?? '') ?></small></h2>
  <?php if (!$aiRows): ?><p class="muted">비교할 항목이 없습니다.</p><?php else: ?>
  <table class="table air-table">
    <thead><tr><th>결과</th><th>구분</th><th>AI 예측</th><th>실제 기록</th></tr></thead>
    <tbody><?php foreach ($aiRows as $i): [$ic, $lb] = $mark[$i['result']]; ?>
      <tr class="air-<?= e($i['result']) ?>"><td><?= $ic ?> <?= e($lb) ?></td><td><?= e(($areaIcon[$i['area']] ?? '') . ' ' . $i['area']) ?></td><td><b><?= e($i['title']) ?></b></td><td class="muted"><?= e($i['actual']) ?></td></tr>
    <?php endforeach ?></tbody>
  </table>
  <p class="muted tiny-text">AI 예측 문장 속 설비 이름·객실 이름·분류 낱말(에어컨, 온수, 벌집 등)로 실제 기록을 찾아 맞춥니다. 찾을 낱말이 없으면 '자동 비교 안 됨'으로 둡니다.</p>
  <?php endif ?>
</section>
<?php endif ?>

<?php if ($can['민원'] || $can['시설']): ?>
<section class="card">
  <h2>규칙 예측 vs 실제 <small class="muted"><?= count($items) ?>건</small></h2>
  <?php if (!$items): ?><p class="muted">그날까지 기록으로는 눈에 띄는 예측이 없었습니다 (작년 기록이나 반복 기록이 부족).</p><?php else: ?>
  <table class="table air-table">
    <thead><tr><th>결과</th><th>구분</th><th>그때 예측</th><th>근거 (그날까지 기록)</th><th>실제 (<?= e($md($r['from'])) ?>~<?= e($md($r['to'])) ?>)</th></tr></thead>
    <tbody><?php foreach ($items as $i): [$ic, $lb] = $mark[$i['result']]; ?>
      <tr class="air-<?= e($i['result']) ?>"><td><?= $ic ?> <?= e($lb) ?></td><td><?= e($areaIcon[$i['area']] . ' ' . $i['area']) ?></td><td><b><?= e($i['title']) ?></b></td><td class="muted"><?= e($i['why']) ?></td><td><?= e($i['actual']) ?></td></tr>
    <?php endforeach ?></tbody>
  </table>
  <?php endif ?>
</section>

<section class="card">
  <h2>❗ 예측에 없던 일 <small class="muted">그 4주에 실제로 생겼지만 미리 알 수 없었던 것</small></h2>
  <?php if (!$missed): ?><p class="muted">없습니다.</p><?php else: ?>
  <table class="table air-table">
    <thead><tr><th>구분</th><th>무슨 일</th><th>실제</th></tr></thead>
    <tbody><?php foreach ($missed as $i): ?><tr><td><?= e($areaIcon[$i['area']] . ' ' . $i['area']) ?></td><td><?= e($i['title']) ?></td><td class="muted"><?= e($i['actual']) ?></td></tr><?php endforeach ?></tbody>
  </table>
  <?php endif ?>
  <p class="muted tiny-text">처음 생긴 고장·사고는 기록이 없어 미리 알 수 없습니다. 같은 이름(객실·설비·분류)으로 기록이 쌓일수록 다음에는 예측에 들어옵니다.</p>
</section>
<?php endif ?>

<details class="card">
  <summary><b>어떻게 비교하나요?</b></summary>
  <ul>
    <li><b>민원 예상</b>: 작년 같은 4주에 2건 이상 있었던 분류 → 그 4주에 같은 소분류 민원이 있으면 ✅, 같은 중분류(예: 객실 시설)만 있으면 🟡</li>
    <li><b>객실 재발</b>: 그날 전 120일에 같은 객실·같은 분류 2건 이상 → 그 객실에 같은 분류 민원이 또 있으면 ✅, 비슷한 분류면 🟡</li>
    <li><b>설비</b>: 그날 전 1년 수리 2회 이상, 또는 작년 이맘때(±2주) 수리 → 그 4주에 그 설비 수리 기록이 있으면 ✅, 4주 뒤 석 달 안에 고장 났으면 🟡 (미리 점검했으면 좋았을 설비)</li>
    <li>단순문의와 소모품은 비교하지 않습니다. 오늘 기록은 아직 다 들어오지 않아 어제까지만 봅니다.</li>
  </ul>
</details>
<?php layout_footer();
