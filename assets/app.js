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
      const cashInput = $(t, '[data-ticket-cash]');
      if (cashInput) {
        const cash = num(cashInput.value);
        setText($(t, '[data-ticket-card]'), fmt(Math.max(amount - cash, 0)) + '원');
        cashInput.classList.toggle('invalid', cash > amount);
      }
      grand += amount;
    });

    // 객실: 입실인원을 넣으면 판매. 요금구분(비수기 평일/주말, 성수기) + 할인율, 객실별 상품권 환급
    const dc = window.ROOM_DC || {};
    $$(document, 'table[data-room-table]').forEach((t) => {
      let rooms = 0, guests = 0, amount = 0, refundTotal = 0;
      const vsum = {};
      $$(t, 'tbody tr').forEach((tr) => {
        let refund = 0;
        $$(tr, 'input[data-vdenom]').forEach((inp) => {
          const d = inp.dataset.vdenom, q = num(inp.value);
          vsum[d] = (vsum[d] || 0) + q;
          refund += q * num(d);
        });
        refundTotal += refund;
        const rateSel = $(tr, '[data-rate]');
        if (!rateSel) return; // 객실 미지정 환급 행
        const rate = rateSel.value;
        const pct = dc[rate] || 0;
        const dcBox = $(tr, '[data-dc]');
        setText($(tr, '[data-dc-pct]'), pct + '%');
        dcBox.disabled = pct === 0;
        if (dcBox.disabled) dcBox.checked = false;
        const base = num(tr.dataset[rate]);
        const unit = dcBox.checked ? Math.floor(base * (100 - pct) / 1000) * 10 : base;
        const g = num($(tr, '[data-guests]').value);
        const sold = g > 0;
        const max = num(tr.dataset.max);
        setText($(tr, '[data-unit]'), fmt(unit));
        setText($(tr, '[data-line-amount]'), sold ? fmt(unit) : '');
        setText($(tr, '[data-refund-amt]'), fmt(refund));
        $(tr, '[data-guests]').classList.toggle('invalid', (max > 0 && g > max) || (!sold && refund > 0));
        // 기준 환급액: 요금구분별 (data-refund-weekday / -weekend / -peak)
        const expected = num(tr.dataset['refund' + rate.charAt(0).toUpperCase() + rate.slice(1)]);
        setText($(tr, '[data-refund-base]'), fmt(expected));
        const mismatch = sold ? refund !== expected : refund > 0;
        tr.classList.toggle('sold', sold);
        tr.classList.toggle('refund-mismatch', mismatch);
        tr.dataset.mismatch = mismatch ? `${tr.dataset.name}: 환급 ${fmt(refund)}원 / 기준 ${fmt(expected)}원` : '';
        if (sold) { rooms += 1; guests += g; amount += unit; }
      });
      setText($(t, '[data-room-count]'), `${rooms}실`);
      setText($(t, '[data-room-guests]'), fmt(guests));
      setText($(t, '[data-room-amount]'), fmt(amount) + '원');
      $$(t, '[data-vsum]').forEach((c) => setText(c, fmt(vsum[c.dataset.vsum] || 0)));
      setText($(t, '[data-vsum-amt]'), fmt(refundTotal) + '원');
      grand += amount;

      // 환급 합계 · 남은 재고
      const sum = $(document, 'table[data-voucher-summary]');
      if (sum) {
        let outQ = 0, outA = 0, leftQ = 0, leftA = 0;
        $$(sum, 'tbody tr').forEach((tr) => {
          const d = num(tr.dataset.denom), q = vsum[tr.dataset.denom] || 0, left = num(tr.dataset.stock) - q;
          setText($(tr, '[data-out]'), fmt(q));
          setText($(tr, '[data-out-amt]'), fmt(q * d));
          setText($(tr, '[data-left]'), fmt(left));
          setText($(tr, '[data-left-amt]'), fmt(left * d));
          tr.classList.toggle('issue', left < 0);
          outQ += q; outA += q * d; leftQ += left; leftA += left * d;
        });
        setText($(sum, '[data-out-total]'), fmt(outQ));
        setText($(sum, '[data-out-amt-total]'), fmt(outA) + '원');
        setText($(sum, '[data-left-total]'), fmt(leftQ));
        setText($(sum, '[data-left-amt-total]'), fmt(leftA) + '원');
      }
    });
    // 시설대관: 대관 시간 요금 + 야간 추가요금, × 건수 (시간·야간을 고르면 건수 기본 1)
    $$(document, 'table[data-rental-table]').forEach((t) => {
      let count = 0, amount = 0;
      $$(t, 'tbody tr').forEach((tr) => {
        const prices = JSON.parse(tr.dataset.prices || '{}');
        const time = $(tr, '[data-rent-time]').value;
        const night = $(tr, '[data-rent-night]').checked;
        const used = !!time || night;
        const unit = (time ? prices[time] || 0 : 0) + (night ? num(tr.dataset.night) : 0);
        const q = used ? (num($(tr, '[data-rent-qty]').value) || 1) : 0;
        setText($(tr, '[data-unit]'), used ? fmt(unit) : '');
        setText($(tr, '[data-line-amount]'), used ? fmt(unit * q) : '');
        tr.classList.toggle('sold', used);
        $(tr, '[data-rent-qty]').classList.toggle('invalid', !used && num($(tr, '[data-rent-qty]').value) > 0);
        count += q; amount += unit * q;
      });
      setText($(t, '[data-rent-count]'), `${count}건`);
      setText($(t, '[data-rent-amount]'), fmt(amount) + '원');
      grand += amount;
    });

    // 대관 숙박시설: 정액 요금 × (100 − 할인율)%, 10원 단위 버림 × 건수
    $$(document, 'table[data-lodge-table]').forEach((t) => {
      let count = 0, amount = 0;
      $$(t, 'tbody tr').forEach((tr) => {
        const dcInp = $(tr, '[data-lodge-dc]');
        const raw = dcInp.value.trim();
        const bad = raw !== '' && (!/^\d+$/.test(raw) || +raw > 100);
        dcInp.classList.toggle('invalid', bad);
        const pct = bad ? 0 : +raw || 0;
        const base = num(tr.dataset.price);
        const unit = pct > 0 ? Math.floor(base * (100 - pct) / 1000) * 10 : base;
        const q = num($(tr, '[data-lodge-qty]').value);
        setText($(tr, '[data-unit]'), fmt(unit));
        setText($(tr, '[data-line-amount]'), q ? fmt(unit * q) : '');
        tr.classList.toggle('sold', q > 0);
        count += q; amount += unit * q;
      });
      setText($(t, '[data-lodge-count]'), `${count}건`);
      setText($(t, '[data-lodge-amount]'), fmt(amount) + '원');
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
  document.querySelectorAll('[data-rent-time], [data-rent-night]').forEach((el) => el.addEventListener('change', recalc));
  document.querySelectorAll('[data-lodge-dc]').forEach((el) => el.addEventListener('input', recalc));

  // 매출보고: 일자를 바꾸면 기간요금(동절기 등)과 객실 요금구분(금·토, 성수기=주말)을 다시 맞춤
  const seasonFor = (grp, md) => (window.SEASONS || []).find((s) =>
    s.grp === grp && (s.start <= s.end ? md >= s.start && md <= s.end : md >= s.start || md <= s.end));
  const dateInput = document.querySelector('[data-sales-form]') && document.querySelector('input[name="work_date"]');
  if (dateInput) {
    dateInput.addEventListener('change', () => {
      const d = new Date(dateInput.value + 'T00:00:00');
      if (isNaN(d)) return;
      const md = dateInput.value.slice(5);
      const ts = seasonFor('ticket', md);
      setText($(document, '[data-ticket-season]'), ts ? ts.label + ' 요금 적용' : '');
      document.querySelectorAll('table[data-ticket-table] tbody tr').forEach((tr) => {
        const sp = JSON.parse(tr.dataset.seasons || '{}');
        const price = ts && sp[ts.id] !== undefined ? sp[ts.id] : num(tr.dataset.base);
        tr.dataset.price = price;
        setText($(tr, '[data-unit]'), fmt(price));
      });
      const rate = seasonFor('room', md) ? 'peak' : (d.getDay() === 5 || d.getDay() === 6 ? 'weekend' : 'weekday');
      document.querySelectorAll('[data-rate]').forEach((s) => { s.value = rate; });
      recalc();
    });
  }

  // 저장 전 확인: 객실 상품권 환급액이 기준과 다르거나 재고가 모자라면 팝업으로 알림
  const roomTable = document.querySelector('table[data-room-table]');
  if (roomTable && roomTable.closest('form')) {
    const form = roomTable.closest('form');
    form.addEventListener('submit', (ev) => {
      recalc();
      const msgs = $$(roomTable, 'tbody tr').map((tr) => tr.dataset.mismatch).filter(Boolean);
      const short = $$(document, 'table[data-voucher-summary] tbody tr.issue').map((tr) => tr.cells[0].textContent.trim());
      if (!msgs.length && !short.length) return;
      let text = '';
      if (msgs.length) text += '지역상품권 환급액이 객실 기준 환급액과 다릅니다.\n\n· ' + msgs.join('\n· ') + '\n\n';
      if (short.length) text += '상품권 재고가 부족합니다: ' + short.join(', ') + '\n\n';
      if (!confirm(text + '이대로 저장할까요?')) ev.preventDefault();
    });
  }

  recalc();
})();

