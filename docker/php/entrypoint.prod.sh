#!/bin/sh
set -e

echo "[entrypoint] Starting SurgicalHub PHP container (APP_ENV=${APP_ENV:-prod})..."

# var/ and public/uploads are bind-mounted Docker volumes — they come up
# owned by root, so fix ownership before php-fpm drops privileges to www-data.
mkdir -p var/cache var/log var/jwt public/uploads
chown -R www-data:www-data var public/uploads

# JWT keypair: generated once into var/jwt (persisted on the app_var volume),
# never baked into the image. JWT_SECRET_KEY / JWT_PUBLIC_KEY must point here.
JWT_SECRET_PATH="${JWT_SECRET_KEY:-/var/www/backend/var/jwt/private.pem}"
JWT_PUBLIC_PATH="${JWT_PUBLIC_KEY:-/var/www/backend/var/jwt/public.pem}"

if [ ! -f "$JWT_SECRET_PATH" ] || [ ! -f "$JWT_PUBLIC_PATH" ]; then
    if mkdir "var/jwt.lock" 2>/dev/null; then
        echo "[entrypoint] JWT keypair absent — generating..."
        su -s /bin/sh www-data -c "php bin/console lexik:jwt:generate-keypair --skip-if-exists"
        rmdir "var/jwt.lock"
        echo "[entrypoint] JWT keypair generated."
    else
        echo "[entrypoint] Another process is generating the JWT keypair — waiting..."
        until [ -f "$JWT_SECRET_PATH" ] && [ -f "$JWT_PUBLIC_PATH" ]; do
            sleep 1
        done
    fi
fi

echo "[entrypoint] Ready. Executing: $*"
exec "$@"
