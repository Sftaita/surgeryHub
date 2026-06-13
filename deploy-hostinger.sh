#!/usr/bin/env bash
# =============================================================================
# SurgicalHub — deploy-hostinger.sh
#
# A executer SUR LE SERVEUR (via SSH), depuis la racine du repo déployé :
#
#   ssh -p 65002 u245913739@91.108.115.96
#   cd ~/domains/surgicalhub.be
#   git pull origin main
#   ./deploy-hostinger.sh
#
# Structure imposée par Hostinger (document root non modifiable) :
#
#   ~/domains/surgicalhub.be/
#   ├── backend/              Symfony complet — JAMAIS public
#   ├── frontend/             sources + dist/ (build local, uploadé)  — JAMAIS public
#   └── public_html/          docroot surgicalhub.be (Hostinger)
#       ├── index.html        copié depuis frontend/dist/
#       ├── assets/            "
#       └── api/               docroot api.surgicalhub.be (sous-dossier imposé)
#           ├── index.php      copié depuis backend/public/index.prod.php
#           ├── .htaccess      copié depuis backend/public/.htaccess
#           └── uploads/       SYMLINK -> ../../backend/public/uploads
#
# Ce script :
#  1. vérifie APP_ENV=prod / APP_DEBUG=0 dans backend/.env.prod.local
#  2. composer install --no-dev, migrations (avec confirmation), cache, permissions
#  3. synchronise backend/public/ -> public_html/api/ (sauf uploads/index.php)
#     + index.php adapté + symlink uploads
#  4. synchronise frontend/dist/ -> public_html/ (sauf api/)
#     + .htaccess SPA (sans interférer avec /api)
#
# Ne fait PAS le backup ni "npm run build" — voir docs/production-hostinger.md.
# =============================================================================

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BACKEND_DIR="$ROOT_DIR/backend"
FRONTEND_DIST="$ROOT_DIR/frontend/dist"
PUBLIC_HTML="$ROOT_DIR/public_html"
API_DIR="$PUBLIC_HTML/api"

echo "==> SurgicalHub deploy — $(date)"
echo "==> Racine: $ROOT_DIR"

# --- 1. Vérifications préalables --------------------------------------------
if [ ! -f "$BACKEND_DIR/.env.prod.local" ]; then
  echo "ERREUR: backend/.env.prod.local introuvable." >&2
  echo "        cp backend/.env.prod.local.example backend/.env.prod.local puis le remplir." >&2
  exit 1
fi

if ! grep -q '^APP_ENV=prod' "$BACKEND_DIR/.env.prod.local"; then
  echo "ERREUR: APP_ENV=prod absent de backend/.env.prod.local — refus de continuer." >&2
  exit 1
fi

if grep -q '^APP_DEBUG=1' "$BACKEND_DIR/.env.prod.local"; then
  echo "ERREUR: APP_DEBUG=1 dans backend/.env.prod.local — refus de continuer." >&2
  exit 1
fi

echo "==> APP_ENV=prod / APP_DEBUG=0 confirmés."

# --- 2. Backend : composer, migrations, cache, permissions ------------------
cd "$BACKEND_DIR"

echo "==> composer install --no-dev --optimize-autoloader"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> Migrations Doctrine — état actuel:"
php bin/console doctrine:migrations:status --env=prod

read -r -p "Lancer 'doctrine:migrations:migrate --env=prod' maintenant ? [y/N] " RUN_MIGRATIONS
if [[ "$RUN_MIGRATIONS" =~ ^[Yy]$ ]]; then
  php bin/console doctrine:migrations:migrate --no-interaction --env=prod
else
  echo "==> Migrations non lancées (à faire manuellement si nécessaire)."
fi

echo "==> Cache Symfony (clear + warmup, env=prod)"
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod

echo "==> Permissions backend"
mkdir -p public/uploads
chmod -R 755 var/cache var/log var/share public/uploads
chmod 600 .env.prod.local 2>/dev/null || true
chmod 600 config/jwt/*.pem 2>/dev/null || true

# --- 3. public_html/api/ : entrée publique Symfony ---------------------------
echo "==> Synchronisation backend/public/ -> public_html/api/"
mkdir -p "$API_DIR"

rsync -a --delete \
  --exclude 'uploads' \
  --exclude 'index.php' \
  --exclude 'index.prod.php' \
  "$BACKEND_DIR/public/" "$API_DIR/"

echo "==> Écriture de public_html/api/index.php (adapté pour ../../backend)"
cp "$BACKEND_DIR/public/index.prod.php" "$API_DIR/index.php"

echo "==> Symlink public_html/api/uploads -> ../../backend/public/uploads"
if [ -e "$API_DIR/uploads" ] && [ ! -L "$API_DIR/uploads" ]; then
  echo "    (suppression de l'ancien dossier uploads non-symlink)"
  rm -rf "$API_DIR/uploads"
fi
ln -sfn ../../backend/public/uploads "$API_DIR/uploads"

# --- 4. public_html/ : build React -------------------------------------------
if [ -d "$FRONTEND_DIST" ]; then
  echo "==> Synchronisation frontend/dist/ -> public_html/ (hors api/)"
  rsync -a --delete \
    --exclude 'api' \
    --exclude '.htaccess' \
    "$FRONTEND_DIST/" "$PUBLIC_HTML/"

  echo "==> Écriture de public_html/.htaccess (SPA, sans interférer avec /api)"
  cat > "$PUBLIC_HTML/.htaccess" <<'HTACCESS'
<IfModule mod_rewrite.c>
  RewriteEngine On
  RewriteBase /

  # Ne jamais réécrire les requêtes destinées au sous-dossier /api
  # (document root de api.surgicalhub.be -> public_html/api, géré par son
  # propre .htaccess Symfony)
  RewriteCond %{REQUEST_URI} ^/api(/|$)
  RewriteRule ^ - [L]

  # Fichiers/dossiers réels (assets buildés) servis tels quels
  RewriteCond %{REQUEST_FILENAME} -f [OR]
  RewriteCond %{REQUEST_FILENAME} -d
  RewriteRule ^ - [L]

  # Fallback SPA : toute autre route -> index.html (React Router)
  RewriteRule . /index.html [L]
</IfModule>
HTACCESS
else
  echo "==> frontend/dist absent — build local + rsync attendu"
  echo "    (voir docs/production-hostinger.md §13.5)"
fi

# --- 5. Résumé -----------------------------------------------------------------
echo "==> Dernières lignes de backend/var/log/prod.log :"
tail -n 20 "$BACKEND_DIR/var/log/prod.log" 2>/dev/null || echo "(pas de log encore)"

echo "==> Déploiement terminé. Vérifier docs/production-checklist.md."
