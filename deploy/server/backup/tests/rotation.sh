#!/usr/bin/env bash
# D-164 — tests de la rotation (rotate_backups.sh) sur une arborescence factice et un faux docker.
# Ne touche ni au serveur, ni au vrai Docker.   bash deploy/server/backup/tests/rotation.sh
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); echo "  ok   $*"; }
ko() { FAIL=$((FAIL + 1)); echo "  FAIL $*"; }
check() { local l="$1"; shift; if "$@"; then ok "$l"; else ko "$l"; fi; }

export BACKUP_CONF=/dev/null BACKUP_ROOT="$T/b" APP_ROOT="$T/app" BACKUP_LOG="$T/b/backup.log"
export DOCKER="$HERE/tests/docker-stub.sh" STUB="$T/stub" RETENTION_NOW
RETENTION_NOW=$(date -u -d '2026-10-23 12:00:00' +%s)
mkdir -p "$T/b/mysql" "$T/b/uploads" "$T/b/surgicalhub-var" "$T/b/code" "$T/app/deploy-backups" "$T/app/releases" "$STUB/images" "$STUB/containers"
R="$HERE/rotate_backups.sh"
d() { date -u -d "2026-10-23 03:00:00 UTC $1 days ago" +%Y%m%d; }

# 400 dumps quotidiens (03:00), du 2026-10-23 au 2025-09-19 ; un .sha256 compagnon.
for i in $(seq 0 399); do echo x > "$T/b/mysql/all_$(d "$i")_030001.sql.gz"; done
echo s > "$T/b/mysql/all_20261009_030001.sql.gz.sha256"
for i in $(seq 1 12); do echo x > "$T/b/mysql/surgicalhub_pre_deploy_L${i}_$(d "$i")_120000.sql.gz"; done
echo x > "$T/b/mysql/medvue_manuel.sql.gz"; echo x > "$T/b/mysql/notes.txt"
echo x > "$T/outside.sql.gz"; ln -s "$T/outside.sql.gz" "$T/b/mysql/all_20240101_030001.sql.gz"
for i in 0 10 29 31 45; do echo x > "$T/b/uploads/surgicalhub_$(d "$i")_031501.tar.gz"; done
for i in 60 61 62 63; do echo x > "$T/b/uploads/medclick_$(d "$i")_031501.tar.gz"; done
for i in $(seq 0 34); do echo x > "$T/b/surgicalhub-var/surgicalhub_var_$(d "$i")_031501.tar.gz.gpg"; done
for i in $(seq 1 12); do echo x > "$T/b/code/src_pre_deploy_$(d "$i")_120000.tar.gz"; echo e > "$T/b/code/env_pre_deploy_$(d "$i")_120000"; chmod 664 "$T/b/code/env_pre_deploy_$(d "$i")_120000"; done
for i in $(seq 1 12); do mkdir -p "$T/app/deploy-backups/$(d "$i")_120000"; echo "$(printf %040d "$i")" > "$T/app/deploy-backups/$(d "$i")_120000/previous_release"; done
echo legacy > "$T/app/deploy-backups/$(d 12)_120000/previous_release"

# Images : 8 anciennes releases rollback-*, la release active, legacy, :local.
img() { echo "$3 $(date -u -d "2026-10-23 00:00:00 UTC $4 days ago" +%FT%TZ) 1000000" > "$STUB/images/$1__$2"; }
for i in 1 2 3 4 5 6 7 8; do for r in surgicalhub-php surgicalhub-nginx; do img "$r" "rollback-aaaaaa$i" "id-$r-$i" $((i * 2)); done; done
A=$(printf 'a%.0s' {1..40})
for r in surgicalhub-php surgicalhub-nginx; do img "$r" "$A" "id-$r-A" 0; img "$r" local "id-$r-A" 0; img "$r" rollback-legacy "id-$r-L" 30; done
ln -s "releases/$A" "$T/app/current"; mkdir -p "$T/app/releases/$A"
echo "id-surgicalhub-php-8" > "$STUB/containers/vieux-conteneur-arrete"   # image de rollback-aaaaaa8 encore utilisée
echo "$(printf %040d 1)" > "$T/app/deploy-backups/$(d 1)_120000/previous_release"

snapshot() { (cd "$T" && find b app ! -name backup.log ! -name .restore-test-ok -printf '%p %s\n' | LC_ALL=C sort; ls "$STUB/images") | sha256sum; }
BEFORE=$(snapshot)

echo "== dry-run (défaut)"
bash "$R" --list > "$T/dry.out" 2>&1; cat "$T/dry.out" | grep -vE '^    - '
check "dry-run : rien n'est modifié" bash -c "[ '$(snapshot)' = '$BEFORE' ]"
check "dry-run : 400 dumps quotidiens, 30 conservés, 370 à supprimer" grep -qE 'MySQL quotidiens.* 400 au total, +30 conservés, +370 à supprimer' "$T/dry.out"
check "dry-run : espace récupéré affiché" grep -q 'Espace récupéré' "$T/dry.out"

echo "== --apply sans test de restauration"
check "refusé sans marqueur de test de restauration" bash -c "! bash '$R' --apply > /dev/null 2>&1"
touch -d '2026-09-01' "$T/b/.restore-test-ok"
check "refusé si le dernier test a plus de 35 jours" bash -c "! bash '$R' --apply > /dev/null 2>&1"
check "… et toujours rien de modifié" bash -c "[ '$(snapshot)' = '$BEFORE' ]"
touch -d '2026-10-20' "$T/b/.restore-test-ok"

