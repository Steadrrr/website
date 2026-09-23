// 숫자 입력칸 천단위 콤마 + 매출보고/상품권 금액 자동계산
(function () {
  const fmt = (n) => n.toLocaleString('ko-KR');
  const num = (v) => parseInt(String(v).replace(/\D/g, ''), 10) || 0;
  const $ = (root, sel) => root.querySelector(sel);
  const $$ = (root, sel) => Array.from(root.querySelectorAll(sel));
  const setText = (el, v) => { if (el) el.textContent = v; };

  function recalc() {
    let grand = 0;

    // 입장권: 단가 × 수량
    $$(document, 'table[data-ticket-table]').forEach((t) => {
      let qty = 0, amount = 0, free = 0;
      $$(t, 'tbody tr').forEach((tr) => {
        const q = num($(tr, '[data-qty]').value);
        const a = q * num(tr.dataset.price);
        setText($(tr, '[data-line-amount]'), fmt(a));
        qty += q; amount += a;
        if (tr.dataset.free === '1') free += q;
      });
      setText($(t, '[data-ticket-qty]'), fmt(qty));
      setText($(t, '[data-ticket-amount]'), fmt(amount) + '원');
      setText($(t, '[data-ticket-breakdown]'), `유료 ${fmt(qty - free)} · 무료 ${fmt(free)}`);
      grand += amount;
    });

    // 객실: 요금구분/할인에 따른 단가 × 객실수, 입실인원 최대인원 확인
    $$(document, 'table[data-room-table]').forEach((t) => {
      let rooms = 0, guests = 0, amount = 0;
      $$(t, 'tbody tr').forEach((tr) => {
        const rate = $(tr, '[data-rate]').value; // weekday | weekend
        const dcBox = $(tr, '[data-dc]');
        const dcPrice = num(tr.dataset[rate === 'weekend' ? 'dcWeekend' : 'dcWeekday']);
        dcBox.disabled = dcPrice === 0; // 할인가 미설정이면 체크 불가
        if (dcBox.disabled) dcBox.checked = false;
        const unit = dcBox.checked ? dcPrice : num(tr.dataset[rate]);
        const q = num($(tr, '[data-qty]').value);
        const g = num($(tr, '[data-guests]').value);
        const max = num(tr.dataset.max) * Math.max(q, 1);
        const a = unit * q;
        setText($(tr, '[data-unit]'), fmt(unit));
        setText($(tr, '[data-line-amount]'), fmt(a));
        $(tr, '[data-guests]').classList.toggle('invalid', (q > 0 && g === 0) || (max > 0 && g > max));
        tr.classList.toggle('sold', q > 0);
        rooms += q; guests += g; amount += a;
      });
      setText($(t, '[data-room-qty]'), fmt(rooms));
      setText($(t, '[data-room-guests]'), fmt(guests));
      setText($(t, '[data-room-amount]'), fmt(amount) + '원');
      grand += amount;
    });
    setText($(document, '[data-grand]'), fmt(grand) + '원');

    // 상품권: 권종 × 매수, 출고 시 재고 초과 경고
    $$(document, 'table[data-voucher-table]').forEach((t) => {
      let total = 0, over = false;
      const isOut = !!$(t, 'input[name^="voucher_out"]');
      $$(t, 'tbody tr').forEach((tr) => {
        const q = num($(tr, '[data-vqty]').value);
        const a = q * num(tr.dataset.denom);
        setText($(tr, '[data-vamount]'), fmt(a));
        total += a;
        if (isOut && q > num(tr.dataset.stock)) over = true;
      });
      setText($(t, '[data-vtotal]'), fmt(total) + '원');
      setText($(t, '[data-vwarn]'), over ? '재고보다 많이 출고됩니다' : '');
    });
  }

  document.querySelectorAll('input[data-money]').forEach((el) => {
    el.addEventListener('input', () => {
      const n = num(el.value);
      el.value = n ? fmt(n) : '';
      recalc();
    });
  });
  document.querySelectorAll('[data-rate], [data-dc]').forEach((el) => el.addEventListener('change', recalc));

  // 매출보고: 일자를 바꾸면 요금구분 기본값(금·토=주말)을 다시 맞춤
  const dateInput = document.querySelector('[data-sales-form]') && document.querySelector('input[name="work_date"]');
  if (dateInput && window.PEAK_SEASONS) {
    dateInput.addEventListener('change', () => {
      const d = new Date(dateInput.value + 'T00:00:00');
      if (isNaN(d)) return;
      const md = dateInput.value.slice(5);
      const weekend = d.getDay() === 5 || d.getDay() === 6 || window.PEAK_SEASONS.some(([f, t]) => md >= f && md <= t);
      document.querySelectorAll('[data-rate]').forEach((s) => { s.value = weekend ? 'weekend' : 'weekday'; });
      recalc();
    });
  }

  recalc();
})();
