<?php
defined('APP_ROOT') || exit;

/*
 * 일정표 일간·주간 보기 (구글 캘린더의 시간 격자). schedule.php 안에서 require 한다.
 * 위: 종일·여러 날 일정 막대 / 아래: 시간 일정 (시작~종료 시간 위치, 겹치면 나란히)
 * 쓰는 변수: $view, $date, $weekStart, $events, $CATS, $today, $holidays, $q
 */
$tgN = $view === 'week' ? 7 : 1;
$tgStart = $view === 'week' ? $weekStart : new DateTimeImmutable($date);
$tgDays = [];
for ($i = 0; $i < $tgN; $i++) $tgDays[] = $tgStart->modify("+$i days")->format('Y-m-d');
$tgFrom = $tgDays[0];
$tgTo = $tgDays[$tgN - 1];

$tgTimed = [];
$tgAllDay = [];
foreach ($events as $e) {
    if ($e['start_date'] > $tgTo || $e['end_date'] < $tgFrom) continue;
    if (!$e['all_day'] && $e['start_date'] === $e['end_date']) $tgTimed[$e['start_date']][] = $e;
    else $tgAllDay[] = $e;
}

// 보여 줄 시간대: 기본 08~18시, 일정이 그 밖에 있으면 넓힌다
$tgMin = fn(?string $t) => $t ? (int) substr($t, 0, 2) * 60 + (int) substr($t, 3, 2) : null;
$tgH0 = 8;
$tgH1 = 18;
foreach ($tgTimed as $list) foreach ($list as $e) {
    $s = $tgMin($e['start_time']);
    $en = max($tgMin($e['end_time']) ?? $s + 60, $s + 30);
    $tgH0 = min($tgH0, intdiv($s, 60));
    $tgH1 = max($tgH1, (int) ceil($en / 60));
}
$tgH1 = min(24, $tgH1);

// 종일 막대 줄 배치 (일간은 한 칸)
[$tgBars] = week_layout($tgAllDay, $tgStart, 99);
$tgBars = array_values(array_filter($tgBars, fn($b) => $b['start'] < $tgN));
foreach ($tgBars as &$b) {
    $b['span'] = min($b['span'], $tgN - $b['start']);
    if ($tgN === 1) $b['contR'] = $b['ev']['end_date'] > $tgTo;
}
unset($b);
$tgLanes = $tgBars ? max(array_column($tgBars, 'lane')) + 1 : 1;
?>
<div class="tg-scroll">
<div class="tg tg-<?= $view ?>" style="--n: <?= $tgN ?>">
  <div class="tg-row tg-head">
    <div class="tg-gut"></div>
    <?php foreach ($tgDays as $ds): $w = (int) date('w', strtotime($ds)); ?>
      <div class="tg-dh <?= $ds === $today ? 'today' : '' ?> <?= $w === 0 ? 'sun' : ($w === 6 ? 'sat' : '') ?> <?= isset($holidays[$ds]) ? 'holiday' : '' ?>">
        <span><?= weekday_ko($ds) ?></span>
        <a class="tg-dnum" href="<?= e(url($q(['view' => 'day', 'date' => $ds]))) ?>" title="일간 보기"><?= date('j', strtotime($ds)) ?></a>
        <?php if (isset($holidays[$ds])): ?><small><?= e($holidays[$ds]) ?></small><?php endif ?>
      </div>
    <?php endforeach ?>
  </div>

  <div class="tg-row tg-allday">
    <div class="tg-gut">종일</div>
    <div class="tg-alld" style="grid-template-rows: repeat(<?= $tgLanes ?>, 24px)">
      <?php foreach ($tgDays as $i => $ds): ?><div class="tg-alld-cell" data-create="<?= $ds ?>" style="grid-column: <?= $i + 1 ?>; grid-row: 1 / -1"></div><?php endforeach ?>
      <?php foreach ($tgBars as $b): $e = $b['ev']; ?>
        <button type="button" class="gcal-ev bar <?= $b['contL'] ? 'cont-l' : '' ?> <?= $b['contR'] ? 'cont-r' : '' ?>" data-id="<?= (int) $e['id'] ?>" data-cat="<?= e($e['category']) ?>"
                style="--c: <?= $CATS[$e['category']][1] ?>; grid-column: <?= $b['start'] + 1 ?> / span <?= $b['span'] ?>; grid-row: <?= $b['lane'] + 1 ?>;"
                title="<?= e($e['title'] . ' · ' . event_when($e, true)) ?>"><span class="n"><?= e($e['title']) ?><?= !$e['all_day'] ? ' · ' . e(substr((string) $e['start_time'], 0, 5)) : '' ?></span></button>
      <?php endforeach ?>
    </div>
  </div>

  <div class="tg-row tg-body" style="--rows: <?= $tgH1 - $tgH0 ?>">
    <div class="tg-gut tg-hours">
      <?php for ($h = $tgH0; $h < $tgH1; $h++): ?><div class="tg-h"><span><?= sprintf('%02d:00', $h) ?></span></div><?php endfor ?>
    </div>
    <?php foreach ($tgDays as $ds): ?>
      <div class="tg-col <?= $ds === $today ? 'today' : '' ?>">
        <?php for ($h = $tgH0; $h < $tgH1; $h++): ?><div class="tg-slot" data-create="<?= $ds ?>" data-time="<?= sprintf('%02d:00', $h) ?>"></div><?php endfor ?>
        <?php foreach (timegrid_columns($tgTimed[$ds] ?? [], $tgMin) as [$e, $s, $en, $col, $cols]): ?>
          <button type="button" class="gcal-ev tg-ev" data-id="<?= (int) $e['id'] ?>" data-cat="<?= e($e['category']) ?>"
                  style="--c: <?= $CATS[$e['category']][1] ?>; top: calc(var(--hr) * <?= round(($s - $tgH0 * 60) / 60, 3) ?>); height: calc(var(--hr) * <?= round(($en - $s) / 60, 3) ?> - 2px); left: <?= round($col / $cols * 100, 2) ?>%; width: calc(<?= round(100 / $cols, 2) ?>% - 3px);"
                  title="<?= e($e['title'] . ' · ' . event_when($e)) ?>">
            <b class="n"><?= e($e['title']) ?></b>
            <span class="t"><?= e(event_when($e)) ?></span>
            <?php if ($view === 'day' && $e['location']): ?><span class="t">📍 <?= e($e['location']) ?></span><?php endif ?>
          </button>
        <?php endforeach ?>
      </div>
    <?php endforeach ?>
  </div>
</div>
</div>
<p class="muted small no-print">빈 시간 칸을 누르면 그 시간으로 일정을 만들고, 날짜 숫자를 누르면 그 날의 일간 보기로 갑니다.</p>
