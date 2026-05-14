const CACHE = 'task-tracker-v4';
const PRECACHE = [
  './',
  './index.html',
  './login.html',
  './dashboard.html',
  './setting.html',
  './manifest.json',
  './icons/icon-192.png',
  './icons/icon-512.png',
  'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.5/main.min.css',
  'https://cdn.jsdelivr.net/npm/fullcalendar@5.11.5/main.min.js',
  'https://cdn.jsdelivr.net/npm/sortablejs@1.14.0/Sortable.min.js',
];

self.addEventListener('install', e => {
  e.waitUntil(caches.open(CACHE).then(c => c.addAll(PRECACHE)));
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', e => {
  // PHP (API・認証) は常にネットワーク
  if (e.request.url.includes('.php')) {
    e.respondWith(fetch(e.request));
    return;
  }
  // それ以外: キャッシュ優先、なければネットワーク取得してキャッシュ
  e.respondWith(
    caches.match(e.request).then(cached =>
      cached || fetch(e.request).then(res => {
        if (res.ok) {
          const clone = res.clone();
          caches.open(CACHE).then(c => c.put(e.request, clone));
        }
        return res;
      })
    )
  );
});
