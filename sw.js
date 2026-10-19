// ═══════════════════════════════════════════════════
// sw.js — Service Worker SonkeMap
// Déposer dans : /calcul_itineraire/sw.js
// ═══════════════════════════════════════════════════

const CACHE_PREFIX = 'sonkemap-stable-';
const CACHE_NAME = CACHE_PREFIX + 'v1';

// Fichiers à mettre en cache pour le mode hors-ligne
const CACHE_URLS = [
    './index.html',
    'https://cdn.jsdelivr.net/npm/ol@v10.8.0/dist/ol.js',
    'https://cdn.jsdelivr.net/npm/ol@v10.8.0/ol.css',
    'https://code.jquery.com/jquery-3.7.1.min.js',
    'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap',
    'https://cdn-icons-png.flaticon.com/128/854/854878.png',
    'https://cdn-icons-png.flaticon.com/512/854/854878.png',
];

// ── Installation : mettre en cache les ressources essentielles ──
self.addEventListener('install', function(event) {
    event.waitUntil(
        caches.open(CACHE_NAME).then(function(cache) {
            console.log('SonkeMap SW: mise en cache des ressources');
            // Cache les ressources locales, ignore les erreurs réseau
            return Promise.allSettled(
                CACHE_URLS.map(url =>
                    cache.add(url).catch(e => console.warn('Cache failed:', url, e))
                )
            );
        }).then(function() {
            return self.skipWaiting(); // Activer immédiatement
        })
    );
});

// ── Activation : nettoyer les anciens caches ──
self.addEventListener('activate', function(event) {
    event.waitUntil(
        caches.keys().then(function(keys) {
            return Promise.all(
                keys
                    .filter(key => key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME)
                    .map(key => caches.delete(key))
            );
        }).then(function() {
            return self.clients.claim();
        })
    );
});

// ── Fetch : stratégie Network First avec fallback cache ──
self.addEventListener('fetch', function(event) {
    var url = event.request.url;

    // Ignorer les requêtes non-GET et les APIs tierces (ORS, Nominatim, Overpass)
    if (event.request.method !== 'GET') return;
    if (url.includes('openrouteservice.org')) return;
    if (url.includes('nominatim.openstreetmap.org')) return;
    if (url.includes('overpass-api.de')) return;
    if (url.includes('mt1.google.com')) return;
    if (url.includes('tile.openstreetmap.org')) return;

    event.respondWith(
        fetch(event.request)
            .then(function(response) {
                // Mettre en cache si la réponse est valide
                if (response && response.status === 200 && response.type !== 'opaque') {
                    var responseClone = response.clone();
                    caches.open(CACHE_NAME).then(function(cache) {
                        cache.put(event.request, responseClone);
                    });
                }
                return response;
            })
            .catch(function() {
                // Mode hors-ligne : chercher dans le cache
                return caches.match(event.request).then(function(cached) {
                    if (cached) return cached;
                    // Fallback pour la page principale
                    if (event.request.mode === 'navigate') {
                        return caches.match('./index.html');
                    }
                });
            })
    );
});
