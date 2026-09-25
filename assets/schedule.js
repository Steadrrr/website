// 일정표: 일정 보기·만들기·수정 창, 날짜별 목록, 분류 필터
(function () {
  const data = window.GCAL || { events: [], categories: {} };
  const byId = Object.fromEntries(data.events.map((e) => [e.id, e]));
  const dlg = document.getElementById('evDialog');
  const gcal = document.getElementById('gcal');
  if (!dlg || !gcal) return;
  const $ = (sel, root = dlg) => root.querySelector(sel);
  const form = $('[data-pane="form"]');
  const F = (name) => form.elements[name]; // form.id·form.title 은 폼 자체 속성이라 입력칸은 elements 로 접근
  const wd = ['일', '월', '화', '수', '목', '금', '토'];
  const fmtDay = (ds) => { const d = new Date(ds + 'T00:00:00'); return `${d.getMonth() + 1}월 ${d.getDate()}일 (${wd[d.getDay()]})`; };

  function show(pane) {
    dlg.querySelectorAll('.ev-pane').forEach((p) => { p.hidden = p.dataset.pane !== pane; });
    if (!dlg.open) dlg.showModal();
  }
  dlg.addEventListener('click', (ev) => { if (ev.target === dlg) dlg.close(); }); // 바깥 누르면 닫기
  dlg.querySelectorAll('[data-close]').forEach((b) => b.addEventListener('click', () => dlg.close()));

  // ── 일정 보기
  function openView(id) {
    const e = byId[id];
    if (!e) return;
    const cat = data.categories[e.category];
    $('.ev-swatch').style.background = cat.color;
    $('[data-f="title"]').textContent = e.title;
    $('[data-f="when"]').textContent = e.when;
    $('[data-f="category"]').textContent = cat.label;
    $('[data-f="author"]').textContent = e.author;
    const loc = $('[data-f="location"]');
    loc.hidden = !e.location;
    loc.querySelector('span').textContent = e.location;
    const desc = $('[data-f="description"]');
    desc.hidden = !e.description;
    desc.textContent = e.description;
    dlg.querySelectorAll('[data-can-edit]').forEach((b) => { b.hidden = !e.can_edit; });
    $('[data-delete-form] [name="id"]').value = e.id;
    const ser = $('[data-f="series"]');
    ser.hidden = !e.series;
    ser.querySelector('b').textContent = e.series || '';
    $('[data-edit]').onclick = () => openForm(e);
    show('view');
  }

  // ── 삭제: 이 일정만 / 반복 일정 모두
  const delForm = $('[data-delete-form]');
  delForm.addEventListener('submit', (ev) => {
    if (!confirm('이 일정을 삭제할까요?')) ev.preventDefault();
    delForm.elements.scope.value = 'one';
  });
  $('[data-series-delete]').addEventListener('click', () => {
    const n = $('[data-f="series"] b').textContent;
    if (!confirm(`같이 만든 반복 일정 ${n}개를 모두 삭제할까요?`)) return;
    delForm.elements.scope.value = 'series';
    delForm.submit();
  });

  // ── 반복 (새 일정만): 매주 요일 / 매월·매년 날짜 또는 N번째 요일
  const repBox = $('[data-repeat-box]');
  const repDates = (start, until, r) => {
    const out = [];
    for (let d = new Date(start + 'T00:00:00'), end = new Date(until + 'T00:00:00'); d <= end && out.length <= 400; d.setDate(d.getDate() + 1)) {
      const w = d.getDay(), day = d.getDate(), last = new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
      const inMonth = r.mode === 'nth' ? w === r.nwd && (r.nth === -1 ? day + 7 > last : Math.ceil(day / 7) === r.nth) : day === r.day;
      const ok = r.repeat === 'weekly' ? r.wd.includes(w) : (r.repeat !== 'yearly' || d.getMonth() + 1 === r.month) && inMonth;
      if (ok) out.push(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`);
    }
    return out;
  };
  function syncRepeat() {
    const rep = F('repeat').value;
    repBox.querySelectorAll('[data-rep]').forEach((el) => { el.hidden = !rep || !el.dataset.rep.split(' ').includes(rep); });
    F('repeat_until').required = !!rep;
    const pv = $('[data-rep-preview]', repBox);
    if (!rep || !F('start_date').value || !F('repeat_until').value) { pv.textContent = rep ? '반복 종료일을 고르면 만들어질 일정 수가 보입니다.' : ''; return; }
    const r = {
      repeat: rep, mode: form.querySelector('[name="rep_mode"]:checked').value, day: +F('rep_day').value, nth: +F('rep_nth').value, nwd: +F('rep_nwd').value, month: +F('rep_month').value,
      wd: [...form.querySelectorAll('[name="rep_wd[]"]:checked')].map((c) => +c.value),
    };
    const ds = repDates(F('start_date').value, F('repeat_until').value, r);
    pv.textContent = ds.length ? `일정 ${ds.length}개가 만들어집니다 (${ds[0]} ~ ${ds[ds.length - 1]})` + (ds.length > 400 ? ' — 최대 400개까지 가능합니다.' : '') : '조건에 맞는 날짜가 없습니다.';
    pv.classList.toggle('warn', !ds.length || ds.length > 400);
  }
  function repeatDefaults() { // 시작일 기준 기본값: 그 요일, 그 날짜, 그 달, N번째 요일
    const s = F('start_date').value;
    if (!s) return;
    const d = new Date(s + 'T00:00:00');
    form.querySelectorAll('[name="rep_wd[]"]').forEach((c) => { c.checked = +c.value === d.getDay(); });
    F('rep_day').value = d.getDate();
    F('rep_month').value = d.getMonth() + 1;
    F('rep_nwd').value = d.getDay();
    F('rep_nth').value = d.getDate() + 7 > new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate() && Math.ceil(d.getDate() / 7) > 4 ? -1 : Math.min(4, Math.ceil(d.getDate() / 7));
  }
  repBox.addEventListener('change', syncRepeat);
  repBox.addEventListener('input', syncRepeat);
  F('start_date').addEventListener('change', () => { if (!F('repeat').value) repeatDefaults(); syncRepeat(); });

  // ── 만들기 / 수정
  function syncAllDay() {
    const allDay = F('all_day').checked;
    $('.ev-times', form).hidden = allDay;
    F('start_time').required = !allDay;
  }
  F('all_day').addEventListener('change', syncAllDay);
  F('start_date').addEventListener('change', () => {
    if (!F('end_date').value || F('end_date').value < F('start_date').value) F('end_date').value = F('start_date').value;
  });

  function openForm(e, date) {
    form.reset();
    F('id').value = e ? e.id : '';
    F('title').value = e ? e.title : '';
    const catInput = form.querySelector(`[name="category"][value="${e ? e.category : (data.newCat || 'event')}"]`) || form.querySelector('[name="category"]');
    catInput.checked = true;
    F('start_date').value = e ? e.start_date : date;
    F('end_date').value = e ? e.end_date : date;
    F('all_day').checked = e ? !!e.all_day : true;
    F('start_time').value = e ? e.start_time : '10:00';
    F('end_time').value = e ? e.end_time : '';
    F('location').value = e ? e.location : '';
    F('description').value = e ? e.description : '';
    $('[data-form-title]').textContent = e ? '일정 수정' : '일정 만들기';
    repBox.hidden = !!e; // 반복은 새로 만들 때만
    F('repeat').value = '';
    repeatDefaults();
    syncRepeat();
    syncAllDay();
    show('form');
    setTimeout(() => F('title').focus(), 30);
  }

  // ── 날짜별 목록 (+N개 더보기, 미니 달력)
  function openDay(ds) {
    const list = data.events.filter((e) => e.start_date <= ds && e.end_date >= ds && !gcal.classList.contains('hide-' + e.category));
    $('[data-day-title]').textContent = fmtDay(ds);
    const box = $('.ev-daylist');
    box.innerHTML = '';
    list.forEach((e) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'ag-ev';
      b.style.setProperty('--c', data.categories[e.category].color);
      b.innerHTML = '<i></i><span class="ag-when"></span><span class="ag-title"><b></b></span>';
      b.querySelector('.ag-when').textContent = e.all_day && e.start_date === e.end_date ? '종일' : e.when;
      b.querySelector('b').textContent = e.title;
      b.addEventListener('click', () => openView(e.id));
      box.appendChild(b);
    });
    // 근태 (사원)
    if (!gcal.classList.contains('hide-att')) {
      (data.att || []).filter((a) => a.start_date <= ds && a.end_date >= ds).forEach((a) => {
        const link = document.createElement(a.open ? 'a' : 'div'); // 다른 사원 근태는 표시만
        link.className = 'ag-ev' + (a.open ? '' : ' locked');
        if (a.open) link.href = data.viewUrl + a.id;
        link.style.setProperty('--c', a.color);
        link.innerHTML = '<i></i><span class="ag-when"></span><span class="ag-title"><b></b></span>';
        link.querySelector('.ag-when').textContent = '근태' + (a.pending ? ' · 결재중' : '');
        link.querySelector('b').textContent = a.title;
        box.appendChild(link);
      });
    }
    if (!box.children.length) box.innerHTML = '<p class="muted center">일정이 없습니다.</p>';
    $('[data-day-create]').onclick = () => openForm(null, ds);
    show('day');
  }

  // ── 클릭 연결
  gcal.addEventListener('click', (ev) => {
    if (ev.target.closest('a.gcal-ev, .gcal-ev.locked')) return; // 근태는 결재 문서로 이동 (다른 사원 근태는 표시만)
    const evBtn = ev.target.closest('.gcal-ev[data-id]');
    if (evBtn) return openView(+evBtn.dataset.id);
    const more = ev.target.closest('.gcal-more, .mini-d, .gcal-num');
    if (more) return openDay(more.dataset.day);
    const create = ev.target.closest('[data-create]');
    if (create) return openForm(null, create.dataset.create);
  });

  if (data.openNew) openForm(null, data.openNew);

  // ── 분류 필터 (이 브라우저에 기억)
  const KEY = 'gcal-hidden';
  let hidden = [];
  try { hidden = JSON.parse(localStorage.getItem(KEY) || '[]'); } catch (e) { hidden = []; }
  gcal.querySelectorAll('[data-filter]').forEach((cb) => {
    const cat = cb.dataset.filter;
    cb.checked = !hidden.includes(cat);
    gcal.classList.toggle('hide-' + cat, !cb.checked);
    cb.addEventListener('change', () => {
      gcal.classList.toggle('hide-' + cat, !cb.checked);
      hidden = [...gcal.querySelectorAll('[data-filter]')].filter((c) => !c.checked).map((c) => c.dataset.filter);
      try { localStorage.setItem(KEY, JSON.stringify(hidden)); } catch (e) { /* 저장 못 해도 동작에는 지장 없음 */ }
    });
  });
})();
