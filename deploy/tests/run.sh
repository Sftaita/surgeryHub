#!/usr/bin/env bash
# D-163 — tests isolés de la procédure de déploiement (deploy/deploy.sh + deploy/remote.sh).
# Clone jetable du dépôt (vrais commits, vrai git archive / export-subst), faux serveur dans un
# répertoire temporaire (DEPLOY_HOST=local), faux docker (deploy/tests/docker-stub.sh).
# Ne touche ni à la production, ni au vrai Docker, ni au dépôt courant.
#   bash deploy/tests/run.sh
set -uo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); echo "  ok   $*"; }
ko() { FAIL=$((FAIL + 1)); echo "  FAIL $*"; }
check() { local label="$1"; shift; if "$@"; then ok "$label"; else ko "$label"; fi; }
refused() { local label="$1"; shift; if "$@" >"$T/out" 2>&1; then ko "$label (accepté à tort)"; cat "$T/out"; else ok "$label"; fi; }

# TEST_BUNDLE : bundle git du commit à tester (deploy/tests/run-in-docker.sh) ; défaut : ce dépôt.
git clone -q "${TEST_BUNDLE:-$SRC}" "$T/repo"
R="$T/repo"; D="$R/deploy/deploy.sh"
git -C "$R" config user.email test@surgicalhub.test; git -C "$R" config user.name test
git -C "$R" -c core.autocrlf=false checkout -q -B test-main
export DEPLOY_MAIN_REF=test-main DEPLOY_DIST="$T/dist" DEPLOY_HOST=local APP_ROOT="$T/server"
export DOCKER="$R/deploy/tests/docker-stub.sh" STUB_STATE="$T/stub" STUB_LOG="$T/docker.log"
export BACKUP_MYSQL="$T/backup_mysql.sh"
export WORK_INVENTORY=off   # D-169 : rapport des travaux en attente testé par deploy/work-inventory/tests
printf '#!/usr/bin/env bash\necho "[mysql] OK — dump simulé"\n' > "$BACKUP_MYSQL"; chmod +x "$BACKUP_MYSQL"

# Faux serveur dans l'état d'avant D-163 : .env (secrets), compose historique, src/ manuel.
mkdir -p "$T/server/src/backend"
printf 'APP_SECRET=ne-jamais-toucher\nIMAGE_TAG=local\n' > "$T/server/.env"; chmod 600 "$T/server/.env"
echo "# compose historique (context ./src)" > "$T/server/docker-compose.yml"
echo "fichier legacy" > "$T/server/src/backend/legacy.txt"
mkdir -p "$STUB_STATE/images"; echo legacy > "$STUB_STATE/images/surgicalhub-php_local"; echo legacy > "$STUB_STATE/images/surgicalhub-nginx_local"
ENV_SUM=$(sha256sum "$T/server/.env" | cut -d' ' -f1)

A=$(git -C "$R" rev-parse HEAD)
echo "== commit A = $A"

echo "== Périmètre de l'archive (dérivé du compose et des Dockerfiles)"
P=$(bash "$D" paths "$A")
for p in deploy/production/docker-compose.yml .dockerignore docker/php/Dockerfile.prod docker/nginx/Dockerfile.prod docker/php/entrypoint.prod.sh docker/nginx/prod.conf backend frontend; do
  check "inclut $p" grep -qx "$p" <<< "$P"
done
check "n'inclut pas docs/" bash -c "! grep -q '^docs' <<< '$P'"

echo "== prepare"
check "archive du commit A préparée" bash "$D" prepare "$A"
ARCH_A="$T/dist/surgicalhub-$A.tar.gz"
check "REVISION de l'archive = A" bash -c "[ \"\$(tar xzOf '$ARCH_A' backend/REVISION | tr -d '\r\n')\" = '$A' ]"
check "la configuration Docker est livrée" bash -c "tar tzf '$ARCH_A' | grep -qx docker/nginx/prod.conf"
check "aucun .env de production ni clé" bash -c "! tar tzf '$ARCH_A' | grep -E '\.pem$|\.env\.local$|\.env\.prod$'"
refused "SHA abrégé refusé" bash "$D" prepare "${A:0:7}"

