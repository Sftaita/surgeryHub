#!/usr/bin/env bash
# SurgicalHub — déploiement reproductible d'un commit validé (D-163). Procédure complète :
# docs/deployment-versioning.md §4. Partie serveur : deploy/remote.sh (envoyé par ssh).
#
#   deploy/deploy.sh paths    <sha>           périmètre de l'archive (dérivé du compose et des Dockerfiles)
#   deploy/deploy.sh prepare  <sha>           archive + SHA-256 + contrôles de contenu (local)
#   deploy/deploy.sh upload   <sha>           transfert + contrôle d'intégrité sur le serveur
#   deploy/deploy.sh stage    <sha>           extraction vérifiée dans releases/<sha> + fichiers retirés
#   deploy/deploy.sh build    <sha>           images :<sha>, sans toucher au service
#   deploy/deploy.sh migrations <sha>         dry-run des migrations sur la nouvelle image
#   deploy/deploy.sh activate <sha>           sauvegardes, bascule, redémarrage, contrôle du code exécuté
#   deploy/deploy.sh migrate  <sha>           migrations réelles (après relecture du dry-run), cache, redémarrages
#   deploy/deploy.sh mark     <sha> <tag>     version officielle (APRÈS tests de santé et tag Git poussé)
#   deploy/deploy.sh rollback <sha|legacy>    release précédente, sa configuration et ses images
#   deploy/deploy.sh status
#   deploy/deploy.sh pending  [<sha>]         travaux non fusionnés / non déployés / non installés (D-169) ;
#                                             avec <sha> : correctifs P0/P1 absents de cette release, conflits
#
# Toujours un SHA complet : jamais un nom de branche, jamais un nom d'archive générique.
# Variables : DEPLOY_HOST (défaut surgicalhub-prod ; « local » = exécution locale, tests),
# APP_ROOT, DEPLOY_DIST (archives locales), DEPLOY_MAIN_REF (défaut origin/main).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEPLOY_HOST="${DEPLOY_HOST:-surgicalhub-prod}"
APP_ROOT="${APP_ROOT:-/opt/stack/apps/surgicalhub}"
DEPLOY_DIST="${DEPLOY_DIST:-$ROOT/.deploy-dist}"
DEPLOY_MAIN_REF="${DEPLOY_MAIN_REF:-origin/main}"
COMPOSE_FILE=deploy/production/docker-compose.yml

die() { echo "ERREUR : $*" >&2; exit 1; }
git_() { git -C "$ROOT" "$@"; }

need_sha() {
  [[ "${1:-}" =~ ^[0-9a-f]{40}$ ]] || die "SHA complet (40 caractères) attendu, reçu « ${1:-} »."
  git_ cat-file -e "$1^{commit}" 2>/dev/null || die "commit $1 inconnu localement (git fetch ?)."
}

archive_file() { echo "$DEPLOY_DIST/surgicalhub-$1.tar.gz"; }

# Périmètre = ce que la production construit réellement : le compose versionné, ses Dockerfiles,
# le .dockerignore du contexte, et toutes les sources COPY de ces Dockerfiles (hors --from).
cmd_paths() {
  need_sha "${1:-}"; local sha="$1" df src
  git_ cat-file -e "$sha:$COMPOSE_FILE" 2>/dev/null || die "$COMPOSE_FILE absent du commit $sha."
  {
    echo "$COMPOSE_FILE"
    echo ".dockerignore"
    for df in $(git_ show "$sha:$COMPOSE_FILE" | sed -nE 's/^\s*dockerfile:\s*([^ #]+).*/\1/p' | sort -u); do
      echo "$df"
      git_ show "$sha:$df" | tr -d '\r' | grep -E '^\s*COPY\s' | grep -v -- '--from=' \
        | sed -E 's/^\s*COPY\s+//; s/--[a-z]+=[^ ]+\s+//g' \
        | awk '{ for (i = 1; i < NF; i++) print $i }'
    done
  } | sed -E 's#/+$##; s#^\./##' | awk 'NF' | LC_ALL=C sort -u | awk '
      { p[NR] = $0 }
      END { for (i = 1; i <= NR; i++) { keep = 1
              for (j = 1; j <= NR; j++) if (i != j && index(p[i], p[j] "/") == 1) keep = 0
              if (keep) print p[i] } }' | while read -r src; do
    git_ cat-file -e "$sha:$src" 2>/dev/null || die "« $src » requis par la construction mais absent du commit $sha."
    echo "$src"
  done
}

