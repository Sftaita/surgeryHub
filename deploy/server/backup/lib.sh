#!/usr/bin/env bash
# SurgicalHub — bibliothèque commune des sauvegardes serveur (D-164). Sourcée par les scripts
# de deploy/server/backup/, jamais exécutée seule.
#
# Configuration : valeurs par défaut ci-dessous, surchargées par $BACKUP_CONF
# (défaut ~/.config/surgicalhub-backup.conf, modèle : backup.conf.example), puis par
# l'environnement (tests).

BACKUP_CONF="${BACKUP_CONF:-$HOME/.config/surgicalhub-backup.conf}"
# shellcheck disable=SC1090
[ -f "$BACKUP_CONF" ] && . "$BACKUP_CONF"

BACKUP_ROOT="${BACKUP_ROOT:-/home/deploy/backups}"
MYSQL_DIR="${MYSQL_DIR:-$BACKUP_ROOT/mysql}"
UPLOADS_DIR="${UPLOADS_DIR:-$BACKUP_ROOT/uploads}"
VAR_DIR="${VAR_DIR:-$BACKUP_ROOT/surgicalhub-var}"
CODE_DIR="${CODE_DIR:-$BACKUP_ROOT/code}"
APP_ROOT="${APP_ROOT:-/opt/stack/apps/surgicalhub}"
DEPLOY_BACKUPS="${DEPLOY_BACKUPS:-$APP_ROOT/deploy-backups}"
BACKUP_LOG="${BACKUP_LOG:-$BACKUP_ROOT/backup.log}"
PASSFILE="${PASSFILE:-$HOME/.surgicalhub-backup-passphrase}"
MYSQL_ENV_FILE="${MYSQL_ENV_FILE:-/opt/stack/mysql/.env}"
MYSQL_CONTAINER="${MYSQL_CONTAINER:-mysql}"
UPLOAD_VOLUMES="${UPLOAD_VOLUMES:-surgicalhub_uploads:surgicalhub medatwork_uploads:medatwork medclick_uploads:medclick}"
VAR_VOLUME="${VAR_VOLUME:-surgicalhub_var}"
REMOTE_BASE="${REMOTE_BASE:-gdrive:INFORMATIQUE/Base de donnée/surgicalhub-chiffre}"
DOCKER="${DOCKER:-docker}"
RCLONE="${RCLONE:-rclone}"
HELPER_IMAGE="${HELPER_IMAGE:-alpine:latest}"

# Rétention locale (validée le 2026-10-23).
KEEP_DAILY_DAYS="${KEEP_DAILY_DAYS:-14}"     # dumps quotidiens : tous pendant N jours,
KEEP_WEEKLY="${KEEP_WEEKLY:-8}"              # puis le plus récent de chacune des N dernières semaines,
KEEP_MONTHLY="${KEEP_MONTHLY:-12}"           # puis le plus récent de chacun des N derniers mois
KEEP_PRE_DEPLOY="${KEEP_PRE_DEPLOY:-10}"     # dumps d'avant déploiement
KEEP_VOLUME_DAYS="${KEEP_VOLUME_DAYS:-30}"   # archives uploads / var
KEEP_VOLUME_MIN="${KEEP_VOLUME_MIN:-3}"      # … jamais moins des N plus récentes par volume
KEEP_CODE="${KEEP_CODE:-10}"                 # archives de code d'avant déploiement
KEEP_ENV_COPIES="${KEEP_ENV_COPIES:-10}"     # copies de .env
KEEP_DEPLOY_BACKUPS="${KEEP_DEPLOY_BACKUPS:-10}"   # deploy-backups/<horodatage>/
KEEP_RELEASES="${KEEP_RELEASES:-5}"          # images (et releases/) des N dernières releases
BUILD_CACHE_MAX_GB="${BUILD_CACHE_MAX_GB:-8}"      # seuil au-delà duquel le cache de build est élagué
BUILD_CACHE_UNTIL="${BUILD_CACHE_UNTIL:-168h}"     # … seulement les entrées plus anciennes que cela

# Rétention hors serveur, indépendante (Google Drive), validée le 2026-10-23.
REMOTE_KEEP_DAILY_DAYS="${REMOTE_KEEP_DAILY_DAYS:-30}"
REMOTE_KEEP_WEEKLY="${REMOTE_KEEP_WEEKLY:-12}"
REMOTE_KEEP_MONTHLY="${REMOTE_KEEP_MONTHLY:-24}"
REMOTE_KEEP_VOLUME_DAYS="${REMOTE_KEEP_VOLUME_DAYS:-90}"

