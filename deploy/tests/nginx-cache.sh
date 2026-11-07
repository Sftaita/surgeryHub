#!/usr/bin/env bash
# D-163 — règles de cache réelles de docker/nginx/prod.conf, sur un vrai nginx (conteneur
# jetable, faux frontend, requêtes depuis l'intérieur du conteneur). Image : NGINX_TEST_IMAGE
# (défaut nginx:alpine ; toute image nginx contenant curl).
set -uo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
IMAGE="${NGINX_TEST_IMAGE:-nginx:alpine}"
CONF="$ROOT/docker/nginx/prod.conf"
command -v cygpath >/dev/null 2>&1 && CONF=$(cygpath -w "$CONF")

MSYS_NO_PATHCONV=1 docker run --rm --add-host php:127.0.0.1 -v "$CONF:/etc/nginx/conf.d/default.conf:ro" --entrypoint sh "$IMAGE" -c '
set -u
mkdir -p /var/www/frontend/assets /var/www/frontend/icons /var/www/backend/public
echo "<!doctype html><title>SurgicalHub</title>" > /var/www/frontend/index.html
echo "console.log(1)" > /var/www/frontend/assets/index-Ab12Cd34.js
echo "self.addEventListener(\"push\",()=>{})" > /var/www/frontend/sw.js
echo "{}" > /var/www/frontend/manifest.json
echo png > /var/www/frontend/icons/icon-192.png
nginx -t 2>&1 | tail -1 || exit 1
nginx
PASS=0; FAIL=0
h() { curl -s -o /dev/null -D - -H "Host: surgicalhub.be" "http://127.0.0.1$1"; }
expect() { # expect <label> <path> <code> <cache-control attendu ou -> [content-type attendu]
  local out code cc ct
  out=$(h "$2"); code=$(echo "$out" | head -1 | cut -d" " -f2)
  cc=$(echo "$out" | grep -i "^cache-control:" | cut -d" " -f2- | tr -d "\r")
  ct=$(echo "$out" | grep -i "^content-type:" | cut -d" " -f2- | tr -d "\r")
  if [ "$code" = "$3" ] && { [ "$4" = - ] || [ "$cc" = "$4" ]; } && { [ -z "${5:-}" ] || [ "$ct" = "$5" ]; }; then
    PASS=$((PASS+1)); echo "  ok   $1 ($code, ${cc:-pas de Cache-Control})"
  else
    FAIL=$((FAIL+1)); echo "  FAIL $1 : $code / ${cc:-—} / ${ct:-—}"
  fi
}
expect "index.html revalidé à chaque chargement" / 200 "no-cache" "text/html"
expect "index.html (chemin direct)" /index.html 200 "no-cache"
expect "route SPA → index.html revalidé" /app/s/profile 200 "no-cache" "text/html"
expect "asset hashé : cache long immuable" /assets/index-Ab12Cd34.js 200 "public, max-age=31536000, immutable"
expect "asset disparu (ancien bundle) : 404 jamais mis en cache, jamais du HTML" /assets/index-Ancien00.js 404 ""
expect "Service Worker toujours revalidé" /sw.js 200 "no-cache"
expect "manifest PWA revalidé" /manifest.json 200 "no-cache"
expect "icône revalidée" /icons/icon-192.png 200 "no-cache"
echo "Résultat : $PASS réussis, $FAIL échoués"
[ "$FAIL" -eq 0 ]'
