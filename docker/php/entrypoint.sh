#!/bin/sh
set -e

echo "[entrypoint] Starting SurgicalHub PHP container (APP_ENV=${APP_ENV:-dev})..."

if [ ! -f vendor/autoload.php ] || [ ! -f vendor/autoload_runtime.php ]; then
    if [ "${SKIP_COMPOSER_INSTALL:-0}" = "1" ]; then
        echo "[entrypoint] vendor/ not ready — waiting for php service to run composer install..."
        until [ -f vendor/autoload.php ] && [ -f vendor/autoload_runtime.php ]; do
            sleep 2
        done
        echo "[entrypoint] vendor/ ready."
    else
        echo "[entrypoint] vendor/ incomplete — running composer install..."
        composer install --no-interaction --prefer-dist
        echo "[entrypoint] Composer install done."
    fi
fi

if [ ! -f config/jwt/private.pem ]; then
    echo "[entrypoint] JWT keypair absent — generating..."
    mkdir -p config/jwt
    php bin/console lexik:jwt:generate-keypair --skip-if-exists
    echo "[entrypoint] JWT keypair generated."
fi

echo "[entrypoint] Ready. Executing: $*"
exec "$@"
