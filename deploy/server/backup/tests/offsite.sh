#!/usr/bin/env bash
# D-164 — tests de la copie hors serveur (backup_offsite.sh) avec un faux rclone (tests/rclone-stub.sh) :
# seuls les fichiers chiffrés sortent, jamais de suppression distante implicite, rétention distante
# indépendante (30 j / 12 sem. / 24 mois ; volumes 90 j), rien n'est supprimé si la copie n'est pas vérifiée.
#   bash deploy/server/backup/tests/offsite.sh   (sous Linux : tests/run-in-docker.sh)
set -uo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
PASS=0; FAIL=0
ok() { PASS=$((PASS + 1)); echo "  ok   $*"; }
ko() { FAIL=$((FAIL + 1)); echo "  FAIL $*"; }
check() { local l="$1"; shift; if "$@"; then ok "$l"; else ko "$l"; fi; }

export BACKUP_CONF=/dev/null BACKUP_ROOT="$T/b" APP_ROOT="$T/app" BACKUP_LOG="$T/b/backup.log"
export RCLONE="$HERE/tests/rclone-stub.sh" RSTORE="$T/drive" REMOTE_BASE="gdrive:SH" RETENTION_NOW
RETENTION_NOW=$(date -u -d '2026-10-23 12:00:00' +%s)
d() { date -u -d "2026-10-23 03:00:00 UTC $1 days ago" +%Y%m%d; }
M="$T/b/mysql"; U="$T/b/uploads"; V="$T/b/surgicalhub-var"; R="$T/drive/SH"
mkdir -p "$M" "$U" "$V" "$T/b/code" "$T/drive"
O="$HERE/backup_offsite.sh"

for i in $(seq 0 799); do f="$M/all_$(d "$i")_030001.sql.gz.gpg"; echo "c$i" > "$f"; echo "s" > "$f.sha256"; done
echo clair > "$M/all_$(d 0)_020000.sql.gz"                              # ancien dump en clair : ne sort jamais
echo x > "$M/surgicalhub_pre_deploy_v2026.10.23-prod_$(d 0)_053708.sql.gz.gpg"
for i in 0 50 89 91 120; do echo u > "$U/surgicalhub_$(d "$i")_031501.tar.gz.gpg"; done
for i in 100 110 120 130; do echo m > "$U/medatwork_$(d "$i")_031501.tar.gz.gpg"; done   # volume sans archive récente
echo u > "$U/surgicalhub_$(d 0)_031501.tar.gz"                           # archive en clair : ne sort jamais
for i in 0 95; do echo v > "$V/surgicalhub_var_$(d "$i")_031501.tar.gz.gpg"; done
echo SECRET > "$T/b/code/env_pre_deploy_20261023_053708"

echo "== copie"
check "copie vérifiée" bash -c "bash '$O' > '$T/o1.out' 2>&1"
check "dumps chiffrés copiés (800 + .sha256)" bash -c "[ \$(ls '$R/mysql' | grep -c '\.sql\.gz\.gpg$') = 800 ] && [ \$(ls '$R/mysql' | grep -c '\.sha256$') = 800 ]"
check "aucun fichier en clair hors du serveur" bash -c "! find '$R' -type f \( -name '*.sql.gz' -o -name '*.tar.gz' \) | grep -q ."
check "aucune copie de .env hors du serveur" bash -c "! grep -rqs SECRET '$R'"
check "dumps d'avant déploiement dans leur propre dossier" bash -c "ls '$R/mysql-pre-deploy' | grep -q pre_deploy && ! ls '$R/mysql' | grep -q pre_deploy"
check "uploads et var copiés" bash -c "[ \$(ls '$R/uploads' | grep -c 'gpg$') = 9 ] && [ \$(ls '$R/surgicalhub-var' | grep -c 'gpg$') = 2 ]"
check "dernière archive de CHAQUE volume vérifiée à distance" bash -c "grep -q 'uploads : surgicalhub_$(d 0)_031501.tar.gz.gpg vérifié' '$T/o1.out' && grep -q 'uploads : medatwork_$(d 100)_031501.tar.gz.gpg vérifié' '$T/o1.out'"
check "sans option : rotation distante en dry-run, rien de supprimé" bash -c "grep -q 'à supprimer (dry-run)' '$T/o1.out' && ! grep -q deletefile '$RSTORE.log'"

echo "== suppressions locales jamais répercutées"
rm -f "$M"/all_2025*
check "nouvelle copie après suppressions locales" bash -c "bash '$O' > /dev/null 2>&1"
check "les dumps 2025 restent sur le Drive" bash -c "ls '$R/mysql' | grep -q '^all_2025'"

echo "== copie distante non vérifiée → aucune rotation"
check "échec signalé" bash -c "! RCLONE_FAIL_CHECK=1 bash '$O' --apply-remote-rotation > /dev/null 2>&1"
check "… et rien supprimé à distance" bash -c "! grep -q deletefile '$RSTORE.log'"

echo "== rotation distante réelle (--apply-remote-rotation)"
check "rotation distante" bash -c "bash '$O' --apply-remote-rotation > '$T/o2.out' 2>&1"
check "dumps : 30 derniers jours conservés" bash -c "for i in \$(seq 0 29); do [ -f '$R/mysql/all_'\$(date -u -d \"2026-10-23 03:00:00 UTC \$i days ago\" +%Y%m%d)_030001.sql.gz.gpg ] || exit 1; done"
check "dumps : 24e mois conservé (2024-11-30), 25e supprimé (2024-10-31)" bash -c "[ -f '$R/mysql/all_20241130_030001.sql.gz.gpg' ] && [ ! -e '$R/mysql/all_20241031_030001.sql.gz.gpg' ] && [ ! -e '$R/mysql/all_20241031_030001.sql.gz.gpg.sha256' ]"
check "dumps : 12e semaine conservée (dim. 2026-08-09), 13e supprimée (2026-08-02), jour non retenu supprimé (2026-08-08)" bash -c "[ -f '$R/mysql/all_20260809_030001.sql.gz.gpg' ] && [ ! -e '$R/mysql/all_20260802_030001.sql.gz.gpg' ] && [ ! -e '$R/mysql/all_20260808_030001.sql.gz.gpg' ]"
check "uploads : 90 jours (0, 50, 89 conservés ; 91, 120 supprimés)" bash -c "[ \$(ls '$R/uploads' | grep -c '^surgicalhub_.*gpg$') = 3 ]"
check "uploads : jamais moins des 3 plus récentes PAR volume (medatwork 100/110/120 conservés, 130 supprimé)" bash -c "[ \$(ls '$R/uploads' | grep -c '^medatwork_.*gpg$') = 3 ] && [ ! -e '$R/uploads/medatwork_$(d 130)_031501.tar.gz.gpg' ]"
check "var : 90 jours, jamais moins des 3 plus récentes" bash -c "[ \$(ls '$R/surgicalhub-var' | grep -c 'gpg$') = 2 ]"
check "copie locale inchangée par la rotation distante" bash -c "[ \$(ls '$U' | grep -c 'gpg$') = 9 ]"

echo
echo "Résultat : $PASS réussis, $FAIL échoués"
[ "$FAIL" -eq 0 ]
