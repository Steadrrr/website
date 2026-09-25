// 탭 화면(shell.php): 메뉴로 연 페이지를 최대 10개 탭으로 띄우고, 하단 탭으로 오간다.
// 탭마다 iframe 을 살려 두므로 다른 탭을 봐도 입력하던 내용이 남는다.
(function () {
  const CFG = window.FORESTLOG_TABS;
  const frames = document.querySelector('[data-frames]');
  const bar = document.querySelector('[data-tabs]');
  const subbar = document.querySelector('[data-shell-subbar]');
  const KEY = 'forestlog.tabs.v1';
  let tabs = [];   // {id, url, title, used}
  let active = null;
  let seq = 1;

  const norm = (href) => { const u = new URL(href, location.href); return u.origin === location.origin ? u.pathname + u.search : null; };
  const frameOf = (t) => frames.querySelector(`iframe[data-id="${t.id}"]`);
  const dirty = (t) => { try { return !!frameOf(t).contentWindow.__formDirty; } catch (e) { return false; } };
  let closing = false; // 로그아웃·세션 만료로 탭을 모두 닫는 중이면 저장하지 않는다
  const endSession = () => {
    closing = true;
    try { sessionStorage.removeItem(KEY); } catch (e) {}
    frames.querySelectorAll('iframe').forEach((f) => { try { f.contentWindow.__formDirty = false; } catch (e) {} }); // 탭마다 또 묻지 않게
  };
  const save = () => { if (closing) return; try { sessionStorage.setItem(KEY, JSON.stringify({ uid: CFG.uid, tabs: tabs.map(({ url, title }) => ({ url, title })), active: tabs.indexOf(tabs.find((t) => t.id === active)) })); } catch (e) {} };

  function render() {
    bar.innerHTML = '';
    tabs.forEach((t) => {
      const b = document.createElement('div');
      b.className = 'tab' + (t.id === active ? ' on' : '') + (dirty(t) ? ' dirty' : '');
      b.title = t.title + (dirty(t) ? ' (입력 중)' : '');
      b.innerHTML = '<span class="tab-title"></span><button type="button" class="tab-x" aria-label="탭 닫기">×</button>';
      b.querySelector('.tab-title').textContent = t.title || '불러오는 중…';
      b.addEventListener('click', (ev) => { if (ev.target.closest('.tab-x')) closeTab(t); else activate(t); });
      b.addEventListener('auxclick', (ev) => { if (ev.button === 1) closeTab(t); }); // 휠 클릭으로 닫기
      bar.appendChild(b);
    });
    document.querySelector('[data-tab-count]').textContent = `${tabs.length}/${CFG.max}`;
    document.querySelector('[data-tabs-closeall]').disabled = tabs.length < 2;
    const t = tabs.find((x) => x.id === active);
    document.title = (t && t.title ? t.title : '업무일지') + CFG.site;
    syncMenu(t);
  }

  // 상단 메뉴·서브메뉴에서 지금 탭의 메뉴를 표시
  function syncMenu(t) {
    const links = [...document.querySelectorAll('.topbar .nav a[href]')];
    const cur = t ? t.url : '';
    const path = cur.split('?')[0];
    const hit = links.find((a) => norm(a.href) === cur) || links.find((a) => norm(a.href).split('?')[0] === path && !norm(a.href).includes('?'));
    links.forEach((a) => a.classList.toggle('on', a === hit));
    document.querySelectorAll('.topbar .nav-group, .topbar .nav > a.nav-main').forEach((g) => g.classList.toggle('on', !!hit && (g === hit || g.contains(hit))));
    const group = hit && hit.closest('.nav-group');
    subbar.innerHTML = '';
    subbar.hidden = !group;
    if (!group) return;
    const title = document.createElement('span');
    title.className = 'subbar-title';
    title.textContent = group.querySelector('.nav-main').firstChild.textContent.trim();
    subbar.appendChild(title);
    group.querySelectorAll('.nav-sub a').forEach((a) => { const c = a.cloneNode(true); c.classList.toggle('on', a === hit); subbar.appendChild(c); });
  }

  function activate(t) {
    active = t.id;
    t.used = Date.now();
    frames.querySelectorAll('iframe').forEach((f) => f.classList.toggle('on', f.dataset.id === String(t.id)));
    render();
    save();
  }

  function makeFrame(t) {
    const f = document.createElement('iframe');
    f.dataset.id = t.id;
    f.src = t.url;
    f.title = t.title || '페이지';
    f.addEventListener('load', () => {
      try {
        const w = f.contentWindow;
        if (/\/login\.php$/.test(w.location.pathname)) { endSession(); top.location.href = w.location.href; return; } // 세션 만료
        t.url = w.location.pathname + w.location.search;
        t.title = (w.document.title || '').replace(CFG.site, '').trim() || t.title;
        w.addEventListener('input', () => setTimeout(render, 0), true);
        w.addEventListener('submit', () => setTimeout(render, 0), true);
      } catch (e) {}
      render();
      save();
    });
    frames.appendChild(f);
    return f;
  }

  function open(url, { title = '' } = {}) {
    const same = tabs.find((t) => t.url === url.split('#')[0]);
    if (same) { activate(same); return; }
    if (tabs.length >= CFG.max) {
      const cand = tabs.filter((t) => !dirty(t)).sort((a, b) => (a.used || 0) - (b.used || 0))[0];
      if (!cand) { alert(`탭은 최대 ${CFG.max}개까지 열 수 있습니다.\n모든 탭에서 입력 중이라 닫을 수 있는 탭이 없습니다. 저장하거나 탭을 하나 닫은 뒤 다시 여세요.`); return; }
      if (!confirm(`탭은 최대 ${CFG.max}개까지 열 수 있습니다.\n가장 오래 안 본 '${cand.title}' 탭을 닫고 새로 열까요?`)) return;
      removeTab(cand);
    }
    const t = { id: seq++, url, title, used: Date.now() };
    tabs.push(t);
    makeFrame(t);
    activate(t);
  }

  function removeTab(t) {
    const f = frameOf(t);
    if (f) f.remove();
    tabs = tabs.filter((x) => x !== t);
  }

  function closeTab(t) {
    if (dirty(t) && !confirm(`'${t.title}' 탭에 입력 중인 내용이 있습니다. 닫으면 사라집니다. 닫을까요?`)) return;
    const i = tabs.indexOf(t);
    removeTab(t);
    if (!tabs.length) { open(norm(CFG.home)); return; }
    if (active === t.id) activate(tabs[Math.min(i, tabs.length - 1)]);
    else { render(); save(); }
  }

  // 상단 메뉴·서브메뉴·로고를 누르면 탭으로 열기 (Ctrl·Shift·휠 클릭은 브라우저 기본 = 새 창)
  document.addEventListener('click', (ev) => {
    const a = ev.target.closest('.topbar a[href], [data-shell-subbar] a[href]');
    if (!a || ev.defaultPrevented || ev.button !== 0 || ev.ctrlKey || ev.metaKey || ev.shiftKey || a.target) return;
    const url = norm(a.href);
    if (url && /\/logout\.php$/.test(url.split('?')[0])) { // 로그아웃: 탭 모두 닫기
      if (tabs.some(dirty) && !confirm('입력 중인 탭이 있습니다. 로그아웃하면 모든 탭이 닫히고 입력한 내용이 사라집니다. 로그아웃할까요?')) { ev.preventDefault(); return; }
      endSession();
      return;
    }
    if (!url || /\/shell\.php$/.test(url.split('?')[0])) return;
    ev.preventDefault();
    document.body.classList.remove('nav-open');
    document.querySelectorAll('.nav-group.open').forEach((g) => g.classList.remove('open'));
    open(url, { title: a.textContent.trim() });
  });

  // 모든 탭 닫기: 지금 보는 탭만 남긴다 (입력 중인 탭이 있으면 확인)
  document.querySelector('[data-tabs-closeall]').addEventListener('click', () => {
    const others = tabs.filter((t) => t.id !== active);
    if (!others.length) return;
    const busy = others.filter(dirty);
    if (busy.length && !confirm(`입력 중인 탭이 ${busy.length}개 있습니다 (${busy.map((t) => t.title).join(', ')}).\n닫으면 입력한 내용이 사라집니다. 지금 탭만 남기고 모두 닫을까요?`)) return;
    others.forEach(removeTab);
    render();
    save();
  });

  // 각 페이지의 '?' 버튼: 도움말 탭을 열고(이미 있으면 그 탭) 해당 항목으로
  CFG.openHelp = (href) => {
    const u = new URL(href, location.href);
    const url = u.pathname + u.search;
    const t = tabs.find((x) => x.url === url);
    if (t) {
      activate(t);
      try { frameOf(t).contentWindow.location.hash = u.hash; } catch (e) {}
      return;
    }
    open(url + u.hash, { title: '도움말' });
  };

  document.querySelector('[data-tabs-off]').addEventListener('click', () => {
    const t = tabs.find((x) => x.id === active);
    if (tabs.some(dirty) && !confirm('입력 중인 탭이 있습니다. 탭을 끄면 지금 보는 페이지만 남고 나머지 탭은 닫힙니다. 계속할까요?')) return;
    try { localStorage.setItem('forestlog.tabs', '0'); sessionStorage.removeItem(KEY); } catch (e) {}
    location.href = t ? t.url : CFG.home;
  });

  // 창을 닫거나 새로고침할 때 입력 중인 탭이 있으면 확인
  window.addEventListener('beforeunload', (ev) => { if (!closing && tabs.some(dirty)) { ev.preventDefault(); ev.returnValue = ''; } });

  // 시작: 이전 탭 복원 + 주소 # 뒤의 페이지 열기
  let saved = null;
  try { saved = JSON.parse(sessionStorage.getItem(KEY) || 'null'); } catch (e) {}
  if (saved && saved.uid !== CFG.uid) saved = null; // 다른 계정이 열어 둔 탭은 복원하지 않는다
  if (saved && Array.isArray(saved.tabs)) {
    saved.tabs.slice(0, CFG.max).forEach((s) => { if (typeof s.url === 'string' && s.url.startsWith('/')) { const t = { id: seq++, url: s.url, title: s.title || '', used: 0 }; tabs.push(t); makeFrame(t); } });
    if (tabs[saved.active]) activate(tabs[saved.active]);
  }
  const want = decodeURIComponent(location.hash.slice(1));
  history.replaceState(null, '', location.pathname);
  const wantUrl = want.startsWith('/') && !want.startsWith('//') ? norm(want) : null;
  if (wantUrl && !/\/shell\.php$/.test(wantUrl.split('?')[0])) open(wantUrl);
  else if (!tabs.length) open(norm(CFG.home));
  else if (active === null) activate(tabs[0]);
})();
