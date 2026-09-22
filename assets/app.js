// 금액 입력칸: 천단위 콤마 + 매출표 합계 자동계산
(function () {
  const fmt = (n) => n.toLocaleString('ko-KR');
  const num = (v) => parseInt(String(v).replace(/\D/g, ''), 10) || 0;

  document.querySelectorAll('input[data-money]').forEach((el) => {
    el.addEventListener('input', () => {
      const n = num(el.value);
      el.value = n ? fmt(n) : '';
      recalc();
    });
  });

  function recalc() {
    document.querySelectorAll('table[data-sales]').forEach((table) => {
      const sums = { qty: 0, card: 0, cash: 0, transfer: 0 };
      let grand = 0;
      table.querySelectorAll('tbody tr').forEach((tr) => {
        let sub = 0;
        tr.querySelectorAll('input[data-money]').forEach((inp) => {
          const key = inp.name.match(/\[(\w+)\]$/)[1];
          const v = num(inp.value);
          sums[key] += v;
          if (inp.hasAttribute('data-amount')) sub += v;
        });
        tr.querySelector('[data-subtotal]').textContent = fmt(sub);
        grand += sub;
      });
      Object.keys(sums).forEach((k) => {
        const cell = table.querySelector(`[data-sum="${k}"]`);
        if (cell) cell.textContent = fmt(sums[k]);
      });
      table.querySelector('[data-grand]').textContent = fmt(grand) + '원';
    });
  }
  recalc();
})();
