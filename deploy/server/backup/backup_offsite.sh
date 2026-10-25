#!/usr/bin/env bash
# SurgicalHub — copie hors serveur (Google Drive, rclone) des sauvegardes CHIFFRÉES (D-164).
# Remplace sync_gdrive.sh : `rclone sync` répercutait chaque suppression locale sur le Drive ;
# ici `rclone copy` n'efface jamais rien à distance, et la rétention distante est indépendante.
#
#   backup_offsite.sh                        copie + vérification, rotation distante en DRY-RUN
#   backup_offsite.sh --apply-remote-rotation  copie + vérification + rotation distante réelle
#
# Seuls les fichiers *.gpg (et leur .sha256) sortent du serveur : jamais un dump en clair, jamais
# une copie de .env. Les anciens dossiers en clair (« mysql », « uploads ») ne sont pas touchés.
set -euo pipefail
LOG_TAG=offsite
# shellcheck source=lib.sh
. "$(dirname "$(readlink -f "$0")")/lib.sh"
export PATH="$HOME/bin:$PATH"
APPLY_REMOTE=0; [ "${1:-}" = --apply-remote-rotation ] && APPLY_REMOTE=1
command -v "$RCLONE" > /dev/null || die "rclone introuvable."
rc=0

# copy_dir <dir local> <sous-dossier distant> <regex des sauvegardes (rotation distante)> <mode> <préfixe rclone>
copy_dir() {
  local dir="$1" sub="$2" re="$3" mode="$4" glob="$5" remote="$REMOTE_BASE/$2" latest
  [ -d "$dir" ] || return 0
  "$RCLONE" copy "$dir" "$remote" --include "$glob.gpg" --include "$glob.gpg.sha256" --log-file "$BACKUP_LOG" --log-level NOTICE \
    || { log "ERREUR copie $dir → $remote"; rc=1; return 0; }
  latest=$(ls -1 "$dir" | grep -E "$re" | LC_ALL=C sort | tail -1 || true)
  if [ -n "$latest" ]; then
    "$RCLONE" check "$dir" "$remote" --one-way --include "$latest" --log-file "$BACKUP_LOG" --log-level NOTICE \
      && log "OK $sub : $latest vérifié à distance" || { log "ERREUR $sub : $latest non vérifié à distance"; rc=1; return 0; }
  fi
  # Rotation distante : uniquement si la dernière copie est vérifiée, jamais la plus récente.
  local listing keep n del=0
  listing=$("$RCLONE" lsf "$remote" --files-only | grep -E "$re" | while read -r n; do echo "$(stamp_epoch "$n") $n"; done || true)
  if [ "$mode" = gfs ]; then
    keep=$(gfs_keep "$REMOTE_KEEP_DAILY_DAYS" "$REMOTE_KEEP_WEEKLY" "$REMOTE_KEEP_MONTHLY" <<< "$listing")
  else
    keep=$(age_keep "$REMOTE_KEEP_VOLUME_DAYS" "$KEEP_VOLUME_MIN" <<< "$listing")
  fi
  while read -r _ n; do
    [ -n "$n" ] || continue
    grep -qxF -- "$n" <<< "$keep" && continue
    del=$((del + 1))
    if [ "$APPLY_REMOTE" = 1 ]; then
      "$RCLONE" deletefile "$remote/$n" && "$RCLONE" deletefile "$remote/$n.sha256" 2>/dev/null || true
      log "rotation distante : $sub/$n"
    fi
  done <<< "$listing"
  log "$sub : $(echo "$listing" | awk NF | wc -l) à distance, $del $( [ "$APPLY_REMOTE" = 1 ] && echo supprimé\(s\) || echo "à supprimer (dry-run)")"
}

copy_dir "$MYSQL_DIR" mysql '^all_[0-9]{8}_[0-9]{6}\.sql\.gz\.gpg$' gfs 'all_*'
copy_dir "$MYSQL_DIR" mysql-pre-deploy '^surgicalhub_pre_deploy_.+\.sql\.gz\.gpg$' age 'surgicalhub_pre_deploy_*'
# Uploads : une série par volume, comme en local — la dernière archive de CHAQUE volume est
# vérifiée à distance, et le minimum de $KEEP_VOLUME_MIN archives s'applique par volume.
for pair in $UPLOAD_VOLUMES; do
  l="${pair##*:}"
  copy_dir "$UPLOADS_DIR" uploads "^${l}_[0-9]{8}_[0-9]{6}\.tar\.gz\.gpg\$" age "${l}_*"
done
copy_dir "$VAR_DIR" surgicalhub-var "^${VAR_VOLUME}_[0-9]{8}_[0-9]{6}\\.tar\\.gz\\.gpg\$" age '*'
exit "$rc"
