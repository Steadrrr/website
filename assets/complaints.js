// 일일업무일지 민원: 대분류→중분류→소분류 연동, '기타' 직접 입력, 장소(객실·시설), 처리상태 기본값, 행 추가·삭제, 요약
(function () {
  const box = document.querySelector('[data-cpl-form]');
  if (!box) return;
  const data = window.CPL || { tree: {}, etc: [] };
  const list = box.querySelector('[data-cpl-list]');
  const tpl = document.getElementById('cplTpl');
  const $ = (root, sel) => root.querySelector(sel);
  const workDate = () => { const i = document.querySelector('input[name="work_date"]'); return i ? i.value : box.dataset.workDate; };
  const num = (v) => parseInt(String(v).replace(/\D/g, ''), 10) || 0;

  function fill(sel, items, value, placeholder) {
    sel.innerHTML = `<option value="">${placeholder}</option>` + items.map(([k, v]) => `<option value="${k}">${k} ${v.replace(/</g, '&lt;')}</option>`).join('');
    sel.value = items.some(([k]) => k === value) ? value : '';
  }

  function syncCats(row) {
    const c1 = $(row, '[data-cat1]'), c2 = $(row, '[data-cat2]'), c3 = $(row, '[data-cat3]');
    const mids = data.tree[c1.value] || [];
    fill(c2, mids.map(([k, n]) => [k, n]), c2.value || c2.dataset.value, '중분류');
    const mid = mids.find(([k]) => k === c2.value);
    fill(c3, mid ? mid[2] : [], c3.value || c3.dataset.value, '소분류');
    c2.dataset.value = c3.dataset.value = '';
    const etc = $(row, '[data-etc]');
    etc.hidden = !data.etc.includes(c3.value);
    etc.required = !etc.hidden;
    row.classList.toggle('urgent', c1.value === data.urgent);
  }

  function syncPlace(row) {
    const type = $(row, '[data-place-type]').value, sel = $(row, '[data-place-id]');
    sel.hidden = !type;
    sel.querySelectorAll('optgroup').forEach((g) => { g.hidden = g.dataset.pt !== type; g.disabled = g.dataset.pt !== type; });
    const cur = sel.selectedOptions[0];
    if (!cur || cur.parentElement.dataset.pt !== type) {
      const first = sel.querySelector(`optgroup[data-pt="${type}"] option`);
      sel.value = first ? first.value : '';
    }
  }

  function syncStatus(row) {
    const st = $(row, '[data-status]').value;
    $(row, '[data-done-box]').hidden = st !== 'done';
    const d = $(row, '[data-done]');
    d.required = st === 'done';
    if (st === 'done' && !d.value) d.value = workDate();
  }

  // 대분류를 고르면 처리상태 기본값: 단순문의 → 완료(처리일자 = 접수일), 긴급·안전 → 처리중, 그 밖 → 미조치
  function defaults(row) {
    const c1 = $(row, '[data-cat1]').value, st = $(row, '[data-status]'), d = $(row, '[data-done]');
    if (c1 === data.simple) { st.value = 'done'; d.value = workDate(); }
    else if (c1 === data.urgent) { st.value = 'progress'; d.value = ''; }
    else if (c1) { st.value = st.value && st.value !== 'done' ? st.value : 'open'; if (st.value !== 'done') d.value = ''; }
    syncStatus(row);
  }

  function summary() {
    const rows = [...list.querySelectorAll('[data-cpl-row]')];
    const by1 = {}, bySt = {};
    let total = 0;
    rows.forEach((row, i) => {
      $(row, '[data-cpl-no]').textContent = i + 1;
      const c1 = $(row, '[data-cat1]').value, st = $(row, '[data-status]').value, q = Math.max(1, num($(row, '[data-qty]').value));
      if (!c1) return;
      by1[c1] = (by1[c1] || 0) + q; bySt[st] = (bySt[st] || 0) + q; total += q;
    });
    box.querySelectorAll('[data-cs1]').forEach((b) => { b.textContent = by1[b.dataset.cs1] || 0; });
    box.querySelectorAll('[data-css]').forEach((b) => { b.textContent = bySt[b.dataset.css] || 0; });
    const t = $(box, '[data-cs-total]'); if (t) t.textContent = total;
  }

  function init(row) { syncCats(row); syncPlace(row); syncStatus(row); }
  list.querySelectorAll('[data-cpl-row]').forEach(init);

  list.addEventListener('change', (ev) => {
    const row = ev.target.closest('[data-cpl-row]');
    if (!row) return;
    if (ev.target.matches('[data-cat1]')) { $(row, '[data-cat2]').value = ''; $(row, '[data-cat3]').value = ''; syncCats(row); defaults(row); }
    else if (ev.target.matches('[data-cat2]')) { $(row, '[data-cat3]').value = ''; syncCats(row); }
    else if (ev.target.matches('[data-cat3]')) syncCats(row);
    else if (ev.target.matches('[data-place-type]')) syncPlace(row);
    else if (ev.target.matches('[data-status]')) syncStatus(row);
    summary();
  });
  list.addEventListener('input', (ev) => { if (ev.target.matches('[data-qty]')) summary(); });
  list.addEventListener('click', (ev) => {
    const btn = ev.target.closest('[data-cpl-remove]');
    if (!btn) return;
    const row = btn.closest('[data-cpl-row]');
    if (($(row, '[data-cat1]').value || $(row, 'textarea').value.trim()) && !confirm('이 민원을 삭제할까요?')) return;
    row.remove();
    summary();
  });
  let seq = Date.now();
  $(box, '[data-cpl-add]').addEventListener('click', () => {
    list.insertAdjacentHTML('beforeend', tpl.innerHTML.replace(/__KEY__/g, 'n' + seq++));
    const row = list.lastElementChild;
    init(row);
    $(row, '[data-cat1]').focus();
    summary();
  });
  summary();
})();
