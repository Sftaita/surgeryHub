#!/usr/bin/env bash
# SurgicalHub — partie SERVEUR du déploiement (D-163). Jamais lancé à la main : deploy/deploy.sh
# l'envoie par `ssh <hôte> bash -s -- <commande> <args> < deploy/remote.sh`.
#
# Arborescence gérée (APP_ROOT, défaut /opt/stack/apps/surgicalhub) :
#   .env                       secrets — lu, jamais écrit ni supprimé
#   docker-compose.yml         installé depuis releases/<sha>/deploy/production/ à l'activation
#   current -> releases/<sha>  release active (lien remplacé atomiquement)
#   releases/<sha>/            arbre exact du commit, extrait d'une archive vérifiée ; .release
#   incoming/                  archives transférées (surgicalhub-<sha>.tar.gz)
#   deploy-backups/<horodatage>/  compose, .env et volume var sauvegardés avant activation
#   src/                       ancienne copie manuelle (avant D-163) — jamais modifiée ici
#
# Volumes Docker (surgicalhub_uploads, surgicalhub_var) : jamais touchés, sauf lecture pour la
# sauvegarde. Toute vérification qui échoue arrête la commande AVANT toute modification.
set -euo pipefail

APP_ROOT="${APP_ROOT:-/opt/stack/apps/surgicalhub}"
DOCKER="${DOCKER:-docker}"
BACKUP_MYSQL="${BACKUP_MYSQL:-/home/deploy/scripts/backup_mysql.sh}"
PROJECT=surgicalhub

die() { echo "ERREUR : $*" >&2; exit 1; }
info() { echo "• $*"; }

is_sha() { [[ "${1:-}" =~ ^[0-9a-f]{40}$ ]]; }
need_sha() { is_sha "${1:-}" || die "SHA complet (40 caractères hexadécimaux) attendu, reçu « ${1:-} »."; }

# Fichiers qui ne doivent JAMAIS se trouver dans une release (secrets, données, dépendances).
forbidden_files() {
  find "$1" \( -name '.env.local' -o -name '.env.*.local' -o -name '*.pem' -o -name '*.key' -o -name '.env.prod' \
    -o -path '*/var/*' -o -path '*/vendor/*' -o -path '*/node_modules/*' \) -print
}

compose() { # compose <release-sha|current> args...
  local rel="$1"; shift
  local file="$APP_ROOT/releases/$rel/deploy/production/docker-compose.yml"
  [ "$rel" = current ] && file="$APP_ROOT/docker-compose.yml"
  "$DOCKER" compose --project-directory "$APP_ROOT" -f "$file" "$@"
}

current_release() {
  if [ -L "$APP_ROOT/current" ]; then basename "$(readlink "$APP_ROOT/current")"; else echo legacy; fi
}

image_revision() { # image_revision <image> → contenu de REVISION dans l'image
  "$DOCKER" run --rm --entrypoint cat "$1" /var/www/backend/REVISION 2>/dev/null | tr -d '\r\n '
}

# ── verify <sha> <sha256> : intégrité de l'archive transférée ─────────────────
cmd_verify() {
  need_sha "${1:-}"; local sha="$1" expected="${2:-}"
  [[ "$expected" =~ ^[0-9a-f]{64}$ ]] || die "empreinte SHA-256 attendue absente ou invalide."
  local archive="$APP_ROOT/incoming/surgicalhub-$sha.tar.gz"
  [ -f "$archive" ] || die "archive $archive introuvable (transfert échoué ?)."
  local actual; actual=$(sha256sum "$archive" | cut -d' ' -f1)
  [ "$actual" = "$expected" ] || die "empreinte de $archive = $actual, attendu $expected : archive altérée ou ancienne. Rien n'est extrait."
  local rev; rev=$(tar xzOf "$archive" backend/REVISION 2>/dev/null | tr -d '\r\n ') || true
  [ "$rev" = "$sha" ] || die "backend/REVISION de l'archive = « $rev », attendu $sha : ce n'est pas le commit autorisé. Rien n'est extrait."
  info "archive vérifiée : $archive (sha256 $expected, REVISION $sha)"
}

