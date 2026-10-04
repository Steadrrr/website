<?php
/**
 * 객실관리 › 입퇴실현황: 그 날 퇴실예정(왼쪽)·입실예정(오른쪽) 객실과 비고, 하단 중점정비사항
 *   room_turnover.php[?date=2026-10-04]
 * 입실·퇴실 목록은 일일객실판매(반려 제외, 임시저장 포함)에서 계산한다:
 *   입실예정 = 그 날 판매(묵는) 객실, 퇴실예정 = 전날 판매 객실. 같은 객실이 연달아 판매된 날은 한 번의 연박으로 보고
 *   입실일 = 연속 판매 첫날, 퇴실일 = 마지막 날 다음 날. 연박 중이라 그 날 실제 입실·퇴실이 없는 객실은 '연박'으로 표시한다.
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

/** 기간 안의 객실별 판매(묵은) 날짜 [객실 id => [날짜 => 입실 인원]] — 일일객실판매, 반려 제외 */
function rt_nights(string $from, string $to): array
{
    $st = db()->prepare("SELECT l.product_id, j.work_date, SUM(l.guests) AS g FROM journals j JOIN sales_lines l ON l.journal_id = j.id
                          WHERE j.type = 'rooms' AND j.status <> 'rejected' AND l.grp = 'room' AND j.work_date BETWEEN ? AND ?
                          GROUP BY l.product_id, j.work_date");
    $st->execute([$from, $to]);
    $out = [];
    foreach ($st as $r) $out[(int) $r['product_id']][$r['work_date']] = (int) $r['g'];
    return $out;
}

// 연박을 이어 보려고 앞뒤 60일까지 읽는다
$nights = rt_nights(date('Y-m-d', strtotime("$date -60 days")), date('Y-m-d', strtotime("$date +60 days")));
/** 그 날 묵는 객실의 연속 판매 구간 → [입실일, 퇴실일, 박수] */
$span = function (array $set, string $d): array {
    $s = $d;
    while (isset($set[date('Y-m-d', strtotime("$s -1 day"))])) $s = date('Y-m-d', strtotime("$s -1 day"));
    $e = $d;
    while (isset($set[date('Y-m-d', strtotime("$e +1 day"))])) $e = date('Y-m-d', strtotime("$e +1 day"));
    $out = date('Y-m-d', strtotime("$e +1 day"));
    return [$s, $out, (int) round((strtotime($out) - strtotime($s)) / 86400)];
};
$rooms = array_filter(products_all(), fn($p) => $p['grp'] === 'room');
$types = room_types_all();
$order = fn(array $p) => [(int) ($types[(int) $p['room_type_id']]['sort_order'] ?? 9999), (int) $p['sort_order'], (int) $p['id']];
$lists = ['out' => [], 'in' => []];
foreach ($nights as $pid => $set) {
    $p = $rooms[$pid] ?? null;
    if (!$p) continue;
    if (isset($set[$prev])) { // 전날 묵음 → 오늘 퇴실 (오늘도 묵으면 연박이라 퇴실 없음)
        [$s, $o, $n] = $span($set, $prev);
        $lists['out'][$pid] = ['p' => $p, 'from' => $s, 'to' => $o, 'nights' => $n, 'guests' => $set[$prev], 'stay' => isset($set[$date])];
    }
    if (isset($set[$date])) { // 오늘 묵음 → 오늘 입실 (전날도 묵었으면 연박이라 입실 없음)
        [$s, $o, $n] = $span($set, $date);
        $lists['in'][$pid] = ['p' => $p, 'from' => $s, 'to' => $o, 'nights' => $n, 'guests' => $set[$date], 'stay' => isset($set[$prev])];
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
<section class="card no-print">
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
  <div class="kpis k4">
    <div class="kpi"><span>퇴실예정</span><b><?= $count('out', false) ?>실</b><small class="muted"><?= $count('out', true) ? '연박 ' . $count('out', true) . '실 (퇴실 없음)' : '전날 묵은 객실' ?></small></div>
    <div class="kpi total"><span>입실예정</span><b><?= $count('in', false) ?>실</b><small class="muted"><?= $count('in', true) ? '연박 ' . $count('in', true) . '실 (입실 없음)' : '오늘 묵는 객실' ?></small></div>
    <div class="kpi"><span>오늘 묵는 객실</span><b><?= count($lists['in']) ?>실</b><small class="muted">입실 인원 <?= number_format(array_sum(array_column($lists['in'], 'guests'))) ?>명</small></div>
    <div class="kpi"><span>미판매 객실</span><b><?= count($blocked) ?>실</b><small class="muted"><?= e(implode(', ', array_map(fn($pid, $r) => ($rooms[$pid]['name'] ?? '') . ' ' . (ROOM_BLOCK_REASONS[$r] ?? ''), array_keys($blocked), $blocked)) ?: '예비·공사·업무예약 없음') ?></small></div>
  </div>
  <?php if (!$todayDoc): ?><p class="small warn">이 날 일일객실판매가 아직 없어 입실예정이 비어 있을 수 있습니다. <a href="<?= e(url('write.php?type=rooms&date=' . $date)) ?>">객실판매관리에서 작성</a>(입실예정 엑셀로 채우기)하면 여기에 나옵니다.</p><?php endif ?>
</section>

<form method="post" class="rt-form">
  <?= csrf_field() ?><input type="hidden" name="date" value="<?= e($date) ?>">
  <h1 class="print-only">입퇴실현황 · <?= e(date('Y년 n월 j일', strtotime($date))) ?> (<?= e(weekday_ko($date)) ?>)</h1>
  <div class="rt-grid">
    <?php foreach (['out' => '퇴실예정', 'in' => '입실예정'] as $side => $label): ?>
    <section class="card rt-<?= $side ?>">
      <h2><?= $label ?> <small class="muted"><?= $side === 'out' ? $md($prev) . ' 묵은 객실' : $md($date) . ' 묵는 객실' ?> · <?= count($lists[$side]) ?>실</small></h2>
      <div class="table-scroll">
      <table class="table rt-table">
        <thead><tr><th>객실구분</th><th>객실명</th><th>입실</th><th>퇴실</th><th>연박</th><th>비고</th></tr></thead>
        <tbody>
        <?php foreach ($lists[$side] as $pid => $r): ?>
          <tr class="<?= $r['stay'] ? 'rt-stay' : '' ?>">
            <td class="small nowrap"><?= e(room_type_name($r['p']['room_type_id'] ? (int) $r['p']['room_type_id'] : null)) ?></td>
            <td class="nowrap"><b><?= e($r['p']['name']) ?></b> <small class="muted"><?= (int) $r['guests'] ?>명</small></td>
            <td class="nowrap <?= $side === 'in' && !$r['stay'] ? 'strong' : '' ?>"><?= e($md($r['from'])) ?></td>
            <td class="nowrap <?= $side === 'out' && !$r['stay'] ? 'strong' : '' ?>"><?= e($md($r['to'])) ?></td>
            <td class="nowrap"><?= $r['nights'] > 1 ? '<span class="badge rt-badge">연박 ' . (int) $r['nights'] . '박</span>' . ($r['stay'] ? '<br><small class="muted">' . ($side === 'out' ? '오늘 퇴실 없음' : '오늘 입실 없음') . '</small>' : '') : '' ?></td>
            <td><input name="note[<?= $side ?>][<?= (int) $pid ?>]" value="<?= e($notes[$side][$pid] ?? '') ?>" maxlength="300" placeholder="비고"></td>
          </tr>
        <?php endforeach ?>
        <?php if (!$lists[$side]): ?><tr><td colspan="6" class="center muted"><?= $label ?> 객실이 없습니다.</td></tr><?php endif ?>
        </tbody>
      </table>
      </div>
    </section>
    <?php endforeach ?>
  </div>
  <section class="card">
    <h2>중점정비사항 <?php if ($day && $day['updated_at']): ?><small class="muted"><?= e((string) $day['user_name']) ?> · <?= e(substr((string) $day['updated_at'], 0, 16)) ?></small><?php endif ?></h2>
    <textarea name="focus" rows="5" placeholder="예: 별A 욕실 배수 점검, 퇴실 객실 침구 전체 교체, 구름B 에어컨 필터 청소"><?= e((string) ($day['focus'] ?? '')) ?></textarea>
    <div class="actions no-print"><button class="btn primary">비고·중점정비사항 저장</button></div>
  </section>
</form>
<p class="muted small no-print">입실·퇴실은 <a href="<?= e(url('journal.php?type=rooms')) ?>">객실관리 › 객실판매관리</a>의 일일객실판매(임시저장 포함, 반려 제외)로 계산합니다. 퇴실예정 = 전날 판매한 객실, 입실예정 = 이 날 판매한 객실이며,
  같은 객실이 연달아 판매되면 한 번의 연박으로 보고 입실일·퇴실일·박수를 보여 줍니다 (연박 중인 객실은 그 날 입실·퇴실이 없습니다).</p>
<?php layout_footer();
