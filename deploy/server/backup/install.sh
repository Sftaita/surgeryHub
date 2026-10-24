#!/usr/bin/env bash
# SurgicalHub — installation contrôlée des scripts de sauvegarde sur le serveur (D-164).
# Lancé depuis le poste de déploiement :
#   deploy/server/backup/install.sh                  ce qui changerait (scripts et crontab), rien n'est écrit
#   deploy/server/backup/install.sh --apply-scripts  copie les scripts dans $DEST (aucune tâche cron modifiée)
#   deploy/server/backup/install.sh --apply-crontab  sauvegarde la crontab, remplace les 4 anciennes
#                                                    lignes de sauvegarde par crontab.proposed (phase 1)
# Chaque option --apply-* exige un accord explicite préalable. Aucun fichier de sauvegarde
# existant n'est jamais supprimé ni déplacé par ce script.
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOST="${DEPLOY_HOST:-surgicalhub-prod}"
DEST="${DEST:-/home/deploy/scripts/surgicalhub-backup}"
FILES=(lib.sh backup_mysql.sh backup_volumes.sh backup_offsite.sh rotate_backups.sh restore_test.sh backup.conf.example crontab.proposed)
OLD_LINES='scripts/(backup_mysql|backup_uploads|sync_gdrive|rotate_backups)\.sh'
mode="${1:-diff}"

stage=$(ssh "$HOST" mktemp -d)
trap 'ssh "$HOST" rm -rf "$stage"' EXIT
tar cf - -C "$HERE" "${FILES[@]}" | ssh "$HOST" tar xf - -C "$stage"

echo "== Scripts ($DEST)"
ssh "$HOST" "for f in ${FILES[*]}; do if [ -f '$DEST/'\$f ]; then diff -q '$DEST/'\$f '$stage/'\$f > /dev/null && echo \"  identique  \$f\" || echo \"  modifié    \$f\"; else echo \"  nouveau    \$f\"; fi; done"

echo "== Crontab : lignes remplacées (phase 1)"
ssh "$HOST" "crontab -l | grep -E '$OLD_LINES' | sed 's/^/  - /'; grep -vE '^\s*(#|$)' '$stage/crontab.proposed' | sed 's/^/  + /'"

case "$mode" in
  diff) echo "(rien n'a été écrit)" ;;
  --apply-scripts)
    ssh "$HOST" "mkdir -p '$DEST' && chmod 700 '$DEST' && cp '$stage'/* '$DEST/' && chmod 700 '$DEST'/*.sh && chmod 600 '$DEST'/backup.conf.example '$DEST'/crontab.proposed"
    echo "scripts installés dans $DEST (aucune tâche cron modifiée)" ;;
  --apply-crontab)
    ssh "$HOST" "set -e; [ -x '$DEST/backup_mysql.sh' ] || { echo 'scripts non installés (--apply-scripts)'; exit 1; }
      [ -f \"\$HOME/.surgicalhub-backup-passphrase\" ] || { echo 'passphrase absente : refus'; exit 1; }
      ! crontab -l | grep -q 'scripts/surgicalhub-backup/' || { echo 'crontab D-164 déjà installée : rien à faire'; exit 1; }
      b=/home/deploy/backups/cron/crontab_before_d164_\$(date +%Y%m%d_%H%M%S).txt; crontab -l > \"\$b\"
      { crontab -l | grep -vE '$OLD_LINES'; echo; echo '# D-164 — sauvegardes SurgicalHub (deploy/server/backup)'; echo 'CRON_TZ=UTC'; grep -vE '^\s*(#|$)' '$DEST/crontab.proposed'; } | crontab -
      echo \"crontab remplacée ; ancienne version : \$b\"" ;;
  *) echo "option inconnue : $mode" >&2; exit 2 ;;
esac