# ── stage <sha> <sha256> : extraction dans releases/<sha>, jamais dans la release active ──
cmd_stage() {
  cmd_verify "$@"
  local sha="$1" expected="$2"
  local dest="$APP_ROOT/releases/$sha"
  if [ -d "$dest" ]; then
    grep -qx "archive_sha256=$expected" "$dest/.release" 2>/dev/null \
      || die "$dest existe déjà avec une autre archive : refus (une release n'est jamais réécrite)."
    info "release $sha déjà préparée à l'identique."
    return 0
  fi
  mkdir -p "$APP_ROOT/releases"
  local tmp; tmp=$(mktemp -d "$APP_ROOT/releases/.staging-$sha-XXXXXX")
  # Toute sortie avant le `mv` final supprime le staging : la release n'existe jamais à moitié.
  trap 'rm -rf "$tmp"' EXIT
  tar xzf "$APP_ROOT/incoming/surgicalhub-$sha.tar.gz" -C "$tmp" || die "extraction impossible."
  [ "$(tr -d '\r\n ' < "$tmp/backend/REVISION")" = "$sha" ] || die "REVISION extrait ≠ $sha."
  [ -f "$tmp/deploy/production/docker-compose.yml" ] || die "deploy/production/docker-compose.yml absent de l'archive."
  local bad; bad=$(forbidden_files "$tmp")
  [ -z "$bad" ] || die "fichiers interdits dans l'archive (secrets, données, dépendances) : $bad"
  printf 'commit=%s\narchive_sha256=%s\nstaged_at=%s\n' "$sha" "$expected" "$(date -u +%FT%TZ)" > "$tmp/.release"
  (cd "$tmp" && find . -type f ! -name .release ! -name .files | sed 's#^\./##' | LC_ALL=C sort > .files)
  mv "$tmp" "$dest"
  trap - EXIT
  info "release préparée : $dest ($(wc -l < "$dest/.files") fichiers)"
  cmd_diff "$sha"
}

# ── diff <sha> : fichiers retirés / ajoutés par rapport à la release active ───
cmd_diff() {
  need_sha "${1:-}"; local sha="$1" cur; cur=$(current_release)
  local new="$APP_ROOT/releases/$sha/.files" old
  if [ "$cur" = legacy ]; then
    old=$(mktemp)
    (cd "$APP_ROOT/src" 2>/dev/null && find backend frontend docker -type f \
      ! -path 'backend/vendor/*' ! -path 'backend/var/*' ! -path 'frontend/node_modules/*' ! -path 'frontend/dist/*' 2>/dev/null \
      | LC_ALL=C sort) > "$old" || true
  else
    old="$APP_ROOT/releases/$cur/.files"
  fi
  echo "Fichiers absents de la nouvelle release (non livrés, jamais supprimés de la release active) :"
  LC_ALL=C comm -23 "$old" "$new" | sed 's/^/  - /' || true
  echo "Nombre de fichiers ajoutés : $(LC_ALL=C comm -13 "$old" "$new" | wc -l)"
}

# ── build <sha> : images surgicalhub-{php,nginx}:<sha>, sans toucher au service ─
cmd_build() {
  need_sha "${1:-}"; local sha="$1"
  [ -f "$APP_ROOT/releases/$sha/.release" ] || die "release $sha non préparée (stage)."
  info "build --no-cache de la release $sha (images :$sha, conteneurs en service inchangés)"
  IMAGE_TAG="$sha" SURGICALHUB_RELEASE_DIR="./releases/$sha" compose "$sha" build --no-cache \
    || die "échec du build : rien n'est activé, la version en service est inchangée."
  local rev; rev=$(image_revision "$PROJECT-php:$sha")
  [ "$rev" = "$sha" ] || die "l'image $PROJECT-php:$sha contient REVISION « $rev » ≠ $sha."
  "$DOCKER" run --rm --entrypoint sh "$PROJECT-nginx:$sha" -c "grep -rqs $sha /var/www/frontend/assets" \
    || die "le bundle de $PROJECT-nginx:$sha ne porte pas le commit $sha."
  info "images vérifiées : $PROJECT-php:$sha et $PROJECT-nginx:$sha portent le commit $sha"
}

