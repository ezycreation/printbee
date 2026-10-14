// Occasion service worker. Lives at <folder>/sw.js and only ever handles requests inside its own folder.
const CACHE_PREFIX = 'occasion-';
const CACHE_VERSION = 'occasion-v56';
const SCOPE_URL = new URL(self.registration.scope);   // e.g. https://host/occasion/
const APP_SHELL = [
  './',
  './index.html',
  './styles.css?v=56',
  './script.js?v=56',
  './manifest.json',
  './lib/html2canvas.min.js',
  './lib/jspdf.umd.min.js',
  './icons/icon-192.png',
  './icons/icon-512.png',
  './icons/icon-512-maskable.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_VERSION)
      .then((cache) => cache.addAll(APP_SHELL))
      .catch(() => {}) // never block install on one missing asset
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        // Only ever delete OUR old caches — other apps on this origin keep theirs.
        keys.filter((k) => k.startsWith(CACHE_PREFIX) && k !== CACHE_VERSION).map((k) => caches.delete(k))
      ))
      .catch(() => {})
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if(req.method !== 'GET') return;

  const url = new URL(req.url);
  // Ignore anything outside this app's folder (other apps, other origins).
  if(url.origin !== SCOPE_URL.origin || !url.pathname.startsWith(SCOPE_URL.pathname)) return;
  // Server endpoints (hymn lookup, access check, admin) are never cached here.
  if(url.pathname.startsWith(SCOPE_URL.pathname + 'api/')) return;

  // Network-first, bypassing the HTTP cache; fall back to our cache only when offline.
  event.respondWith(
    fetch(req, {cache: 'reload'}).then((res) => {
      const resClone = res.clone();
      caches.open(CACHE_VERSION).then((cache) => cache.put(req, resClone)).catch(() => {});
      return res;
    }).catch(() => caches.match(req).catch(() => undefined))
  );
});
