// 근태 달력: 날짜별 목록, 근태 입력 창 (실시간 연차·병가 검사, 진단서 안내), 종류 필터
(function () {
  const data = window.ATT || { items: [], kinds: {}, workers: {} };
  const dlg = document.getElementById('attDialog');
  const gcal = document.getElementById('gcal');
  if (!dlg || !gcal) return;
  const $ = (sel, root = dlg) => root.querySelector(sel);
  const form = $('[data-pane="form"]');
  const wd = ['일', '월', '화', '수', '목', '금', '토'];
  const fmtDay = (ds) => { const d = new Date(ds + 'T00:00:00'); return `${d.getMonth() + 1}월 ${d.getDate()}일 (${wd[d.getDay()]})`; };

  function show(pane) {
    dlg.querySelectorAll('.ev-pane').forEach((p) => { p.hidden = p.dataset.pane !== pane; });
    if (!dlg.open) dlg.showModal();
  }
  dlg.addEventListener('click', (ev) => { if (ev.target === dlg) dlg.close(); });
  dlg.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => dlg.close()));

  // ── 날짜별 목록
  function openDay(ds) {
    const list = data.items.filter((e) => e.start_date <= ds && e.end_date >= ds && !gcal.classList.contains('hide-' + e.kind));
    $('[data-day-title]').textContent = fmtDay(ds);
    const box = $('.ev-daylist');
    box.innerHTML = '';
    list.forEach((e) => {
      const a = document.createElement('a');
      a.className = 'ag-ev';
      a.href = data.viewUrl + e.id;
      a.style.setProperty('--c', data.kinds[e.kind].color);
      a.innerHTML = '<i></i><span class="ag-when"></span><span class="ag-title"><b></b></span>';
      a.querySelector('.ag-when').textContent = data.kinds[e.kind].label + (e.pending ? ' · 결재중' : '');
      a.querySelector('b').textContent = e.title;
      box.appendChild(a);
    });
    if (!list.length) box.innerHTML = '<p class="muted center">근태가 없습니다.</p>';
    const c = $('[data-day-create]');
    if (c) c.onclick = () => openForm(ds);
    show('day');
  }

  // ── 근태 입력
  const F = (name) => form && form.elements[name];
  const kind = () => { const k = form.querySelector('[name="kind"]:checked'); return k ? k.value : ''; };
  const unit = () => (data.kinds[kind()] || {}).unit;
  const check = form ? $('.att-check', form) : null;
  let timer = null;

  function syncKind() {
    const time = unit() === 'time';
    $('.ev-times', form).hidden = !time;
    $('[data-day-only]', form).hidden = time;
    F('start_time').required = F('end_time').required = time;
    $('[data-attach]', form).hidden = !['sick', 'official'].includes(kind());
    // 기본 시간: 근무자의 근무시간 (조퇴는 끝나는 시간, 초과근무는 근무시간 그대로)
    const w = data.workers[F('worker_id').value];
    const [ws, we] = w ? w.hours : ['09:00', '18:00'];
    if (time && !F('start_time').dataset.touched) {
      if (kind() === 'early') { F('end_time').value = we; F('start_time').value = ''; }
      else if (kind() === 'out') { F('start_time').value = ''; F('end_time').value = ''; }
      else { F('start_time').value = ws; F('end_time').value = we; }
    }
    runCheck();
  }

  function li(text, cls) { const el = document.createElement('li'); el.textContent = text; if (cls) el.className = cls; return el; }

  function runCheck() {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      if (!F('worker_id').value) { check.innerHTML = '<p class="muted small">근무자를 고르세요.</p>'; return; }
      const q = new URLSearchParams({ user: F('worker_id').value, kind: kind(), start_date: F('start_date').value, end_date: F('end_date').value,
        start_time: F('start_time').value, end_time: F('end_time').value });
      let res;
      try { res = await (await fetch(data.checkUrl + '&' + q.toString(), { credentials: 'same-origin' })).json(); } catch (e) { return; }
      check.innerHTML = '';
      const s = res.summary;
      if (s) {
        const box = document.createElement('div');
        box.className = 'att-check-sum';
        box.textContent = s.set
          ? `연차 발생 ${s.accrued} (최대 ${s.max}) · 사용 ${s.used} · 남은 ${s.remain} · 병가 ${s.sick}`
          : '입사일 미등록 — 연차·병가를 계산할 수 없습니다.';
        const w = document.createElement('small');
        w.className = 'muted';
        w.textContent = ` · 근무 ${s.work}${s.configured ? '' : ' (근무 설정 전)'}`;
        box.appendChild(w);
        check.appendChild(box);
      }
      const ul = document.createElement('ul');
      if (res.amount) ul.appendChild(li('신청: ' + res.amount, 'ok'));
      (res.errors || []).forEach((m) => ul.appendChild(li(m, 'err')));
      (res.notes || []).forEach((m) => ul.appendChild(li(m, m.includes('진단서') ? 'cert' : 'note')));
      if (ul.children.length) check.appendChild(ul);
      form.dataset.cert = res.cert ? '1' : '';
      if (res.cert) $('[data-attach]', form).hidden = false;
    }, 250);
  }

  function openForm(date) {
    if (!form) return;
    form.reset();
    F('start_date').value = date;
    F('end_date').value = date;
    delete F('start_time').dataset.touched;
    syncKind();
    show('form');
  }

  if (form) {
    form.querySelectorAll('[name="kind"]').forEach((r) => r.addEventListener('change', syncKind));
    F('worker_id').addEventListener('change', syncKind);
    F('start_date').addEventListener('change', () => {
      if (!F('end_date').value || F('end_date').value < F('start_date').value) F('end_date').value = F('start_date').value;
      runCheck();
    });
    ['end_date', 'start_time', 'end_time'].forEach((n) => F(n).addEventListener('change', () => { F('start_time').dataset.touched = '1'; runCheck(); }));
    form.addEventListener('submit', (ev) => {
      if (form.dataset.cert && !F('attachment').files.length
          && !confirm('의사의 진단서를 첨부해야 하는 병가입니다.\n첨부 없이 올릴까요? (문서 화면에서 나중에 첨부할 수 있습니다)')) ev.preventDefault();
    });
  }

  // ── 클릭 연결
  gcal.addEventListener('click', (ev) => {
    if (ev.target.closest('a')) return;
    const more = ev.target.closest('.gcal-more, .gcal-num');
    if (more) return openDay(more.dataset.day);
    const create = ev.target.closest('[data-create]');
    if (create) return openForm(create.dataset.create);
  });
  if (data.openNew) openForm(data.openNew);

  // ── 종류 필터 (이 브라우저에 기억)
  const KEY = 'att-hidden';
  let hidden = [];
  try { hidden = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { hidden = []; }
  gcal.querySelectorAll('[data-filter]').forEach((cb) => {
    const k = cb.dataset.filter;
    cb.checked = !hidden.includes(k);
    gcal.classList.toggle('hide-' + k, !cb.checked);
    cb.addEventListener('change', () => {
      gcal.classList.toggle('hide-' + k, !cb.checked);
      hidden = [...gcal.querySelectorAll('[data-filter]')].filter((c) => !c.checked).map((c) => c.dataset.filter);
      try { localStorage.setItem(KEY, JSON.stringify(hidden)); } catch (e) { /* 저장 못 해도 동작에는 지장 없음 */ }
    });
  });
})();
