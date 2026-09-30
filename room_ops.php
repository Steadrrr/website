<?php
/**
 * 객실관리 › 객실운영관리: 판매하지 않는 객실(예비객실·공사·업무예약)과 기간 등록
 *   room_ops.php[?show=now|past|all]   목록 (기본: 진행 중·예정)
 *   room_ops.php?edit=3                 한 건 고치기
 * 객실이용통계의 가동률에서 이 객실·날짜(실·일)를 뺀다. 종료일을 비우면 해제할 때까지 계속 (예비객실처럼 자주 바뀌는 경우).
 * 객실관리 메뉴 권한이 있으면 누구나 등록·수정·해제·삭제.
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
require_menu($user, 'room');
$pdo = db();
$today = date('Y-m-d');
$rooms = array_filter(products_all(), fn($p) => $p['grp'] === 'room');
$show = in_array($_GET['show'] ?? '', ['past', 'all'], true) ? $_GET['show'] : 'now';

/** 같은 객실에 기간이 겹치는 다른 등록 */
function rb_overlap(int $pid, string $from, ?string $to, int $exceptId = 0): ?array
{
    $st = db()->prepare('SELECT * FROM room_blocks WHERE product_id = ? AND id <> ? AND date_from <= ? AND (date_to IS NULL OR date_to >= ?) LIMIT 1');
    $st->execute([$pid, $exceptId, $to ?? '9999-12-31', $from]);
    return $st->fetch() ?: null;
}

$fmtRange = fn(string $f, ?string $t) => date('Y.n.j', strtotime($f)) . ' ~ ' . ($t ? date('Y.n.j', strtotime($t)) : '해제할 때까지');

