#!/usr/bin/env bash
#
# Build the distributable plugin package: a single directory named for the
# slug, containing only runtime files.
#
# .distignore is the single source of truth for what is excluded, so this
# script, the Plugin Check CI job, and the WordPress.org deploy all ship
# exactly the same file list.
#
# Usage:
#   bin/build-dist.sh [output-dir]     # default: build/
#
set -euo pipefail

cd "$( dirname "${BASH_SOURCE[0]}" )/.." || exit 1

SLUG="onsite-spam-guard"
OUT_DIR="${1:-build}"
DEST="${OUT_DIR}/${SLUG}"

rm -rf "$OUT_DIR"
mkdir -p "$DEST"

# Strip comments and blank lines from .distignore; rsync reads the rest as
# exclude patterns.
grep -vE '^\s*(#|$)' .distignore > "${OUT_DIR}/.rsync-excludes"

rsync -a \
  --exclude-from="${OUT_DIR}/.rsync-excludes" \
  --exclude="/${OUT_DIR}" \
  ./ "${DEST}/"

rm -f "${OUT_DIR}/.rsync-excludes"

echo "Built ${DEST}"
find "$DEST" -type f | sort | sed 's/^/  /'
