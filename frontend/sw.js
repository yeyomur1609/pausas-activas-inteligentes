/**
 * Service Worker PWA — Pausas Activas Inteligentes
 * - Caché de assets estáticos (CSS, JS, SVG, manifiesto)
 * - Estrategia Network-First para vistas HTML (excluyendo /api/ y peticiones *.php)
 */

const CACHE_NAME = 'pai-static-v39';
const STATIC_ASSETS = [
  './',
  './index.html',
  './login.html',
  './parques.html',
  './rutinas.html',
  './chatbot.html',
  './ajustes.html',
  './legal.html',
  './404.html',
  './assets/css/variables.css',
  './assets/css/app.css',
  './assets/css/accessibility.css',
  './assets/fonts/fraunces-latin-600.woff2',
  './assets/fonts/fraunces-latin-ext-600.woff2',
  './assets/fonts/fraunces-latin-700.woff2',
  './assets/fonts/fraunces-latin-ext-700.woff2',
  './assets/fonts/atkinson-hyperlegible-latin-400.woff2',
  './assets/fonts/atkinson-hyperlegible-latin-ext-400.woff2',
  './assets/fonts/atkinson-hyperlegible-latin-700.woff2',
  './assets/fonts/atkinson-hyperlegible-latin-ext-700.woff2',
  './assets/fonts/ibm-plex-mono-latin-500.woff2',
  './assets/fonts/ibm-plex-mono-latin-ext-500.woff2',
  './assets/fonts/ibm-plex-mono-latin-700.woff2',
  './assets/fonts/ibm-plex-mono-latin-ext-700.woff2',
  './assets/js/vendor/lottie_light.min.js',
  './assets/js/modules/config.js',
  './assets/js/modules/api-client.js',
  './assets/js/modules/ui.js',
  './assets/js/modules/auth.js',
  './assets/js/modules/layout.js',
  './assets/js/modules/inicio.js',
  './assets/js/modules/lottie-ejercicios.js',
  './assets/js/modules/rutinas.js',
  './assets/js/modules/parques.js',
  './assets/js/modules/chatbot.js',
  './assets/js/modules/ajustes.js',
  './assets/animations/regla-20-20-20-parpadeo.json',
  './assets/animations/liberacion-lateral-cuello.json',
  './assets/animations/circulos-escapulares-apertura.json',
  './assets/animations/descompresion-munecas.json',
  './assets/animations/torsion-columna-toracica.json',
  './assets/animations/coordinacion-cruzada-enfoque.json',
  './assets/animations/respiracion-diafragmatica.json',
  './assets/animations/marcha-activacion-parque.json',
  './assets/animations/apertura-pectoral-aire-libre.json',
  './assets/animations/movilidad-cadera-tobillos.json',
  './assets/animations/estiramiento-lateral-cadena.json',
  './assets/animations/pausa-sensorial-cierre.json',
  './assets/animations/gato-vaca-columna.json',
  './assets/animations/empuje-dinamico-banca.json',
  './assets/animations/respiracion-cuadrada-enfoque.json',
  './assets/animations/default.json',
  './public/logo.svg',
  './public/favicon.svg',
  './public/topo-pattern.svg',
  './public/icon-192.png',
  './public/icon-512.png',
  './public/og-cover.png',
  './public/manifest.webmanifest'
];

const CLEAN_ROUTE_FALLBACKS = {
  login: './login.html',
  parques: './parques.html',
  rutinas: './rutinas.html',
  chatbot: './chatbot.html',
  ajustes: './ajustes.html',
  legal: './legal.html'
};

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(async (cache) => {
      await Promise.allSettled(
        STATIC_ASSETS.map((assetUrl) => cache.add(new Request(assetUrl, { cache: 'reload' })))
      );
      return self.skipWaiting();
    })
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key !== CACHE_NAME)
          .map((oldKey) => caches.delete(oldKey))
      )
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  // Nunca interceptar métodos distintos a GET ni peticiones con encabezado Authorization
  if (request.method !== 'GET' || request.headers.has('Authorization')) return;

  const url = new URL(request.url);

  // Nunca interceptar el dominio de Render ni orígenes externos
  if (url.origin !== self.location.origin || url.hostname.endsWith('.onrender.com')) return;
  // Nunca interceptar ni cachear rutas de API (/api, /api/*), health checks ni scripts PHP dinámicos
  if (
    url.pathname === '/api' ||
    url.pathname.startsWith('/api/') ||
    url.pathname.includes('/api/') ||
    url.pathname === '/health' ||
    url.pathname.endsWith('.php')
  ) {
    return;
  }

  const acceptHeader = request.headers.get('Accept') || '';
  const cleanSegment = url.pathname.replace(/\/+$/, '').split('/').pop() || '';
  const isCleanRoute = Object.prototype.hasOwnProperty.call(CLEAN_ROUTE_FALLBACKS, cleanSegment);

  const isHtmlNavigation =
    request.mode === 'navigate' ||
    acceptHeader.includes('text/html') ||
    url.pathname.endsWith('.html') ||
    url.pathname.endsWith('/') ||
    isCleanRoute;

  const isCodeAsset =
    url.pathname.endsWith('.js') ||
    url.pathname.endsWith('.mjs') ||
    url.pathname.endsWith('.css') ||
    url.pathname.endsWith('.json');

  // Estrategia Network-First para vistas HTML, JS y CSS (siempre muestra cambios al instante con respaldo offline)
  if (isHtmlNavigation || isCodeAsset) {
    event.respondWith(
      fetch(new Request(request, { cache: 'no-cache' }))
        .then((networkResponse) => {
          if (networkResponse && networkResponse.ok) {
            const copy = networkResponse.clone();
            event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.put(request, copy)));
          }
          return networkResponse;
        })
        .catch(async () => {
          const directMatch = await caches.match(request);
          if (directMatch) return directMatch;

          if (isHtmlNavigation) {
            const mappedHtml = CLEAN_ROUTE_FALLBACKS[cleanSegment];
            if (mappedHtml) {
              const mappedMatch = await caches.match(mappedHtml);
              if (mappedMatch) return mappedMatch;
            }

            return (
              (await caches.match('./index.html')) ||
              (await caches.match('./')) ||
              new Response('Offline', {
                status: 503,
                statusText: 'Service Unavailable',
                headers: { 'Content-Type': 'text/plain; charset=utf-8' }
              })
            );
          }

          return new Response('Offline', {
            status: 503,
            statusText: 'Service Unavailable',
            headers: { 'Content-Type': 'text/plain; charset=utf-8' }
          });
        })
    );
    return;
  }

  // Estrategia Cache-First con actualización en segundo plano para assets estáticos
  event.respondWith(
    caches.match(request).then((cachedResponse) => {
      const networkFetch = fetch(request)
        .then(async (networkResponse) => {
          if (networkResponse && networkResponse.ok) {
            const copy = networkResponse.clone();
            const cache = await caches.open(CACHE_NAME);
            await cache.put(request, copy);
          }
          return networkResponse;
        })
        .catch(() => cachedResponse || new Response('Offline', { status: 503, statusText: 'Service Unavailable' }));

      if (cachedResponse) {
        event.waitUntil(networkFetch);
        return cachedResponse;
      }

      return networkFetch;
    })
  );
});