NOW="${RETENTION_NOW:-$(date +%s)}"   # surchargeable pour les tests

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] [${LOG_TAG:-backup}] $*" | tee -a "$BACKUP_LOG"; }
die() { log "ERREUR : $*"; exit 1; }

need_passfile() {
  [ -f "$PASSFILE" ] || die "$PASSFILE absent (passphrase de chiffrement, à conserver AUSSI hors du serveur)."
  [ "$(stat -c %a "$PASSFILE")" = 600 ] || die "$PASSFILE doit être en 600."
}

# encrypt_to <fichier.gpg> : chiffre l'entrée standard (AES-256, passphrase), vérifie que le
# résultat se déchiffre, puis écrit <fichier.gpg> et <fichier.gpg>.sha256 (600). Rien n'est
# laissé à moitié : le .part est supprimé en cas d'échec.
encrypt_to() {
  local out="$1"
  ( umask 077
    gpg --batch --yes --quiet --pinentry-mode loopback --passphrase-file "$PASSFILE" \
      --symmetric --cipher-algo AES256 -o "$out.part" ) || { rm -f "$out.part"; return 1; }
  decrypt "$out.part" > /dev/null || { rm -f "$out.part"; return 1; }
  mv "$out.part" "$out"
  (cd "$(dirname "$out")" && sha256sum "$(basename "$out")" > "$(basename "$out").sha256" && chmod 600 "$(basename "$out").sha256")
}

decrypt() { gpg --batch --quiet --pinentry-mode loopback --passphrase-file "$PASSFILE" -d "$1"; }

# Horodatage contenu dans un nom de fichier (YYYYMMDD_HHMMSS, YYYYMMDDTHHMMSSZ ou YYYYMMDD seul)
# → epoch ; vide si le nom n'en contient pas.
stamp_epoch() {
  local n="$1" d t
  if [[ "$n" =~ ([0-9]{8})[_T]([0-9]{6}) ]]; then d="${BASH_REMATCH[1]}"; t="${BASH_REMATCH[2]}"
  elif [[ "$n" =~ ([0-9]{8}) ]]; then d="${BASH_REMATCH[1]}"; t=000000
  else return 0; fi
  date -u -d "${d:0:4}-${d:4:2}-${d:6:2} ${t:0:2}:${t:2:2}:${t:4:2}" +%s 2>/dev/null || true
}

# gfs_keep <jours> <semaines> <mois> : lit « epoch nom » (une ligne par sauvegarde) et écrit
# les noms à CONSERVER : toutes celles de moins de <jours> jours, la plus récente de chacune
# des <semaines> dernières semaines ISO et de chacun des <mois> derniers mois. La plus récente
# est toujours conservée.
gfs_keep() {
  local days="$1" weeks="$2" months="$3" e n w m first=1
  declare -A seen_w=() seen_m=()
  local nw=0 nm=0
  sort -rn | while read -r e n; do
    [ -n "$n" ] || continue
    w=$(date -u -d "@$e" +%G-W%V); m=$(date -u -d "@$e" +%Y-%m)
    keep=0
    [ "$first" = 1 ] && keep=1; first=0
    [ $(( (NOW - e) / 86400 )) -lt "$days" ] && keep=1
    if [ -z "${seen_w[$w]:-}" ]; then seen_w[$w]=1; nw=$((nw + 1)); [ "$nw" -le "$weeks" ] && keep=1; fi
    if [ -z "${seen_m[$m]:-}" ]; then seen_m[$m]=1; nm=$((nm + 1)); [ "$nm" -le "$months" ] && keep=1; fi
    [ "$keep" = 1 ] && echo "$n"
  done
  return 0   # idem : jamais d'échec dû au dernier élément examiné
}

# newest_keep <n> : lit « epoch nom », écrit les <n> noms les plus récents.
newest_keep() { sort -rn | head -n "$1" | cut -d' ' -f2-; }

# age_keep <jours> <minimum> : lit « epoch nom », écrit les noms de moins de <jours> jours, et
# au moins les <minimum> plus récents.
age_keep() {
  local days="$1" min="$2" i=0 e n
  sort -rn | while read -r e n; do
    i=$((i + 1))
    if [ "$i" -le "$min" ] || [ $(( (NOW - e) / 86400 )) -lt "$days" ]; then echo "$n"; fi
  done
  return 0   # le statut du dernier test de la boucle ne doit jamais faire échouer l'appelant (set -e)
}

human() { numfmt --to=iec --suffix=o "${1:-0}" 2>/dev/null || echo "${1:-0} o"; }
