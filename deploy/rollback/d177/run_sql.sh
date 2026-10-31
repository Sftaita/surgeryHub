#!/bin/bash
# run_sql.sh <fichier.sql> — exécute un script SQL du repli D-177 sur la base de production,
# dans le conteneur MySQL (même accès que /home/deploy/scripts/backup_mysql.sh). Sortie tabulée.
set -uo pipefail
SQL="${1:?fichier SQL attendu}"
[ -f "$SQL" ] || { echo "introuvable : $SQL" >&2; exit 1; }
ROOTPW=$(grep -E "^MYSQL_ROOT_PASSWORD=" /opt/stack/mysql/.env | cut -d= -f2-)
docker exec -i mysql mysql -uroot -p"$ROOTPW" --default-character-set=utf8mb4 -t surgicalhub < "$SQL" 2>&1 | grep -v "Using a password"
