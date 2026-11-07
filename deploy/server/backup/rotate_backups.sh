#!/usr/bin/env bash
# SurgicalHub — rotation des sauvegardes LOCALES, des images de rollback et du cache de build
# (D-164). Remplace /home/deploy/scripts/rotate_backups.sh.
#
#   rotate_backups.sh            DRY-RUN (défaut) : ce qui serait supprimé, et l'espace récupéré
#   rotate_backups.sh --list     DRY-RUN avec la liste détaillée des éléments supprimés
#   rotate_backups.sh --apply    suppression réelle — refusée sans test de restauration réussi
#                                récent (restore_test.sh, marqueur $BACKUP_ROOT/.restore-test-ok)
#
# Ne supprime QUE des fichiers dont le nom correspond exactement à un format connu, dans les
# répertoires configurés (jamais de lien symbolique, jamais de récursion). Tout le reste est
# signalé « non géré » et conservé. La sauvegarde la plus récente de chaque série est toujours
# conservée. Aucune règle ne s'applique à la copie hors serveur (backup_offsite.sh).
set -euo pipefail
LOG_TAG=rotate
# shellcheck source=lib.sh
. "$(dirname "$(readlink -f "$0")")/lib.sh"

MODE=dry-run; LIST=0
case "${1:-}" in
  --apply) MODE=apply ;;
  --list) LIST=1 ;;
  "") ;;
  *) echo "usage : $0 [--list|--apply]" >&2; exit 2 ;;
esac
RESTORE_TEST_MAX_AGE_DAYS="${RESTORE_TEST_MAX_AGE_DAYS:-35}"

if [ "$MODE" = apply ]; then
  marker="$BACKUP_ROOT/.restore-test-ok"
  [ -f "$marker" ] || die "aucun test de restauration réussi ($marker) : suppression refusée."
  age=$(( (NOW - $(stat -c %Y "$marker")) / 86400 ))
  [ "$age" -le "$RESTORE_TEST_MAX_AGE_DAYS" ] || die "dernier test de restauration il y a $age jours (> $RESTORE_TEST_MAX_AGE_DAYS) : suppression refusée."
fi

TOTAL_BYTES=0
SUMMARY=()

# files_matching <dir> <regex> : « epoch nom » des fichiers réguliers dont le nom correspond.
files_matching() {
  local dir="$1" re="$2" f n e
  [ -d "$dir" ] || return 0
  find "$dir" -maxdepth 1 -type f -printf '%f\n' | LC_ALL=C sort | while IFS= read -r n; do
    [[ "$n" =~ $re ]] || continue
    e=$(stamp_epoch "$n"); [ -n "$e" ] || e=$(stat -c %Y "$dir/$n")
    echo "$e $n"
  done
}

# apply_series <libellé> <dir> <liste candidats « epoch nom »> <liste à conserver>
apply_series() {
  local label="$1" dir="$2" candidates="$3" keep="$4" n del=0 bytes=0 total=0 sz
  while read -r _ n; do
    [ -n "$n" ] || continue
    total=$((total + 1))
    grep -qxF -- "$n" <<< "$keep" && continue
    sz=$(stat -c %s "$dir/$n"); [ -f "$dir/$n.sha256" ] && sz=$((sz + $(stat -c %s "$dir/$n.sha256")))
    del=$((del + 1)); bytes=$((bytes + sz))
    [ "$LIST" = 1 ] && echo "    - $dir/$n ($(human "$sz"))"
    if [ "$MODE" = apply ]; then rm -f -- "$dir/$n" "$dir/$n.sha256"; log "supprimé $dir/$n"; fi
  done <<< "$candidates"
  TOTAL_BYTES=$((TOTAL_BYTES + bytes))
  SUMMARY+=("$(printf '%-42s %5d au total, %5d conservés, %5d à supprimer (%s)' "$label" "$total" $((total - del)) "$del" "$(human "$bytes")")")
}

# ── Dumps MySQL quotidiens : grand-père / père / fils ────────────────────────
c=$(files_matching "$MYSQL_DIR" '^all_[0-9]{8}_[0-9]{6}\.sql\.gz(\.gpg)?$')
apply_series "MySQL quotidiens (${KEEP_DAILY_DAYS} j / ${KEEP_WEEKLY} sem. / ${KEEP_MONTHLY} mois)" "$MYSQL_DIR" "$c" \
  "$(gfs_keep "$KEEP_DAILY_DAYS" "$KEEP_WEEKLY" "$KEEP_MONTHLY" <<< "$c")"

# ── Dumps d'avant déploiement ────────────────────────────────────────────────
c=$(files_matching "$MYSQL_DIR" '^surgicalhub_pre_deploy_.+\.sql\.gz(\.gpg)?$')
apply_series "MySQL d'avant déploiement (${KEEP_PRE_DEPLOY} derniers)" "$MYSQL_DIR" "$c" "$(newest_keep "$KEEP_PRE_DEPLOY" <<< "$c")"