// 사진 업로드: 브라우저에서 긴 변 1600px JPEG로 줄여서 올림 (호스팅 업로드 제한·트래픽 절약)
(function () {
  async function shrink(file, MAX) {
    if (!file.type.startsWith('image/') || file.type === 'image/gif') return file;
    const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
    const scale = Math.min(1, MAX / Math.max(bmp.width, bmp.height));
    if (scale === 1 && file.size < 1.5 * 1024 * 1024) return file;
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bmp.width * scale);
    canvas.height = Math.round(bmp.height * scale);
    canvas.getContext('2d').drawImage(bmp, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise((r) => canvas.toBlob(r, 'image/jpeg', 0.85));
    return blob ? new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file;
  }
  document.querySelectorAll('input[type=file][data-resize]').forEach((input) => {
    input.addEventListener('change', async () => {
      if (!window.DataTransfer || !window.createImageBitmap) return; // 구형 브라우저는 원본 그대로
      const form = input.form;
      const buttons = form ? form.querySelectorAll('button') : [];
      buttons.forEach((b) => (b.disabled = true));
      try {
        const dt = new DataTransfer();
        const max = parseInt(input.dataset.resize, 10) || 1600; // data-resize="800" 처럼 크기 지정 가능
        for (const f of input.files) dt.items.add(await shrink(f, max).catch(() => f));
        input.files = dt.files;
      } finally {
        buttons.forEach((b) => (b.disabled = false));
      }
    });
  });
})();

// 상단 메뉴: 메인메뉴를 누르면 서브메뉴 열기/닫기 (휴대폰·태블릿), 바깥을 누르면 닫기
(function () {
  const groups = document.querySelectorAll('.nav-group');
  groups.forEach((g) => {
    g.querySelector('.nav-main').addEventListener('click', (ev) => {
      ev.stopPropagation();
      const open = !g.classList.contains('open');
      groups.forEach((o) => o.classList.remove('open'));
      g.classList.toggle('open', open);
    });
  });
  document.addEventListener('click', () => groups.forEach((g) => g.classList.remove('open')));
})();
