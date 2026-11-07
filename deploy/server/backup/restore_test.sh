#!/usr/bin/env bash
# SurgicalHub — test RÉEL de restauration des dernières sauvegardes (D-164).
#   restore_test.sh                → dernières sauvegardes LOCALES ;
#   restore_test.sh --from-remote  → dernières sauvegardes TÉLÉCHARGÉES depuis le Drive ($REMOTE_BASE).
#   Vérifie, sans jamais toucher à la production :
#     1. intégrité (SHA-256) et déchiffrement des dernières sauvegardes chiffrées ;
#     2. dump MySQL restauré dans un MySQL JETABLE (conteneur sans réseau, données en tmpfs),
#        bases et tables comptées, lignes de tables clés de surgicalhub ;
#     3. volume surgicalhub_var : clés JWT présentes et appariées, registre des versions lisible ;
#     4. volume uploads : archive lisible, nombre de fichiers comparé au volume en service.
# Tout est extrait dans un répertoire temporaire (700) supprimé à la sortie. En cas de succès,
# écrit le marqueur $BACKUP_ROOT/.restore-test-ok (exigé par « rotate_backups.sh --apply »), ou
# .restore-test-remote-ok avec --from-remote.
set -euo pipefail
LOG_TAG=restore-test
# shellcheck source=lib.sh
. "$(dirname "$(readlink -f "$0")")/lib.sh"
MYSQL_TEST_IMAGE="${MYSQL_TEST_IMAGE:-mysql:8.0}"
need_passfile

T=$(mktemp -d); chmod 700 "$T"
CT="surgicalhub-restore-test-$$"
cleanup() { "$DOCKER" rm -f "$CT" > /dev/null 2>&1 || true; rm -rf "$T"; }
trap cleanup EXIT
FAIL=0
ok() { log "OK   $*"; }
ko() { log "ÉCHEC $*"; FAIL=1; }

latest() { ls -1 "$1" 2>/dev/null | grep -E "$2" | LC_ALL=C sort | tail -1; }
check_sum() { (cd "$(dirname "$1")" && sha256sum -c --status "$(basename "$1").sha256"); }

# --from-remote : la dernière sauvegarde de chaque série est d'abord TÉLÉCHARGÉE depuis la copie
# hors serveur ($REMOTE_BASE), avec son .sha256, dans le répertoire temporaire ; tous les contrôles
# ci-dessous portent alors sur ces copies distantes, jamais sur les fichiers locaux.
MARKER="$BACKUP_ROOT/.restore-test-ok"
if [ "${1:-}" = --from-remote ]; then
  export PATH="$HOME/bin:$PATH"
  command -v "$RCLONE" > /dev/null || die "rclone introuvable."
  fetch_latest() { # fetch_latest <sous-dossier distant> <regex> <dir local>
    local n; mkdir -p "$3"
    n=$("$RCLONE" lsf "$REMOTE_BASE/$1" --files-only 2>/dev/null | grep -E "$2" | LC_ALL=C sort | tail -1 || true)
    [ -n "$n" ] || { log "aucune sauvegarde dans $REMOTE_BASE/$1"; return 0; }
    "$RCLONE" copyto "$REMOTE_BASE/$1/$n" "$3/$n" && "$RCLONE" copyto "$REMOTE_BASE/$1/$n.sha256" "$3/$n.sha256" \
      && log "     téléchargé depuis le Drive : $1/$n"
  }
  fetch_latest mysql '^all_[0-9]{8}_[0-9]{6}\.sql\.gz\.gpg$' "$T/remote/mysql"
  fetch_latest surgicalhub-var "^${VAR_VOLUME}_[0-9]{8}_[0-9]{6}\\.tar\\.gz\\.gpg\$" "$T/remote/var"
  for pair in $UPLOAD_VOLUMES; do fetch_latest uploads "^${pair##*:}_[0-9]{8}_[0-9]{6}\\.tar\\.gz\\.gpg\$" "$T/remote/uploads"; done
  MYSQL_DIR="$T/remote/mysql"; VAR_DIR="$T/remote/var"; UPLOADS_DIR="$T/remote/uploads"
  MARKER="$BACKUP_ROOT/.restore-test-remote-ok"
  log "test de restauration depuis la copie hors serveur ($REMOTE_BASE)"
fi

