// 대시보드 매출 그래프 (Chart.js): 구분별 누적 막대 + 합계 꺾은선
(function () {
  const canvas = document.getElementById('salesChart');
  if (!canvas || !window.Chart) return;

  const palette = ['#2f7d4f', '#6fb07f', '#c9a227', '#4a7fb5', '#b5654a', '#8a8f98', '#7b5ea7', '#3c9d9b'];
  let chart;

  async function load(period) {
    const res = await fetch(`${window.SALES_API}?period=${period}`, { credentials: 'same-origin' });
    if (!res.ok) return;
    const data = await res.json();

    const datasets = data.datasets.map((ds, i) => ({
      type: 'bar',
      label: ds.label,
      data: ds.data,
      backgroundColor: palette[i % palette.length],
      stack: 'sales',
    }));
    datasets.push({
      type: 'line',
      label: '합계',
      data: data.totals,
      borderColor: '#1f2d24',
      backgroundColor: '#1f2d24',
      pointRadius: 2,
      tension: 0,
    });

    if (chart) chart.destroy();
    chart = new Chart(canvas, {
      data: { labels: data.labels, datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: {
          x: { stacked: true },
          y: { stacked: true, ticks: { callback: (v) => (v >= 10000 ? v / 10000 + '만' : v) } },
        },
        plugins: {
          tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${c.parsed.y.toLocaleString('ko-KR')}원` } },
          legend: { position: 'bottom' },
        },
      },
    });
  }

  document.querySelectorAll('#periodTabs button').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('#periodTabs button').forEach((b) => b.classList.remove('on'));
      btn.classList.add('on');
      load(btn.dataset.period);
    });
  });
  load('day');
})();