cmd_prepare() {
  need_sha "${1:-}"; local sha="$1"
  git_ merge-base --is-ancestor "$sha" "$DEPLOY_MAIN_REF" 2>/dev/null \
    || die "$sha n'est pas sur $DEPLOY_MAIN_REF : seul un commit fusionné et validé est déployable."
  pending_summary "$sha"
  local paths; paths=$(cmd_paths "$sha") || exit 1
  mkdir -p "$DEPLOY_DIST"
  local out; out=$(archive_file "$sha")
  rm -f "$out" "$out.sha256"
  # shellcheck disable=SC2086
  git_ archive --format=tar.gz "$sha" -- $paths > "$out.tmp" || die "git archive a échoué."
  mv "$out.tmp" "$out"
  local rev; rev=$(tar xzOf "$out" backend/REVISION | tr -d '\r\n ')
  [ "$rev" = "$sha" ] || die "backend/REVISION de l'archive = « $rev » ≠ $sha (export-subst ?)."
  tar xzOf "$out" frontend/src/app/features/app-version/buildRevision.ts | grep -q "$sha" \
    || die "le bundle frontend ne portera pas le commit $sha."
  local bad; bad=$(tar tzf "$out" | grep -E '(^|/)\.env\.local$|(^|/)\.env\.[^/]+\.local$|\.pem$|\.key$|(^|/)\.env\.prod$|(^|/)(var|vendor|node_modules)/' || true)
  [ -z "$bad" ] && echo "• aucun fichier interdit" || die "fichiers interdits dans l'archive : $bad"
  sha256sum "$out" | cut -d' ' -f1 > "$out.sha256"
  echo "• périmètre : $(echo "$paths" | tr '\n' ' ')"
  echo "• archive : $out ($(tar tzf "$out" | grep -vc '/$') fichiers)"
  echo "• REVISION : $rev"
  echo "• SHA-256 : $(cat "$out.sha256")"
}

expected_sha256() { # recalculé à chaque usage : l'archive locale doit toujours correspondre
  local out; out=$(archive_file "$1")
  [ -f "$out" ] && [ -f "$out.sha256" ] || die "archive $out absente : lancer prepare $1."
  local actual; actual=$(sha256sum "$out" | cut -d' ' -f1)
  [ "$actual" = "$(cat "$out.sha256")" ] || die "l'archive locale a changé depuis prepare : refaire prepare $1."
  echo "$actual"
}

remote() { # remote <commande> <args...> — exécute deploy/remote.sh sur le serveur
  if [ "$DEPLOY_HOST" = local ]; then
    APP_ROOT="$APP_ROOT" bash "$ROOT/deploy/remote.sh" "$@"
  else
    ssh -o ConnectTimeout=20 "$DEPLOY_HOST" "APP_ROOT='$APP_ROOT' bash -s -- $*" < "$ROOT/deploy/remote.sh"
  fi
}

