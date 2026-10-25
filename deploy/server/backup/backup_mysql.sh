#!/usr/bin/env bash
# SurgicalHub — dump MySQL quotidien chiffré (D-164). Remplace /home/deploy/scripts/backup_mysql.sh.
#   backup_mysql.sh                 → $MYSQL_DIR/all_<horodatage>.sql.gz.gpg (+ .sha256)
#   backup_mysql.sh --pre-deploy X  → $MYSQL_DIR/surgicalhub_pre_deploy_X_<horodatage>.sql.gz.gpg
# Toutes les bases (--all-databases), comme avant. La dernière ligne affichée est le fichier créé.
set -euo pipefail
LOG_TAG=mysql
# shellcheck source=lib.sh
. "$(dirname "$(readlink -f "$0")")/lib.sh"

prefix=all
if [ "${1:-}" = --pre-deploy ]; then
  [[ "${2:-}" =~ ^[A-Za-z0-9.-]+$ ]] || die "étiquette de pré-déploiement attendue (ex. v2026.10.23-prod)."
  prefix="surgicalhub_pre_deploy_$2"
fi

need_passfile
[ -f "$MYSQL_ENV_FILE" ] || die "$MYSQL_ENV_FILE introuvable."
"$DOCKER" inspect -f '{{.State.Running}}' "$MYSQL_CONTAINER" 2>/dev/null | grep -q true || die "conteneur $MYSQL_CONTAINER non démarré."
MYSQL_PWD_VALUE=$(grep -E '^MYSQL_ROOT_PASSWORD=' "$MYSQL_ENV_FILE" | cut -d= -f2- | tr -d '"'"'"'\r')
[ -n "$MYSQL_PWD_VALUE" ] || die "MYSQL_ROOT_PASSWORD vide dans $MYSQL_ENV_FILE."

mkdir -p "$MYSQL_DIR"
out="$MYSQL_DIR/${prefix}_$(date +%Y%m%d_%H%M%S).sql.gz.gpg"
log "démarrage → $(basename "$out")"
# Le mot de passe passe par l'environnement (MYSQL_PWD), jamais en argument : « -e MYSQL_PWD » sans
# valeur reprend celle de l'environnement du client docker, invisible dans la liste des processus.
MYSQL_PWD="$MYSQL_PWD_VALUE" "$DOCKER" exec -i -e MYSQL_PWD "$MYSQL_CONTAINER" \
    mysqldump -uroot --all-databases --single-transaction --routines --triggers --events \
  | gzip | encrypt_to "$out" || die "dump ou chiffrement échoué."

# Contrôle : déchiffrable, gzip valide, dump terminé.
decrypt "$out" | gunzip | tail -1 | grep -q 'Dump completed' || { rm -f "$out" "$out.sha256"; die "dump incomplet ou illisible."; }
log "OK $(basename "$out") ($(du -h "$out" | cut -f1))"
echo "$out"