# ── Archives des volumes (par volume) ───────────────────────────────────────
for pair in $UPLOAD_VOLUMES; do
  l="${pair##*:}"
  c=$(files_matching "$UPLOADS_DIR" "^${l}_[0-9]{8}_[0-9]{6}\\.tar\\.gz(\\.gpg)?\$")
  apply_series "Uploads $l (${KEEP_VOLUME_DAYS} j, min. ${KEEP_VOLUME_MIN})" "$UPLOADS_DIR" "$c" "$(age_keep "$KEEP_VOLUME_DAYS" "$KEEP_VOLUME_MIN" <<< "$c")"
done
c=$(files_matching "$VAR_DIR" "^${VAR_VOLUME}_[0-9]{8}_[0-9]{6}\\.tar\\.gz\\.gpg\$")
apply_series "Volume $VAR_VOLUME (${KEEP_VOLUME_DAYS} j, min. ${KEEP_VOLUME_MIN})" "$VAR_DIR" "$c" "$(age_keep "$KEEP_VOLUME_DAYS" "$KEEP_VOLUME_MIN" <<< "$c")"

# ── Archives de code et copies de .env (backups/code) ────────────────────────
c=$(files_matching "$CODE_DIR" '^(src_pre_deploy|pre)_[0-9]{8}_[0-9]{6}\.tar\.gz$')
apply_series "Archives de code (${KEEP_CODE} dernières)" "$CODE_DIR" "$c" "$(newest_keep "$KEEP_CODE" <<< "$c")"
c=$(files_matching "$CODE_DIR" '^[a-z_]*env_pre_[A-Za-z0-9_.-]+$')
apply_series "Copies de .env (${KEEP_ENV_COPIES} dernières)" "$CODE_DIR" "$c" "$(newest_keep "$KEEP_ENV_COPIES" <<< "$c")"
if [ "$MODE" = apply ] && [ -d "$CODE_DIR" ]; then
  find "$CODE_DIR" -maxdepth 1 -type f -perm /077 -exec chmod 600 {} +   # secrets : jamais lisibles par d'autres
fi

# ── deploy-backups/<horodatage>/ (D-163) ─────────────────────────────────────
if [ -d "$DEPLOY_BACKUPS" ]; then
  legacy_dir=""
  if "$DOCKER" image inspect surgicalhub-php:rollback-legacy > /dev/null 2>&1; then
    for d in "$DEPLOY_BACKUPS"/*/; do   # nécessaire à « deploy.sh rollback legacy » tant que l'image existe
      [ "$(cat "$d/previous_release" 2>/dev/null)" = legacy ] && legacy_dir=$(basename "$d")
    done
  fi
  c=$(find "$DEPLOY_BACKUPS" -mindepth 1 -maxdepth 1 -type d -printf '%f\n' | grep -E '^[0-9]{8}_[0-9]{6}$' | while read -r n; do echo "$(stamp_epoch "$n") $n"; done || true)
  keep=$(newest_keep "$KEEP_DEPLOY_BACKUPS" <<< "$c"; [ -n "$legacy_dir" ] && echo "$legacy_dir")
  del=0; bytes=0; total=0
  while read -r _ n; do
    [ -n "$n" ] || continue; total=$((total + 1))
    grep -qxF -- "$n" <<< "$keep" && continue
    sz=$(du -sb "$DEPLOY_BACKUPS/$n" | cut -f1); del=$((del + 1)); bytes=$((bytes + sz))
    [ "$LIST" = 1 ] && echo "    - $DEPLOY_BACKUPS/$n/ ($(human "$sz"))"
    if [ "$MODE" = apply ]; then rm -rf -- "${DEPLOY_BACKUPS:?}/$n"; log "supprimé $DEPLOY_BACKUPS/$n/"; fi
  done <<< "$c"
  TOTAL_BYTES=$((TOTAL_BYTES + bytes))
  SUMMARY+=("$(printf '%-42s %5d au total, %5d conservés, %5d à supprimer (%s)' "deploy-backups (${KEEP_DEPLOY_BACKUPS} derniers + legacy)" "$total" $((total - del)) "$del" "$(human "$bytes")")")
fi

# ── Images de rollback et releases/ ──────────────────────────────────────────
# Une « release » = un tag commun aux images php et nginx : <sha> (D-163) ou rollback-<sha court>.
# Toujours protégées : rollback-legacy, les images utilisées par un conteneur (même arrêté),
# celles de :local, la release active et la précédente. Puis les N plus récentes.
protected_ids=$( { "$DOCKER" ps -aq | xargs -r "$DOCKER" inspect -f '{{.Image}}'; \
                   "$DOCKER" image inspect -f '{{.Id}}' surgicalhub-php:local surgicalhub-nginx:local 2>/dev/null; } | sort -u || true)
current=$( [ -L "$APP_ROOT/current" ] && basename "$(readlink "$APP_ROOT/current")" || echo legacy)
previous=$(ls -1d "$DEPLOY_BACKUPS"/*/ 2>/dev/null | tail -1 | xargs -r -I{} cat {}previous_release 2>/dev/null || true)
rel=$("$DOCKER" image ls --format '{{.Tag}}' surgicalhub-php | grep -E '^([0-9a-f]{40}|rollback-[0-9a-f]{7,40})$' | sort -u | while read -r t; do
  echo "$(date -u -d "$("$DOCKER" image inspect -f '{{.Created}}' "surgicalhub-php:$t")" +%s) $t"; done || true)
