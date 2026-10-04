<?php
/**
 * 객실관리 › 입퇴실현황: 그 날 퇴실예정(왼쪽)·입실예정(오른쪽) 객실과 비고, 하단 중점정비사항
 *   room_turnover.php[?date=2026-10-04]
 * 입실·퇴실 목록은 일일객실판매(반려 제외, 임시저장 포함)에서 계산한다: 입실예정 = 그 날 묵는 객실, 퇴실예정 = 전날 묵은 객실.
 * 연박은 일일객실판매에서 객실마다 고른 '연박 2·3박'(sales_lines.stay_nights, 예약 엑셀의 숙박기간으로 자동)이 기준 — 그 날부터 그 박수만큼 한 손님.
 * 연박이 아닌 판매는 연달아 있어도 다른 손님으로 본다. 연박 중이라 그 날 실제 입실·퇴실이 없는 객실은 '오늘 입실(퇴실) 없음'.
 * 비고(객실별)와 중점정비사항(날짜별)은 room_turnover_notes · room_turnover_days 에 저장. 객실관리 메뉴 권한이 있으면 누구나 작성.
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
require_menu($user, 'room');
$pdo = db();
$date = valid_date($_GET['date'] ?? '') ? $_GET['date'] : date('Y-m-d');
$prev = date('Y-m-d', strtotime("$date -1 day"));

if (is_post()) {
    csrf_verify();
    $d = post('date');
    if (!valid_date($d)) abort(400, '잘못된 날짜입니다.');
    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM room_turnover_notes WHERE work_date = ?')->execute([$d]);
    $ins = $pdo->prepare('INSERT INTO room_turnover_notes (work_date, side, product_id, note) VALUES (?, ?, ?, ?)');
    foreach (['out', 'in'] as $side) {
        foreach ((array) ($_POST['note'][$side] ?? []) as $pid => $note) {
            $note = mb_substr(trim((string) $note), 0, 300);
            if ($note !== '' && (int) $pid > 0) $ins->execute([$d, $side, (int) $pid, $note]);
        }
    }
    $focus = trim(post('focus'));
    $pdo->prepare('INSERT INTO room_turnover_days (work_date, focus, updated_by, updated_at) VALUES (?, ?, ?, NOW())
                   ON DUPLICATE KEY UPDATE focus = VALUES(focus), updated_by = VALUES(updated_by), updated_at = NOW()')
        ->execute([$d, $focus !== '' ? mb_substr($focus, 0, 5000) : null, $user['id']]);
    $pdo->commit();
    flash(date('n월 j일', strtotime($d)) . ' 입퇴실현황의 비고·중점정비사항을 저장했습니다.', 'success');
    redirect('room_turnover.php?date=' . $d);
}

/** 기간 안의 객실별 판매(묵은) 날짜 [객실 id => [날짜 => ['g' => 입실 인원, 's' => 연박 박수]]] — 일일객실판매, 반려 제외 */
function rt_nights(string $from, string $to): array
{
    $st = db()->prepare("SELECT l.product_id, j.work_date, SUM(l.guests) AS g, MAX(l.stay_nights) AS s FROM journals j JOIN sales_lines l ON l.journal_id = j.id
                          WHERE j.type = 'rooms' AND j.status <> 'rejected' AND l.grp = 'room' AND j.work_date BETWEEN ? AND ?
                          GROUP BY l.product_id, j.work_date ORDER BY j.work_date");
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st as $r) $out[(int) $r['product_id']][$r['work_date']] = ['g' => (int) $r['g'], 's' => (int) $r['s']];
    return $out;
}

/**
 * 객실 하나의 숙박 구간: 연박(2·3박 …)을 고른 날부터 그 박수만큼은 한 손님 (다음 날 줄이 없어도 묵는 것으로 봄),
 * 연박이 아닌 판매는 하루씩 따로 (연달아 판매돼도 다른 손님).  [['from' => 첫날, 'last' => 마지막 날, 'nights', 'g'], ...]
 */
function rt_stays(array $days): array
{
    $stays = [];
    $until = '';
    foreach ($days as $d => $x) {
        if ($d <= $until) continue; // 앞선 연박 안의 날
        $n = max(1, $x['s']);
        $last = date('Y-m-d', strtotime("$d +" . ($n - 1) . ' days'));
        $stays[] = ['from' => $d, 'last' => $last, 'nights' => $n, 'g' => $x['g']];
        $until = $last;
    }
    return $stays;
}

