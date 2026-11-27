const CACHE = 'uvoria-v121';
const RELEASE = 'dev';
const RELEASE_CACHE = CACHE + '-' + RELEASE;
const APP_SHELL = [
  './',
  './index.html',
  './login.html',
  './offline.html',
  './privacy-policy.html',
  './personal-data-consent.html',
  './legal.css',
  './styles.css',
  './sidebar.css',
  './login.css',
  './pwa.css',
  './app.js',
  './question-builder.js',
  './login.js',
  './attempt-security.js',
  './pwa.js',
  './manifest.webmanifest',
  './favicon.png?v=4',
  './icons/urovia-mark.svg?v=1',
  './icons/urovia-mark-mono.svg?v=1',
  './icons/uvoria-app.svg',
  './icons/uvoria-maskable.svg',
  './icons/icon-180.png?v=4',
  './icons/icon-192.png?v=4',
  './icons/icon-512.png?v=4',
  './icons/icon-maskable-512.png?v=4'
];

self.addEventListener('install', event => {
  event.waitUntil(caches.open(RELEASE_CACHE).then(cache => cache.addAll(APP_SHELL)));
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(keys.filter(key => key !== RELEASE_CACHE).map(key => caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('message', event => {
  if (event.data?.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin || url.pathname.includes('/api/')) {
    return;
  }

  if (url.pathname.endsWith('/version.json')) {event.respondWith(fetch(request,{cache:'no-store'}));return;}
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request, { cache: 'no-store' })
        .then(response => response)
        .catch(async () => {
          const offline = await caches.match('./offline.html');
          return offline || Response.error();
        })
    );
    return;
  }

  event.respondWith(
    fetch(request)
      .then(response => {
        if (response.ok) {
          const copy = response.clone();
          caches.open(RELEASE_CACHE).then(cache => cache.put(request, copy));
        }
        return response;
      })
      .catch(() => caches.match(request))
  );
});
