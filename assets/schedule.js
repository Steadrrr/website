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
    $('[data-edit]').onclick = () => openForm(e);
    show('view');
  }

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
    // 근태 (관리원)
    if (!gcal.classList.contains('hide-att')) {
      (data.att || []).filter((a) => a.start_date <= ds && a.end_date >= ds).forEach((a) => {
        const link = document.createElement('a');
        link.className = 'ag-ev';
        link.href = data.viewUrl + a.id;
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
    if (ev.target.closest('a.gcal-ev')) return; // 근태는 결재 문서로 이동
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
