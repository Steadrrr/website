// 대시보드: 입장권(무료/유료) · 객실(판매객실/입실인원) 그래프 + 현재 구간 숫자
(function () {
  const fmt = (n) => Number(n).toLocaleString('ko-KR');
  const charts = {};

  function draw(id, labels, datasets, unit) {
    const canvas = document.getElementById(id);
    if (!canvas || !window.Chart) return;
    if (charts[id]) charts[id].destroy();
    charts[id] = new Chart(canvas, {
      data: { labels, datasets },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } },
        plugins: {
          legend: { position: 'bottom' },
          tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${fmt(c.parsed.y)}${c.dataset.unit || unit}` } },
        },
      },
    });
  }

  async function load(period) {
    const res = await fetch(`${window.SALES_API}?period=${period}`, { credentials: 'same-origin' });
    if (!res.ok) return;
    const d = await res.json();

    document.querySelectorAll('[data-kpi]').forEach((el) => { el.textContent = fmt(d.current[el.dataset.kpi]); });
    document.querySelectorAll('[data-current-label]').forEach((el) => { el.textContent = d.current.label; });

    draw('ticketChart', d.labels, [
      { type: 'bar', label: '유료', data: d.series.paid, backgroundColor: '#2f7d4f', stack: 't' },
      { type: 'bar', label: '무료', data: d.series.free, backgroundColor: '#a8d5b5', stack: 't' },
    ], '매');

    draw('roomChart', d.labels, [
      { type: 'bar', label: '판매 객실', data: d.series.rooms, backgroundColor: '#4a7fb5', stack: 'r', unit: '실' },
      { type: 'line', label: '입실 인원', data: d.series.guests, borderColor: '#c9a227', backgroundColor: '#c9a227', pointRadius: 2, stack: 'g', unit: '명' },
    ], '');
  }

  document.querySelectorAll('#periodTabs button').forEach((btn) => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('#periodTabs button').forEach((b) => b.classList.remove('on'));
      btn.classList.add('on');
      load(btn.dataset.period);
    });
  });
  load('week'); // 주별 · 월별
})();
