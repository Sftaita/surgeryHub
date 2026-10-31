#!/usr/bin/env bash
# D-177 — séquence de mise en production et de repli, sur le même banc que deploy/tests/run.sh
# (clone jetable, faux serveur DEPLOY_HOST=local, faux docker). deploy.sh / remote.sh inchangés.
#  1. Migration additive AVANT la bascule : exécutée depuis l'image de la nouvelle release
#     (même commande que `deploy.sh migrations`, sans --dry-run) pendant que l'ancienne release
#     reste en service ; aucun redémarrage, aucune bascule ; activation ensuite.
#  2. Version de repli hors de origin/main : refusée par défaut, acceptée avec
#     DEPLOY_MAIN_REF=<branche de repli>, préparée et construite sans toucher au service,
#     activable sans nouvelle PR ; retour en avant par `rollback <M>`.
#   bash deploy/tests/d177-sequence.sh   (Linux : bash deploy/tests/run-in-docker.sh d177-sequence.sh)
set -uo pipefail
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); echo "  ok   $*"; }
ko() { FAIL=$((FAIL + 1)); echo "  FAIL $*"; }
check() { local label="$1"; shift; if "$@"; then ok "$label"; else ko "$label"; fi; }
refused() { local label="$1"; shift; if "$@" >"$T/out" 2>&1; then ko "$label (accepté à tort)"; cat "$T/out"; else ok "$label"; fi; }

git clone -q "${TEST_BUNDLE:-$SRC}" "$T/repo"
R="$T/repo"; D="$R/deploy/deploy.sh"
git -C "$R" config user.email test@surgicalhub.test; git -C "$R" config user.name test
git -C "$R" -c core.autocrlf=false checkout -q -B test-main
export DEPLOY_MAIN_REF=test-main DEPLOY_DIST="$T/dist" DEPLOY_HOST=local APP_ROOT="$T/server"
export DOCKER="$R/deploy/tests/docker-stub.sh" STUB_STATE="$T/stub" STUB_LOG="$T/docker.log"
export BACKUP_MYSQL="$T/backup_mysql.sh" WORK_INVENTORY=off
printf '#!/usr/bin/env bash\necho "[mysql] OK — dump simulé"\n' > "$BACKUP_MYSQL"; chmod +x "$BACKUP_MYSQL"
mkdir -p "$T/server/src/backend"
printf 'APP_SECRET=ne-jamais-toucher\nIMAGE_TAG=local\n' > "$T/server/.env"; chmod 600 "$T/server/.env"
echo "# compose historique (context ./src)" > "$T/server/docker-compose.yml"
mkdir -p "$STUB_STATE/images"; echo legacy > "$STUB_STATE/images/surgicalhub-php_local"; echo legacy > "$STUB_STATE/images/surgicalhub-nginx_local"
release() { bash "$D" prepare "$1" >/dev/null && bash "$D" upload "$1" >/dev/null && bash "$D" stage "$1" >/dev/null && bash "$D" build "$1" >/dev/null; }
current() { readlink "$T/server/current"; }

echo "== A : release en service (équivalent d32dbe7)"
A=$(git -C "$R" rev-parse HEAD)
release "$A" && bash "$D" activate "$A" >/dev/null
check "A en service" bash -c "[ \"\$(readlink '$T/server/current')\" = 'releases/$A' ]"

echo "== M : nouvelle release avec migration additive (équivalent 953ce9a)"
mkdir -p "$R/backend/migrations"; echo "<?php // migration additive de test" > "$R/backend/migrations/Version29990101000000.php"
git -C "$R" add backend/migrations/Version29990101000000.php; git -C "$R" -c core.autocrlf=false commit -qm "M"
M=$(git -C "$R" rev-parse HEAD)
check "prepare / upload / stage / build M sans toucher au service" release "$M"
check "… A toujours en service" bash -c "[ \"\$(readlink '$T/server/current')\" = 'releases/$A' ] && [ \"\$(cat '$STUB_STATE/images/surgicalhub-php_local')\" = '$A' ]"
: > "$STUB_LOG"
check "dry-run des migrations de M" bash "$D" migrations "$M"

