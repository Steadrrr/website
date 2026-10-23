<?php
/**
 * AI 분석 › 소넷과 비교 (최고관리자)
 *   ai_compare.php?id=N     발행본 N 과 같은 근거로 푼 소넷 답을 나란히 보기 (id 없으면 가장 최근 발행본)
 *   POST action=run         아직 비교 안 했으면 소넷에 한 번 보내기 (발행본 하나당 한 번, 약 75원)
 * 계산은 app/ai.php (ai_compare_run)
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_admin();

$id = (int) ($_GET['id'] ?? 0);
$st = db()->prepare("SELECT a.*, u.name AS user_name FROM ai_insights a LEFT JOIN users u ON u.id = a.created_by WHERE a.kind = 'forecast'" . ($id ? ' AND a.id = ?' : '') . ' ORDER BY a.id DESC LIMIT 1');
$st->execute($id ? [$id] : []);
$base = $st->fetch() ?: null;

if (is_post()) {
    csrf_verify();
    if (!$base) abort(404, '발행본이 없습니다.');
    if (ai_key() === '') {
        flash('AI 연결(API 키)이 없습니다.', 'error');
    } else {
        $res = ai_compare_run($user, $base);
        $res['ok'] ? flash('소넷 답을 받았습니다. 아래에서 나란히 비교해 보세요.', 'success') : flash($res['error'], 'error');
    }
    redirect('ai_compare.php?id=' . (int) $base['id']);
}

$cmp = $base ? ai_compare_get((int) $base['id']) : null;
$cols = [];
if ($base) {
    $cols[] = ['row' => $base, 'data' => json_decode((string) $base['result'], true) ?: [], 'sec' => null];
    if ($cmp) $cols[] = ['row' => $cmp, 'data' => $cmp['data'], 'sec' => (json_decode((string) $cmp['facts'], true) ?: [])['걸린 시간(초)'] ?? null];
}
$lv = ['높음' => 'high', '보통' => 'mid', '낮음' => 'low'];
$areaIcon = ['민원' => '💬', '시설' => '🛠', '소모품' => '🧴', '객실운영' => '🛏'];
$md = fn(string $d) => date('n/j', strtotime($d));

layout_header('AI 모델 비교', 'ai');
?>
<div class="card ai-head">
  <div>
    <h1>🆚 오퍼스 vs 소넷 <small class="muted">같은 근거, 다른 모델</small></h1>
    <p class="muted">발행본을 만들 때 AI에게 보낸 <b>근거 숫자를 그대로</b> 소넷에 한 번 더 보내, 두 답을 나란히 봅니다.
      소넷은 오퍼스의 절반 값입니다. 답이 충분히 좋으면 발행 모델을 소넷으로 바꿀 수 있습니다. (최고관리자만 · 발행본 하나당 한 번 · 약 75원)</p>
  </div>
  <a class="btn" href="<?= e(url('ai.php')) ?>">← AI 분석</a>
</div>

<?php if (!$base): ?>
  <div class="card"><p class="muted">아직 발행된 AI 예측이 없습니다. AI 분석 화면에서 먼저 발행해 주세요.</p></div>
<?php else: ?>
<div class="card">
  <p>비교할 발행본: <b><?= e(date('Y.n.j H:i', strtotime($base['created_at']))) ?></b> 발행 · 대상 <?= e($md($base['period_from'])) ?>~<?= e($md($base['period_to'])) ?> · <?= e(ai_model_label((string) $base['model'])) ?></p>
  <?php if (!$cmp): ?>
    <form method="post" data-ai-run>
      <?= csrf_field() ?><input type="hidden" name="action" value="run">
      <button class="btn primary">🆚 소넷으로 같은 자료 풀어 보기</button>
      <span class="muted tiny-text">30초~1분 걸립니다</span>
    </form>
  <?php endif ?>
</div>

<div class="grid2 cmp-cols">
  <?php foreach ($cols as $c): $d = $c['data']; $r = $c['row']; ?>
  <section class="card ai-result">
    <h2><?= e(ai_model_label((string) $r['model'])) ?></h2>
    <p class="muted tiny-text">입력 <?= number_format((int) $r['input_tokens']) ?> · 출력 <?= number_format((int) $r['output_tokens']) ?> 토큰 · 💸 약 <?= number_format(ai_cost_won($r)) ?>원<?= $c['sec'] !== null ? ' · ' . (int) $c['sec'] . '초' : '' ?></p>
    <h3>요약</h3>
    <p class="ai-summary"><?= nl2br(e((string) ($d['summary'] ?? ''))) ?></p>
    <h3>미리 챙길 일 <small class="muted"><?= count($d['items'] ?? []) ?>건</small></h3>
    <div class="cmp-items">
      <?php foreach ($d['items'] ?? [] as $it): if (!is_array($it)) continue; $it += ['area' => '', 'level' => '', 'when' => '', 'title' => '', 'why' => '', 'action' => '']; ?>
        <div class="ai-item lv-<?= e($lv[$it['level']] ?? 'low') ?>">
          <div class="ai-item-top"><span class="chip"><?= e(($areaIcon[$it['area']] ?? '') . ' ' . $it['area']) ?></span> <span class="chip lv"><?= e($it['level']) ?></span> <span class="muted tiny-text"><?= e($it['when']) ?></span></div>
          <b><?= e($it['title']) ?></b>
          <div class="muted"><?= e($it['why']) ?></div>
          <div>👉 <?= e($it['action']) ?></div>
        </div>
      <?php endforeach ?>
    </div>
    <h3>🧾 구매 목록 초안 <small class="muted"><?= count($d['purchase_list'] ?? []) ?>건</small></h3>
    <?php if (empty($d['purchase_list'])): ?><p class="muted">없음</p><?php else: ?>
    <div class="table-scroll"><table class="table"><tbody><?php foreach ($d['purchase_list'] as $p): if (!is_array($p)) continue; $p += ['item' => '', 'qty' => '', 'est_cost' => '', 'reason' => '']; ?>
      <tr><td><?= e($p['item']) ?></td><td><?= e($p['qty']) ?></td><td><?= e($p['est_cost']) ?></td><td class="muted"><?= e($p['reason']) ?></td></tr><?php endforeach ?></tbody></table></div>
    <?php endif ?>
    <h3>✅ 이번 주 할 일</h3>
    <ul class="ai-check"><?php foreach ((array) ($d['checklist'] ?? []) as $x): if (is_string($x)): ?><li><?= e($x) ?></li><?php endif; endforeach ?></ul>
  </section>
  <?php endforeach ?>
</div>
<details class="card">
  <summary><b>무엇을 보면 되나요?</b></summary>
  <ul>
    <li>중요한 일을 빠뜨리지 않았는지 (예: 반복 고장 설비, 곧 떨어질 소모품)</li>
    <li>근거 숫자를 정확히 옮겼는지 (화면 아래 '근거 숫자 보기'와 맞는지), 없는 숫자를 지어내지 않았는지</li>
    <li>미리 할 일이 현장에서 바로 할 수 있을 만큼 구체적인지</li>
    <li>하루 이틀 결과만으로 정하지 말고, 몇 번 비교해 보고 정하는 것이 좋습니다.</li>
  </ul>
</details>
<?php endif ?>
<script>
document.querySelectorAll('[data-ai-run]').forEach(function (f) {
  f.addEventListener('submit', function () { var b = f.querySelector('button'); b.disabled = true; b.textContent = '소넷이 분석 중입니다… (30초~1분)'; });
});
</script>
<?php layout_footer();