// 연박을 이어 보려고 앞뒤 60일까지 읽는다
$nights = rt_nights(date('Y-m-d', strtotime("$date -60 days")), date('Y-m-d', strtotime("$date +60 days")));
$rooms = array_filter(products_all(), fn($p) => $p['grp'] === 'room');
$types = room_types_all();
$order = fn(array $p) => [(int) ($types[(int) $p['room_type_id']]['sort_order'] ?? 9999), (int) $p['sort_order'], (int) $p['id']];
$lists = ['out' => [], 'in' => []];
foreach ($nights as $pid => $days) {
    $p = $rooms[$pid] ?? null;
    if (!$p) continue;
    foreach (rt_stays($days) as $st) {
        $row = ['p' => $p, 'nights' => $st['nights'], 'guests' => $st['g']];
        // 퇴실예정: 전날 묵은 객실 — 연박 중이라 오늘도 묵으면 퇴실 없음
        if ($st['from'] <= $prev && $prev <= $st['last']) $lists['out'][$pid] = $row + ['stay' => $st['last'] > $prev];
        // 입실예정: 오늘 묵는 객실 — 연박으로 전부터 묵고 있으면 입실 없음
        if ($st['from'] <= $date && $date <= $st['last']) $lists['in'][$pid] = $row + ['stay' => $st['from'] < $date];
    }
}
foreach ($lists as &$l) uasort($l, fn($a, $b) => $order($a['p']) <=> $order($b['p']));
unset($l);

$notes = ['out' => [], 'in' => []];
$st = $pdo->prepare('SELECT side, product_id, note FROM room_turnover_notes WHERE work_date = ?');
$st->execute([$date]);
foreach ($st as $r) $notes[$r['side']][(int) $r['product_id']] = $r['note'];
$st = $pdo->prepare('SELECT d.*, u.name AS user_name FROM room_turnover_days d LEFT JOIN users u ON u.id = d.updated_by WHERE d.work_date = ?');
$st->execute([$date]);
$day = $st->fetch() ?: null;
$st = $pdo->prepare("SELECT id FROM journals WHERE type = 'rooms' AND work_date = ? AND status <> 'rejected'");
$st->execute([$date]);
$todayDoc = (int) $st->fetchColumn();
$blocked = room_block_map($date, $date)[$date] ?? [];
$md = fn(string $d) => date('n/j', strtotime($d)) . '(' . weekday_ko($d) . ')';
$count = fn(string $side, bool $stay) => count(array_filter($lists[$side], fn($r) => $r['stay'] === $stay));

layout_header('입퇴실현황', 'turnover');
?>
<section class="card no-print rt-page">
  <div class="card-head">
    <h1>입퇴실현황 <small class="muted"><?= e(date('Y년 n월 j일', strtotime($date))) ?> (<?= e(weekday_ko($date)) ?>)<?= $date === date('Y-m-d') ? ' · 오늘' : '' ?></small></h1>
    <div class="actions no-margin">
      <a class="btn small" href="<?= e(url('room_turnover.php?date=' . $prev)) ?>">‹ 전날</a>
      <form method="get" class="inline"><input type="date" name="date" value="<?= e($date) ?>" onchange="this.form.submit()"></form>
      <a class="btn small" href="<?= e(url('room_turnover.php?date=' . date('Y-m-d', strtotime("$date +1 day")))) ?>">다음날 ›</a>
      <?php if ($date !== date('Y-m-d')): ?><a class="btn small ghost" href="<?= e(url('room_turnover.php')) ?>">오늘</a><?php endif ?>
      <button class="btn small" type="button" onclick="window.print()">인쇄</button>
    </div>
  </div>
  <div class="kpis k4 rt-kpis">
    <div class="kpi"><span>퇴실예정</span><b><?= $count('out', false) ?>실</b></div>
    <div class="kpi total"><span>입실예정</span><b><?= $count('in', false) ?>실</b></div>
    <div class="kpi"><span>오늘 묵는 객실</span><b><?= count($lists['in']) ?>실</b></div>
    <div class="kpi"><span>미판매 객실</span><b><?= count($blocked) ?>실</b></div>
  </div>
  <?php if (!$todayDoc): ?><p class="small warn">이 날 일일객실판매가 아직 없어 입실예정이 비어 있을 수 있습니다. <a href="<?= e(url('write.php?type=rooms&date=' . $date)) ?>">객실판매관리에서 작성</a>(입실예정 엑셀로 채우기)하면 여기에 나옵니다.</p><?php endif ?>
</section>

