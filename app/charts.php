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

/**
 * 그래프 카드 하나
 * $datasets = [['label' => '합계', 'data' => [..], 'color' => '#2f7d4f', 'type' => 'bar'|'line'], ...]
 */
function stat_chart(string $id, string $title, array $labels, array $datasets, string $unit = '', string $note = ''): void
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
  <h2><?= e($title) ?><?php if ($note): ?> <small class="muted"><?= e($note) ?></small><?php endif ?></h2>
  <div class="stat-chart"><canvas id="<?= e($id) ?>"></canvas></div>
</section>
<script>
(function () {
  const el = document.getElementById(<?= json_encode($id) ?>);
  if (!el || !window.Chart) { if (el) el.closest('.stat-chart').innerHTML = '<p class="muted small">그래프를 불러오지 못했습니다 (인터넷 연결 확인).</p>'; return; }
  const unit = <?= json_encode($unit, JSON_UNESCAPED_UNICODE) ?>;
  new Chart(el, {
    data: { labels: <?= json_encode(array_values($labels), JSON_UNESCAPED_UNICODE) ?>, datasets: <?= json_encode($ds, JSON_UNESCAPED_UNICODE) ?> },
    options: {
      responsive: true, maintainAspectRatio: false, animation: false,
      interaction: { mode: 'index', intersect: false },
      scales: { y: { beginAtZero: true, ticks: { precision: 0, callback: (v) => Number(v).toLocaleString('ko-KR') } }, x: { ticks: { autoSkip: true, maxRotation: 0 } } },
      plugins: { legend: { position: 'bottom', display: <?= count($datasets) > 1 ? 'true' : 'false' ?> },
        tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${Number(c.parsed.y).toLocaleString('ko-KR')}${unit}` } } },
    },
  });
})();
</script>
    <?php
}