keep=$( { newest_keep "$KEEP_RELEASES" <<< "$rel"; echo "$current"; echo "$previous"; } | awk NF)
del=0; bytes=0; total=0; img_del=()
while read -r _ t; do
  [ -n "$t" ] || continue; total=$((total + 1))
  grep -qxF -- "$t" <<< "$keep" && continue
  skip=0
  for r in surgicalhub-php surgicalhub-nginx; do
    id=$("$DOCKER" image inspect -f '{{.Id}}' "$r:$t" 2>/dev/null || true)
    [ -n "$id" ] && grep -qxF -- "$id" <<< "$protected_ids" && skip=1
  done
  [ "$skip" = 1 ] && continue
  del=$((del + 1))
  for r in surgicalhub-php surgicalhub-nginx; do
    "$DOCKER" image inspect "$r:$t" > /dev/null 2>&1 || continue
    sz=$("$DOCKER" image inspect -f '{{.Size}}' "$r:$t"); bytes=$((bytes + sz)); img_del+=("$r:$t")
  done
  [ "$LIST" = 1 ] && echo "    - images surgicalhub-{php,nginx}:$t$( [ -d "$APP_ROOT/releases/$t" ] && echo " + releases/$t")"
  if [ "$MODE" = apply ]; then
    for r in surgicalhub-php surgicalhub-nginx; do "$DOCKER" image rm "$r:$t" > /dev/null 2>&1 && log "image retirée $r:$t" || true; done
    [[ "$t" =~ ^[0-9a-f]{40}$ ]] && [ -d "$APP_ROOT/releases/$t" ] && rm -rf -- "${APP_ROOT:?}/releases/$t" && log "release retirée releases/$t"
  fi
done <<< "$rel"
SUMMARY+=("$(printf '%-42s %5d au total, %5d conservés, %5d à supprimer (%s apparents*)' "Releases / images rollback (${KEEP_RELEASES} + active, précédente, legacy)" "$total" $((total - del)) "$del" "$(human "$bytes")")")

# ── Cache de build ───────────────────────────────────────────────────────────
bc=$("$DOCKER" system df --format '{{.Type}}={{.Size}}' | sed -n 's/^Build Cache=//p' | head -1)
bc_bytes=$(numfmt --from=auto "${bc%B}" 2>/dev/null || echo 0)
max_bytes=$((BUILD_CACHE_MAX_GB * 1000 * 1000 * 1000))
if [ "$bc_bytes" -gt "$max_bytes" ]; then
  if pgrep -f 'buildkit|docker-buildx|compose .*build' > /dev/null 2>&1; then
    SUMMARY+=("Cache de build : ${bc:-0} > ${BUILD_CACHE_MAX_GB} Go, mais un build est en cours : rien n'est fait")
  elif [ "$MODE" = apply ]; then
    "$DOCKER" builder prune -f --filter "until=$BUILD_CACHE_UNTIL" | tail -1 | while read -r l; do log "cache de build : $l"; done
    SUMMARY+=("Cache de build : élagué (entrées de plus de $BUILD_CACHE_UNTIL)")
  else
    SUMMARY+=("Cache de build : ${bc:-0} > ${BUILD_CACHE_MAX_GB} Go → élagage des entrées de plus de $BUILD_CACHE_UNTIL")
  fi
else
  SUMMARY+=("Cache de build : ${bc:-0} ≤ ${BUILD_CACHE_MAX_GB} Go → rien à faire")
fi

# ── Fichiers non gérés (jamais supprimés, signalés pour décision) ────────────
unmanaged=$( { [ -d "$MYSQL_DIR" ] && find "$MYSQL_DIR" -maxdepth 1 -type f ! -name '*.sha256' -printf '%f\n' | grep -vE '^(all_[0-9]{8}_[0-9]{6}|surgicalhub_pre_deploy_.+)\.sql\.gz(\.gpg)?$' || true; \
               [ -d "$CODE_DIR" ] && find "$CODE_DIR" -maxdepth 1 -type f -printf '%f\n' | grep -vE '^((src_pre_deploy|pre)_[0-9]{8}_[0-9]{6}\.tar\.gz|[a-z_]*env_pre_[A-Za-z0-9_.-]+)$' || true; } | wc -l)

echo "Rotation des sauvegardes — mode : $MODE ($(date '+%Y-%m-%d %H:%M'))"
printf '  %s\n' "${SUMMARY[@]}"
echo "  Espace récupéré (fichiers) : $(human "$TOTAL_BYTES") ; images : $(human "$bytes") apparents*"
echo "  Fichiers non gérés (conservés) : $unmanaged"
echo "  * taille apparente : les couches partagées entre images ne sont libérées qu'une fois."
[ "$MODE" = apply ] && log "rotation terminée ($(human "$TOTAL_BYTES") de fichiers)"
exit 0
