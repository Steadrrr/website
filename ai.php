<?php
/**
 * AI 분석 (메인 메뉴) — 다음 4주 미리 보기: 민원 · 시설 · 소모품
 *   ai.php                 가장 최근 발행된 AI 예측(모두 봄) + 어제까지 숫자로 만든 규칙 예측 + AI에게 주는 근거 표
 *   POST action=run        오늘 AI 예측 발행 (주무관 이상, 하루 한 번 — 어제까지 자료로 오늘부터 4주)
 * 계산·전송 내용은 app/ai.php
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();

if (is_post()) {
    csrf_verify();
    if (post('action') === 'run') {
        if (!ai_can_run($user)) abort(403, 'AI 예측 발행은 주무관 이상이 할 수 있습니다.');
        $res = ai_forecast_run($user);
        $res['ok'] ? flash('오늘 AI 예측을 발행했습니다. 모든 직원이 이 화면에서 볼 수 있습니다.', 'success') : flash($res['error'], 'error');
    }
    redirect('ai.php');
}

$facts = ai_facts();
// 메뉴 권한(회원관리)에 맞춰 보여 줄 구분만: 민원 = 운영관리, 시설 = 시설관리, 소모품·객실 = 객실관리
$can = ['민원' => can_menu($user, 'ops'), '시설' => can_menu($user, 'fac'), '소모품' => can_menu($user, 'room'), '객실운영' => can_menu($user, 'room')];
$canAll = !in_array(false, $can, true);
$rules = array_values(array_filter(ai_rule_items($facts), fn($r) => $can[$r['area']] ?? false));
$latest = ai_latest();
$hasKey = ai_key() !== '';
$publishedToday = ai_published_today();
[$from, $to] = ai_window();
$ai = $latest['data'] ?? null;
$fresh = $latest && substr($latest['created_at'], 0, 10) === date('Y-m-d');
$lv = ['높음' => 'high', '보통' => 'mid', '낮음' => 'low'];
$areaIcon = ['민원' => '💬', '시설' => '🛠', '소모품' => '🧴', '객실운영' => '🛏'];
$md = fn(string $d) => date('n/j', strtotime($d));

layout_header('AI 분석', 'ai');
?>
<div class="card ai-head">
  <div>
    <h1>✨ AI 분석 <small class="muted">오늘부터 4주 미리 보기 · <?= e($md($from)) ?> ~ <?= e($md($to)) ?> · 어제(<?= e($md(ai_basis())) ?>)까지 자료 기준</small></h1>
    <p class="muted">작년 같은 기간의 민원·고장 기록, 최근 반복되는 문제, 소모품이 줄어드는 속도를 숫자로 계산하고, AI가 그 숫자를 읽어 <b>미리 챙길 일</b>을 정리합니다.
      AI 예측은 <b>하루 한 번 발행</b>되고 모든 직원이 함께 봅니다. AI에게는 아래 '근거 숫자'만 보내며, 민원 원문은 보내지 않고 설비 기록 문구의 전화번호·이메일·이름(호칭 앞)은 가립니다.</p>
  </div>
  <div class="ai-side">
  <a class="btn" href="<?= e(url('ai_review.php')) ?>" title="그날 예측했다면 맞았을까? — 지난 예측과 실제 기록 비교">🔁 지난 예측 되돌아보기</a>
  <?php if (ai_can_run($user) && $hasKey && !$publishedToday): ?>
    <form method="post" data-ai-run>
      <?= csrf_field() ?><input type="hidden" name="action" value="run">
      <button class="btn primary ai-run" title="1회 발행 약 150원 — 자판기 커피 반 잔 값 ☕">✨ 오늘 AI 예측 발행</button>
      <div class="muted tiny-text">하루 한 번 · 30초~1분 걸립니다<?php if (is_demo() || !empty($user['is_admin'])): ?> · <a href="<?= e(url('ai_key.php')) ?>">API 키</a><?php endif ?></div>
    </form>
  <?php elseif ($publishedToday): ?>
    <div class="muted tiny-text ai-published">✅ 오늘 예측 발행됨</div>
  <?php elseif ($hasKey && !ai_can_run($user)): ?>
    <div class="muted tiny-text ai-published">오늘 예측은 아직 발행 전입니다 (주무관 이상이 발행)</div>
  <?php endif ?>
  </div>
</div>

<?php if (!$hasKey): ?>
  <div class="flash">AI 연결 전입니다. 지금은 <b>통계 규칙으로 만든 예측</b>만 보입니다. Claude API 키를 넣으면 AI 해설·구매 목록·이번 주 할 일이 함께 나옵니다.
    <?php if (is_demo() || !empty($user['is_admin'])): ?> <a class="btn" href="<?= e(url('ai_key.php')) ?>">🔑 API 키 넣기</a><?php endif ?></div>
<?php endif ?>

<?php if ($ai): ?>
<section class="card ai-result">
  <div class="ai-result-head">
    <h2>AI 예측 <?= $fresh ? '<span class="chip">오늘 발행</span>' : '<span class="chip">' . e($md($latest['created_at'])) . ' 발행본</span>' ?></h2>
    <span class="muted tiny-text"><?= e(date('n/j H:i', strtotime($latest['created_at']))) ?> 발행 · <?= e($latest['user_name'] ?? '') ?> · 대상 <?= e($md($latest['period_from'])) ?>~<?= e($md($latest['period_to'])) ?><?= $fresh ? '' : ' · <b>오늘은 아직 발행 전 — 최근 발행본입니다</b>' ?><?php if ($c = ai_cost_won($latest)): ?> · <span title="자판기 커피 반 잔 값으로 4주를 미리 봅니다 ☕">💸 이번 발행 약 <?= number_format($c) ?>원</span><?php endif ?> · <a href="<?= e(url('ai_review.php?id=' . (int) $latest['id'])) ?>">🔁 실제와 비교</a><?php if (!empty($user['is_admin'])): ?> · <a href="<?= e(url('ai_compare.php?id=' . (int) $latest['id'])) ?>">🆚 소넷과 비교</a><?php endif ?></span>
  </div>
  <?php if ($canAll): ?><p class="ai-summary"><?= nl2br(e($ai['summary'] ?? '')) ?></p><?php endif ?>
  <div class="ai-items">
    <?php foreach ($ai['items'] ?? [] as $it): if (!is_array($it) || !($can[$it['area'] ?? ''] ?? false)) continue; $it += ['level' => '', 'when' => '', 'title' => '', 'why' => '', 'action' => '']; ?>
      <div class="ai-item lv-<?= e($lv[$it['level']] ?? 'low') ?>">
        <div class="ai-item-top"><span class="chip"><?= e(($areaIcon[$it['area']] ?? '') . ' ' . $it['area']) ?></span> <span class="chip lv"><?= e($it['level']) ?></span> <span class="muted tiny-text"><?= e($it['when']) ?></span></div>
        <b><?= e($it['title']) ?></b>
        <div class="muted"><?= e($it['why']) ?></div>
        <div>👉 <?= e($it['action']) ?></div>
      </div>
    <?php endforeach ?>
  </div>
  <div class="grid2">
    <div>
      <h3>🧾 구매 목록 초안</h3>
      <?php if (!$can['소모품']): ?><p class="muted">객실관리 메뉴 권한이 있는 직원에게 보입니다.</p><?php elseif (empty($ai['purchase_list'])): ?><p class="muted">지금 살 것은 없습니다.</p><?php else: ?>
      <table class="table">
        <thead><tr><th>품목</th><th>수량</th><th>예상 금액</th><th>이유</th></tr></thead>
        <tbody><?php foreach ($ai['purchase_list'] as $p): if (!is_array($p)) continue; $p += ['item' => '', 'qty' => '', 'est_cost' => '', 'reason' => '']; ?><tr><td><?= e($p['item']) ?></td><td><?= e($p['qty']) ?></td><td><?= e($p['est_cost']) ?></td><td class="muted"><?= e($p['reason']) ?></td></tr><?php endforeach ?></tbody>
      </table>
      <?php if (can_purchase($user)): ?><p><a class="btn" href="<?= e(url('write.php?type=purchase')) ?>">물품구매 문서 쓰기 ›</a></p><?php endif ?>
      <?php endif ?>
    </div>
    <div>
      <h3>✅ 이번 주 할 일</h3>
      <?php if ($canAll): ?><ul class="ai-check"><?php foreach ((array) ($ai['checklist'] ?? []) as $c): if (is_string($c)): ?><li><?= e($c) ?></li><?php endif; endforeach ?></ul><?php else: ?><p class="muted">모든 메뉴 권한이 있는 직원에게 보입니다.</p><?php endif ?>
    </div>
  </div>
</section>
<?php endif ?>

<section class="card">
  <h2><?= $ai ? '숫자로 본 예측 (규칙)' : '오늘부터 4주 예측 (통계 규칙)' ?> <small class="muted"><?= count($rules) ?>건 · 어제까지 자료로 바로 계산</small></h2>
  <?php if (!$rules): ?><p class="muted">눈에 띄는 위험이 없습니다. 자료가 쌓일수록 정확해집니다.</p><?php else: ?>
  <table class="table ai-rules">
    <thead><tr><th>구분</th><th>중요</th><th>예상되는 일</th><th>근거</th><th>미리 할 일</th></tr></thead>
    <tbody>
    <?php foreach ($rules as $r): ?>
      <tr class="lv-<?= e($lv[$r['level']]) ?>"><td><?= e(($areaIcon[$r['area']] ?? '') . ' ' . $r['area']) ?></td><td><span class="chip lv"><?= e($r['level']) ?></span></td>
        <td><b><?= e($r['title']) ?></b></td><td class="muted"><?= e($r['why']) ?></td><td><?= e($r['action']) ?></td></tr>
    <?php endforeach ?>
    </tbody>
  </table>
  <?php endif ?>
</section>

<details class="card ai-facts">
  <summary><b>AI에게 주는 근거 숫자 보기</b> <span class="muted">— 계산은 시스템이, 해석은 AI가</span></summary>
  <div class="grid2">
    <div>
      <?php if ($can['객실운영']): ?><h3>객실 판매</h3>
      <ul class="list"><?php foreach ($facts['rooms'] as $k => $v): ?><li><span><?= e($k) ?></span><b><?= e(is_bool($v) ? ($v ? '예' : '아니오') : $v) ?></b></li><?php endforeach ?></ul><?php endif ?>
      <?php if ($can['민원']): ?><h3>민원 — 작년 같은 기간 (<?= e($facts['period']['작년 같은 기간']) ?>)</h3>
      <table class="table"><tbody><?php foreach ($facts['complaints']['작년 같은 기간 많았던 분류'] as $c): ?><tr><td><?= e($c['분류']) ?></td><td><?= (int) $c['작년 같은 기간 건수'] ?>건</td></tr><?php endforeach ?>
        <?php if (!$facts['complaints']['작년 같은 기간 많았던 분류']): ?><tr><td class="muted">작년 같은 기간 자료가 없습니다.</td></tr><?php endif ?></tbody></table>
      <h3>같은 객실 반복 (최근 120일)</h3>
      <table class="table"><tbody><?php foreach ($facts['complaints']['같은 객실 반복(최근 120일)'] as $r): ?><tr><td><?= e($r['객실']) ?></td><td><?= e($r['분류']) ?></td><td><?= (int) $r['최근 120일 건수'] ?>건</td><td class="muted"><?= e($r['마지막']) ?></td></tr><?php endforeach ?></tbody></table><?php endif ?>
    </div>
    <div>
      <?php if ($can['시설']): ?><h3>설비 고장 이력 (위험한 순)</h3>
      <table class="table"><thead><tr><th>설비</th><th>1년 수리</th><th>마지막 기록</th></tr></thead><tbody>
        <?php foreach (array_slice($facts['equipment'], 0, 10) as $e): ?><tr><td><?= e($e['설비']) ?><?= $e['상태'] === '수리중' ? ' <span class="chip">수리중</span>' : '' ?></td><td><?= (int) $e['최근 1년 수리'] ?>회</td><td class="muted"><?= e($e['마지막 기록']) ?></td></tr><?php endforeach ?>
      </tbody></table><?php endif ?>
      <?php if ($can['소모품']): ?><h3>소모품 재고 (빨리 떨어지는 순)</h3>
      <table class="table"><thead><tr><th>품목</th><th>재고</th><th>8주 사용</th><th>남은 기간</th></tr></thead><tbody>
        <?php foreach ($facts['supplies'] as $s): ?><tr><td><?= e($s['품목']) ?></td><td><?= (int) $s['현재 재고'] ?><?= e($s['단위']) ?></td><td><?= (int) $s['최근 8주 사용'] ?></td>
          <td><?= $s['남은 기간(주)'] === null ? '-' : e($s['남은 기간(주)']) . '주' ?></td></tr><?php endforeach ?>
      </tbody></table>
      <p class="muted tiny-text">다음 8주 예상 판매 <?= (int) $facts['supply_basis']['다음 8주 예상 판매(박)'] ?>박 (<?= e($facts['supply_basis']['예상 근거']) ?>) 기준</p><?php endif ?>
    </div>
  </div>
</details>
<script>
document.querySelectorAll('[data-ai-run]').forEach(function (f) {
  f.addEventListener('submit', function () {
    var b = f.querySelector('button'); b.disabled = true; b.textContent = 'AI가 분석 중입니다… (30초~1분)';
  });
});
</script>
<?php layout_footer();
