#!/usr/bin/env bash
#
# Smoke-test a built copy of the plugin inside a real WordPress install.
#
# PHPUnit runs against stubs, so it cannot show the plugin working on any
# particular WordPress. This does. CI runs it at both ends of the range the
# plugin claims: the declared minimum (`Requires at least`, on the minimum
# PHP) and the newest version tested (`Tested up to`).
#
# Stages, each asserted by tests/smoke/checks.php:
#   1. Upgrade from the previous release by replacing its files, as an update
#      does — no reactivation and no admin_init — then block and log spam.
#   2. Uninstall that upgraded install.
#   3. Fresh activation.
#   4. Admin: settings, menu, settings page, spam log page, Site Health test.
#   5. Guard pipeline: a genuine comment passes, honeypot spam is blocked,
#      monitor mode lets it through and logs it.
#   6. Abilities: registered, admin-only and free of personal data on
#      WordPress 6.9+; absent and harmless below it.
#   7. Every integration initialises with its host plugin absent.
#   8. Uninstall.
#   9. The debug log holds no error from this plugin (see scan_log).
#
# Usage:
#   tests/smoke/run.sh <wordpress-dir> <built-plugin-dir>
#
# The WordPress install must have WP_DEBUG and WP_DEBUG_LOG on, with the log
# at DEBUG_LOG (default <wordpress-dir>/wp-content/debug.log).
#
#   WP_CLI=/path/wp-cli.phar   use this WP-CLI rather than `wp` from PATH
#   UPGRADE_FROM=x.y.z          previous release to upgrade from (default below)
#
set -euo pipefail

WP_PATH="${1:?usage: run.sh <wordpress-dir> <built-plugin-dir>}"
BUILD="${2:?usage: run.sh <wordpress-dir> <built-plugin-dir>}"
DEBUG_LOG="${DEBUG_LOG:-${WP_PATH}/wp-content/debug.log}"

# The last release before the newest schema migration, so stage 1 exercises
# that migration. Move it forward whenever Database_Manager::DB_VERSION changes.
UPGRADE_FROM="${UPGRADE_FROM:-1.5.1}"

SLUG="onsite-spam-guard"
CHECKS="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )/checks.php"
DEST="${WP_PATH}/wp-content/plugins/${SLUG}"

if [ -n "${WP_CLI:-}" ]; then
	WP=( php "${WP_CLI}" )
else
	WP=( wp )
fi

# Not named wp(): with WP=( wp ) the function would call itself.
wpcli() { "${WP[@]}" --path="${WP_PATH}" "$@"; }
checks() { wpcli eval-file "${CHECKS}" "$@"; }
stage() { printf '\n== %s\n' "$1"; }

# Install the build as an update would leave it: files replaced in place.
install_build() {
	rm -rf "${DEST}"
	cp -R "${BUILD}" "${DEST}"
}

# Fail on anything this plugin, or WordPress on its behalf, wrote to the log.
#
# The minimum is WordPress 6.2 on PHP 8.2, and 6.2's PHP 8.2 support was beta:
# core raises deprecations of its own. Those are PHP-level deprecations in
# wp-includes/ or wp-admin/, and are reported but not failed. Everything else
# fails, including WordPress's own "called incorrectly" and "deprecated since"
# notices — core files raise those, but on behalf of whoever called them.
scan_log() {
	[ -f "${DEBUG_LOG}" ] || { echo "  ok    no debug log written"; return 0; }

	awk -v core="${WP_PATH%/}/wp-" '
		function flush() {
			if ( entry == "" ) return
			if ( entry ~ /called <strong>incorrectly<\/strong>|is <strong>deprecated<\/strong> since|deprecated since version/ ) {
				failed++; print "  FAIL  " entry
			} else if ( entry ~ /PHP Deprecated:/ && ( index( entry, core "includes/" ) || index( entry, core "admin/" ) ) ) {
				excused++
			} else {
				failed++; print "  FAIL  " entry
			}
			entry = ""
		}
		/^\[/ { flush(); entry = $0; next }
		{ entry = entry "\n" $0 }
		END {
			flush()
			printf "  %s   %d error(s); %d core deprecation(s) excused\n", ( failed ? "FAIL" : "ok  " ), failed, excused
			exit failed ? 1 : 0
		}
	' "${DEBUG_LOG}"
}

: > "${DEBUG_LOG}"
echo "WordPress $( wpcli core version ) on PHP $( wpcli eval 'echo PHP_VERSION;' )"

stage "1. Upgrade from ${UPGRADE_FROM} without reactivation"
wpcli plugin install "${SLUG}" --version="${UPGRADE_FROM}" --activate --force --quiet
from_schema="$( wpcli option get simple_spam_shield_db_version )"
install_build
checks upgrade "${from_schema}"

stage "2. Uninstall the upgraded install"
wpcli plugin uninstall "${SLUG}" --deactivate --quiet
checks uninstalled

stage "3. Fresh activation"
install_build
wpcli plugin activate "${SLUG}" --quiet
checks activation

stage "4. Admin screens"
# WP_ADMIN must be defined before WordPress loads, so is_admin() is true on
# plugins_loaded; checks.php sets up the rest of the admin request.
checks admin --exec="define( 'WP_ADMIN', true );"

stage "5. Guard pipeline"
checks guards

stage "6. Abilities"
checks abilities

stage "7. Integrations without their host plugins"
checks integrations

stage "8. Uninstall"
wpcli plugin uninstall "${SLUG}" --deactivate --quiet
checks uninstalled

stage "9. PHP errors"
scan_log
