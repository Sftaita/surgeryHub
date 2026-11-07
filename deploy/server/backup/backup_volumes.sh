#!/usr/bin/env bash
# SurgicalHub — archives chiffrées des volumes persistants (D-164). Remplace backup_uploads.sh.
#   - volumes uploads ($UPLOAD_VOLUMES) → $UPLOADS_DIR/<étiquette>_<horodatage>.tar.gz.gpg
#   - surgicalhub_var SANS cache/      → $VAR_DIR/surgicalhub_var_<horodatage>.tar.gz.gpg
#     (clés JWT var/jwt, registre des versions var/release — D-162)
# Les volumes sont montés en lecture seule dans un conteneur jetable SANS l'entrypoint applicatif.
set -euo pipefail
LOG_TAG=volumes
# shellcheck source=lib.sh
. "$(dirname "$(readlink -f "$0")")/lib.sh"
need_passfile
stamp=$(date +%Y%m%d_%H%M%S)
rc=0

archive_volume() { # archive_volume <volume> <fichier.gpg> [exclusions tar…]
  local vol="$1" out="$2"; shift 2
  if ! "$DOCKER" volume inspect "$vol" > /dev/null 2>&1; then log "SKIP $vol absent"; return 0; fi
  mkdir -p "$(dirname "$out")"; chmod 700 "$(dirname "$out")"
  if "$DOCKER" run --rm --network none --entrypoint tar -v "$vol:/data:ro" "$HELPER_IMAGE" czf - "$@" -C /data . \
      | encrypt_to "$out"; then
    log "OK $(basename "$out") ($(du -h "$out" | cut -f1))"
  else
    rm -f "$out" "$out.part"; log "ERREUR archive $vol"; rc=1
  fi
}

for pair in $UPLOAD_VOLUMES; do
  archive_volume "${pair%%:*}" "$UPLOADS_DIR/${pair##*:}_${stamp}.tar.gz.gpg"
done
archive_volume "$VAR_VOLUME" "$VAR_DIR/${VAR_VOLUME}_${stamp}.tar.gz.gpg" --exclude=./cache --exclude=./log
exit "$rc"
