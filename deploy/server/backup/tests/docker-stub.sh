#!/usr/bin/env bash
# Faux `docker` pour tests/rotation.sh. Images : $STUB/images/<repo>__<tag> = « id créé taille ».
# Conteneurs : $STUB/containers/<nom> = id de l'image utilisée. Journal : $STUB/docker.log.
set -uo pipefail
S="${STUB:?}"; echo "docker $*" >> "$S/docker.log"
img() { echo "$S/images/${1%%:*}__${1#*:}"; }
case "$1 ${2:-}" in
  "ps -aq") ls "$S/containers" 2>/dev/null ;;
  "inspect -f") shift 3; for c in "$@"; do cat "$S/containers/$c"; done ;;
  "image inspect")
    if [ "$3" = -f ]; then
      f=$(img "$5"); [ -f "$f" ] || exit 1
      for ref in "${@:5}"; do f=$(img "$ref"); [ -f "$f" ] || exit 1
        read -r id created size < "$f"
        case "$4" in *Id*) echo "$id" ;; *Created*) echo "$created" ;; *Size*) echo "$size" ;; esac
      done
    else [ -f "$(img "$3")" ]; fi ;;
  "image ls") repo="${!#}"; for f in "$S"/images/"${repo}"__*; do [ -f "$f" ] && echo "${f##*__}"; done ;;
  "image rm") rm -f "$(img "$3")" ;;
  "system df") echo "Images=1GB"; echo "Build Cache=${STUB_BUILD_CACHE:-1GB}" ;;
  "builder prune") echo "Total reclaimed space: 1GB" ;;
  *) exit 0 ;;
esac
