#!/bin/bash
# due_priorities.sh — EN MODE REPLI D-177 UNIQUEMENT, toutes les 5 minutes (crontab) :
# applique 03_due_priorities.sql (priorités échues → demandes refusées à leur échéance, à
# rediffuser par un manager). À retirer de la crontab dès le retour en avant.
set -uo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
LOG=/home/deploy/logs/d177-rollback-due-priorities.log
LOCK=/home/deploy/locks/d177-rollback-due-priorities.lock
mkdir -p "$(dirname "$LOG")" "$(dirname "$LOCK")"
exec 200>"$LOCK"
/usr/bin/flock -n 200 || exit 0
{ echo "[$(date '+%Y-%m-%d %H:%M:%S')] due-priorities"; "$DIR/run_sql.sh" "$DIR/03_due_priorities.sql"; } >> "$LOG" 2>&1
