#!/usr/bin/env bash
# Faux `docker` pour deploy/tests/run.sh : journalise chaque appel dans $STUB_LOG et simule
# l'état des images (STUB_STATE/images/<repo>:<tag> contient le commit porté par l'image).
# STUB_BUILD_FAIL=1 : le build échoue. Ne touche jamais au vrai Docker.
set -uo pipefail
STATE="${STUB_STATE:?}"
mkdir -p "$STATE/images"
echo "docker $*" >> "${STUB_LOG:?}"

img_file() { local n="${1//\//_}"; echo "$STATE/images/${n//:/_}"; } # « : » interdit dans un nom de fichier Windows

case "${1:-}" in
  compose)
    if printf '%s\n' "$@" | grep -qx build; then
      [ "${STUB_BUILD_FAIL:-0}" = 1 ] && { echo "build failed (stub)" >&2; exit 1; }
      echo "${IMAGE_TAG:?}" > "$(img_file "surgicalhub-php:$IMAGE_TAG")"
      echo "$IMAGE_TAG" > "$(img_file "surgicalhub-nginx:$IMAGE_TAG")"
      echo "build context=${SURGICALHUB_RELEASE_DIR:-} tag=$IMAGE_TAG" >> "$STUB_LOG"
    fi
    exit 0 ;;
  tag)
    f=$(img_file "$2"); [ -f "$f" ] || { echo "No such image: $2" >&2; exit 1; }
    cp "$f" "$(img_file "$3")"; exit 0 ;;
  image)
    [ "${2:-}" = inspect ] && [ -f "$(img_file "$3")" ]; exit $? ;;
  run)
    # run --rm --entrypoint cat <image> /var/www/backend/REVISION | --entrypoint sh <image> -c ... | -v ...:/backup alpine tar ...
    if printf '%s\n' "$@" | grep -qx alpine; then
      dir=$(printf '%s\n' "$@" | sed -n 's#^\(.*\):/backup$#\1#p')
      [ -n "$dir" ] && : > "$dir/surgicalhub_var.tar.gz"; exit 0
    fi
    image=""; prev=""
    for a in "$@"; do if [ "$prev" = --entrypoint ]; then shift_next=1; elif [ "${shift_next:-0}" = 1 ]; then image="$a"; shift_next=2; fi; prev="$a"; done
    f=$(img_file "$image"); [ -f "$f" ] || exit 1
    printf '%s\n' "$@" | grep -qx cat && cat "$f"
    exit 0 ;;
  exec)
    if printf '%s\n' "$@" | grep -q "REVISION"; then cat "$(img_file surgicalhub-php:local)"; fi
    exit 0 ;;
  restart) exit 0 ;;
  *) exit 0 ;;
esac
