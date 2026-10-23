#!/usr/bin/env bash
# D-164 — test de bout en bout sur un VRAI Docker local, entièrement jetable : un MySQL, deux
# volumes et des clés JWT créés pour le test, puis sauvegardes chiffrées (backup_mysql.sh,
# backup_volumes.sh), restauration réelle (restore_test.sh), et refus des sauvegardes altérées
# ou d'une mauvaise passphrase. Tout est supprimé à la fin. Ne touche à aucun autre conteneur.
#   MYSQL_TEST_IMAGE=mysql:8.0 HELPER_IMAGE=alpine:3.20 bash deploy/server/backup/tests/e2e-docker.sh
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
T=$(mktemp -d); ID="shbk$$"
# Git Bash : pas de conversion de chemins pour docker seulement (cale limitée au test).
mkdir -p "$T/bin"; printf '#!/usr/bin/env bash
MSYS_NO_PATHCONV=1 exec docker "$@"
' > "$T/bin/dk"; chmod +x "$T/bin/dk"
export DOCKER="$T/bin/dk"; docker() { "$T/bin/dk" "$@"; }
cleanup() { docker rm -f "$ID-mysql" > /dev/null 2>&1; docker volume rm -f "${ID}_uploads" "${ID}_var" > /dev/null 2>&1; rm -rf "$T"; }
trap cleanup EXIT
PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); echo "  ok   $*"; }
ko() { FAIL=$((FAIL + 1)); echo "  FAIL $*"; }
check() { local l="$1"; shift; if "$@"; then ok "$l"; else ko "$l"; fi; }

export BACKUP_CONF=/dev/null BACKUP_ROOT="$T/b" APP_ROOT="$T/app" BACKUP_LOG="$T/b/backup.log"
export PASSFILE="$T/pass" MYSQL_ENV_FILE="$T/mysql.env" MYSQL_CONTAINER="$ID-mysql"
export UPLOAD_VOLUMES="${ID}_uploads:surgicalhub" VAR_VOLUME="${ID}_var"
export HELPER_IMAGE="${HELPER_IMAGE:-alpine:3.20}" MYSQL_TEST_IMAGE="${MYSQL_TEST_IMAGE:-mysql:8.0}"
mkdir -p "$T/b" "$T/app" "$T/bin"
head -c 32 /dev/urandom | base64 > "$PASSFILE"; chmod 600 "$PASSFILE"
# Git Bash (NTFS) n'applique pas chmod : cale `stat` limitée au fichier de passphrase du test.
# Le refus d'une passphrase lisible par d'autres est testé sous Linux (tests/rotation.sh).
if [ "$(stat -c %a "$PASSFILE")" != 600 ]; then
  printf '#!/usr/bin/env bash\n[ "$1 $2 $3" = "-c %%a %s" ] && { echo 600; exit 0; }\nexec /usr/bin/stat "$@"\n' "$PASSFILE" > "$T/bin/stat"
  chmod +x "$T/bin/stat"; export PATH="$T/bin:$PATH"
fi

echo "== données de test"
RP=$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n'); echo "MYSQL_ROOT_PASSWORD=$RP" > "$MYSQL_ENV_FILE"
docker run -d --name "$ID-mysql" --network none -e MYSQL_ROOT_PASSWORD="$RP" "$MYSQL_TEST_IMAGE" > /dev/null
for _ in $(seq 1 90); do docker exec -e MYSQL_PWD="$RP" "$ID-mysql" mysql -uroot -e 'SELECT 1' > /dev/null 2>&1 && break; sleep 2; done
docker exec -i -e MYSQL_PWD="$RP" "$ID-mysql" mysql -uroot <<'SQL'
CREATE DATABASE surgicalhub; USE surgicalhub;
CREATE TABLE user (id INT PRIMARY KEY, email VARCHAR(80));
CREATE TABLE mission (id INT PRIMARY KEY, label VARCHAR(80));
CREATE TABLE doctrine_migration_versions (version VARCHAR(191) PRIMARY KEY);
INSERT INTO user VALUES (1,'a@surgicalhub.internal'),(2,'b@surgicalhub.internal'),(3,'c@surgicalhub.internal');
INSERT INTO mission VALUES (1,'m1'),(2,'m2');
INSERT INTO doctrine_migration_versions VALUES ('DoctrineMigrations\\Version20261016110000');
SQL
JP=$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n'); echo "JWT_PASSPHRASE=$JP" > "$T/app/.env"
mkdir -p "$T/v/jwt" "$T/v/release" "$T/v/cache/prod" "$T/u/photos"
(cd "$T/v/jwt" && openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -aes256 -pass "pass:$JP" -out private.pem 2>/dev/null   && openssl pkey -in private.pem -passin "pass:$JP" -pubout -out public.pem)
echo '{"releases":[{"tag":"v2026.10.21-prod-2","commit":"20aa821"}]}' > "$T/v/release/releases.json"
echo cache > "$T/v/cache/prod/c.php"; for i in 1 2 3 4; do echo "photo $i" > "$T/u/photos/p$i.jpg"; done
for v in var u; do docker volume create "${ID}_$( [ $v = u ] && echo uploads || echo var)" > /dev/null; done
tar cf - -C "$T/v" . | docker run --rm -i -v "${ID}_var:/d" --entrypoint tar "$HELPER_IMAGE" xf - -C /d
tar cf - -C "$T/u" . | docker run --rm -i -v "${ID}_uploads:/d" --entrypoint tar "$HELPER_IMAGE" xf - -C /d