echo "== --apply"
check "rotation appliquée" bash -c "bash '$R' --apply > '$T/apply.out' 2>&1"
M="$T/b/mysql"
check "quotidiens : les 14 derniers jours conservés" bash -c "for i in \$(seq 0 13); do [ -f '$M/all_'\$(date -u -d \"2026-10-23 03:00:00 UTC \$i days ago\" +%Y%m%d)_030001.sql.gz ] || exit 1; done"
check "quotidiens : 2026-10-09 supprimé (ni plus récent de sa semaine ni de son mois), avec son .sha256" bash -c "[ ! -e '$M/all_20261009_030001.sql.gz' ] && [ ! -e '$M/all_20261009_030001.sql.gz.sha256' ]"
check "hebdomadaire : 2026-09-06 (8e semaine) conservé, 2026-08-30 (9e) supprimé" bash -c "[ -f '$M/all_20260906_030001.sql.gz' ] && [ ! -e '$M/all_20260830_030001.sql.gz' ]"
check "mensuel : 2026-08-31 et 2025-11-30 (12e mois) conservés, 2025-10-31 (13e) supprimé" bash -c "[ -f '$M/all_20260831_030001.sql.gz' ] && [ -f '$M/all_20251130_030001.sql.gz' ] && [ ! -e '$M/all_20251031_030001.sql.gz' ]"
check "quotidiens : exactement 30 conservés" bash -c "[ \$(find '$M' -type f -name 'all_*.sql.gz' | wc -l) = 30 ]"
check "pré-déploiement : les 10 derniers conservés" bash -c "[ \$(ls '$M' | grep -c '^surgicalhub_pre_deploy_') = 10 ]"
check "fichiers non gérés jamais supprimés" bash -c "[ -f '$M/medvue_manuel.sql.gz' ] && [ -f '$M/notes.txt' ]"
check "lien symbolique ignoré, cible intacte" bash -c "[ -L '$M/all_20240101_030001.sql.gz' ] && [ -f '$T/outside.sql.gz' ]"
check "uploads : moins de 30 jours conservés, 31 et 45 j supprimés" bash -c "[ \$(ls '$T/b/uploads' | grep -c '^surgicalhub_') = 3 ]"
check "uploads : jamais moins des 3 plus récentes (medclick, toutes > 30 j)" bash -c "[ \$(ls '$T/b/uploads' | grep -c '^medclick_') = 3 ]"
check "volume var : 30 jours conservés" bash -c "[ \$(ls '$T/b/surgicalhub-var' | wc -l) = 30 ]"
check "code : 10 archives, 10 copies de .env" bash -c "[ \$(ls '$T/b/code' | grep -c '^src_pre_deploy_') = 10 ] && [ \$(ls '$T/b/code' | grep -c '^env_pre_deploy_') = 10 ]"
check "copies de .env passées en 600" bash -c "[ -z \"\$(find '$T/b/code' -type f -perm /077)\" ]"
check "deploy-backups : 10 derniers + la sauvegarde legacy" bash -c "[ \$(ls '$T/app/deploy-backups' | wc -l) = 11 ] && [ -d '$T/app/deploy-backups/$(d 12)_120000' ]"
S="$STUB/images"
check "images : release active, :local et rollback-legacy conservées" bash -c "[ -f '$S/surgicalhub-php__$A' ] && [ -f '$S/surgicalhub-php__local' ] && [ -f '$S/surgicalhub-nginx__rollback-legacy' ]"
check "images : 5 plus récentes conservées (aaaaaa1–4 + active)" bash -c "for i in 1 2 3 4; do [ -f '$S/surgicalhub-php__rollback-aaaaaa'\$i ] || exit 1; done"
check "images : aaaaaa5–7 retirées (php et nginx)" bash -c "for i in 5 6 7; do [ ! -e '$S/surgicalhub-php__rollback-aaaaaa'\$i ] && [ ! -e '$S/surgicalhub-nginx__rollback-aaaaaa'\$i ] || exit 1; done"
check "images : une image utilisée par un conteneur n'est jamais retirée (aaaaaa8)" test -f "$S/surgicalhub-php__rollback-aaaaaa8"

echo "== cache de build"
check "sous le seuil : rien" bash -c "bash '$R' | grep -q 'rien à faire'"
check "au-dessus du seuil en dry-run : annoncé, pas exécuté" bash -c "STUB_BUILD_CACHE=9.5GB bash '$R' | grep -q 'élagage' && ! grep -q 'builder prune' '$STUB/docker.log'"
check "au-dessus du seuil avec --apply : élagage limité aux entrées anciennes" bash -c "STUB_BUILD_CACHE=9.5GB bash '$R' --apply > /dev/null && grep -q 'builder prune -f --filter until=168h' '$STUB/docker.log'"

echo "== passphrase de chiffrement"
echo secret > "$T/pass"; chmod 644 "$T/pass"
check "passphrase lisible par d'autres (644) : refus" bash -c "! PASSFILE='$T/pass' bash -c '. \"$HERE/lib.sh\"; need_passfile' > /dev/null 2>&1"
chmod 600 "$T/pass"
check "passphrase en 600 : acceptée" bash -c "PASSFILE='$T/pass' bash -c '. \"$HERE/lib.sh\"; need_passfile'"
check "passphrase absente : refus" bash -c "! PASSFILE='$T/absente' bash -c '. \"$HERE/lib.sh\"; need_passfile' > /dev/null 2>&1"

echo
echo "Résultat : $PASS réussis, $FAIL échoués"
[ "$FAIL" -eq 0 ]
