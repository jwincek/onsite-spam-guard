#!/usr/bin/env bash
#
# Verify that languages/onsite-spam-guard.pot is up to date with the source.
#
# bin/check-versions.sh compares the .pot's Project-Id-Version against the
# plugin header, so it catches a stale *version*. It cannot catch a stale set
# of *strings*: a release can otherwise ship a template missing strings that
# were added since it was last generated, leaving them untranslatable.
#
# Only msgid lines are compared. The full file differs on every run because
# POT-Creation-Date is a timestamp, so a whole-file diff would always fail.
#
# Usage:
#   bin/check-pot.sh              # uses `wp` from PATH
#   WP_CLI=/path/wp-cli.phar bin/check-pot.sh
#
set -uo pipefail

cd "$( dirname "${BASH_SOURCE[0]}" )/.." || exit 1

SLUG="onsite-spam-guard"
POT="languages/${SLUG}.pot"

if [ -n "${WP_CLI:-}" ]; then
	WP="php ${WP_CLI}"
elif command -v wp >/dev/null 2>&1; then
	WP="wp"
else
	echo "FAIL  no WP-CLI found. Install it, or set WP_CLI=/path/to/wp-cli.phar"
	exit 1
fi

if [ ! -f "$POT" ]; then
	echo "FAIL  $POT is missing. Generate it with:"
	echo "      wp i18n make-pot . $POT --slug=$SLUG"
	exit 1
fi

TMP="$( mktemp "${TMPDIR:-/tmp}/osg-pot.XXXXXX" )" || exit 1
trap 'rm -f "$TMP"' EXIT

# make-pot runs as static analysis; it does not need a WordPress install.
if ! $WP i18n make-pot . "$TMP" --slug="$SLUG" >/dev/null 2>&1; then
	echo "FAIL  could not regenerate the template for comparison"
	exit 1
fi

if diff -q <( grep '^msgid ' "$TMP" | sort ) <( grep '^msgid ' "$POT" | sort ) >/dev/null; then
	echo "ok    $POT is up to date ($( grep -c '^msgid ' "$POT" ) strings)"
	exit 0
fi

echo "FAIL  $POT is out of date with the source."
echo
echo "  Strings in the source but missing from the committed template:"
comm -23 <( grep '^msgid ' "$TMP" | sort -u ) <( grep '^msgid ' "$POT" | sort -u ) | sed 's/^/    + /'
echo
echo "  Strings in the template no longer found in the source:"
comm -13 <( grep '^msgid ' "$TMP" | sort -u ) <( grep '^msgid ' "$POT" | sort -u ) | sed 's/^/    - /'
echo
echo "  Regenerate with:"
echo "    wp i18n make-pot . $POT --slug=$SLUG"
exit 1
