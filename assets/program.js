// 프로그램 운영보고: 회차 추가·삭제(번호 자동), 인원 합계, 프로그램 금액(고른 프로그램의 1인 요금), 보고서 합계
(function () {
  const form = document.querySelector('[data-program-form]');
  if (!form) return;
  const box = form.querySelector('[data-sessions]');
  const tpl = document.getElementById('sessionTpl');
  const fmt = (n) => n.toLocaleString('ko-KR');
  const num = (v) => parseInt(String(v).replace(/\D/g, ''), 10) || 0;
  const set = (el, v) => { if (el) el.textContent = v; };

  function recalc() {
    const cards = [...box.querySelectorAll('[data-session]')];
    const sum = { total: 0, m: 0, f: 0, paid: 0, discount: 0, free: 0, amount: 0 };
    cards.forEach((card, i) => {
      set(card.querySelector('[data-no]'), i + 1); // 회차 번호는 순서대로 1부터
      const gs = { m: 0, f: 0 }, as = {};
      card.querySelectorAll('[data-people]').forEach((inp) => {
        const n = num(inp.value);
        gs[inp.dataset.g] += n;
        as[inp.dataset.age] = (as[inp.dataset.age] || 0) + n;
      });
      const total = gs.m + gs.f;
      Object.entries(gs).forEach(([g, v]) => set(card.querySelector(`[data-gsum="${g}"]`), fmt(v)));
      card.querySelectorAll('[data-asum]').forEach((c) => set(c, fmt(as[c.dataset.asum] || 0)));
      const ft = card.querySelector('[data-fee-type]:checked'); // 유료 / 할인 / 무료
      const type = ft ? ft.value : 'paid';
      const sel = card.querySelector('[data-product]'); // 고른 프로그램의 1인 요금
      const opt = sel && sel.value ? sel.selectedOptions[0] : null;
      const fees = { paid: opt ? num(opt.dataset.price) : 0, discount: opt ? num(opt.dataset.discount) : 0, free: 0 };
      card.querySelectorAll('[data-fee-label]').forEach((el) => set(el, opt ? `(1인 ${fmt(fees[el.dataset.feeLabel])}원)` : ''));
      const warn = card.querySelector('[data-pick-warn]');
      if (warn) warn.hidden = !!opt || !sel; // 프로그램을 안 골랐으면 안내
      if (sel) sel.classList.toggle('need-pick', !opt);
      const amount = total * fees[type];
      set(card.querySelector('[data-total]'), fmt(total));
      set(card.querySelector('[data-total-text]'), fmt(total) + '명');
      set(card.querySelector('[data-amount]'), type === 'free' ? '무료' : fmt(amount) + '원');
      card.querySelector('[data-remove-session]').hidden = cards.length === 1;
      sum.total += total; sum.m += gs.m; sum.f += gs.f; sum.amount += amount;
      sum[type] += total;
    });
    set(form.querySelector('[data-sum-sessions]'), cards.length + '회');
    set(form.querySelector('[data-sum-total]'), fmt(sum.total) + '명');
    ['m', 'f', 'paid', 'discount', 'free'].forEach((k) => set(form.querySelector(`[data-sum-${k}]`), fmt(sum[k])));
    set(form.querySelector('[data-sum-amount]'), fmt(sum.amount) + '원');
  }

  let seq = Date.now();
  form.querySelector('[data-add-session]').addEventListener('click', () => {
    const html = tpl.innerHTML.replace(/__KEY__/g, 'n' + seq++);
    box.insertAdjacentHTML('beforeend', html);
    recalc();
    const last = box.lastElementChild;
    last.scrollIntoView({ behavior: 'smooth', block: 'center' });
    last.querySelector('input').focus({ preventScroll: true });
  });
  box.addEventListener('click', (ev) => {
    const btn = ev.target.closest('[data-remove-session]');
    if (!btn) return;
    const card = btn.closest('[data-session]');
    const filled = [...card.querySelectorAll('input:not([type=radio]):not([type=hidden]), textarea')].some((i) => i.value.trim() !== '') || !!card.querySelector('.photo-editor img');
    if (filled && !confirm('이 회차를 삭제할까요? 뒤 회차 번호가 앞으로 당겨지고, 저장하면 이 회차의 활동사진도 함께 삭제됩니다.')) return;
    card.remove();
    recalc();
  });
  box.addEventListener('input', (ev) => {
    if (ev.target.matches('[data-people]')) {
      const n = num(ev.target.value);
      ev.target.value = n ? String(n) : '';
    }
    recalc();
  });
  box.addEventListener('change', recalc);
  // 결재 올리기 전에: 내용을 입력한 회차에 프로그램이 안 골라져 있으면 알려 주고 그 칸으로
  const formEl = form.closest('form');
  if (formEl) formEl.addEventListener('submit', (ev) => {
    const btn = ev.submitter;
    if (btn && btn.value === 'save') return; // 임시저장은 그대로
    const cards = [...box.querySelectorAll('[data-session]')];
    for (let i = 0; i < cards.length; i++) {
      const c = cards[i];
      const sel = c.querySelector('[data-product]');
      const used = [...c.querySelectorAll('[data-people], input[name$="[group_name]"]')].some((x) => x.value.trim() !== '' && x.value.trim() !== '0');
      if (sel && used && !sel.value) {
        ev.preventDefault();
        alert(`${i + 1}회차: 프로그램을 고르세요. 프로그램을 골라야 1인 요금이 들어가고 저장됩니다.`);
        sel.scrollIntoView({ block: 'center' });
        sel.focus();
        return;
      }
    }
  });
  recalc();
})();
