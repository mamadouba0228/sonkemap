#!/usr/bin/env bash
# build-pages.sh — génère la version statique pour GitHub Pages
# Usage : scripts/build-pages.sh <stable|demo> <dossier_sortie> <url_pages>
# GitHub Pages n'exécute pas PHP : les appels API sont redirigés vers le backend Alwaysdata.

set -euo pipefail

CHANNEL="${1:?canal requis : stable ou demo}"
OUT="${2:?dossier de sortie requis}"
PAGES_URL="${3:?URL GitHub Pages requise}"
BACKEND="${BACKEND_URL:-https://lamadgeo.alwaysdata.net/calcul_itineraire}"
SRC="calcul_itineraire"

case "$CHANNEL" in stable|demo) ;; *) echo "Canal inconnu : $CHANNEL" >&2; exit 1 ;; esac

rm -rf "$OUT"
mkdir -p "$OUT"

# ── Page principale ──
sed \
  -e "s|'/calcul_itineraire/\([a-z_]*\.php\)'|'$BACKEND/\1'|g" \
  -e "s|href=\"/calcul_itineraire/manifest.json\"|href=\"manifest.json\"|" \
  -e "s|register('/calcul_itineraire/sw.js')|register('sw.js')|" \
  -e "s|const APP_BASE_URL = '[^']*'|const APP_BASE_URL = '$PAGES_URL'|" \
  "$SRC/SonkeMap.html" > "$OUT/index.html"

# ── Manifest PWA ──
sed \
  -e 's|"/calcul_itineraire/SonkeMap.html"|"./"|g' \
  -e 's|"/calcul_itineraire/"|"./"|g' \
  "$SRC/manifest.json" > "$OUT/manifest.json"

# ── Service worker : cache propre à chaque canal (stable et démo partagent la même origine) ──
sed \
  -e "s|const CACHE_NAME = 'sonkemap-v1';|const CACHE_PREFIX = 'sonkemap-$CHANNEL-';\nconst CACHE_NAME = CACHE_PREFIX + 'v1';|" \
  -e "s|.filter(key => key !== CACHE_NAME)|.filter(key => key.startsWith(CACHE_PREFIX) \&\& key !== CACHE_NAME)|" \
  -e "s|'/calcul_itineraire/SonkeMap.html'|'./index.html'|g" \
  -e "s|url.includes('SonkeMap.html')|event.request.mode === 'navigate'|" \
  "$SRC/sw.js" > "$OUT/sw.js"

# ── Spécificités de la démo ──
if [ "$CHANNEL" = "demo" ]; then
  sed -i \
    -e 's|"name": "\([^"]*\)"|"name": "\1 (Démo)"|' \
    -e 's|"short_name": "SonkeMap"|"short_name": "SonkeMap Démo"|' \
    "$OUT/manifest.json"
  sed -i \
    -e 's|<title>\([^<]*\)</title>|<title>[DÉMO] \1</title>|' \
    -e 's|</body>|<div style="position:fixed;top:0;left:50%;transform:translateX(-50%);z-index:99999;background:#FF9500;color:#fff;font:600 11px/1 sans-serif;padding:4px 10px;border-radius:0 0 6px 6px;pointer-events:none">VERSION DÉMO</div>\n</body>|' \
    "$OUT/index.html"
fi

touch "$OUT/.nojekyll"

# Vérifie qu'aucun chemin serveur absolu ne subsiste
if grep -n "[\"'(]/calcul_itineraire/" "$OUT/index.html" "$OUT/manifest.json" "$OUT/sw.js"; then
  echo "Erreur : chemins /calcul_itineraire/ restants (non disponibles sur GitHub Pages)" >&2
  exit 1
fi
echo "Build $CHANNEL -> $OUT"
