<?php
/**
 * 개인업무 › 문서관리: 모든 결재 문서를 분류·기간·상태·작성자로 조회하고 정렬한다.
 *   docs.php?type=&from=&to=&status=&author=&q=&sort=date|id|type|author|status|submitted|edited&dir=asc|desc&page=
 * 공무직 이상·최고관리자만. 수정하면 결재가 처음부터 다시 진행된다 (근태는 수정 대신 취소 후 다시 입력).
 * 삭제는 최고관리자만. 다른 사람의 임시저장 문서(업무일지·매출보고 제외)는 보이지 않는다.
 */
require __DIR__ . '/app/bootstrap.php';
require __DIR__ . '/app/xlsx.php';

$user = require_login();
if (!can_manage_docs($user)) abort(403, '문서관리는 공무직 이상만 사용할 수 있습니다.');
$pdo = db();
$isAdmin = !empty($user['is_admin']);

// 삭제 (최고관리자)
if (is_post()) {
    csrf_verify();
    if (!$isAdmin) abort(403, '문서 삭제는 최고관리자만 할 수 있습니다.');
    $ids = array_values(array_unique(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $n = 0;
    foreach ($ids as $id) {
        if ($j = journal_find($id)) {
            journal_delete($j);
            $n++;
        }
    }
    flash("문서 {$n}건을 삭제했습니다.", 'success');
    redirect($_POST['back'] ?? 'docs.php');
}

/* ───────────── 조건 ───────────── */
$type = isset(JOURNAL_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$status = isset(JOURNAL_STATUS[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$from = valid_date($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01', strtotime('-2 months'));
$to = valid_date($_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d', strtotime('+1 month'));
if ($from > $to) [$from, $to] = [$to, $from];
$author = (int) ($_GET['author'] ?? 0);
$q = trim((string) ($_GET['q'] ?? ''));
$SORTS = [
    'date'      => ['j.work_date', '일자'],
    'id'        => ['j.id', '문서번호'],
    'type'      => ['j.type', '분류'],
    'author'    => ['u.name', '작성자'],
    'status'    => ["FIELD(j.status, 'draft', 'pending', 'rejected', 'approved')", '상태'],
    'submitted' => ['j.submitted_at', '상신일시'],
    'edited'    => ['COALESCE(j.last_edited_at, j.submitted_at, j.created_at)', '최근 변경'],
];
$sort = isset($SORTS[$_GET['sort'] ?? '']) ? $_GET['sort'] : 'date';
$dir = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 50;
$params = array_filter(['type' => $type, 'status' => $status, 'from' => $from, 'to' => $to, 'author' => $author ?: null, 'q' => $q, 'sort' => $sort, 'dir' => $dir], fn($v) => $v !== null && $v !== '');
$link = fn(array $o = []) => 'docs.php?' . http_build_query(array_filter(array_merge($params, $o), fn($v) => $v !== null && $v !== ''));

$where = ['j.work_date BETWEEN ? AND ?'];
$args = [$from, $to];
// 다른 사람의 임시저장(공유 문서 제외)은 보이지 않음
$where[] = "(j.status <> 'draft' OR j.author_id = ? OR j.type IN ('" . implode("','", SHARED_DRAFT_TYPES) . "'))";
$args[] = $user['id'];
if ($type) { $where[] = 'j.type = ?'; $args[] = $type; }
if ($status) { $where[] = 'j.status = ?'; $args[] = $status; }
if ($author) { $where[] = '(j.author_id = ? OR a.user_id = ?)'; $args[] = $author; $args[] = $author; }
if ($q !== '') {
    $where[] = '(j.content LIKE ? OR j.remarks LIKE ? OR u.name LIKE ? OR au.name LIKE ? OR j.id = ?)';
    array_push($args, "%$q%", "%$q%", "%$q%", "%$q%", ctype_digit($q) ? (int) $q : 0);
}
$from_sql = 'FROM journals j JOIN users u ON u.id = j.author_id
             LEFT JOIN attendance a ON a.journal_id = j.id LEFT JOIN users au ON au.id = a.user_id
            WHERE ' . implode(' AND ', $where);

$st = $pdo->prepare("SELECT COUNT(*) $from_sql");
$st->execute($args);
$total = (int) $st->fetchColumn();
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$export = ($_GET['export'] ?? '') === 'xlsx';

$st = $pdo->prepare("SELECT j.*, u.name AS author_name, u.rank_level AS author_rank, a.kind AS att_kind, a.start_date AS att_start, a.end_date AS att_end, au.name AS att_user,
        (SELECT ap.required_rank FROM approvals ap WHERE ap.journal_id = j.id AND ap.status = 'waiting' ORDER BY ap.step_order LIMIT 1) AS waiting_rank,
        (SELECT COALESCE(SUM(l.amount), 0) FROM sales_lines l WHERE l.journal_id = j.id) AS sales_amount
        $from_sql
        ORDER BY {$SORTS[$sort][0]} " . strtoupper($dir) . ', j.id ' . strtoupper($dir)
        . ($export ? '' : ' LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per)));
$st->execute($args);
$rows = $st->fetchAll();

/** 문서 한 줄 요약 */
$summary = function (array $j): string {
    if ($j['type'] === 'attendance') return trim(($j['att_user'] ?? '') . ' ' . ($j['att_kind'] ? att_kind_name($j['att_kind']) : '') . ' ' . ($j['att_start'] ?? '') . ($j['att_end'] && $j['att_end'] !== $j['att_start'] ? ' ~ ' . $j['att_end'] : ''));
    if ($j['type'] === 'rooms') return '객실 매출 ' . number_format((int) $j['sales_amount']) . '원' . ($j['content'] ? ' · ' . mb_strimwidth(preg_replace('/\s+/', ' ', $j['content']), 0, 40, '…') : '');
    if ($j['type'] === 'sales') return '매출 ' . number_format((int) $j['sales_amount']) . '원' . ($j['content'] ? ' · ' . mb_strimwidth(preg_replace('/\s+/', ' ', $j['content']), 0, 40, '…') : '');
    if ($j['type'] === 'facility' && $j['team_id']) return team_name((int) $j['team_id']) . ($j['content'] ? ' · ' . mb_strimwidth(preg_replace('/\s+/', ' ', $j['content']), 0, 40, '…') : '');
    return mb_strimwidth(preg_replace('/\s+/', ' ', (string) $j['content']), 0, 60, '…');
};
$stepText = fn(array $j) => $j['status'] === 'pending' && $j['waiting_rank'] ? rank_name((int) $j['waiting_rank']) . ' 결재 차례' : '';

if ($export) {
    xlsx_send("문서목록_{$from}_{$to}.xlsx", [[
        'name' => '문서', 'title' => "문서 목록 ($from ~ $to)" . ($type ? ' · ' . JOURNAL_TYPES[$type] : ''),
        'subtitle' => '출력 ' . date('Y-m-d H:i') . ' · ' . $user['name'],
        'header' => ['문서번호', '분류', '일자', '내용', '작성자', '상태', '결재 진행', '수정 횟수', '상신일시', '결재완료'],
        'rows' => array_map(fn($j) => [(int) $j['id'], JOURNAL_TYPES[$j['type']], $j['work_date'], $summary($j), $j['author_name'], JOURNAL_STATUS[$j['status']],
            $stepText($j), (int) $j['revision'], (string) $j['submitted_at'], (string) $j['completed_at']], $rows),
        'widths' => [9, 18, 11, 50, 10, 9, 14, 8, 17, 17],
    ]]);
}

$authors = $pdo->query("SELECT id, name, rank_level FROM users WHERE status = 'active' AND hide_in_org = 0 ORDER BY rank_level DESC, name")->fetchAll();
$typeCount = [];
$st = $pdo->prepare('SELECT j.type, COUNT(*) FROM journals j WHERE j.work_date BETWEEN ? AND ? GROUP BY j.type');
$st->execute([$from, $to]);
$typeCount = $st->fetchAll(PDO::FETCH_KEY_PAIR);
$sortHead = function (string $key, string $label, string $cls = '') use ($sort, $dir, $link): string {
    $on = $sort === $key;
    $next = $on && $dir === 'desc' ? 'asc' : 'desc';
    return '<th class="' . $cls . '"><a class="sort ' . ($on ? 'on' : '') . '" href="' . e(url($link(['sort' => $key, 'dir' => $next, 'page' => null]))) . '">'
        . e($label) . ($on ? ($dir === 'desc' ? ' ▼' : ' ▲') : '') . '</a></th>';
};
$presets = [
    '이번 달' => [date('Y-m-01'), date('Y-m-t')],
    '지난달'  => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
    '최근 3개월' => [date('Y-m-d', strtotime('-3 months')), date('Y-m-d')],
    '올해'   => [date('Y-01-01'), date('Y-12-31')],
];
$back = $link(['page' => $page > 1 ? $page : null]);

layout_header('문서관리', 'docs');
?>
<section class="card">
  <div class="card-head">
    <h1>문서관리</h1>
    <div class="actions no-margin no-print">
      <a class="btn" href="<?= e(url($link(['export' => 'xlsx', 'page' => null]))) ?>">엑셀</a>
      <button class="btn ghost" type="button" onclick="window.print()">인쇄</button>
    </div>
  </div>
  <p class="muted small">모든 결재 문서를 분류·기간·상태·작성자로 찾아 보고 수정할 수 있습니다 (공무직 이상). 줄을 누르면 문서가 열리고, 제목 칸을 누르면 정렬됩니다.
    결재를 올린 문서를 수정하면 <b>결재가 처음부터 다시</b> 진행되고 수정 이력이 남습니다. 근태는 수정 대신 문서에서 취소 후 다시 입력합니다.
    <?= $isAdmin ? '문서 삭제는 최고관리자만 할 수 있습니다.' : '문서 삭제는 최고관리자에게 요청하세요.' ?></p>

  <form method="get" class="filters doc-filters no-print">
    <label>분류<select name="type">
      <option value="">전체</option>
      <?php foreach (JOURNAL_TYPES as $k => $v): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= e($v) ?><?= isset($typeCount[$k]) ? ' (' . (int) $typeCount[$k] . ')' : '' ?></option><?php endforeach ?>
    </select></label>
    <label>시작일<input type="date" name="from" value="<?= e($from) ?>"></label>
    <label>종료일<input type="date" name="to" value="<?= e($to) ?>"></label>
    <label>상태<select name="status">
      <option value="">전체</option>
      <?php foreach (JOURNAL_STATUS as $k => $v): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach ?>
    </select></label>
    <label>작성자·대상자<select name="author">
      <option value="">전체</option>
      <?php foreach ($authors as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $author === (int) $a['id'] ? 'selected' : '' ?>><?= e($a['name']) ?> (<?= e(rank_name($a['rank_level'])) ?>)</option><?php endforeach ?>
    </select></label>
    <label>검색<input name="q" value="<?= e($q) ?>" placeholder="내용·이름·문서번호"></label>
    <input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="dir" value="<?= e($dir) ?>">
    <button class="btn primary">조회</button>
    <a class="btn ghost" href="<?= e(url('docs.php')) ?>">초기화</a>
  </form>
  <div class="doc-presets no-print">
    <?php foreach ($presets as $label => [$f, $t]): ?>
      <a class="chip <?= $from === $f && $to === $t ? 'on' : '' ?>" href="<?= e(url($link(['from' => $f, 'to' => $t, 'page' => null]))) ?>"><?= e($label) ?></a>
    <?php endforeach ?>
    <span class="muted small">· <?= e($from) ?> ~ <?= e($to) ?> · 총 <b><?= number_format($total) ?></b>건</span>
  </div>
</section>

<section class="card">
  <form method="post" id="docForm">
    <?= csrf_field() ?><input type="hidden" name="back" value="<?= e($back) ?>">
    <?php if ($isAdmin): ?>
    <div class="bulk-bar no-print">
      <label class="inline-check"><input type="checkbox" data-check-all> 전체선택</label>
      <span class="muted small" data-selected-count>0건 선택</span>
      <button class="btn small danger" data-bulk disabled onclick="return confirm('선택한 문서를 삭제합니다. 결재 기록·첨부·사진도 함께 지워지며 되돌릴 수 없습니다. 삭제할까요?')">선택 문서 삭제</button>
    </div>
    <?php endif ?>
  <div class="table-scroll">
  <table class="table doc-table">
    <thead><tr>
      <?php if ($isAdmin): ?><th class="check-col no-print"></th><?php endif ?>
      <?= $sortHead('id', '번호', 'right') ?><?= $sortHead('type', '분류') ?><?= $sortHead('date', '일자') ?><th>내용</th>
      <?= $sortHead('author', '작성자') ?><?= $sortHead('status', '상태') ?><?= $sortHead('submitted', '상신') ?><?= $sortHead('edited', '최근 변경') ?><th class="no-print"></th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $j): $edited = $j['last_edited_at'] ?: ($j['submitted_at'] ?: $j['created_at']); ?>
      <tr class="clickable" onclick="if (!event.target.closest('a, button, input, .check-col')) location.href='<?= e(url('view.php?id=' . $j['id'])) ?>'">
        <?php if ($isAdmin): ?><td class="check-col no-print"><input type="checkbox" name="ids[]" value="<?= (int) $j['id'] ?>" data-row-check></td><?php endif ?>
        <td class="right muted"><?= (int) $j['id'] ?></td>
        <td class="nowrap"><?= e(JOURNAL_TYPES[$j['type']]) ?></td>
        <td class="nowrap"><?= e($j['work_date']) ?> <small class="muted">(<?= weekday_ko($j['work_date']) ?>)</small></td>
        <td class="small doc-sum"><?= e($summary($j)) ?></td>
        <td class="nowrap"><?= e($j['author_name']) ?> <small class="muted"><?= e(rank_name((int) $j['author_rank'])) ?></small></td>
        <td class="nowrap"><?= journal_badges($j) ?><?= $stepText($j) ? '<br><small class="muted">' . e($stepText($j)) . '</small>' : '' ?></td>
        <td class="small nowrap"><?= $j['submitted_at'] ? e(date('y.m.d H:i', strtotime($j['submitted_at']))) : '-' ?></td>
        <td class="small nowrap"><?= e(date('y.m.d H:i', strtotime($edited))) ?></td>
        <td class="nowrap no-print">
          <?= $j['type'] !== 'attendance' && can_edit_journal($j, $user) ? edit_button($j, 'btn small') : '<span class="muted small">' . ($j['type'] === 'attendance' ? '취소만' : '') . '</span>' ?>
        </td>
      </tr>
    <?php endforeach ?>
    <?php if (!$rows): ?><tr><td colspan="<?= $isAdmin ? 10 : 9 ?>" class="center muted">조건에 맞는 문서가 없습니다.</td></tr><?php endif ?>
    </tbody>
  </table>
  </div>
  </form>
  <?php if ($pages > 1): ?>
  <nav class="pager no-print">
    <?php if ($page > 1): ?><a class="btn small" href="<?= e(url($link(['page' => $page - 1]))) ?>">‹ 이전</a><?php endif ?>
    <?php for ($p = max(1, $page - 4); $p <= min($pages, $page + 4); $p++): ?>
      <a class="btn small <?= $p === $page ? 'primary' : 'ghost' ?>" href="<?= e(url($link(['page' => $p]))) ?>"><?= $p ?></a>
    <?php endfor ?>
    <?php if ($page < $pages): ?><a class="btn small" href="<?= e(url($link(['page' => $page + 1]))) ?>">다음 ›</a><?php endif ?>
    <span class="muted small"><?= $page ?> / <?= $pages ?>쪽</span>
  </nav>
  <?php endif ?>
</section>
<?php if ($isAdmin): ?>
<script>
(function () {
  const form = document.getElementById('docForm');
  const rows = [...form.querySelectorAll('[data-row-check]')];
  const all = form.querySelector('[data-check-all]');
  const sync = () => {
    const n = rows.filter((c) => c.checked).length;
    form.querySelector('[data-selected-count]').textContent = n + '건 선택';
    form.querySelector('[data-bulk]').disabled = !n;
    all.checked = n && n === rows.length;
  };
  all.addEventListener('change', () => { rows.forEach((c) => { c.checked = all.checked; }); sync(); });
  rows.forEach((c) => c.addEventListener('change', sync));
})();
</script>
<?php endif ?>
<?php layout_footer();