# D-169 — rapport des développements non déployés. Les CONSTATS ne bloquent pas le déploiement (le
# périmètre se décide explicitement ; un correctif P0/P1 absent de la release ressort en tête), mais
# un contrôle qui n'a pas pu s'exécuter arrête prepare : jamais d'échec silencieux. WORK_INVENTORY=off
# le désactive explicitement (tests isolés, ou passer outre en connaissance de cause) et le signale.
inventory() { node "$ROOT/deploy/work-inventory/inventory.mjs" --repo "$ROOT" "$@"; }
pending_summary() {
  if [ "${WORK_INVENTORY:-on}" = off ]; then
    echo "── ATTENTION : contrôle des travaux en attente DÉSACTIVÉ (WORK_INVENTORY=off) : rien n'a été vérifié (D-169)." >&2
    return 0
  fi
  command -v node > /dev/null 2>&1 \
    || die "contrôle des travaux en attente (D-169) impossible : node absent. Installer node, ou WORK_INVENTORY=off pour passer outre en connaissance de cause."
  echo "── Travaux en attente (D-169 ; constats non bloquants ; détail : deploy/deploy.sh pending $1)"
  local out rc=0
  out=$(inventory --fetch --release "$1" --summary --no-snapshot 2>&1) || rc=$?
  printf '%s\n' "$out" | sed 's/^/   /'
  [ "$rc" -eq 0 ] \
    || die "contrôle des travaux en attente NON EFFECTUÉ (code $rc) : release non préparée. Corriger l'erreur ci-dessus, ou WORK_INVENTORY=off pour passer outre en connaissance de cause."
  echo "──"
}
cmd_pending() {
  command -v node > /dev/null 2>&1 || die "node est requis pour le rapport des travaux en attente (D-169)."
  if [ -n "${1:-}" ]; then need_sha "$1"; inventory --fetch --server --release "$1"; else inventory --fetch --server; fi
}

cmd_upload() {
  need_sha "${1:-}"; local sha="$1" sum; sum=$(expected_sha256 "$sha")
  local name; name=$(basename "$(archive_file "$sha")")
  if [ "$DEPLOY_HOST" = local ]; then
    mkdir -p "$APP_ROOT/incoming" && cp "$(archive_file "$sha")" "$APP_ROOT/incoming/$name.part"
  else
    ssh -o ConnectTimeout=20 "$DEPLOY_HOST" "mkdir -p '$APP_ROOT/incoming'"
    scp -q "$(archive_file "$sha")" "$DEPLOY_HOST:$APP_ROOT/incoming/$name.part" || die "transfert échoué."
  fi
  # Le fichier transféré n'est renommé qu'après vérification de son empreinte sur le serveur.
  local check="cd '$APP_ROOT/incoming' && [ \"\$(sha256sum '$name.part' | cut -d' ' -f1)\" = '$sum' ] && mv '$name.part' '$name' || { rm -f '$name.part'; exit 1; }"
  if [ "$DEPLOY_HOST" = local ]; then bash -c "$check"; else ssh -o ConnectTimeout=20 "$DEPLOY_HOST" "$check"; fi \
    || die "empreinte différente après transfert : fichier supprimé, rien n'est extrait."
  remote verify "$sha" "$sum"
}

cmd_stage() { need_sha "${1:-}"; remote stage "$1" "$(expected_sha256 "$1")"; }

cmd_mark() {
  need_sha "${1:-}"; local sha="$1" tag="${2:-}"
  [[ "$tag" =~ ^v[0-9]{4}\.[0-9]{2}\.[0-9]{2}-prod(-[1-9][0-9]*)?$ ]] || die "tag de production attendu (vYYYY.MM.DD-prod[-N])."
  local remote_sha; remote_sha=$(git_ ls-remote origin "refs/tags/$tag^{}" | cut -f1)
  [ "$remote_sha" = "$sha" ] || die "le tag $tag n'est pas sur origin ou ne pointe pas sur $sha (trouvé « $remote_sha »)."
  remote mark "$sha" "$tag"
}

cmd="${1:-}"; shift || true
case "$cmd" in
  paths) cmd_paths "$@" ;;
  prepare) cmd_prepare "$@" ;;
  upload) cmd_upload "$@" ;;
  stage) cmd_stage "$@" ;;
  build|migrations|migrate|activate|diff) need_sha "${1:-}"; remote "$cmd" "$1" ;;
  rollback) [ "${1:-}" = legacy ] || need_sha "${1:-}"; remote rollback "$1" ;;
  mark) cmd_mark "$@" ;;
  status) remote status ;;
  pending) cmd_pending "$@" ;;
  *) sed -n '2,22p' "$0"; exit 2 ;;
esac
