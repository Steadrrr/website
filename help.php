<?php
/**
 * 기타 › 도움말: 왼쪽 목차(트리) + 검색, 오른쪽 기능별 설명과 화면 캡처 (assets/help/*.jpg).
 * help.php#항목id 로 바로 간다. 각 페이지의 '?' 버튼이 그 화면의 항목으로 연결한다. 내용은 app/help.php.
 */
require __DIR__ . '/app/bootstrap.php';

$user = require_login();
$tree = help_tree();
layout_header('도움말', 'help');
?>
<div class="help-layout">
  <aside class="help-toc no-print">
    <input type="search" class="help-search" placeholder="도움말 검색 (예: 전결, 환급, 연차)" data-help-search autocomplete="off">
    <p class="muted small help-count" data-help-count hidden></p>
    <nav data-help-tree>
      <?php foreach ($tree as $g): ?>
        <details open data-help-group="<?= e($g['id']) ?>">
          <summary><?= e($g['label']) ?></summary>
          <?php foreach ($g['items'] as $it): ?><a href="#<?= e($it['id']) ?>" data-help-link="<?= e($it['id']) ?>"><?= e($it['title']) ?></a><?php endforeach ?>
        </details>
      <?php endforeach ?>
    </nav>
  </aside>
  <article class="help-body" data-help-body>
    <div class="card help-intro">
      <h1>도움말</h1>
      <p class="muted">왼쪽 목차에서 항목을 고르거나 검색하세요. 각 화면 오른쪽 아래의 <span class="help-fab-sample">?</span> 버튼을 누르면 그 화면의 도움말로 바로 옵니다.</p>
      <p class="muted small" data-help-empty hidden>검색 결과가 없습니다. 다른 낱말로 찾아 보세요.</p>
    </div>
    <?php foreach ($tree as $g): ?>
      <section class="help-group" data-help-group-body="<?= e($g['id']) ?>">
        <h2 id="<?= e($g['id']) ?>"><?= e($g['label']) ?></h2>
        <?php foreach ($g['items'] as $it): ?>
          <section class="card help-sec" id="<?= e($it['id']) ?>" data-help-sec>
            <h3><?= e($it['title']) ?> <a class="help-perma no-print" href="#<?= e($it['id']) ?>" title="이 항목 주소">#</a></h3>
            <div class="help-text"><?= $it['body'] ?></div>
            <?php if ($it['img']): ?>
              <div class="help-shots">
                <?php foreach ($it['img'] as $file => $cap): if (!is_file(APP_ROOT . '/assets/help/' . $file)) continue; ?>
                  <figure><a href="<?= e(url('assets/help/' . $file)) ?>" target="_blank" title="크게 보기"><img src="<?= e(url('assets/help/' . $file)) ?>" alt="<?= e($cap) ?>" loading="lazy"></a>
                    <figcaption><?= e($cap) ?> <small class="muted">(예시 화면 — 누르면 크게)</small></figcaption></figure>
                <?php endforeach ?>
              </div>
            <?php endif ?>
          </section>
        <?php endforeach ?>
      </section>
    <?php endforeach ?>
  </article>
</div>
<script>
(function () {
  const input = document.querySelector('[data-help-search]');
  const secs = [...document.querySelectorAll('[data-help-sec]')];
  const texts = new Map(secs.map((s) => [s, s.querySelector('.help-text').innerHTML]));
  const titles = new Map(secs.map((s) => [s, s.querySelector('h3').firstChild.textContent]));
  const esc = (v) => v.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  function mark(el, q) { // 텍스트 노드 안의 낱말만 <mark> 로 감싼다
    const re = new RegExp(esc(q), 'gi');
    const walk = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
    const nodes = [];
    while (walk.nextNode()) if (re.test(walk.currentNode.nodeValue)) nodes.push(walk.currentNode);
    nodes.forEach((n) => {
      const span = document.createElement('span');
      span.innerHTML = n.nodeValue.replace(/[&<>]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[c])).replace(new RegExp(esc(q).replace(/&/g, '&amp;'), 'gi'), (m) => `<mark>${m}</mark>`);
      n.replaceWith(...span.childNodes);
    });
  }
  function search() {
    const q = input.value.trim();
    let n = 0;
    secs.forEach((s) => {
      const t = s.querySelector('.help-text');
      t.innerHTML = texts.get(s);
      s.querySelector('h3').firstChild.textContent = titles.get(s);
      const hit = !q || (titles.get(s) + ' ' + t.textContent).toLowerCase().includes(q.toLowerCase());
      s.hidden = !hit;
      document.querySelector(`[data-help-link="${s.id}"]`).hidden = !hit;
      if (hit && q) { mark(t, q); n++; }
    });
    document.querySelectorAll('[data-help-group-body]').forEach((g) => { g.hidden = ![...g.querySelectorAll('[data-help-sec]')].some((s) => !s.hidden); });
    document.querySelectorAll('[data-help-group]').forEach((g) => { g.hidden = ![...g.querySelectorAll('a')].some((a) => !a.hidden); if (q) g.open = true; });
    const cnt = document.querySelector('[data-help-count]');
    cnt.hidden = !q;
    cnt.textContent = `'${q}' ${n}개 항목`;
    document.querySelector('[data-help-empty]').hidden = !q || n > 0;
  }
  let timer = null;
  input.addEventListener('input', () => {
    search();
    clearTimeout(timer);
    timer = setTimeout(() => { // 입력을 멈추면 첫 결과로
      const first = input.value.trim() && secs.find((s) => !s.hidden);
      if (first) first.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }, 350);
  });
  // 목차에서 지금 보고 있는 항목 표시
  const links = new Map([...document.querySelectorAll('[data-help-link]')].map((a) => [a.dataset.helpLink, a]));
  const io = new IntersectionObserver((es) => es.forEach((e) => {
    if (!e.isIntersecting) return;
    links.forEach((a) => a.classList.remove('on'));
    const a = links.get(e.target.id);
    if (a) { a.classList.add('on'); a.scrollIntoView({ block: 'nearest' }); }
  }), { rootMargin: '-10% 0px -75% 0px' });
  secs.forEach((s) => io.observe(s));
  // #항목 으로 왔으면 그 항목 강조
  function flash() {
    const el = location.hash && document.getElementById(decodeURIComponent(location.hash.slice(1)));
    if (!el) return;
    if (el.hidden) { input.value = ''; search(); }
    el.scrollIntoView({ block: 'start' });
    el.classList.remove('flash-target'); void el.offsetWidth; el.classList.add('flash-target');
  }
  window.addEventListener('hashchange', flash);
  flash();
})();
</script>
<?php layout_footer();