echo "== sauvegardes chiffrées"
check "backup_mysql.sh" bash -c "bash '$HERE/backup_mysql.sh' > '$T/m.out' 2>&1"
D=$(ls "$T/b/mysql" | grep -E '^all_.*\.sql\.gz\.gpg$' | tail -1)
check "dump chiffré + empreinte, aucun dump en clair" bash -c "[ -n '$D' ] && [ -f '$T/b/mysql/$D.sha256' ] && ! ls '$T/b/mysql' | grep -qE '\.sql\.gz$'"
check "le dump chiffré n'est pas lisible sans la passphrase" bash -c "! gunzip -t '$T/b/mysql/$D' 2>/dev/null && ! grep -qa 'surgicalhub.internal' '$T/b/mysql/$D'"
check "backup_volumes.sh" bash -c "bash '$HERE/backup_volumes.sh' > '$T/v.out' 2>&1"
check "archives uploads et var chiffrées" bash -c "ls '$T/b/uploads' | grep -qE '^surgicalhub_.*\.tar\.gz\.gpg$' && ls '$T/b/surgicalhub-var' | grep -qE '^${ID}_var_.*\.tar\.gz\.gpg$'"

echo "== restauration réelle"
check "restore_test.sh réussi" bash -c "bash '$HERE/restore_test.sh' > '$T/r.out' 2>&1"
grep -E 'OK|ÉCHEC|lignes|bases' "$T/r.out" | sed 's/^\[[^]]*\] \[restore-test\] /     /'
check "dump restauré : 3 utilisateurs, 2 missions" bash -c "grep -q 'surgicalhub.user : 3 lignes' '$T/r.out' && grep -q 'surgicalhub.mission : 2 lignes' '$T/r.out'"
check "clés JWT restaurées et appariées" grep -q 'clés JWT restaurées et appariées' "$T/r.out"
check "registre des versions restauré" grep -q 'registre des versions restauré' "$T/r.out"
check "cache exclu, 4 uploads lisibles" bash -c "grep -q 'cache Symfony exclu' '$T/r.out' && grep -qE 'lisible : 4 fichiers \(volume en service : 4\)' '$T/r.out'"
check "marqueur de test de restauration écrit" test -f "$T/b/.restore-test-ok"
check "aucun conteneur de test restant" bash -c "! docker ps -a --format '{{.Names}}' | grep -q surgicalhub-restore-test"

echo "== refus"
rm -f "$T/b/.restore-test-ok"
cp "$T/b/mysql/$D" "$T/orig.gpg"; printf '\x00' | dd of="$T/b/mysql/$D" bs=1 seek=200 conv=notrunc 2>/dev/null
check "dump altéré : test de restauration en échec, pas de marqueur" bash -c "! bash '$HERE/restore_test.sh' > '$T/r2.out' 2>&1 && grep -q 'ÉCHEC SHA-256' '$T/r2.out' && [ ! -f '$T/b/.restore-test-ok' ]"
cp "$T/orig.gpg" "$T/b/mysql/$D"
cp "$PASSFILE" "$T/pass.ok"; head -c 32 /dev/urandom | base64 > "$PASSFILE"
check "mauvaise passphrase : déchiffrement refusé" bash -c "! bash '$HERE/restore_test.sh' > '$T/r3.out' 2>&1 && grep -q 'ÉCHEC déchiffrement' '$T/r3.out'"
cp "$T/pass.ok" "$PASSFILE"
check "sauvegardes intactes après les refus : nouveau test réussi" bash -c "bash '$HERE/restore_test.sh' > /dev/null 2>&1"

echo
echo "Résultat : $PASS réussis, $FAIL échoués"
[ "$FAIL" -eq 0 ]
