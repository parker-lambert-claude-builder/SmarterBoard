#!/usr/bin/env bash
# Copies the site (everything tracked or new, except tests) from this checkout to $MASHPAD_SITE.
set -euo pipefail
SITE="${MASHPAD_SITE:-/srv/site}"
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
mkdir -p "$SITE"
find "$SITE" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
cd "$REPO"
git ls-files -co --exclude-standard | grep -v '^tests/' | while read -r f; do mkdir -p "$SITE/$(dirname "$f")"; cp "$f" "$SITE/$f"; done
mkdir -p "$SITE/data/boards"
chmod -R a+rwX "$SITE/data"      # the web server has to be able to write its config and the legacy pad files
echo "deployed $REPO to $SITE"