# ── migrations <sha> : dry-run sur la nouvelle image, avant activation ────────
cmd_migrations() {
  need_sha "${1:-}"; local sha="$1"
  IMAGE_TAG="$sha" compose "$sha" run --rm --no-deps -T php php bin/console doctrine:migrations:migrate --dry-run --no-interaction --env=prod
}

# ── migrate <sha> : migrations réelles, APRÈS relecture du dry-run et activation ──
cmd_migrate() {
  need_sha "${1:-}"; local sha="$1"
  [ "$(current_release)" = "$sha" ] || die "la release active n'est pas $sha : migrations refusées."
  "$DOCKER" exec "$PROJECT-php" php bin/console doctrine:migrations:migrate --no-interaction --env=prod
  "$DOCKER" exec "$PROJECT-php" php bin/console cache:clear --env=prod
  "$DOCKER" restart "$PROJECT-php" "$PROJECT-worker" >/dev/null
  "$DOCKER" restart "$PROJECT-nginx" >/dev/null
  "$DOCKER" exec "$PROJECT-php" php bin/console doctrine:migrations:status --env=prod
}

backup_before_switch() {
  local stamp; stamp=$(date +%Y%m%d_%H%M%S)
  local dir="$APP_ROOT/deploy-backups/$stamp"
  mkdir -p "$dir"; chmod 700 "$dir"
  if [ -x "$BACKUP_MYSQL" ]; then "$BACKUP_MYSQL" | tail -1; else die "script de sauvegarde MySQL $BACKUP_MYSQL introuvable."; fi
  [ -f "$APP_ROOT/docker-compose.yml" ] && cp -p "$APP_ROOT/docker-compose.yml" "$dir/docker-compose.yml"
  [ -f "$APP_ROOT/.env" ] && { cp -p "$APP_ROOT/.env" "$dir/env"; chmod 600 "$dir/env"; }
  "$DOCKER" run --rm -v "${PROJECT}_var:/data:ro" -v "$dir:/backup" alpine tar czf /backup/surgicalhub_var.tar.gz -C /data . \
    || die "sauvegarde du volume ${PROJECT}_var échouée."
  echo "$(current_release)" > "$dir/previous_release"
  echo "$dir"
}

switch_to() { # switch_to <sha> : release, compose et images, puis redémarrage
  local sha="$1"
  ln -sfn "releases/$sha" "$APP_ROOT/.current.tmp" && mv -T "$APP_ROOT/.current.tmp" "$APP_ROOT/current"
  cp "$APP_ROOT/releases/$sha/deploy/production/docker-compose.yml" "$APP_ROOT/.docker-compose.yml.tmp"
  mv "$APP_ROOT/.docker-compose.yml.tmp" "$APP_ROOT/docker-compose.yml"
  "$DOCKER" tag "$PROJECT-php:$sha" "$PROJECT-php:local"
  "$DOCKER" tag "$PROJECT-nginx:$sha" "$PROJECT-nginx:local"
  compose current up -d
  "$DOCKER" exec "$PROJECT-php" php bin/console cache:clear --env=prod
  "$DOCKER" restart "$PROJECT-php" "$PROJECT-worker" >/dev/null
  "$DOCKER" restart "$PROJECT-nginx" >/dev/null   # toujours après php (incident du 2026-10-13)
  local c rev
  for c in "$PROJECT-php" "$PROJECT-worker"; do
    rev=$("$DOCKER" exec "$c" cat /var/www/backend/REVISION | tr -d '\r\n ')
    [ "$rev" = "$sha" ] || die "$c exécute « $rev » au lieu de $sha."
  done
  info "en service : $sha (php et worker vérifiés)"
}

