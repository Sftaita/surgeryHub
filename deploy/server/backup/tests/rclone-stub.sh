#!/usr/bin/env bash
# Faux `rclone` pour les tests : le « Drive » est le répertoire $RSTORE (gdrive:A/B → $RSTORE/A/B).
# Commandes : copy, check, lsf, copyto, deletefile. Journal : $RSTORE.log.
# RCLONE_FAIL_CHECK=1 : `check` échoue (copie distante non vérifiée).
set -uo pipefail
S="${RSTORE:?}"; echo "rclone $*" >> "$S.log"
path() { local p="$1"; [[ "$p" == *:* ]] && echo "$S/${p#*:}" || echo "$p"; }
cmd="$1"; shift
src=""; dst=""; inc=(); one=""
while [ $# -gt 0 ]; do
  case "$1" in
    --include) inc+=("$2"); shift 2 ;;
    --log-file|--log-level) shift 2 ;;
    --files-only|--one-way) shift ;;
    *) if [ -z "$src" ]; then src="$1"; elif [ -z "$dst" ]; then dst="$1"; fi; shift ;;
  esac
done
matches() { local n="$1" g; [ ${#inc[@]} -eq 0 ] && return 0; for g in "${inc[@]}"; do [[ "$n" == $g ]] && return 0; done; return 1; }
case "$cmd" in
  copy)
    d=$(path "$dst"); mkdir -p "$d"
    for f in "$src"/*; do [ -f "$f" ] && matches "$(basename "$f")" && cp -p "$f" "$d/"; done; exit 0 ;;
  check)
    [ "${RCLONE_FAIL_CHECK:-0}" = 1 ] && exit 1
    d=$(path "$dst")
    for f in "$src"/*; do n=$(basename "$f"); matches "$n" || continue; cmp -s "$f" "$d/$n" || exit 1; done; exit 0 ;;
  lsf) d=$(path "$src"); [ -d "$d" ] && ls -1 "$d"; exit 0 ;;
  copyto) cp "$(path "$src")" "$dst" ;;
  deletefile) rm -f "$(path "$src")" ;;
  *) exit 0 ;;
esac
