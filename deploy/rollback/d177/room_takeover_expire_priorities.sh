#!/bin/bash
# room_takeover_expire_priorities.sh — D-177 (version normale) : filet de sécurité de
# l'expiration des priorités de reprise (app:room-takeover:expire-priorities), toutes les
# 5 minutes. Le message différé du worker reste le chemin normal ; cette commande rattrape une
# échéance ou un congé manqués. Idempotente. Modèle : missions_start_due.sh.
# Installé dans /home/deploy/scripts/ ; RETIRÉ de la crontab en cas de repli.
set -uo pipefail

LOG=/home/deploy/logs/room-takeover-expire-priorities.log
LOCK=/home/deploy/locks/room-takeover-expire-priorities.lock
PROJECT_DIR=/opt/stack/apps/surgicalhub
CONTAINER=surgicalhub-php
DOCKER=/usr/bin/docker
FLOCK=/usr/bin/flock

mkdir -p "$(dirname "$LOG")" "$(dirname "$LOCK")"
log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG"; }

exec 200>"$LOCK"
if ! "$FLOCK" -n 200; then
  log "[room-takeover-expire] SKIP — exécution précédente encore en cours (lock actif)"
  exit 0
fi

cd "$PROJECT_DIR" || { log "[room-takeover-expire] ERREUR : $PROJECT_DIR introuvable"; exit 1; }

if ! "$DOCKER" inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null | grep -q true; then
  log "[room-takeover-expire] ERREUR : conteneur $CONTAINER non démarré"
  exit 1
fi

OUTPUT=$("$DOCKER" compose exec -T php php bin/console app:room-takeover:expire-priorities --env=prod 2>&1)
RC=$?
echo "$OUTPUT" >> "$LOG"
if [[ $RC -eq 0 ]]; then log "[room-takeover-expire] OK — exit=$RC"; else log "[room-takeover-expire] ERREUR — exit=$RC"; fi
exit "$RC"
