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

  // 시간 입력: 조퇴·외출은 근무시간 안에서 1시간 단위 선택(드롭박스), 초과근무는 자유 입력
  const boxFree = form && $('[data-times="free"]', form);
  const boxHour = form && $('[data-times="hour"]', form);
  const hourMode = () => ['early', 'out'].includes(kind());
  const box = () => (hourMode() ? boxHour : boxFree);
  const st = () => box().querySelector('[name="start_time"]');
  const et = () => box().querySelector('[name="end_time"]');

  function fillHours(w) {
    const opts = w ? w.options : [];
    const [sSel, eSel] = boxHour.querySelectorAll('select');
    const fill = (sel, list, keep) => {
      sel.innerHTML = '<option value="">선택</option>' + list.map((t) => `<option value="${t}">${t}</option>`).join('');
      if (list.includes(keep)) sel.value = keep;
    };
    fill(sSel, opts.slice(0, -1), sSel.value);
    fill(eSel, opts.slice(1), eSel.value || (kind() === 'early' ? opts[opts.length - 1] : ''));
    if (kind() === 'early' && !eSel.dataset.touched) eSel.value = opts[opts.length - 1] || '';
    $('[data-break]', boxHour).textContent = w && w.break
      ? `근무 ${w.hours[0]}~${w.hours[1]} · 점심 휴게 ${w.break[0]}~${w.break[1]}은 사용 시간에서 빠집니다. (예: ${w.hours[0]}~${w.break[1]}은 ${(parseInt(w.break[0]) - parseInt(w.hours[0]))}시간)`
      : '';
  }

  let lastKind = '';
  function syncKind() {
    const time = unit() === 'time';
    [boxFree, boxHour].forEach((b) => {
      const on = time && b === box();
      b.hidden = !on;
      b.querySelectorAll('input, select').forEach((el) => { el.disabled = !on; el.required = on && el.name !== ''; });
    });
    $('[data-day-only]', form).hidden = time;
    $('[data-attach]', form).hidden = !['sick', 'official'].includes(kind());
    const w = data.workers[F('worker_id').value];
    const [ws, we] = w ? w.hours : ['09:00', '18:00'];
    if (hourMode() && lastKind !== kind()) { // 종류를 바꾸면 시각을 새로 고름 (조퇴는 종료 = 퇴근 시각)
      boxHour.querySelectorAll('select').forEach((sel) => { sel.value = ''; delete sel.dataset.touched; });
    }
    lastKind = kind();
    if (hourMode()) fillHours(w);
    else if (time && !boxFree.dataset.touched) { st().value = ws; et().value = we; } // 초과근무 기본값
    runCheck();
  }

  function li(text, cls) { const el = document.createElement('li'); el.textContent = text; if (cls) el.className = cls; return el; }

  function runCheck() {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      if (!F('worker_id').value) { check.innerHTML = '<p class="muted small">근무자를 고르세요.</p>'; return; }
      const q = new URLSearchParams({ user: F('worker_id').value, kind: kind(), start_date: F('start_date').value, end_date: F('end_date').value,
        start_time: unit() === 'time' ? st().value : '', end_time: unit() === 'time' ? et().value : '' });
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
    delete boxFree.dataset.touched;
    boxHour.querySelectorAll('select').forEach((sel) => { sel.value = ''; delete sel.dataset.touched; });
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
    F('end_date').addEventListener('change', runCheck);
    boxFree.querySelectorAll('input').forEach((el) => el.addEventListener('change', () => { boxFree.dataset.touched = '1'; runCheck(); }));
    boxHour.querySelectorAll('select').forEach((el) => el.addEventListener('change', () => { el.dataset.touched = '1'; runCheck(); }));
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
