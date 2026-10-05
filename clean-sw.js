/*
 * 객실 청소관리 앱 알림 (Service Worker)
 * 서버가 보내는 알림에는 내용이 없다 → 받으면 clean_api.php 에서 이 기기에 보여 줄 알림을 읽어 띄운다.
 * 페이지 요청은 가로채지 않는다(fetch 처리 없음) — 사이트 동작에 영향 없음.
 */
const API = new URL('clean_api.php?act=sw_events', self.location).href;
const APP = new URL('clean.php', self.location).href;
const ICON = new URL('assets/app/clean-192.png', self.location).href;

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('push', (e) => {
  e.waitUntil((async () => {
    let events = [];
    try {
      const sub = await self.registration.pushManager.getSubscription();
      const r = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: sub ? sub.endpoint : '' }) });
      events = (await r.json()).events || [];
    } catch (err) {}
    if (!events.length) events = [{ id: 0, title: '객실 청소관리', body: '새 알림이 있습니다.' }];
    for (const ev of events) {
      await self.registration.showNotification(ev.title, {
        body: ev.body, tag: 'clean-' + ev.id, icon: ICON, badge: ICON, data: { url: APP },
        requireInteraction: ev.kind === 'all', vibrate: ev.kind === 'all' ? [200, 100, 200, 100, 400] : [200],
      });
    }
    // 열려 있는 앱 화면은 바로 새로 고침
    (await self.clients.matchAll({ type: 'window' })).forEach((c) => c.postMessage({ type: 'clean-refresh' }));
  })());
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  e.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    const w = wins.find((c) => c.url.indexOf(APP) === 0);
    if (w) return w.focus();
    return self.clients.openWindow(APP);
  })());
});
