// 商品画像・アイコンだけを保存し、在庫・注文・管理画面は端末のキャッシュに残さない。
const CACHE = 'zaiko-v9-static-only';
self.addEventListener('install', e => e.waitUntil(self.skipWaiting()));
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(keys => Promise.all(
    keys.filter(k => k.startsWith('zaiko-') && k !== CACHE).map(k => caches.delete(k))
  )).then(() => self.clients.claim()));
});
self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET') return;
  const url = new URL(e.request.url);
  if (url.origin !== self.location.origin) return;
  const isAsset = url.pathname.startsWith('/static/');
  e.respondWith((async () => {
    try {
      const res = await fetch(e.request);
      if (isAsset && res.ok && !res.redirected && !/no-store|private/i.test(res.headers.get('Cache-Control') || '')) {
        const copy = res.clone();
        e.waitUntil(caches.open(CACHE).then(c => c.put(e.request, copy)).catch(() => {}));
      }
      return res;
    } catch (error) {
      if (isAsset) {
        const cached = await caches.match(e.request, {cacheName: CACHE});
        if (cached) return cached;
      }
      return new Response('通信できません。接続を確認してから、もう一度開いてください。', {
        status: 503, headers: {'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store'}
      });
    }
  })());
});