echo "== commit B : configuration Docker modifiée + fichier suivi supprimé"
echo "# modification B" >> "$R/docker/nginx/prod.conf"
DELETED=backend/templates/emails/absence_room_release.html.twig
git -C "$R" rm -q "$DELETED"
git -C "$R" -c core.autocrlf=false commit -qam "test B"
B=$(git -C "$R" rev-parse HEAD)
refused "commit hors de la branche validée refusé" env DEPLOY_MAIN_REF=$A bash "$D" prepare "$B"
bash "$D" prepare "$B" >/dev/null
ARCH_B="$T/dist/surgicalhub-$B.tar.gz"
check "la modification Docker de B est dans l'archive B" bash -c "tar xzOf '$ARCH_B' docker/nginx/prod.conf | grep -q 'modification B'"

echo "== commit C : clé privée ajoutée par erreur"
mkdir -p "$R/backend/config/jwt"; echo "secret" > "$R/backend/config/jwt/leak.pem"
git -C "$R" add -f backend/config/jwt/leak.pem; git -C "$R" commit -qm "test C"
C=$(git -C "$R" rev-parse HEAD)
refused "archive contenant une clé refusée" env DEPLOY_MAIN_REF=$C bash "$D" prepare "$C"
git -C "$R" reset -q --hard "$B"

echo "== transfert et intégrité"
check "upload A vérifié" bash "$D" upload "$A"
SUM_A=$(cat "$ARCH_A.sha256"); SUM_B=$(cat "$ARCH_B.sha256")
refused "empreinte attendue erronée : refus" env APP_ROOT="$T/server" bash "$R/deploy/remote.sh" verify "$A" "$SUM_B"
refused "archive absente (transfert échoué) : refus" bash "$R/deploy/remote.sh" stage "$B" "$SUM_B"
cp "$T/server/incoming/surgicalhub-$A.tar.gz" "$T/server/incoming/surgicalhub-$B.tar.gz"
refused "ancienne archive sous le nom de B : refus" bash "$R/deploy/remote.sh" stage "$B" "$SUM_B"
refused "… même avec l'empreinte de l'ancienne archive (REVISION ≠ B)" bash "$R/deploy/remote.sh" stage "$B" "$SUM_A"
check "aucune release B créée après ces refus" bash -c "[ ! -e '$T/server/releases/$B' ] && ! ls '$T/server/releases' 2>/dev/null | grep -q staging"
rm -f "$T/server/incoming/surgicalhub-$B.tar.gz"
cp "$ARCH_A" "$T/altered.tar.gz"; printf 'x' >> "$ARCH_A"
refused "archive locale altérée après prepare : upload refusé" bash "$D" upload "$A"
cp "$T/altered.tar.gz" "$ARCH_A"
printf 'x' >> "$T/server/incoming/surgicalhub-$A.tar.gz"
refused "archive altérée sur le serveur : stage refusé" bash "$D" stage "$A"
check "… et rien n'est extrait" bash -c "[ ! -e '$T/server/releases/$A' ]"
bash "$D" upload "$A" >/dev/null

echo "== stage"
check "stage A" bash "$D" stage "$A"
check "release A complète (.release, REVISION)" bash -c "grep -qx 'commit=$A' '$T/server/releases/$A/.release' && [ \"\$(tr -d '\r\n' < '$T/server/releases/$A/backend/REVISION')\" = '$A' ]"
check "stage idempotent" bash "$D" stage "$A"
check ".env intact" bash -c "[ \"\$(sha256sum '$T/server/.env' | cut -d' ' -f1)\" = '$ENV_SUM' ]"

echo "== build"
: > "$STUB_LOG"
check "build A (images :A, contexte = release A)" bash -c "bash '$D' build '$A' && grep -q 'build context=./releases/$A tag=$A' '$STUB_LOG'"
check "aucun redémarrage ni retag pendant le build" bash -c "! grep -qE 'docker (tag|restart)|compose .* up' '$STUB_LOG'"

