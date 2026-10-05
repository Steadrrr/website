/* 객실 청소관리 웹앱 (clean.php) — 상태 그리기, 처리 버튼, 알림 켜기 */
(function () {
  const C = window.CLEAN;
  let state = C.state;
  let busy = false;
  const picked = new Set(); // 퇴실대기에서 체크한 객실 id
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
    return '<div class="cl-room ' + cls + (check && picked.has(r.id) ? ' picked' : '') + '"' + (check ? ' data-pick-card="' + r.id + '"' : '') + '>'
      + (check ? '<span class="cl-check"><input type="checkbox" data-pick value="' + r.id + '"' + (picked.has(r.id) ? ' checked' : '') + ' aria-label="' + esc(r.name) + ' 선택"></span>' : '')
      + '<div class="info"><span class="name">' + esc(r.name) + '</span><span class="type">' + esc(r.type) + '</span>'
      + (tag ? '<span class="cl-tag">' + tag + '</span>' : '') + (meta ? '<div class="meta">' + meta + '</div>' : '') + '</div>' + right + '</div>';
  }
  const btn = (id, op, label, cls) => '<button type="button" class="cl-act ' + cls + '" data-op="' + op + '" data-room="' + id + '">' + label + '</button>';
  const undo = (id, op, label) => '<button type="button" class="cl-undo" data-op="' + op + '" data-room="' + id + '">' + label + '</button>';

  function section(title, color, items, empty, extra) {
    return '<section class="cl-sec"><h2><span class="dot" style="background:' + color + '"></span>' + title + ' <small>' + items.length + '실</small>' + (extra || '') + '</h2>'
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

    const inTag = (r) => (r.in_today ? '오늘 입실' : '');
    const waitRooms = s.rooms.filter((r) => r.status === 'wait');
    const waitIds = new Set(waitRooms.map((r) => r.id));
    [...picked].forEach((id) => { if (!waitIds.has(id)) picked.delete(id); }); // 이미 퇴실처리된 객실은 선택에서 뺌
    const wait = waitRooms.map((r) => room(r, 'wait', btn(r.id, 'out', '퇴실처리', 'wait'), '', inTag(r), true));
    const allOn = waitRooms.length > 0 && picked.size === waitRooms.length;
    const dirty = s.rooms.filter((r) => r.status === 'dirty').map((r) => room(r, 'dirty',
      undo(r.id, 'undo_out', '퇴실취소') + btn(r.id, 'clean', '청소완료', 'dirty'), '퇴실 ' + esc(r.out_at) + ' ' + esc(r.out_by), inTag(r)));
    const ready = s.rooms.filter((r) => r.status === 'ready').map((r) => room(r, 'ready',
      undo(r.id, 'undo_clean', '되돌리기') + '<span class="cl-done">입실가능</span>', '청소 ' + esc(r.clean_at) + ' ' + esc(r.clean_by), inTag(r)));
    const stays = s.stays.map((r) => room(r, 'stay',
      r.supply_at ? undo(r.id, 'undo_supply', '취소') + '<span class="cl-done stay">지급완료</span>' : btn(r.id, 'supply', '비품지급', 'stay'),
      r.supply_at ? '비품 ' + esc(r.supply_at) + ' ' + esc(r.supply_by) : '청소 없음 · 비품만 지급', r.nights + '박'));
    const arr = s.arrivals.map((r) => room(r, 'arrive', '', '어젯밤 빈 객실 · 청소 없음', '오늘 입실'));
    $('[data-list]').innerHTML = section('퇴실대기', 'var(--wait)', wait, '퇴실을 기다리는 객실이 없습니다.',
      waitRooms.length > 1 ? '<button type="button" class="cl-pick-all" data-pick-all>' + (allOn ? '선택 해제' : '전체선택') + '</button>' : '')
      + section('청소가능', 'var(--dirty)', dirty, '청소할 객실이 없습니다.')
      + section('입실가능', 'var(--ready)', ready, '아직 청소를 마친 객실이 없습니다.')
      + section('연박 (비품지급)', 'var(--stay)', stays, '연박 객실이 없습니다.')
      + (arr.length ? section('빈 객실 입실예정', '#9aa79f', arr, '') : '');
    bulkBar();
  }

  // 체크한 퇴실대기 객실 → 아래 '선택 퇴실처리' 막대
  function bulkBar() {
    const bar = $('[data-bulk]');
    bar.hidden = picked.size === 0;
    bar.querySelector('[data-bulk-count]').textContent = picked.size;
    document.body.classList.toggle('cl-has-bulk', picked.size > 0);
    document.querySelectorAll('[data-pick-card]').forEach((c) => {
      const on = picked.has(Number(c.dataset.pickCard));
      c.classList.toggle('picked', on);
      c.querySelector('[data-pick]').checked = on;
    });
    const all = $('[data-pick-all]');
    if (all) all.textContent = picked.size === document.querySelectorAll('[data-pick-card]').length ? '선택 해제' : '전체선택';
  }
  async function bulkOut() {
    if (busy || !picked.size) return;
    const names = state.rooms.filter((r) => picked.has(r.id)).map((r) => r.name);
    if (!confirm(names.length + '실을 한 번에 퇴실처리할까요?\n' + names.join(', ') + '\n\n청소가능 상태가 되고 모든 사용자에게 알림이 갑니다.')) return;
    busy = true;
    const b = $('[data-bulk-out]'); b.disabled = true;
    const fd = new FormData();
    fd.append('act', 'bulk'); fd.append('date', state.date); fd.append('_csrf', C.csrf);
    picked.forEach((id) => fd.append('rooms[]', id));
    try {
      const r = await fetch(C.api, { method: 'POST', body: fd, credentials: 'same-origin' });
      const j = await r.json();
      if (j.rooms) { picked.clear(); state = j; render(); }
      if (j.error) toast((j.done ? j.done + '실 퇴실처리 · ' : '') + j.error, true);
      else toast(j.done + '실을 퇴실처리했습니다.');
    } catch (e) { toast('저장하지 못했습니다. 인터넷 연결을 확인하세요.', true); }
    b.disabled = false; busy = false;
  }

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
    if (ev.target.closest('[data-bulk-out]')) return bulkOut();
    if (ev.target.closest('[data-bulk-clear]')) { picked.clear(); return bulkBar(); }
    if (ev.target.closest('[data-pick-all]')) {
      const ids = [...document.querySelectorAll('[data-pick-card]')].map((c) => Number(c.dataset.pickCard));
      if (picked.size === ids.length) picked.clear(); else ids.forEach((id) => picked.add(id));
      return bulkBar();
    }
    const card = ev.target.closest('[data-pick-card]');
    if (card && !ev.target.closest('[data-op]')) { // 객실 칸 아무 곳이나 눌러도 체크
      const id = Number(card.dataset.pickCard);
      if (ev.target.matches('[data-pick]') ? !ev.target.checked : picked.has(id)) picked.delete(id); else picked.add(id);
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
