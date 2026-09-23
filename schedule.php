<?php
/**
 * 일정표 (구글 캘린더 스타일): schedule.php?ym=2026-09[&view=month|list]
 * 누구나 일정을 만들 수 있고, 수정·삭제는 작성자·주무관 이상·최고관리자.
 * 공휴일·휴관일은 최고관리자만 등록하며 근태관리와 공유된다 (공휴일은 근태의 근무일에서 빠짐).
 * 근태(사원) 결재중·완료 건도 '근태' 분류로 함께 보여 준다 (&att_team=팀 으로 팀별 조회).
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
$pdo = db();

$ym = $_GET['ym'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $ym) || !valid_date("$ym-01")) $ym = date('Y-m');
$view = ($_GET['view'] ?? 'month') === 'list' ? 'list' : 'month';

/* ───────────── 저장 / 삭제 ───────────── */
if (is_post()) {
    csrf_verify();
    $id = (int) post('id');
    $ev = null;
    if ($id) {
        $st = $pdo->prepare('SELECT * FROM events WHERE id = ?');
        $st->execute([$id]);
        $ev = $st->fetch() ?: abort(404, '일정을 찾을 수 없습니다.');
        if (!can_edit_event($ev, $user)) abort(403, '작성자 또는 주무관 이상만 수정·삭제할 수 있습니다.');
    }
    $back = fn(string $date) => 'schedule.php?ym=' . substr($date, 0, 7) . ($view === 'list' ? '&view=list' : '');

    if (post('action') === 'delete' && $ev) {
        $pdo->prepare('DELETE FROM events WHERE id = ?')->execute([$id]);
        flash("'{$ev['title']}' 일정을 삭제했습니다.", 'success');
        redirect($back($ev['start_date']));
    }

    $title = mb_substr(post('title'), 0, 100);
    $cat = post('category');
    $start = post('start_date');
    $end = post('end_date') ?: $start;
    $allDay = post('all_day') === '1';
    $t1 = post('start_time');
    $t2 = post('end_time');
    $validTime = fn(string $t) => (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
    $err = null;
    if ($title === '') $err = '제목을 입력하세요.';
    elseif (!isset(EVENT_CATEGORIES[$cat])) $err = '분류를 고르세요.';
    elseif (!can_use_event_category($cat, $user)) $err = '공휴일·휴관일은 최고관리자만 등록할 수 있습니다.';
    elseif (!valid_date($start) || !valid_date($end)) $err = '날짜를 확인하세요.';
    elseif ($end < $start) $err = '종료일이 시작일보다 빠릅니다.';
    elseif (!$allDay && !$validTime($t1)) $err = '시작 시간을 확인하세요.';
    elseif (!$allDay && $t2 !== '' && !$validTime($t2)) $err = '종료 시간을 확인하세요.';
    elseif (!$allDay && $t2 !== '' && $start === $end && $t2 < $t1) $err = '종료 시간이 시작 시간보다 빠릅니다.';
    if ($err) {
        flash($err, 'error');
        redirect($back(valid_date($start) ? $start : "$ym-01"));
    }
    $row = [$title, $cat, $start, $end, (int) $allDay, $allDay ? null : $t1, $allDay || $t2 === '' ? null : $t2,
        mb_substr(post('location'), 0, 100) ?: null, post('description') ?: null];
    if ($ev) {
        $pdo->prepare('UPDATE events SET title = ?, category = ?, start_date = ?, end_date = ?, all_day = ?, start_time = ?, end_time = ?, location = ?, description = ?, updated_at = NOW() WHERE id = ?')
            ->execute([...$row, $id]);
    } else {
        $pdo->prepare('INSERT INTO events (title, category, start_date, end_date, all_day, start_time, end_time, location, description, author_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([...$row, $user['id']]);
    }
    flash("'{$title}' 일정을 저장했습니다.", 'success');
    redirect($back($start));
}

/* ───────────── 달력 계산 ───────────── */
[$first, $last, $gridStart, $gridEnd] = month_grid($ym); // 첫 주 일요일 ~ 마지막 주 토요일
$today = date('Y-m-d');
$events = events_between($gridStart->format('Y-m-d'), $gridEnd->format('Y-m-d'));
$monthEvents = array_filter($events, fn($e) => $e['start_date'] <= $last->format('Y-m-d') && $e['end_date'] >= $first->format('Y-m-d'));
$catCount = array_count_values(array_column($monthEvents, 'category'));

// 근태 (사원) — 팀별 조회
$attTeam = (int) ($_GET['att_team'] ?? 0);
if (!isset(teams_all()[$attTeam])) $attTeam = 0;
$attItems = array_map(fn($i) => $i + ['src' => 'att'], att_calendar_items(att_records(['from' => $gridStart->format('Y-m-d'), 'to' => $gridEnd->format('Y-m-d'), 'team_id' => $attTeam])));
$attMonth = count(array_filter($attItems, fn($i) => $i['start_date'] <= $last->format('Y-m-d') && $i['end_date'] >= $first->format('Y-m-d')));
$holidays = holiday_dates($gridStart->format('Y-m-d'), $gridEnd->format('Y-m-d'));

const VISIBLE_LANES = 3;

// 화면(JS)에 넘길 일정 정보
$jsEvents = array_map(fn($e) => [
    'id' => (int) $e['id'], 'title' => $e['title'], 'category' => $e['category'],
    'start_date' => $e['start_date'], 'end_date' => $e['end_date'], 'all_day' => (int) $e['all_day'],
    'start_time' => $e['start_time'] ? substr($e['start_time'], 0, 5) : '', 'end_time' => $e['end_time'] ? substr($e['end_time'], 0, 5) : '',
    'location' => (string) $e['location'], 'description' => (string) $e['description'],
    'author' => $e['author_name'], 'when' => event_when($e, true), 'can_edit' => can_edit_event($e, $user),
], $events);

$prev = $first->modify('-1 month')->format('Y-m');
$next = $first->modify('+1 month')->format('Y-m');
$q = fn(array $o) => 'schedule.php?' . http_build_query(array_merge(['ym' => $ym, 'view' => $view === 'list' ? 'list' : null, 'att_team' => $attTeam ?: null], $o));

layout_header('일정표 ' . $first->format('Y년 n월'), 'schedule');
?>
<div class="gcal" id="gcal">
  <aside class="gcal-side no-print">
    <button class="gcal-create" type="button" data-create="<?= e($ym === substr($today, 0, 7) ? $today : "$ym-01") ?>"><span>+</span> 만들기</button>

    <div class="mini-cal">
      <div class="mini-head">
        <b><?= e($first->format('Y년 n월')) ?></b>
        <span><a href="<?= e(url($q(['ym' => $prev]))) ?>" aria-label="이전 달">‹</a><a href="<?= e(url($q(['ym' => $next]))) ?>" aria-label="다음 달">›</a></span>
      </div>
      <div class="mini-grid">
        <?php foreach (['일', '월', '화', '수', '목', '금', '토'] as $w): ?><span class="mini-w"><?= $w ?></span><?php endforeach ?>
        <?php for ($d = $gridStart; $d <= $gridEnd; $d = $d->modify('+1 day')):
            $ds = $d->format('Y-m-d');
            $has = array_filter($events, fn($e) => $e['start_date'] <= $ds && $e['end_date'] >= $ds); ?>
          <button type="button" class="mini-d <?= $d->format('m') !== $first->format('m') ? 'other' : '' ?> <?= $ds === $today ? 'today' : '' ?> <?= $has ? 'has' : '' ?>" data-day="<?= $ds ?>"><?= $d->format('j') ?></button>
        <?php endfor ?>
      </div>
    </div>

    <div class="gcal-filters">
      <h4>분류</h4>
      <?php foreach (EVENT_CATEGORIES as $key => [$label, $color]): ?>
        <label style="--c: <?= $color ?>"><input type="checkbox" data-filter="<?= $key ?>" checked><span class="box"></span><?= e($label) ?>
          <small><?= (int) ($catCount[$key] ?? 0) ?></small></label>
      <?php endforeach ?>
      <label style="--c: #1a73e8"><input type="checkbox" data-filter="att" checked><span class="box"></span>근태 (사원)
        <small><?= $attMonth ?></small></label>
      <form method="get">
        <input type="hidden" name="ym" value="<?= e($ym) ?>"><?php if ($view === 'list'): ?><input type="hidden" name="view" value="list"><?php endif ?>
        <select name="att_team" onchange="this.form.submit()" aria-label="근태 팀별 조회">
          <option value="">근태: 전체 팀</option>
          <?php foreach (teams_all() as $t): ?><option value="<?= (int) $t['id'] ?>" <?= $attTeam === (int) $t['id'] ? 'selected' : '' ?>>근태: <?= e($t['name']) ?></option><?php endforeach ?>
        </select>
      </form>
      <a class="small" href="<?= e(url('attendance.php?ym=' . $ym . ($attTeam ? '&team=' . $attTeam : ''))) ?>">근태관리에서 보기 ›</a>
    </div>
  </aside>

  <section class="gcal-main">
    <div class="gcal-toolbar">
      <a class="btn" href="<?= e(url($q(['ym' => date('Y-m')]))) ?>">오늘</a>
      <a class="icon-btn" href="<?= e(url($q(['ym' => $prev]))) ?>" aria-label="이전 달">‹</a>
      <a class="icon-btn" href="<?= e(url($q(['ym' => $next]))) ?>" aria-label="다음 달">›</a>
      <h1><?= e($first->format('Y년 n월')) ?></h1>
      <div class="gcal-views no-print">
        <a href="<?= e(url('schedule.php?ym=' . $ym)) ?>" class="<?= $view === 'month' ? 'on' : '' ?>">월</a>
        <a href="<?= e(url('schedule.php?ym=' . $ym . '&view=list')) ?>" class="<?= $view === 'list' ? 'on' : '' ?>">목록</a>
      </div>
      <button class="btn ghost no-print" type="button" onclick="window.print()">인쇄</button>
    </div>

    <?php if ($view === 'month'): ?>
    <div class="gcal-month">
      <div class="gcal-head"><?php foreach (['일', '월', '화', '수', '목', '금', '토'] as $i => $w): ?><div class="<?= $i === 0 ? 'sun' : ($i === 6 ? 'sat' : '') ?>"><?= $w ?></div><?php endforeach ?></div>
      <?php for ($w = $gridStart; $w <= $gridEnd; $w = $w->modify('+7 days')):
          [$bars, $hidden] = week_layout([...$events, ...$attItems], $w, VISIBLE_LANES); ?>
        <div class="gcal-week">
          <div class="gcal-days">
            <?php for ($i = 0; $i < 7; $i++): $d = $w->modify("+$i days"); $ds = $d->format('Y-m-d'); ?>
              <div class="gcal-day <?= $d->format('m') !== $first->format('m') ? 'other' : '' ?> <?= $ds === $today ? 'today' : '' ?> <?= $i === 0 ? 'sun' : ($i === 6 ? 'sat' : '') ?> <?= isset($holidays[$ds]) ? 'holiday' : '' ?>" data-create="<?= $ds ?>">
                <span class="gcal-num" data-day="<?= $ds ?>"><?= $d->format('j') === '1' ? '<span class="wide">' . $d->format('n월') . ' </span>' : '' ?><?= $d->format('j') ?><?= $d->format('j') === '1' ? '<span class="wide">일</span>' : '' ?></span>
              </div>
            <?php endfor ?>
          </div>
          <div class="gcal-events">
            <?php foreach ($bars as $b): $e = $b['ev'];
                if (($e['src'] ?? '') === 'att'): ?>
              <a href="<?= e(url('view.php?id=' . $e['id'])) ?>" class="gcal-ev <?= $e['all_day'] ? 'bar' : 'dot' ?> <?= $e['status'] === 'pending' ? 'pending' : '' ?> <?= $b['contL'] ? 'cont-l' : '' ?> <?= $b['contR'] ? 'cont-r' : '' ?>"
                 data-cat="att" style="--c: <?= ATT_KINDS[$e['kind']][1] ?>; grid-column: <?= $b['start'] + 1 ?> / span <?= $b['span'] ?>; grid-row: <?= $b['lane'] + 1 ?>;"
                 title="<?= e('근태 · ' . $e['title'] . ' · ' . $e['when'] . ($e['status'] === 'pending' ? ' · 결재중' : '')) ?>">
                <?php if (!$e['all_day']): ?><i></i><?php endif ?><span class="n"><?= e($e['all_day'] ? $e['title'] : $e['rec']['user_name'] . ' ' . att_kind_name($e['kind'])) ?></span>
              </a>
            <?php continue; endif;
                $color = EVENT_CATEGORIES[$e['category']][1];
                $bar = $e['all_day'] || $e['start_date'] !== $e['end_date']; ?>
              <button type="button" class="gcal-ev <?= $bar ? 'bar' : 'dot' ?> <?= $b['contL'] ? 'cont-l' : '' ?> <?= $b['contR'] ? 'cont-r' : '' ?>"
                      data-id="<?= (int) $e['id'] ?>" data-cat="<?= e($e['category']) ?>" style="--c: <?= $color ?>; grid-column: <?= $b['start'] + 1 ?> / span <?= $b['span'] ?>; grid-row: <?= $b['lane'] + 1 ?>;"
                      title="<?= e($e['title'] . ' · ' . event_when($e, true)) ?>">
                <?php if (!$bar): ?><i></i><span class="t"><?= e(substr((string) $e['start_time'], 0, 5)) ?></span><?php endif ?>
                <span class="n"><?= e($e['title']) ?></span>
              </button>
            <?php endforeach ?>
            <?php foreach ($hidden as $i => $n): if (!$n) continue; ?>
              <button type="button" class="gcal-more" data-day="<?= $w->modify("+$i days")->format('Y-m-d') ?>" style="grid-column: <?= $i + 1 ?>; grid-row: <?= VISIBLE_LANES + 1 ?>;">+<?= $n ?><span class="wide">개 더보기</span></button>
            <?php endforeach ?>
          </div>
        </div>
      <?php endfor ?>
    </div>
    <p class="muted small no-print">빈 칸을 누르면 그 날짜로 일정을 만들고, 일정을 누르면 내용을 볼 수 있습니다.</p>

    <?php else: // 목록 보기 (구글 캘린더 '일정' 보기)
        $byDay = [];
        foreach ([...$monthEvents, ...$attItems] as $e) {
            if ($e['start_date'] > $last->format('Y-m-d') || $e['end_date'] < $first->format('Y-m-d')) continue;
            $s = max($e['start_date'], $first->format('Y-m-d'));
            $byDay[$s][] = $e;
        }
        ksort($byDay); ?>
    <div class="gcal-agenda">
      <?php foreach ($byDay as $ds => $list): ?>
        <div class="ag-day <?= $ds === $today ? 'today' : '' ?>">
          <div class="ag-date"><b><?= date('j', strtotime($ds)) ?></b><span><?= date('n월', strtotime($ds)) ?>, <?= weekday_ko($ds) ?></span></div>
          <div class="ag-list">
            <?php foreach ($list as $e): if (($e['src'] ?? '') === 'att'): $kc = ATT_KINDS[$e['kind']][1]; ?>
              <a class="ag-ev gcal-ev" href="<?= e(url('view.php?id=' . $e['id'])) ?>" data-cat="att" style="--c: <?= $kc ?>">
                <i></i><span class="ag-when"><?= e($e['all_day'] ? ($e['start_date'] !== $e['end_date'] ? $e['when'] : '종일') : $e['when']) ?></span>
                <span class="ag-title"><b><?= e($e['title']) ?></b><?= $e['status'] === 'pending' ? ' <small class="muted">· 결재중</small>' : '' ?></span>
                <span class="ag-cat">근태</span>
              </a>
            <?php continue; endif ?>
              <button type="button" class="ag-ev gcal-ev" data-id="<?= (int) $e['id'] ?>" data-cat="<?= e($e['category']) ?>" style="--c: <?= EVENT_CATEGORIES[$e['category']][1] ?>">
                <i></i><span class="ag-when"><?= e(event_when($e)) ?></span>
                <span class="ag-title"><b><?= e($e['title']) ?></b><?= $e['location'] ? ' <small class="muted">· ' . e($e['location']) . '</small>' : '' ?></span>
                <span class="ag-cat"><?= e(EVENT_CATEGORIES[$e['category']][0]) ?></span>
              </button>
            <?php endforeach ?>
          </div>
        </div>
      <?php endforeach ?>
      <?php if (!$byDay): ?><p class="muted center">이 달에는 일정이 없습니다.</p><?php endif ?>
    </div>
    <?php endif ?>
  </section>
</div>

<!-- 일정 보기 / 만들기·수정 / 날짜별 목록 창 -->
<dialog id="evDialog" class="ev-dialog">
  <div class="ev-pane" data-pane="view">
    <div class="ev-tools">
      <form method="post" class="inline" data-delete-form onsubmit="return confirm('이 일정을 삭제할까요?')">
        <?= csrf_field() ?><input type="hidden" name="id"><input type="hidden" name="action" value="delete">
        <button class="icon-btn" data-can-edit title="삭제">🗑</button>
      </form>
      <button type="button" class="icon-btn" data-edit data-can-edit title="수정">✎</button>
      <button type="button" class="icon-btn" data-close title="닫기">✕</button>
    </div>
    <div class="ev-body">
      <span class="ev-swatch"></span>
      <div>
        <h2 data-f="title"></h2>
        <p data-f="when" class="ev-when"></p>
        <p class="muted small"><span data-f="category"></span> · 작성 <span data-f="author"></span></p>
        <p data-f="location" class="ev-line">📍 <span></span></p>
        <div data-f="description" class="ev-desc"></div>
      </div>
    </div>
  </div>

  <form method="post" class="ev-pane ev-form" data-pane="form">
    <?= csrf_field() ?><input type="hidden" name="id">
    <div class="ev-tools"><b data-form-title>일정 만들기</b><button type="button" class="icon-btn" data-close title="닫기">✕</button></div>
    <input name="title" class="ev-title-input" placeholder="제목 추가" maxlength="100" required>
    <div class="ev-cats">
      <?php foreach (EVENT_CATEGORIES as $key => [$label, $color]): if (!can_use_event_category($key, $user)) continue; ?>
        <label style="--c: <?= $color ?>"><input type="radio" name="category" value="<?= $key ?>" <?= $key === 'event' ? 'checked' : '' ?>><span><?= e($label) ?></span></label>
      <?php endforeach ?>
    </div>
    <div class="ev-dates">
      <label>시작<input type="date" name="start_date" required></label>
      <label>종료<input type="date" name="end_date" required></label>
      <label class="inline-check"><input type="checkbox" name="all_day" value="1" checked> 종일</label>
    </div>
    <div class="ev-times">
      <label>시작 시간<input type="time" name="start_time" step="600"></label>
      <label>종료 시간<input type="time" name="end_time" step="600"></label>
    </div>
    <label>장소<input name="location" maxlength="100" placeholder="예: 숲속의집 앞 잔디광장"></label>
    <label>설명<textarea name="description" rows="3"></textarea></label>
    <div class="actions"><button type="button" class="btn ghost" data-close>취소</button><button class="btn primary">저장</button></div>
  </form>

  <div class="ev-pane" data-pane="day">
    <div class="ev-tools"><b data-day-title></b><button type="button" class="icon-btn" data-close title="닫기">✕</button></div>
    <div class="ev-daylist"></div>
    <div class="actions"><button type="button" class="btn primary" data-day-create>+ 이 날짜에 만들기</button></div>
  </div>
</dialog>

<script>
window.GCAL = {
  events: <?= json_encode($jsEvents, JSON_UNESCAPED_UNICODE) ?>,
  categories: <?= json_encode(array_map(fn($c) => ['label' => $c[0], 'color' => $c[1]], EVENT_CATEGORIES), JSON_UNESCAPED_UNICODE) ?>,
  openNew: <?= json_encode(valid_date($_GET['new'] ?? '') ? $_GET['new'] : null) ?>, // 대시보드 '+ 일정 추가'에서 바로 만들기 창
  newCat: <?= json_encode(can_use_event_category((string) ($_GET['cat'] ?? ''), $user) ? $_GET['cat'] : 'event') ?>,
  att: <?= json_encode(array_map(fn($i) => ['id' => $i['id'], 'kind' => $i['kind'], 'start_date' => $i['start_date'], 'end_date' => $i['end_date'],
      'title' => $i['title'], 'when' => $i['when'], 'pending' => $i['status'] === 'pending', 'color' => ATT_KINDS[$i['kind']][1]], $attItems), JSON_UNESCAPED_UNICODE) ?>,
  viewUrl: <?= json_encode(url('view.php?id=')) ?>,
};
</script>
<?php layout_footer([url('assets/schedule.js')]);
