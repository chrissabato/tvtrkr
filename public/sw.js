const CACHE_VERSION = 'tvtrkr-v14';

const APP_SHELL = [
  '/',
  '/index.html',
  '/manifest.json',
  '/css/style.css',
  '/js/app.js',
  '/js/api.js',
  '/js/views/login.js',
  '/js/views/discover.js',
  '/js/views/show-detail.js',
  '/js/views/calendar.js',
  '/js/views/watchlist.js',
  '/js/views/users.js',
  '/js/views/whattowatch.js',
  '/js/views/people.js',
  '/js/views/profile.js',
  '/img/icon.svg',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION).then((cache) => cache.addAll(APP_SHELL))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((key) => key !== CACHE_VERSION).map((key) => caches.delete(key)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  if (event.request.method !== 'GET' || url.origin !== self.location.origin) {
    return;
  }

  // API + TMDB proxy data: network-first so watch status / calendar stay fresh,
  // falling back to cache when offline.
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(
      fetch(event.request)
        .then((response) => {
          const copy = response.clone();
          caches.open(CACHE_VERSION).then((cache) => cache.put(event.request, copy));
          return response;
        })
        .catch(() => caches.match(event.request))
    );
    return;
  }

  // App shell + static assets: cache-first for speed and offline support.
  event.respondWith(
    caches.match(event.request).then((cached) => {
      return (
        cached ||
        fetch(event.request).then((response) => {
          const copy = response.clone();
          caches.open(CACHE_VERSION).then((cache) => cache.put(event.request, copy));
          return response;
        })
      );
    })
  );
});