echo "== activation A (première activation, depuis la configuration historique)"
: > "$STUB_LOG"
check "activate A" bash "$D" activate "$A"
check "current → releases/A" bash -c "[ \"\$(readlink '$T/server/current')\" = 'releases/$A' ]"
check "compose installé = compose versionné de A" cmp -s "$T/server/docker-compose.yml" "$T/server/releases/$A/deploy/production/docker-compose.yml"
check "images en service = A" bash -c "[ \"\$(cat '$STUB_STATE/images/surgicalhub-php_local')\" = '$A' ]"
check "images historiques conservées (rollback-legacy)" bash -c "[ \"\$(cat '$STUB_STATE/images/surgicalhub-php_rollback-legacy')\" = legacy ]"
BK=$(ls -d "$T/server/deploy-backups"/*/ | tail -1)
check "sauvegardes : compose historique, .env (600), volume var" bash -c "grep -q historique '$BK/docker-compose.yml' && [ \"\$(stat -c %a '$BK/env' 2>/dev/null || echo 600)\" = 600 ] && [ -f '$BK/surgicalhub_var.tar.gz' ]"
check "src/ historique archivé à la première activation" bash -c "tar tzf '$BK/src.tar.gz' | grep -qx src/backend/legacy.txt"
check "nginx redémarré après php" bash -c "grep -n 'docker restart' '$STUB_LOG' | tail -1 | grep -q surgicalhub-nginx"
check "jamais de version officielle à l'activation" bash -c "! grep -q 'app:release:mark' '$STUB_LOG'"
check ".env intact" bash -c "[ \"\$(sha256sum '$T/server/.env' | cut -d' ' -f1)\" = '$ENV_SUM' ]"
check "src/ historique intact" grep -q legacy "$T/server/src/backend/legacy.txt"
refused "mark refusé pour un commit qui n'est pas en service" bash "$R/deploy/remote.sh" mark "$B" v2026.10.22-prod

: > "$STUB_LOG"
check "dry-run des migrations sur un cache jetable (jamais celui en service)" bash -c "bash '$D' migrations '$A' && grep -q -- 'run --rm --no-deps -T -v /var/www/backend/var/cache php' '$STUB_LOG'"

echo "== B : échec de build → rien d'activé"
bash "$D" upload "$B" >/dev/null && bash "$D" stage "$B" > "$T/stage_b.out"
check "le fichier supprimé dans Git est signalé" grep -q "$DELETED" "$T/stage_b.out"
check "… absent de la release B" bash -c "[ ! -e '$T/server/releases/$B/$DELETED' ]"
check "… toujours présent dans la release A (rollback possible)" test -f "$T/server/releases/$A/$DELETED"
refused "build B en échec" env STUB_BUILD_FAIL=1 bash "$D" build "$B"
refused "activation impossible sans image B valide" bash "$D" activate "$B"
check "A toujours en service" bash -c "[ \"\$(readlink '$T/server/current')\" = 'releases/$A' ] && [ \"\$(cat '$STUB_STATE/images/surgicalhub-php_local')\" = '$A' ]"

echo "== B activé puis rollback vers A (code + configuration + images)"
bash "$D" build "$B" >/dev/null && bash "$D" activate "$B" >/dev/null
check "B en service, configuration B" bash -c "[ \"\$(readlink '$T/server/current')\" = 'releases/$B' ] && grep -q 'modification B' '$T/server/current/docker/nginx/prod.conf'"
: > "$STUB_LOG"
check "rollback A" bash "$D" rollback "$A"
check "release, compose et images de A restaurés" bash -c "[ \"\$(readlink '$T/server/current')\" = 'releases/$A' ] && cmp -s '$T/server/docker-compose.yml' '$T/server/releases/$A/deploy/production/docker-compose.yml' && [ \"\$(cat '$STUB_STATE/images/surgicalhub-php_local')\" = '$A' ] && ! grep -q 'modification B' '$T/server/current/docker/nginx/prod.conf'"
check "le registre des versions n'est jamais modifié par un rollback" bash -c "! grep -q 'app:release:mark' '$STUB_LOG'"
check "mark accepté pour le commit en service" bash "$R/deploy/remote.sh" mark "$A" v2026.10.22-prod
refused "migrations refusées pour un commit qui n'est pas en service" bash "$R/deploy/remote.sh" migrate "$B"
check "migrations pour le commit en service" bash "$R/deploy/remote.sh" migrate "$A"
bash "$D" activate "$B" >/dev/null; : > "$STUB_LOG"
check "rollback vers la configuration d'avant D-163" bash -c "bash '$D' rollback legacy && grep -q historique '$T/server/docker-compose.yml' && [ ! -e '$T/server/current' ] && [ \"\$(cat '$STUB_STATE/images/surgicalhub-php_local')\" = legacy ]"
check "… cache Symfony vidé avant les redémarrages" bash -c "grep -n -e 'cache:clear' -e 'docker restart' '$STUB_LOG' | head -1 | grep -q cache:clear"
check ".env intact à la fin" bash -c "[ \"\$(sha256sum '$T/server/.env' | cut -d' ' -f1)\" = '$ENV_SUM' ]"

echo
echo "Résultat : $PASS réussis, $FAIL échoués"
[ "$FAIL" -eq 0 ]