if (is_post()) {
    csrf_verify();
    $act = post('act');
    $id = (int) post('id');
    $back = 'room_ops.php' . ($show !== 'now' ? "?show=$show" : '');
    if ($act === 'delete' && $id) {
        $pdo->prepare('DELETE FROM room_blocks WHERE id = ?')->execute([$id]);
        flash('삭제했습니다.', 'success');
        redirect($back);
    }
    if ($act === 'release' && $id) { // 오늘부터 판매 → 어제까지로 끝냄 (오늘 시작한 건은 삭제)
        $st = $pdo->prepare('SELECT * FROM room_blocks WHERE id = ?');
        $st->execute([$id]);
        if ($b = $st->fetch()) {
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            if ($b['date_from'] > $yesterday) $pdo->prepare('DELETE FROM room_blocks WHERE id = ?')->execute([$id]);
            else $pdo->prepare('UPDATE room_blocks SET date_to = ?, updated_by = ? WHERE id = ?')->execute([$yesterday, $user['id'], $id]);
            flash(($rooms[(int) $b['product_id']]['name'] ?? '객실') . '을(를) 오늘부터 판매하는 것으로 바꿨습니다.', 'success');
        }
        redirect($back);
    }
    // 등록 · 수정
    $pids = $id ? [(int) post('product_id')] : array_map('intval', (array) ($_POST['product_ids'] ?? []));
    $pids = array_values(array_filter($pids, fn($p) => isset($rooms[$p])));
    $reason = post('reason');
    $from = post('date_from');
    $to = post('date_to') !== '' ? post('date_to') : null;
    $note = mb_substr(trim(post('note')), 0, 200);
    $errors = [];
    if (!$pids) $errors[] = '객실을 고르세요.';
    if (!isset(ROOM_BLOCK_REASONS[$reason])) $errors[] = '미판매 사유를 고르세요.';
    if (!valid_date($from)) $errors[] = '시작일을 입력하세요.';
    if ($to !== null && (!valid_date($to) || $to < $from)) $errors[] = '종료일이 시작일보다 빠릅니다.';
    foreach ($errors ? [] : $pids as $pid) {
        if ($o = rb_overlap($pid, $from, $to, $id)) {
            $errors[] = $rooms[$pid]['name'] . ': 이미 ' . ROOM_BLOCK_REASONS[$o['reason']] . '(' . $fmtRange($o['date_from'], $o['date_to']) . ')로 등록된 기간과 겹칩니다.';
        }
    }
    if ($errors) {
        foreach ($errors as $e) flash($e, 'error');
        $_SESSION['room_ops_form'] = $_POST;
        redirect($id ? "room_ops.php?edit=$id" : $back);
    }
    if ($id) {
        $pdo->prepare('UPDATE room_blocks SET product_id = ?, reason = ?, date_from = ?, date_to = ?, note = ?, updated_by = ? WHERE id = ?')
            ->execute([$pids[0], $reason, $from, $to, $note ?: null, $user['id'], $id]);
        flash('고쳤습니다.', 'success');
    } else {
        $ins = $pdo->prepare('INSERT INTO room_blocks (product_id, reason, date_from, date_to, note, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($pids as $pid) $ins->execute([$pid, $reason, $from, $to, $note ?: null, $user['id']]);
        flash(count($pids) . '실을 ' . ROOM_BLOCK_REASONS[$reason] . '(' . $fmtRange($from, $to) . ')로 등록했습니다.', 'success');
    }
    redirect($back);
}

$edit = null;
if ($eid = (int) ($_GET['edit'] ?? 0)) {
    $st = $pdo->prepare('SELECT * FROM room_blocks WHERE id = ?');
    $st->execute([$eid]);
    $edit = $st->fetch() ?: null;
}
$old = $_SESSION['room_ops_form'] ?? null;
unset($_SESSION['room_ops_form']);
$val = fn(string $k, $d = '') => $old[$k] ?? ($edit[$k] ?? $d);

$where = match ($show) {
    'past' => 'b.date_to IS NOT NULL AND b.date_to < CURDATE()',
    'all'  => '1',
    default => '(b.date_to IS NULL OR b.date_to >= CURDATE())',
};
$list = $pdo->query("SELECT b.*, u.name AS user_name FROM room_blocks b LEFT JOIN users u ON u.id = COALESCE(b.updated_by, b.created_by)
                      WHERE $where ORDER BY (b.date_from > CURDATE()), b.date_from DESC, b.id DESC")->fetchAll();
$nowMap = room_block_map($today, $today)[$today] ?? [];
$activeRooms = array_filter($rooms, fn($p) => $p['is_active']);
$byType = [];
foreach ($rooms as $p) $byType[room_type_name($p['room_type_id'] ? (int) $p['room_type_id'] : null)][] = $p;

layout_header('객실운영관리', 'room_ops');
?>
<section class="card">
  <div class="card-head">
    <h1>객실운영관리 <small class="muted">판매하지 않는 객실 (예비객실 · 공사 · 업무예약)</small></h1>
    <a class="btn ghost" href="<?= e(url('room_stats.php')) ?>">객실이용통계 ›</a>
  </div>
  <p class="small">여기에 등록한 객실·기간은 <b>객실이용통계의 가동률</b>에서 뺍니다 (가동률 = 판매 객실 ÷ (객실 수 × 영업일 − 미판매 객실·일)).
    예비객실처럼 자주 바뀌면 <b>종료일을 비워</b> 두고, 다시 판매할 때 <b>'오늘부터 판매'</b>를 누르세요.</p>
  <div class="kpis k4">
    <div class="kpi total"><span>오늘 판매 가능</span><b><?= count(array_diff_key($activeRooms, $nowMap)) ?>실</b><small class="muted">판매 중 객실 <?= count($activeRooms) ?>실</small></div>
    <?php foreach (ROOM_BLOCK_REASONS as $k => $label): $ids = array_keys(array_filter($nowMap, fn($r) => $r === $k)); ?>
      <div class="kpi"><span>오늘 <?= e($label) ?></span><b><?= count($ids) ?>실</b><small class="muted"><?= e(implode(', ', array_map(fn($p) => $rooms[$p]['name'] ?? '', $ids)) ?: '-') ?></small></div>
    <?php endforeach ?>
  </div>
</section>

<form method="post" class="card" id="form">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
  <h2><?= $edit ? '미판매 등록 고치기' : '미판매 등록' ?></h2>
  <?php if ($edit): ?>
    <label>객실<select name="product_id">
      <?php foreach ($rooms as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $val('product_id') === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach ?>
    </select></label>
  <?php else: $checked = array_map('intval', (array) ($old['product_ids'] ?? [])); ?>
    <div class="rb-rooms"><span class="label">객실 <small class="muted">(여러 개 고를 수 있음)</small></span>
      <?php foreach ($byType as $tname => $list2): ?>
        <div class="rb-type"><b class="small muted"><?= e($tname) ?></b>
          <?php foreach ($list2 as $p): ?><label class="inline-check"><input type="checkbox" name="product_ids[]" value="<?= (int) $p['id'] ?>" <?= in_array((int) $p['id'], $checked, true) ? 'checked' : '' ?>> <?= e($p['name']) ?><?= isset($nowMap[(int) $p['id']]) ? ' <small class="warn">(' . e(ROOM_BLOCK_REASONS[$nowMap[(int) $p['id']]]) . ')</small>' : '' ?><?= $p['is_active'] ? '' : ' <small class="muted">(판매중지)</small>' ?></label><?php endforeach ?>
        </div>
      <?php endforeach ?>
      <?php if (!$rooms): ?><p class="muted">등록된 객실이 없습니다. 설정 › 상품·요금에서 객실을 만드세요.</p><?php endif ?>
    </div>
  <?php endif ?>
  <div class="row">
    <label>미판매 사유<select name="reason" required>
      <option value="">고르세요</option>
      <?php foreach (ROOM_BLOCK_REASONS as $k => $label): ?><option value="<?= $k ?>" <?= $val('reason') === $k ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?>
    </select></label>
    <label>시작일<input type="date" name="date_from" value="<?= e($val('date_from', $today)) ?>" required></label>
    <label>종료일 <small class="muted">(비우면 해제할 때까지)</small><input type="date" name="date_to" value="<?= e((string) $val('date_to')) ?>"></label>
    <label>메모<input name="note" value="<?= e((string) $val('note')) ?>" maxlength="200" placeholder="예: 욕실 누수 공사, 직원 연수 숙소"></label>
  </div>
  <div class="actions">
    <?php if ($edit): ?><a class="btn ghost" href="<?= e(url('room_ops.php')) ?>">취소</a><?php endif ?>
    <button class="btn primary"><?= $edit ? '고치기' : '등록' ?></button>
  </div>
</form>

<section class="card">
  <div class="card-head">
    <h2>미판매 목록</h2>
    <div class="stat-units">
      <?php foreach (['now' => '진행 중·예정', 'past' => '지난 것', 'all' => '전체'] as $k => $label): ?>
        <a href="<?= e(url('room_ops.php' . ($k === 'now' ? '' : "?show=$k"))) ?>" class="<?= $show === $k ? 'on' : '' ?>"><?= $label ?></a>
      <?php endforeach ?>
    </div>
  </div>
  <div class="table-scroll">
  <table class="table">
    <thead><tr><th>객실</th><th>사유</th><th>기간</th><th class="right">일수</th><th>메모</th><th>등록·수정</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $b):
        $state = $b['date_from'] > $today ? '예정' : ($b['date_to'] !== null && $b['date_to'] < $today ? '지남' : '진행 중');
        $days = $b['date_to'] ? (int) round((strtotime($b['date_to']) - strtotime($b['date_from'])) / 86400) + 1 : null; ?>
      <tr class="<?= $state === '지남' ? 'zero' : '' ?>">
        <td><b><?= e($rooms[(int) $b['product_id']]['name'] ?? '(삭제된 객실)') ?></b></td>
        <td><span class="badge rb-<?= e($b['reason']) ?>"><?= e(ROOM_BLOCK_REASONS[$b['reason']] ?? $b['reason']) ?></span></td>
        <td class="nowrap"><?= e($fmtRange($b['date_from'], $b['date_to'])) ?> <small class="muted"><?= $state ?></small></td>
        <td class="right"><?= $days ? $days . '일' : '-' ?></td>
        <td class="small"><?= e((string) $b['note']) ?></td>
        <td class="small muted nowrap"><?= e((string) $b['user_name']) ?><br><?= e(substr((string) ($b['updated_at'] ?? $b['created_at']), 0, 10)) ?></td>
        <td class="nowrap">
          <a class="btn small" href="<?= e(url('room_ops.php?edit=' . (int) $b['id'])) ?>#form">고치기</a>
          <?php if ($state === '진행 중'): ?>
            <form method="post" class="inline" onsubmit="return confirm('오늘부터 판매하는 것으로 바꿀까요? (종료일 = 어제)')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="btn small" name="act" value="release">오늘부터 판매</button></form>
          <?php endif ?>
          <form method="post" class="inline" onsubmit="return confirm('삭제할까요? 지난 기간의 가동률 계산에서도 빠집니다.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="btn small ghost" name="act" value="delete">삭제</button></form>
        </td>
      </tr>
    <?php endforeach ?>
    <?php if (!$list): ?><tr><td colspan="7" class="center muted">등록된 미판매 객실이 없습니다.</td></tr><?php endif ?>
    </tbody>
  </table>
  </div>
</section>
<?php layout_footer();