# ── activate <sha> : sauvegardes, puis bascule ; ne déclare JAMAIS la version officielle ──
cmd_activate() {
  need_sha "${1:-}"; local sha="$1" prev; prev=$(current_release)
  [ -f "$APP_ROOT/releases/$sha/.release" ] || die "release $sha non préparée."
  [ "$(image_revision "$PROJECT-php:$sha")" = "$sha" ] || die "image $PROJECT-php:$sha absente ou incohérente (build)."
  [ "$prev" != "$sha" ] || die "$sha est déjà la release active."
  local dir; dir=$(backup_before_switch | tail -1)
  info "sauvegardes : $dir (+ dump MySQL)"
  if [ "$prev" = legacy ]; then
    # Première activation D-163 : les images en service n'ont pas de tag par commit.
    "$DOCKER" tag "$PROJECT-php:local" "$PROJECT-php:rollback-legacy"
    "$DOCKER" tag "$PROJECT-nginx:local" "$PROJECT-nginx:rollback-legacy"
  fi
  switch_to "$sha"
  info "version non officielle tant que le tag n'est pas enregistré (deploy.sh mark)."
}

# ── rollback <sha|legacy> : release précédente, sa configuration et ses images ───────
cmd_rollback() {
  local target="${1:-}" prev; prev=$(current_release)
  if [ "$target" = legacy ]; then
    local dir="" d
    for d in "$APP_ROOT"/deploy-backups/*/; do   # ordre chronologique : la plus récente l'emporte
      if [ "$(cat "$d/previous_release" 2>/dev/null)" = legacy ]; then dir="${d%/}"; fi
    done
    [ -n "$dir" ] && [ -f "$dir/docker-compose.yml" ] || die "aucune sauvegarde de la configuration d'avant D-163."
    "$DOCKER" image inspect "$PROJECT-php:rollback-legacy" >/dev/null 2>&1 || die "images rollback-legacy absentes."
    cp "$dir/docker-compose.yml" "$APP_ROOT/docker-compose.yml"
    rm -f "$APP_ROOT/current"
    "$DOCKER" tag "$PROJECT-php:rollback-legacy" "$PROJECT-php:local"
    "$DOCKER" tag "$PROJECT-nginx:rollback-legacy" "$PROJECT-nginx:local"
    "$DOCKER" compose --project-directory "$APP_ROOT" -f "$APP_ROOT/docker-compose.yml" up -d
    "$DOCKER" restart "$PROJECT-php" "$PROJECT-worker" >/dev/null
    "$DOCKER" restart "$PROJECT-nginx" >/dev/null
    info "retour à la configuration d'avant D-163 (src/, images rollback-legacy)."
    return 0
  fi
  need_sha "$target"
  [ "$target" != "$prev" ] || die "$target est déjà la release active."
  [ -f "$APP_ROOT/releases/$target/.release" ] || die "release $target absente de releases/."
  [ "$(image_revision "$PROJECT-php:$target")" = "$target" ] || die "images :$target absentes ou incohérentes."
  switch_to "$target"
  info "rollback effectué : $prev → $target. Le registre des versions n'est pas modifié ; /api/version affiche la version restaurée."
}

# ── mark <sha> <tag> : version officielle, après tests de santé et tag Git ────
cmd_mark() {
  need_sha "${1:-}"; local sha="$1" tag="${2:-}"
  [ "$(current_release)" = "$sha" ] || die "la release active n'est pas $sha : rien n'est enregistré."
  "$DOCKER" exec "$PROJECT-php" php bin/console app:release:mark "$tag" --commit="$sha" --env=prod
  "$DOCKER" exec "$PROJECT-php" php bin/console app:release:show --env=prod
}

cmd_status() {
  echo "release active : $(current_release)"
  [ -d "$APP_ROOT/releases" ] && ls -1 "$APP_ROOT/releases" | grep -E '^[0-9a-f]{40}$' | sed 's/^/  releases\//' || true
  "$DOCKER" exec "$PROJECT-php" php bin/console app:release:show --env=prod 2>/dev/null || true
}

cmd="${1:-}"; shift || true
case "$cmd" in
  verify) cmd_verify "$@" ;;
  stage) cmd_stage "$@" ;;
  diff) cmd_diff "$@" ;;
  build) cmd_build "$@" ;;
  migrations) cmd_migrations "$@" ;;
  migrate) cmd_migrate "$@" ;;
  activate) cmd_activate "$@" ;;
  rollback) cmd_rollback "$@" ;;
  mark) cmd_mark "$@" ;;
  status) cmd_status ;;
  *) die "commande inconnue « $cmd »" ;;
esac