<form method="post" class="rt-form rt-page">
  <?= csrf_field() ?><input type="hidden" name="date" value="<?= e($date) ?>">
  <div class="print-only rt-print-head"><b>입퇴실현황 · <?= e(date('Y년 n월 j일', strtotime($date))) ?> (<?= e(weekday_ko($date)) ?>)</b>
    <span>퇴실예정 <?= $count('out', false) ?>실 · 입실예정 <?= $count('in', false) ?>실 · 오늘 묵는 객실 <?= count($lists['in']) ?>실 · 미판매 <?= count($blocked) ?>실</span></div>
  <div class="rt-grid">
    <?php foreach (['out' => '퇴실예정', 'in' => '입실예정'] as $side => $label): ?>
    <section class="card rt-<?= $side ?>">
      <h2><?= $label ?> <small class="muted"><?= $side === 'out' ? $md($prev) . ' 묵은 객실' : $md($date) . ' 묵는 객실' ?> · <?= count($lists[$side]) ?>실</small></h2>
      <?php // 왼쪽 4인실·독채 등, 오른쪽 2인실 (객실 분류 이름에 '2인'이 있으면 오른쪽)
        $halves = ['L' => [], 'R' => []];
        foreach ($lists[$side] as $pid => $r) {
            $tn = room_type_name($r['p']['room_type_id'] ? (int) $r['p']['room_type_id'] : null);
            $halves[str_contains($tn, '2인') ? 'R' : 'L'][$pid] = $r + ['tn' => $tn];
        }
        $halves = array_filter($halves); ?>
      <?php if (!$halves): ?><p class="center muted"><?= $label ?> 객실이 없습니다.</p><?php endif ?>
      <div class="rt-split <?= count($halves) > 1 ? 'two' : '' ?>">
      <?php foreach ($halves as $rowsH): ?>
        <div class="rt-half">
          <h3 class="rt-half-title"><?= e(implode(' · ', array_unique(array_column($rowsH, 'tn')))) ?> <small class="muted"><?= count($rowsH) ?>실</small></h3>
          <table class="table rt-table">
            <thead><tr><th>객실명</th><th>연박</th><th>비고</th></tr></thead>
            <tbody>
            <?php foreach ($rowsH as $pid => $r): ?>
              <tr class="<?= $r['stay'] ? 'rt-stay' : '' ?>">
                <td class="nowrap"><b><?= e($r['p']['name']) ?></b></td>
                <td class="nowrap"><?= $r['nights'] > 1 ? '<span class="badge rt-badge">연박</span>' . ($r['stay'] ? '<br><small class="muted">' . ($side === 'out' ? '퇴실 없음' : '입실 없음') . '</small>' : '') : '' ?></td>
                <td><input name="note[<?= $side ?>][<?= (int) $pid ?>]" value="<?= e($notes[$side][$pid] ?? '') ?>" maxlength="300" placeholder="비고"></td>
              </tr>
            <?php endforeach ?>
            </tbody>
          </table>
        </div>
      <?php endforeach ?>
      </div>
    </section>
    <?php endforeach ?>
  </div>
  <section class="card">
    <h2>중점정비사항 <?php if ($day && $day['updated_at']): ?><small class="muted"><?= e((string) $day['user_name']) ?> · <?= e(substr((string) $day['updated_at'], 0, 16)) ?></small><?php endif ?></h2>
    <textarea name="focus" rows="5" placeholder="예: 별A 욕실 배수 점검, 퇴실 객실 침구 전체 교체, 구름B 에어컨 필터 청소"><?= e((string) ($day['focus'] ?? '')) ?></textarea>
    <div class="print-only rt-focus-print"></div>
    <div class="actions no-print"><button class="btn primary">비고·중점정비사항 저장</button></div>
  </section>
</form>
<p class="muted small no-print">입실·퇴실은 <a href="<?= e(url('journal.php?type=rooms')) ?>">객실관리 › 객실판매관리</a>의 일일객실판매(임시저장 포함, 반려 제외)로 계산합니다. 퇴실예정 = 전날 묵은 객실, 입실예정 = 이 날 묵는 객실이며,
  <b>연박</b>은 일일객실판매에서 고른 '연박 2·3박'(예약 엑셀로 채우면 숙박기간으로 자동)을 기준으로 한 손님이 이어서 묵는 것으로 봅니다.</p>
<style>@page { size: A4 landscape; margin: 8mm; }</style>
<script>
// 인쇄: A4 가로 한 장에 모두 들어가도록 — 인쇄용 배치로 바꾼 뒤 크기를 재서 넘치면 줄인다
(function () {
  const form = document.querySelector('.rt-form');
  if (!form) return;
  const PAGE_W = 1060, PAGE_H = 705; // A4 가로 − 여백 8mm (96dpi 기준 px, 조금 여유)
  const before = () => {
    document.body.classList.add('rt-printmode');
    const memo = form.querySelector('textarea[name=focus]');
    const out = form.querySelector('.rt-focus-print');
    if (memo && out) out.textContent = memo.value || '(없음)';
    form.style.zoom = 1;
    form.style.width = PAGE_W + 'px';
    for (let i = 0; i < 3; i++) { // 줄이면 폭이 넓어져 높이가 다시 바뀌므로 몇 번 맞춘다
      const z = parseFloat(form.style.zoom) || 1;
      const h = form.getBoundingClientRect().height / z; // 줌 전 높이
      const s = Math.min(1, PAGE_H / h);
      form.style.zoom = s;
      form.style.width = (PAGE_W / s) + 'px';
    }
  };
  const after = () => { document.body.classList.remove('rt-printmode'); form.style.zoom = ''; form.style.width = ''; };
  window.addEventListener('beforeprint', before);
  window.addEventListener('afterprint', after);
})();
</script>
<?php layout_footer();
