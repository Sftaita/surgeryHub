#!/usr/bin/env bash
# Lance tests/rotation.sh et tests/offsite.sh sous Linux (GNU find/date/stat, vrais liens symboliques, chmod) dans un
# conteneur jetable, sur une copie du dépôt. Image : ROTATION_TEST_IMAGE (défaut mariadb:11.8,
# basée sur Ubuntu comme le serveur).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
SRC="$ROOT"; command -v cygpath > /dev/null && SRC=$(cygpath -w "$ROOT")
MSYS_NO_PATHCONV=1 docker run --rm -v "$SRC:/src:ro" --entrypoint bash "${ROTATION_TEST_IMAGE:-mariadb:11.8}" \
  -c 'cp -r /src /w && bash /w/deploy/server/backup/tests/rotation.sh && bash /w/deploy/server/backup/tests/offsite.sh'
