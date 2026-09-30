<?php
defined('APP_ROOT') || exit;

/*
 * 통계 페이지 공통 그래프 (Chart.js, CDN)
 *   기간이 3개월(93일) 이하면 일 단위, 그보다 길면 월 단위 — 주간·월간은 일별, 연간은 월별로 보인다.
 */

const CHART_JS = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js';

/** 그래프 단위: day | month */
function chart_gran(string $from, string $to): string
{
    return (strtotime($to) - strtotime($from)) / 86400 <= 93 ? 'day' : 'month';
}

/** @return array<string,string> 그래프 구간 키 => 이름 (빈 구간 포함) */
function chart_buckets(string $from, string $to, string $gran): array
{
    $out = [];
    if ($gran === 'day') {
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) $out[$d] = date('n/j', strtotime($d)) . '(' . weekday_ko($d) . ')';
    } else {
        for ($m = substr($from, 0, 7); $m <= substr($to, 0, 7); $m = date('Y-m', strtotime("$m-01 +1 month"))) $out[$m] = date('y.n월', strtotime("$m-01"));
    }
    return $out;
}

function chart_key(string $date, string $gran): string
{
    return $gran === 'day' ? substr($date, 0, 10) : substr($date, 0, 7);
}

const CHART_COLORS = ['#2f7d4f', '#4a7fb5', '#c9a227', '#c0392b', '#8e7cc3', '#e67e22', '#16a085', '#7f8c8d', '#d35d8f', '#5d6d7e'];

/**
 * 항목별로 한 기둥에 쌓는 그래프 데이터 (+ 합계·항목별 보기 버튼)
 *   $groups = [항목 이름 => [구간 키 => 값]], $keys = 구간 키 순서
 *   기간 안에 값이 없는 항목은 뺀다 (모두 0이면 그대로). 항목이 하나면 보기 버튼 없음.
 * @return array{0: array, 1: array} [$datasets, $opts] — stat_chart() 에 그대로
 */
function chart_stack(array $groups, array $keys, array $colors = []): array
{
    $nonzero = array_filter($groups, fn($g) => array_sum($g) != 0);
    if ($nonzero) $groups = $nonzero;
    $sets = $views = [];
    $i = 0;
    foreach ($groups as $name => $g) {
        $sets[] = ['label' => (string) $name, 'data' => array_map(fn($k) => $g[$k] ?? 0, $keys), 'color' => $colors[$name] ?? CHART_COLORS[$i % count(CHART_COLORS)]];
        $views[(string) $name] = [$i++];
    }
    return [$sets, count($sets) > 1 ? ['stacked' => true, 'views' => ['합계' => range(0, count($sets) - 1)] + $views] : []];
}

/**
 * 그래프 카드 하나
 * $datasets = [['label' => '합계', 'data' => [..], 'color' => '#2f7d4f', 'type' => 'bar'|'line'], ...]
 * $opts = ['stacked' => true,                         // 막대를 한 기둥에 쌓기 (마우스를 올리면 항목별과 합계)
 *          'views' => ['합계' => [0, 1, 2], '입장권' => [0], ...]]  // 보기 버튼: 누르면 그 항목(데이터셋 번호)만
 */
function stat_chart(string $id, string $title, array $labels, array $datasets, string $unit = '', string $note = '', array $opts = []): void
{
    static $loaded = false;
    if (!$loaded) {
        echo '<script src="' . e(CHART_JS) . '"></script>';
        $loaded = true;
    }
    $ds = array_map(fn($d) => [
        'type' => $d['type'] ?? 'bar', 'label' => $d['label'], 'data' => array_values(array_map('floatval', $d['data'])),
        'backgroundColor' => $d['color'], 'borderColor' => $d['color'], 'borderWidth' => ($d['type'] ?? 'bar') === 'line' ? 2 : 0,
        'pointRadius' => count($labels) > 40 ? 0 : 3, 'tension' => .25, 'cubicInterpolationMode' => 'monotone', 'fill' => false,
    ], $datasets);
    ?>
<section class="card stat-chart-card">
  <div class="card-head">
    <h2><?= e($title) ?><?php if ($note): ?> <small class="muted"><?= e($note) ?></small><?php endif ?></h2>
    <?php if (!empty($opts['views'])): ?>
      <div class="stat-units chart-views no-print" data-chart-views="<?= e($id) ?>">
        <?php $vi = 0; foreach ($opts['views'] as $vlabel => $idx): ?><button type="button" class="<?= $vi++ === 0 ? 'on' : '' ?>" data-show="<?= e(json_encode(array_values($idx))) ?>"><?= e($vlabel) ?></button><?php endforeach ?>
      </div>
    <?php endif ?>
  </div>
  <div class="stat-chart"><canvas id="<?= e($id) ?>"></canvas></div>
</section>
<script>
(function () {
  const el = document.getElementById(<?= json_encode($id) ?>);
  if (!el || !window.Chart) { if (el) el.closest('.stat-chart').innerHTML = '<p class="muted small">그래프를 불러오지 못했습니다 (인터넷 연결 확인).</p>'; return; }
  const unit = <?= json_encode($unit, JSON_UNESCAPED_UNICODE) ?>;
  const stacked = <?= !empty($opts['stacked']) ? 'true' : 'false' ?>;
  const fmt = (v) => Number(v).toLocaleString('ko-KR');
  const chart = new Chart(el, {
    data: { labels: <?= json_encode(array_values($labels), JSON_UNESCAPED_UNICODE) ?>, datasets: <?= json_encode($ds, JSON_UNESCAPED_UNICODE) ?> },
    options: {
      responsive: true, maintainAspectRatio: false, animation: false,
      interaction: { mode: 'index', intersect: false },
      scales: { y: { stacked, beginAtZero: true, ticks: { precision: 0, callback: (v) => fmt(v) } }, x: { stacked, ticks: { autoSkip: true, maxRotation: 0 } } },
      plugins: { legend: { position: 'bottom', display: <?= count($datasets) > 1 ? 'true' : 'false' ?> },
        tooltip: { callbacks: {
          label: (c) => `${c.dataset.label}: ${fmt(c.parsed.y)}${unit}`,
          footer: (items) => stacked && items.length > 1 ? `합계: ${fmt(items.reduce((s, i) => s + i.parsed.y, 0))}${unit}` : '',
        } } },
    },
  });
  // 보기 버튼 (합계 / 항목별)
  const views = document.querySelector('[data-chart-views="' + el.id + '"]');
  if (views) views.addEventListener('click', (ev) => {
    const b = ev.target.closest('button[data-show]');
    if (!b) return;
    const show = JSON.parse(b.dataset.show);
    chart.data.datasets.forEach((_, i) => chart.setDatasetVisibility(i, show.includes(i)));
    chart.update();
    views.querySelectorAll('button').forEach((x) => x.classList.toggle('on', x === b));
  });
})();
</script>
    <?php
}
