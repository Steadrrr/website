/* 객실 청소관리 웹앱 (clean.php) — 상태 그리기, 처리 버튼, 알림 켜기 */
(function () {
  const C = window.CLEAN;
  let state = C.state;
  let busy = false;
  const picked = new Map(); // 체크한 객실 id → 'wait'(퇴실대기) | 'dirty'(청소가능)
  const $ = (s) => document.querySelector(s);
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const WD = ['일', '월', '화', '수', '목', '금', '토'];
  const dt = (d) => new Date(d + 'T00:00:00');
  const ymd = (x) => x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0');

  function toast(msg, err) {
    const t = $('[data-toast]');
    t.textContent = msg; t.className = 'cl-toast' + (err ? ' err' : ''); t.hidden = false;
    clearTimeout(toast.t); toast.t = setTimeout(() => { t.hidden = true; }, err ? 4000 : 2200);
  }

  function room(r, cls, right, meta, tag, check) {
    return '<div class="cl-room ' + cls + (check && picked.has(r.id) ? ' picked' : '') + '"' + (check ? ' data-pick-card="' + r.id + '" data-pick-kind="' + check + '"' : '') + '>'
      + (check ? '<span class="cl-check"><input type="checkbox" data-pick value="' + r.id + '"' + (picked.has(r.id) ? ' checked' : '') + ' aria-label="' + esc(r.name) + ' 선택"></span>' : '')
      + '<div class="info"><span class="name">' + esc(r.name) + '</span>' + (r.type ? '<span class="type">' + esc(r.type) + '</span>' : '')
      + (tag ? '<span class="cl-tag">' + tag + '</span>' : '') + (meta ? '<div class="meta">' + meta + '</div>' : '') + '</div>' + right + '</div>';
  }
  const btn = (id, op, label, cls) => '<button type="button" class="cl-act ' + cls + '" data-op="' + op + '" data-room="' + id + '">' + label + '</button>';
  const undo = (id, op, label) => '<button type="button" class="cl-undo" data-op="' + op + '" data-room="' + id + '">' + label + '</button>';

  const KIND = { wait: '퇴실대기', dirty: '청소가능' };
  let pickKind = null; // 일괄 선택은 처음 체크한 객실과 같은 상태만 (퇴실대기 또는 청소가능)

  // 객실 칸 하나 (상태가 섞여 있어도 됨)
  function card(r) {
    const inTag = r.showIn && r.in_today ? '오늘 입실' : ''; // 퇴실완료 묶음에서는 오늘 새 손님이 오는 객실 표시
    r = Object.assign({}, r, { type: '' }); // 분류는 묶음 제목에 있으므로 칸에서는 뺌
    if (r.arrive) return room(r, 'arrive', '<span class="cl-done">입실가능</span>', '어젯밤 빈 객실 · 청소 없음', '');
    if (r.status === 'wait') return room(r, 'wait', btn(r.id, 'out', '퇴실처리', 'wait'), '퇴실대기', '', 'wait');
    if (r.status === 'dirty') return room(r, 'dirty', undo(r.id, 'undo_out', '퇴실취소') + btn(r.id, 'clean', '청소완료', 'dirty'),
      '퇴실 ' + esc(r.out_at) + ' ' + esc(r.out_by), inTag, 'dirty');
    return room(r, 'ready', undo(r.id, 'undo_clean', '되돌리기') + '<span class="cl-done">입실가능</span>', '청소 ' + esc(r.clean_at) + ' ' + esc(r.clean_by), inTag);
  }
  // 객실 분류(2인실·4인실·독채 …)별로 묶기 — 분류 순서는 설정의 객실 분류 순서
  function byType(list) {
    const groups = new Map();
    // 같은 분류 안에서는 청소가능(오늘 입실 먼저) → 입실가능 순, 그다음 객실 순서
    const rank = (r) => (r.status === 'dirty' ? (r.in_today ? 0 : 1) : r.status === 'ready' ? 2 : 0);
    list.slice().sort((x, y) => x.type_order - y.type_order || rank(x) - rank(y) || x.order - y.order || x.id - y.id)
      .forEach((r) => { const k = r.type || '분류 없음'; if (!groups.has(k)) groups.set(k, []); groups.get(k).push(r); });
    return groups;
  }
  function category(key, title, sub, list, empty) {
    const cnt = (k) => list.filter((r) => !r.arrive && r.status === k).length;
    const picks = ['wait', 'dirty'].filter((k) => cnt(k) > 1)
      .map((k) => '<button type="button" class="cl-pick-all ' + k + '" data-pick-all="' + k + '" data-cat="' + key + '">' + KIND[k] + ' 전체</button>').join('');
    let html = '<section class="cl-cat" data-cat-sec="' + key + '"><h2 class="cl-cat-title">' + title + ' <small>' + list.length + '실' + (sub ? ' · ' + sub : '') + '</small></h2>'
      + (picks ? '<div class="cl-cat-picks">' + picks + '</div>' : '');
    if (!list.length) return html + '<p class="cl-empty">' + empty + '</p></section>';
    byType(list).forEach((rooms, type) => {
      html += '<div class="cl-type"><h3>' + esc(type) + ' <small>' + rooms.length + '실</small></h3><div class="cl-grid">' + rooms.map(card).join('') + '</div></div>';
    });
    return html + '</section>';
  }
  function section(title, color, items, empty) {
    return '<section class="cl-sec"><h2><span class="dot" style="background:' + color + '"></span>' + title + ' <small>' + items.length + '실</small></h2>'
      + (items.length ? '<div class="cl-grid">' + items.join('') + '</div>' : '<p class="cl-empty">' + empty + '</p>') + '</section>';
  }

  function render() {
    const s = state, n = s.count;
    const d = dt(s.date);
    $('[data-date-label]').textContent = (d.getMonth() + 1) + '월 ' + d.getDate() + '일 (' + WD[d.getDay()] + ')' + (s.date === C.today ? ' · 오늘' : '');
    $('[data-today]').hidden = s.date === C.today;
    $('[data-progress]').style.width = (n.total ? Math.round(n.ready / n.total * 100) : 0) + '%';
    $('[data-progress-text]').textContent = n.total ? '입실가능 ' + n.ready + ' / ' + n.total + '실' : '오늘 퇴실 객실이 없습니다';
    $('[data-chips]').innerHTML = '<span class="cl-chip wait">퇴실대기 ' + n.wait + '</span><span class="cl-chip dirty">청소가능 ' + n.dirty + '</span>'
      + '<span class="cl-chip ready">입실가능 ' + n.ready + '</span>' + (n.stays ? '<span class="cl-chip stay">연박 비품 ' + n.supplied + ' / ' + n.stays + '</span>' : '');
    $('[data-allready]').hidden = !s.all_ready;

    // 상태가 바뀐 객실(다른 사람이 처리 등)은 선택에서 뺌
    const now = new Map(s.rooms.map((r) => [r.id, r.status]));
    [...picked].forEach(([id, k]) => { if (now.get(id) !== k) picked.delete(id); });
    if (!picked.size) pickKind = null;

    // 퇴실대기 객실: 금일 입실 예정(오늘 새 손님 — 어젯밤 빈 객실 포함) / 금일 미입실, 퇴실처리한 객실(청소가능·입실가능)은 퇴실완료로 모음
    const waitRooms = s.rooms.filter((r) => r.status === 'wait');
    const arriving = waitRooms.filter((r) => r.in_today).concat(s.arrivals.map((r) => Object.assign({ arrive: true }, r)));
    const notArriving = waitRooms.filter((r) => !r.in_today);
    const outDone = s.rooms.filter((r) => r.status !== 'wait').map((r) => Object.assign({ showIn: true }, r));
    const nDirty = outDone.filter((r) => r.status === 'dirty').length;
    const stays = s.stays.map((r) => room(r, 'stay',
      r.supply_at ? undo(r.id, 'undo_supply', '취소') + '<span class="cl-done stay">지급완료</span>' : btn(r.id, 'supply', '비품지급', 'stay'),
      r.supply_at ? '비품 ' + esc(r.supply_at) + ' ' + esc(r.supply_by) : '청소 없음 · 비품만 지급', r.nights + '박'));
    $('[data-list]').innerHTML = category('in', '금일 입실 예정', '먼저 퇴실·청소', arriving, '퇴실을 기다리는 오늘 입실 객실이 없습니다.')
      + category('none', '금일 미입실', '', notArriving, '퇴실을 기다리는 객실이 없습니다.')
      + category('done', '퇴실완료', '청소가능 ' + nDirty + ' · 입실가능 ' + (outDone.length - nDirty), outDone, '아직 퇴실처리한 객실이 없습니다.')
      + section('연박 (비품지급)', 'var(--stay)', stays, '연박 객실이 없습니다.');
    bulkBar();
    board();
  }

  // 체크한 객실 → 아래 막대 (퇴실대기면 '퇴실처리 N실', 청소가능이면 '청소완료 N실')
  const pickedOf = (k) => [...picked].filter(([, v]) => v === k).map(([id]) => id);
  function bulkBar() {
    const bar = $('[data-bulk]');
    const nOut = pickedOf('wait').length, nClean = pickedOf('dirty').length;
    bar.hidden = picked.size === 0;
    bar.querySelector('[data-bulk-count]').textContent = picked.size;
    const kindEl = bar.querySelector('[data-bulk-kind]');
    if (kindEl) kindEl.textContent = pickKind ? KIND[pickKind] : '';
    const bo = bar.querySelector('[data-bulk-out]'), bc = bar.querySelector('[data-bulk-clean]');
    bo.hidden = !nOut; bo.textContent = '퇴실처리 ' + nOut + '실';
    bc.hidden = !nClean; bc.textContent = '청소완료 ' + nClean + '실';
    document.body.classList.toggle('cl-has-bulk', picked.size > 0);
    document.querySelectorAll('[data-pick-card]').forEach((c) => {
      const on = picked.has(Number(c.dataset.pickCard));
      const off = !!pickKind && c.dataset.pickKind !== pickKind; // 처음 고른 상태와 다른 객실은 선택 불가
      c.classList.toggle('picked', on);
      c.classList.toggle('pick-off', off);
      const cb = c.querySelector('[data-pick]');
      cb.checked = on; cb.disabled = off;
    });
    document.querySelectorAll('[data-pick-all]').forEach((b) => {
      const k = b.dataset.pickAll, sec = b.closest('[data-cat-sec]');
      const ids = [...sec.querySelectorAll('[data-pick-kind="' + k + '"]')].map((c) => Number(c.dataset.pickCard));
      b.disabled = !!pickKind && pickKind !== k;
      b.textContent = KIND[k] + (ids.length && ids.every((id) => picked.has(id)) ? ' 해제' : ' 전체');
    });
  }
  const BULK = {
    out: { kind: 'wait', ask: '퇴실처리', after: '청소가능', done: '퇴실처리' },
    clean: { kind: 'dirty', ask: '청소완료', after: '입실가능', done: '청소완료(입실가능)' },
  };
  async function bulkDo(op) {
    const B = BULK[op], ids = pickedOf(B.kind);
    if (busy || !ids.length) return;
    const names = state.rooms.filter((r) => ids.includes(r.id)).map((r) => r.name);
    if (!confirm(names.length + '실을 한 번에 ' + B.ask + '할까요?\n' + names.join(', ') + '\n\n' + B.after + ' 상태가 되고 모든 사용자에게 알림이 갑니다.')) return;
    busy = true;
    const btns = document.querySelectorAll('[data-bulk] button'); btns.forEach((x) => { x.disabled = true; });
    const fd = new FormData();
    fd.append('act', 'bulk'); fd.append('op', op); fd.append('date', state.date); fd.append('_csrf', C.csrf);
    ids.forEach((id) => fd.append('rooms[]', id));
    try {
      const r = await fetch(C.api, { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await r.json();
      if (j.rooms) { ids.forEach((id) => picked.delete(id)); if (!picked.size) pickKind = null; state = j; render(); }
      if (j.error) toast((j.done ? j.done + '실 ' + B.done + ' · ' : '') + j.error, true);
      else toast(j.all_ready && op === 'clean' ? j.done + '실 청소완료 — 전객실 입실준비완료!' : j.done + '실을 ' + B.done + '했습니다.');
    } catch (e) { toast('저장하지 못했습니다. 인터넷 연결을 확인하세요.', true); }
    btns.forEach((x) => { x.disabled = false; }); busy = false;
  }

  // 맨 위 공지 (공무직 이상 작성·수정)
  let editing = false;
  function board() {
    if (editing) return; // 고치는 중에는 새로 고침으로 덮지 않음
    const el = $('[data-board]'), n = state.notice || {};
    if (!n.text) {
      el.hidden = !C.canNotice;
      el.className = 'cl-board empty';
      el.innerHTML = C.canNotice ? '<button type="button" class="cl-board-add" data-notice-edit>+ 공지 작성</button>' : '';
      return;
    }
    el.hidden = false; el.className = 'cl-board';
    el.innerHTML = '<div class="cl-board-head"><b>📢 공지</b><small>' + esc(n.by || '') + ' · ' + esc((n.at || '').slice(5).replace('-', '/')) + '</small>'
      + (C.canNotice ? '<button type="button" class="cl-board-btn" data-notice-edit>수정</button>' : '') + '</div><div class="cl-board-text">' + esc(n.text) + '</div>';
  }
  function boardEdit() {
    editing = true;
    const el = $('[data-board]'); el.hidden = false; el.className = 'cl-board editing';
    el.innerHTML = '<div class="cl-board-head"><b>📢 공지 작성</b><small>모든 청소관리 사용자에게 보입니다</small></div>'
      + '<textarea rows="4" maxlength="2000" data-notice-text placeholder="예: 오늘 단체 입실 15시 — 산림휴양관 먼저 청소"></textarea>'
      + '<div class="cl-board-actions">' + (state.notice && state.notice.text ? '<button type="button" class="ghost danger" data-notice-del>삭제</button>' : '')
      + '<span></span><button type="button" class="ghost" data-notice-cancel>취소</button><button type="button" data-notice-save>저장</button></div>';
    const ta = el.querySelector('[data-notice-text]');
    ta.value = (state.notice && state.notice.text) || ''; ta.focus();
  }
  async function boardSave(text) {
    const fd = new FormData(); fd.append('act', 'notice'); fd.append('text', text); fd.append('_csrf', C.csrf);
    try {
      const r = await fetch(C.api, { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await r.json();
      if (j.error) return toast(j.error, true);
      state.notice = j.notice; editing = false; board();
      toast(text.trim() ? '공지를 저장했습니다.' : '공지를 지웠습니다.');
    } catch (e) { toast('저장하지 못했습니다. 인터넷 연결을 확인하세요.', true); }
  }
  $('[data-board]').addEventListener('click', (e) => {
    if (e.target.closest('[data-notice-edit]')) return boardEdit();
    if (e.target.closest('[data-notice-cancel]')) { editing = false; return board(); }
    if (e.target.closest('[data-notice-save]')) return boardSave($('[data-notice-text]').value);
    if (e.target.closest('[data-notice-del]') && confirm('공지를 지울까요?')) return boardSave('');
  });

  async function load(date) {
    try {
      const r = await fetch(C.api + '?act=state&date=' + encodeURIComponent(date || state.date), { credentials: 'same-origin', cache: 'no-store' });
      if (r.status === 401) { location.href = C.self; return; }
      const j = await r.json();
      if (j.error) { toast(j.error, true); return; }
      state = j; render();
      return true;
    } catch (e) { /* 잠깐 끊긴 것은 다음 새로 고침에 */ }
    return false;
  }

  const CONFIRM = { out: '퇴실처리할까요?\n청소가능 상태가 되고 모든 사용자에게 알림이 갑니다.', clean: '청소완료할까요?\n입실가능 상태가 되고 모든 사용자에게 알림이 갑니다.',
    undo_out: '퇴실처리를 취소할까요? (알림 없음)', undo_clean: '청소완료를 되돌릴까요? (청소가능 상태로, 알림 없음)', undo_supply: '비품지급을 취소할까요?' };
  const DONE = { out: '퇴실처리했습니다.', clean: '청소완료 — 입실가능', supply: '비품지급을 기록했습니다.', undo_out: '퇴실처리를 취소했습니다.', undo_clean: '되돌렸습니다.', undo_supply: '취소했습니다.' };

  document.addEventListener('click', async (ev) => {
    if (ev.target.closest('[data-bulk-out]')) return bulkDo('out');
    if (ev.target.closest('[data-bulk-clean]')) return bulkDo('clean');
    if (ev.target.closest('[data-bulk-clear]')) { picked.clear(); pickKind = null; return bulkBar(); }
    const all = ev.target.closest('[data-pick-all]');
    if (all) {
      const k = all.dataset.pickAll;
      if (pickKind && pickKind !== k) return toast(KIND[pickKind] + ' 객실을 고르는 중입니다. 해제한 뒤 고르세요.', true);
      const ids = [...all.closest('[data-cat-sec]').querySelectorAll('[data-pick-kind="' + k + '"]')].map((c) => Number(c.dataset.pickCard));
      if (ids.every((id) => picked.has(id))) ids.forEach((id) => picked.delete(id)); else { ids.forEach((id) => picked.set(id, k)); pickKind = k; }
      if (!picked.size) pickKind = null;
      return bulkBar();
    }
    const card = ev.target.closest('[data-pick-card]');
    if (card && !ev.target.closest('[data-op]')) { // 객실 칸 아무 곳이나 눌러도 체크
      const id = Number(card.dataset.pickCard), k = card.dataset.pickKind;
      if (picked.has(id)) picked.delete(id);
      else if (pickKind && pickKind !== k) { if (ev.target.matches('[data-pick]')) ev.target.checked = false; toast('처음 고른 ' + KIND[pickKind] + ' 객실과 같은 상태만 함께 고를 수 있습니다.', true); }
      else { picked.set(id, k); pickKind = k; }
      if (!picked.size) pickKind = null;
      return bulkBar();
    }
    const b = ev.target.closest('[data-op]');
    if (b) {
      if (busy) return;
      const op = b.dataset.op, rm = (state.rooms.concat(state.stays)).find((r) => String(r.id) === b.dataset.room);
      if (CONFIRM[op] && !confirm((rm ? rm.name + ' — ' : '') + CONFIRM[op])) return;
      busy = true; b.disabled = true;
      const fd = new FormData();
      fd.append('act', 'do'); fd.append('date', state.date); fd.append('room', b.dataset.room); fd.append('op', op); fd.append('_csrf', C.csrf);
      try {
        const r = await fetch(C.api, { method: 'POST', body: fd, credentials: 'same-origin' });
        const j = await r.json();
        if (j.rooms) { state = j; render(); }
        if (j.error) toast(j.error, true);
        else { toast((rm ? rm.name + ' ' : '') + DONE[op]); if (j.all_ready && op === 'clean') toast('전객실 입실준비완료!'); }
      } catch (e) { toast('저장하지 못했습니다. 인터넷 연결을 확인하세요.', true); b.disabled = false; }
      busy = false;
      return;
    }
    const rl = ev.target.closest('[data-reload]');
    if (rl) {
      rl.classList.add('spin'); rl.disabled = true;
      const ok = await load();
      rl.classList.remove('spin'); rl.disabled = false;
      toast(ok ? '새로 고쳤습니다.' : '새로 고치지 못했습니다. 인터넷 연결을 확인하세요.', !ok);
      return;
    }
    const day = ev.target.closest('[data-day]');
    if (day) { const x = dt(state.date); x.setDate(x.getDate() + Number(day.dataset.day)); go(ymd(x)); }
    if (ev.target.closest('[data-today]')) go(C.today);
  });
  function go(date) { history.replaceState(null, '', C.self + (date === C.today ? '' : '?date=' + date)); state.date = date; load(date); }

  setInterval(() => { if (!document.hidden && !busy) load(); }, 15000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });

  /* ---------- 알림 (Web Push) ---------- */
  const pushBtn = $('[data-push]');
  const notice = $('[data-push-notice]');
  const isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  const b64 = (s) => { s = s.replace(/-/g, '+').replace(/_/g, '/'); const raw = atob(s + '='.repeat((4 - s.length % 4) % 4)); return Uint8Array.from(raw, (c) => c.charCodeAt(0)); };
  let reg = null;

  function say(html, button) {
    notice.innerHTML = html + (button ? '<br><button type="button" data-push-on>' + button + '</button>' : '');
    notice.hidden = false;
  }
  async function post(act, endpoint) {
    const fd = new FormData(); fd.append('act', act); fd.append('endpoint', endpoint); fd.append('_csrf', C.csrf);
    const r = await fetch(C.api, { method: 'POST', body: fd, credentials: 'same-origin' });
    return r.json();
  }
  async function pushState() {
    if (!window.isSecureContext) { pushBtn.classList.add('off'); return 'insecure'; }
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !C.vapid) { pushBtn.classList.add('off'); return isIOS && !standalone ? 'ios-install' : 'unsupported'; }
    reg = reg || await navigator.serviceWorker.register(C.sw, { scope: C.scope });
    await navigator.serviceWorker.ready;
    const sub = await reg.pushManager.getSubscription();
    if (sub && Notification.permission === 'granted') { pushBtn.classList.add('on'); post('subscribe', sub.endpoint); return 'on'; }
    pushBtn.classList.remove('on');
    return Notification.permission === 'denied' ? 'denied' : 'off';
  }
  const MSG = {
    insecure: '알림은 <b>https://</b> 주소로 접속해야 켤 수 있습니다. 사이트 주소를 https 로 열어 주세요.',
    'ios-install': '<b>아이폰 알림 켜기</b>: Safari 아래 <b>공유(□↑) → 홈 화면에 추가</b>를 누른 뒤, 홈 화면의 <b>청소관리</b> 앱을 열고 🔔 를 누르세요. (iOS 16.4 이상)',
    unsupported: '이 브라우저는 알림을 지원하지 않습니다. 안드로이드는 Chrome, 아이폰은 홈 화면에 추가한 앱에서 사용하세요.',
    denied: '알림이 <b>차단</b>되어 있습니다. 휴대폰 설정 › 알림(또는 브라우저 사이트 설정)에서 이 사이트 알림을 허용한 뒤 다시 누르세요.',
    off: '<b>알림이 꺼져 있습니다.</b> 퇴실·청소완료·전객실 입실준비완료 알림을 받으려면 켜 주세요.',
  };
  async function turnOn() {
    try {
      const st = await pushState();
      if (st === 'on') return toast('알림이 이미 켜져 있습니다.');
      if (st !== 'off') return say(MSG[st]);
      const perm = await Notification.requestPermission();
      if (perm !== 'granted') return say(MSG.denied);
      const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(C.vapid) });
      const j = await post('subscribe', sub.endpoint);
      if (j.error) return toast(j.error, true);
      pushBtn.classList.add('on'); notice.hidden = true;
      toast('알림을 켰습니다. 다른 사람이 퇴실·청소완료하면 알림이 옵니다.');
      onPanel();
    } catch (e) { toast('알림을 켜지 못했습니다: ' + e.message, true); }
  }
  // 알림 테스트: 서버가 이 기기로 보내 보고 알림 서버(구글·애플) 응답을 보여 준다
  const isSamsung = /SamsungBrowser/i.test(navigator.userAgent);
  const androidHelp = '휴대폰 <b>설정 › 애플리케이션 › ' + (isSamsung ? '삼성 인터넷' : 'Chrome') + ' › 알림</b>이 허용인지, '
    + '<b>배터리 › 백그라운드 사용 제한(절전)</b>에 들어가 있지 않은지, <b>방해금지 모드</b>가 아닌지 확인하세요.';
  async function pushTest(again) {
    const sub = reg && await reg.pushManager.getSubscription();
    if (!sub) return say(MSG.off, '🔔 알림 켜기');
    say('알림 테스트 중…');
    let j;
    try { j = await post('test', sub.endpoint); } catch (e) { return say('서버에 연결하지 못했습니다. 인터넷 연결을 확인하세요.'); }
    const code = j.code;
    if (j.resub || code === 403 || code === 401 || code === 404 || code === 410) { // 등록이 없거나 만료·키 불일치 → 다시 등록 후 한 번 더
      if (!again) {
        try { await sub.unsubscribe(); } catch (e) {}
        try {
          const ns = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(C.vapid) });
          await post('subscribe', ns.endpoint);
          return pushTest(true);
        } catch (e) { return say('알림을 다시 등록하지 못했습니다: ' + esc(e.message)); }
      }
      return say('알림 서버가 거절했습니다 (응답 ' + esc(code || '') + ' ' + esc(j.err || j.error || '') + '). 🔔 로 알림을 껐다가 다시 켜 보세요.');
    }
    if (code >= 200 && code < 300) {
      return say('<b>서버 → 알림 서버 전송 정상</b> (응답 ' + code + '). 몇 초 안에 <b>\'알림 테스트\'</b> 알림이 와야 합니다.<br>'
        + '알림이 오지 않으면 휴대폰 설정 문제입니다: ' + (isIOS ? '설정 › 알림 › 청소관리 가 허용인지 확인하세요.' : androidHelp), '다시 테스트');
    }
    return say('<b>서버가 알림 서버에 보내지 못했습니다</b> (응답 ' + esc(code) + ': ' + esc(j.err || '') + ').<br>휴대폰 문제가 아니라 서버 쪽 문제입니다. 이 화면을 캡처해 개발 담당자에게 보내 주세요.', '다시 테스트');
  }
  function onPanel() {
    notice.innerHTML = '<b>알림이 켜져 있습니다.</b><br><button type="button" data-push-test>알림 테스트</button> <button type="button" data-push-off class="ghost">알림 끄기</button>';
    notice.hidden = false;
  }
  async function turnOff() {
    if (!confirm('이 기기의 알림을 끌까요?')) return;
    const sub = reg && await reg.pushManager.getSubscription();
    if (sub) { await post('unsubscribe', sub.endpoint); await sub.unsubscribe(); }
    pushBtn.classList.remove('on'); toast('알림을 껐습니다.');
  }
  pushBtn.addEventListener('click', () => (pushBtn.classList.contains('on') ? onPanel() : turnOn()));
  notice.addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.hasAttribute('data-push-off')) { turnOff().then(() => { notice.hidden = true; }); return; }
    if (b.hasAttribute('data-push-test') || pushBtn.classList.contains('on')) { pushTest(false); return; }
    turnOn();
  });
  pushState().then((st) => { if (st !== 'on') say(MSG[st], st === 'off' ? '🔔 알림 켜기' : ''); }).catch(() => {});
  if ('serviceWorker' in navigator) navigator.serviceWorker.addEventListener('message', (e) => { if (e.data && e.data.type === 'clean-refresh') load(); });

  render();
})();