# ── 1+2. MySQL ───────────────────────────────────────────────────────────────
dump=$(latest "$MYSQL_DIR" '^all_[0-9]{8}_[0-9]{6}\.sql\.gz\.gpg$')
if [ -z "$dump" ]; then ko "aucun dump chiffré dans $MYSQL_DIR"
else
  f="$MYSQL_DIR/$dump"
  if check_sum "$f"; then ok "SHA-256 de $dump"; else ko "SHA-256 de $dump"; fi
  if decrypt "$f" > "$T/dump.sql.gz" && gunzip -t "$T/dump.sql.gz"; then ok "déchiffrement + gzip de $dump"; else ko "déchiffrement de $dump"; fi
  pw=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
  "$DOCKER" run -d --name "$CT" --network none --tmpfs /var/lib/mysql:rw,size=4g \
    -e MYSQL_ROOT_PASSWORD="$pw" "$MYSQL_TEST_IMAGE" --skip-log-bin > /dev/null
  for _ in $(seq 1 90); do
    "$DOCKER" exec -e MYSQL_PWD="$pw" "$CT" mysql -uroot -e 'SELECT 1' > /dev/null 2>&1 && break; sleep 2
  done
  if gunzip -c "$T/dump.sql.gz" | "$DOCKER" exec -i -e MYSQL_PWD="$pw" "$CT" mysql -uroot; then
    ok "dump restauré dans un MySQL jetable ($MYSQL_TEST_IMAGE, sans réseau)"
    q() { "$DOCKER" exec -e MYSQL_PWD="$pw" "$CT" mysql -uroot -N -B -e "$1"; }
    log "     bases : $(q "SELECT GROUP_CONCAT(schema_name) FROM information_schema.schemata WHERE schema_name NOT IN ('mysql','sys','performance_schema','information_schema')")"
    n=$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='surgicalhub'")
    if [ "${n:-0}" -gt 0 ]; then ok "surgicalhub : $n tables"; else ko "surgicalhub absente du dump"; fi
    for t in user mission doctrine_migration_versions; do
      log "     surgicalhub.$t : $(q "SELECT COUNT(*) FROM surgicalhub.\`$t\`" 2>/dev/null || echo '?') lignes"
    done
  else ko "restauration du dump"; fi
  "$DOCKER" rm -f "$CT" > /dev/null 2>&1 || true
fi

# ── 3. surgicalhub_var : clés JWT et registre des versions ───────────────────
va=$(latest "$VAR_DIR" "^${VAR_VOLUME}_[0-9]{8}_[0-9]{6}\\.tar\\.gz\\.gpg\$")
if [ -z "$va" ]; then ko "aucune archive de $VAR_VOLUME dans $VAR_DIR"
else
  f="$VAR_DIR/$va"; mkdir -p "$T/var"
  if check_sum "$f" && decrypt "$f" | tar xzf - -C "$T/var"; then ok "SHA-256, déchiffrement et extraction de $va"; else ko "archive $va"; fi
  if [ -s "$T/var/jwt/private.pem" ] && [ -s "$T/var/jwt/public.pem" ]; then
    # Appariement : la clé publique dérivée de la clé privée = public.pem (passphrase lue dans .env).
    pass=$(grep -E '^JWT_PASSPHRASE=' "$APP_ROOT/.env" 2>/dev/null | cut -d= -f2- | tr -d '"'"'"'\r' || true)
    if JWT_PASS="$pass" openssl pkey -in "$T/var/jwt/private.pem" -passin env:JWT_PASS -pubout 2>/dev/null | cmp -s - <(openssl pkey -pubin -in "$T/var/jwt/public.pem" -pubout); then
      ok "clés JWT restaurées et appariées"
    else ko "clés JWT restaurées mais non appariées (ou passphrase illisible)"; fi
  else ko "clés JWT absentes de l'archive"; fi
  if [ -s "$T/var/release/releases.json" ] && grep -q '"' "$T/var/release/releases.json"; then
    ok "registre des versions restauré ($(wc -c < "$T/var/release/releases.json") octets)"
  else ko "registre des versions absent de l'archive"; fi
  [ ! -e "$T/var/cache" ] && ok "cache Symfony exclu de l'archive" || ko "cache présent dans l'archive"
fi

# ── 4. uploads ──────────────────────────────────────────────────────────────
for pair in $UPLOAD_VOLUMES; do
  vol="${pair%%:*}"; l="${pair##*:}"
  ua=$(latest "$UPLOADS_DIR" "^${l}_[0-9]{8}_[0-9]{6}\\.tar\\.gz\\.gpg\$")
  [ -n "$ua" ] || { "$DOCKER" volume inspect "$vol" > /dev/null 2>&1 && ko "aucune archive chiffrée de $vol"; continue; }
  f="$UPLOADS_DIR/$ua"
  if check_sum "$f" && n=$(decrypt "$f" | tar tzf - | grep -vc '/$'); then
    live=$("$DOCKER" run --rm --network none --entrypoint sh -v "$vol:/d:ro" "$HELPER_IMAGE" -c 'find /d -type f | wc -l' 2>/dev/null || echo '?')
    ok "$ua lisible : $n fichiers (volume en service : $live)"
    first=$(decrypt "$f" | tar tzf - | grep -v '/$' | head -1)
    if [ -z "$first" ] || decrypt "$f" | tar xzf - -C "$T" "$first"; then ok "$l : extraction d'un fichier réussie"; else ko "$l : extraction"; fi
  else ko "archive $ua"; fi
done

if [ "$FAIL" = 0 ]; then
  date -u +%FT%TZ > "$MARKER"
  log "test de restauration RÉUSSI — marqueur $MARKER"
else
  log "test de restauration EN ÉCHEC — aucune rotation ne sera autorisée"; exit 1
fi
