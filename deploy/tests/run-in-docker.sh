#!/usr/bin/env bash
# D-163 — lance deploy/tests/run.sh dans un conteneur Linux, comme le serveur de production :
# vrais liens symboliques, GNU tar et coreutils. Le commit testé est transmis par un bundle git
# (aucun montage du dépôt, aucune dépendance au poste de travail).
# Image : DEPLOY_TEST_IMAGE (défaut composer:2 — Alpine avec git, bash, GNU tar et coreutils).
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
IMAGE="${DEPLOY_TEST_IMAGE:-composer:2}"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT
git -C "$ROOT" bundle create "$WORK/repo.bundle" HEAD >/dev/null 2>&1
MOUNT="$WORK"
command -v cygpath >/dev/null 2>&1 && MOUNT=$(cygpath -w "$WORK")   # Docker Desktop sous Windows
MSYS_NO_PATHCONV=1 docker run --rm -v "$MOUNT:/work" --entrypoint bash "$IMAGE" -c '
  set -e
  git config --global user.email test@surgicalhub.test && git config --global user.name test
  git config --global --add safe.directory "*"
  git clone -q /work/repo.bundle /src && cd /src && TEST_BUNDLE=/work/repo.bundle bash deploy/tests/run.sh'