echo "== 1. migration réelle AVANT la bascule (image M, cache jetable)"
: > "$STUB_LOG"
CUR_BEFORE=$(current)
check "commande de migration pré-bascule" env IMAGE_TAG="$M" "$DOCKER" compose --project-directory "$APP_ROOT" \
  -f "$APP_ROOT/releases/$M/deploy/production/docker-compose.yml" run --rm --no-deps -T -v /var/www/backend/var/cache \
  php php bin/console doctrine:migrations:migrate --no-interaction --env=prod
check "… exécutée sur l'image M (IMAGE_TAG=$M), sans --dry-run" bash -c "grep -q 'doctrine:migrations:migrate --no-interaction --env=prod' '$STUB_LOG' && ! grep -q -- '--dry-run' '$STUB_LOG'"
check "… pendant que A reste en service (aucune bascule, aucun redémarrage, aucun retag)" bash -c "[ '$CUR_BEFORE' = 'releases/$A' ] && [ \"\$(readlink '$T/server/current')\" = 'releases/$A' ] && ! grep -qE 'docker (tag|restart)|compose .* up' '$STUB_LOG'"
: > "$STUB_LOG"
check "activate M ensuite" bash "$D" activate "$M"
check "M en service (php et worker vérifiés)" bash -c "[ \"\$(readlink '$T/server/current')\" = 'releases/$M' ] && [ \"\$(cat '$STUB_STATE/images/surgicalhub-php_local')\" = '$M' ]"
check "migrate M (contrôle, déjà à jour) accepté" bash "$R/deploy/remote.sh" migrate "$M"

echo "== 2. version de repli hors de la branche principale"
git -C "$R" checkout -q -b rollback-test
git -C "$R" rm -q backend/migrations/Version29990101000000.php 2>/dev/null; echo "repli" > "$R/backend/REPLI_TEST"
git -C "$R" add -A; git -C "$R" -c core.autocrlf=false commit -qm "repli"
RB=$(git -C "$R" rev-parse HEAD)
git -C "$R" checkout -q test-main
refused "repli refusé par défaut (pas sur la branche principale)" bash "$D" prepare "$RB"
check "repli accepté avec DEPLOY_MAIN_REF=rollback-test" env DEPLOY_MAIN_REF=rollback-test bash "$D" prepare "$RB"
check "… transfert, staging et build du repli" bash -c "DEPLOY_MAIN_REF=rollback-test bash '$D' upload '$RB' >/dev/null && bash '$D' stage '$RB' >/dev/null && bash '$D' build '$RB' >/dev/null"
check "… sans toucher au service (M toujours en service)" bash -c "[ \"\$(readlink '$T/server/current')\" = 'releases/$M' ] && [ \"\$(cat '$STUB_STATE/images/surgicalhub-php_local')\" = '$M' ]"
: > "$STUB_LOG"
check "incident : activate du repli pré-construit, sans nouvelle PR" bash "$D" activate "$RB"
check "… repli en service, sauvegardes faites" bash -c "[ \"\$(readlink '$T/server/current')\" = 'releases/$RB' ] && [ \"\$(cat '$STUB_STATE/images/surgicalhub-php_local')\" = '$RB' ] && [ \"\$(cat \"\$(ls -d '$T/server/deploy-backups'/*/ | tail -1)previous_release\")\" = '$M' ]"
check "… aucune migration exécutée par le repli (colonnes conservées)" bash -c "! grep -q 'doctrine:migrations:migrate' '$STUB_LOG'"
check "retour en avant : rollback vers M" bash "$D" rollback "$M"
check "… M en service" bash -c "[ \"\$(readlink '$T/server/current')\" = 'releases/$M' ] && [ \"\$(cat '$STUB_STATE/images/surgicalhub-php_local')\" = '$M' ]"

echo
echo "Résultat : $PASS réussis, $FAIL échoués"
[ "$FAIL" -eq 0 ]
