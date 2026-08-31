#!/usr/bin/env node
/**
 * Capture the WordPress.org screenshots.
 *
 *   npm run screenshots
 *
 * Prerequisites: `npx playwright install chromium`, and the local site running.
 * Drives Playwright's own Chromium rather than an installed Chrome, so the
 * renderer is pinned to the Playwright version in package.json and does not
 * drift under you when the browser auto-updates.
 *
 * Environment:
 *   OSG_URL      site URL           (default http://vchs-test.local)
 *   OSG_OUT      output directory   (default .wordpress-org)
 *   OSG_WP_ARGS  extra wp-cli args  (e.g. --require=/tmp/dbhost.php)
 *   OSG_COOKIE   pre-made logged-in cookie value, skipping wp-cli
 *
 * Screenshot numbering is load-bearing: `screenshot-N.png` pairs with the Nth
 * line of readme.txt's `== Screenshots ==` block, and a mismatch fails silently
 * on WordPress.org, attaching captions to the wrong images. SHOTS below is the
 * single source of that order — keep it in step with the readme.
 */

import { chromium } from 'playwright';
import { execFileSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const PLUGIN_DIR = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const SITE_ROOT  = path.resolve( PLUGIN_DIR, '../../..' );

const SITE    = process.env.OSG_URL || 'http://vchs-test.local';
const OUT_DIR = path.resolve( PLUGIN_DIR, process.env.OSG_OUT || '.wordpress-org' );
const WP_ARGS = ( process.env.OSG_WP_ARGS || '' ).split( ' ' ).filter( Boolean );

// The shots frame #wpbody-content, which sits ~180 CSS px narrower than the
// viewport once the admin sidebar is excluded. 1440 puts the content at the
// 1260 CSS px the previous set used, i.e. 2520px at 2x.
const WIDTH = 1440;
const SCALE = 2;

// wp-admin on a plugin-heavy dev site is slow; 30s default is not enough.
const NAV_TIMEOUT = Number( process.env.OSG_TIMEOUT || 120000 );

const SETTINGS_URL = `${ SITE }/wp-admin/admin.php?page=onsite-spam-guard`;
const LOGS_URL     = `${ SITE }/wp-admin/admin.php?page=onsite-spam-guard-spam-logs`;

/** The published order. Keep in step with readme.txt `== Screenshots ==`. */
const SHOTS = [
	{ n: 1, url: SETTINGS_URL, tab: 'guards',    name: 'Guards tab' },
	{ n: 2, url: SETTINGS_URL, tab: 'contexts',  name: 'Per-form tab' },
	{ n: 3, url: SETTINGS_URL, tab: 'allowlist', name: 'Allowlist tab' },
	{ n: 4, url: SETTINGS_URL, tab: 'logging',   name: 'Logging tab' },
	{ n: 5, url: LOGS_URL,     tab: null,        name: 'Spam Logs viewer' },
];

/**
 * Run PHP through wp-cli and return only what it deliberately printed.
 *
 * Other plugins on a dev site emit deprecation notices into the same stream,
 * and PHP writes them without a trailing newline — so filtering line by line
 * still leaves fragments glued to the front of a real value. Delimiting the
 * payload is the only reliable extraction; anything outside the markers is
 * someone else's noise.
 */
function wp( php ) {
	const START = '<<<OSG';
	const END   = 'OSG>>>';

	const raw = execFileSync(
		'wp',
		[ 'eval', `echo "${ START }"; ${ php } ; echo "${ END }";`, ...WP_ARGS, '--skip-themes' ],
		{ cwd: SITE_ROOT, encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 }
	);

	const start = raw.indexOf( START );
	const end   = raw.indexOf( END );

	if ( start === -1 || end === -1 ) {
		throw new Error( `wp-cli produced no delimited output. Raw tail: ${ raw.slice( -300 ) }` );
	}

	return raw.slice( start + START.length, end ).trim();
}

/**
 * Auth cookies, minted directly rather than by driving wp-login.php, so the
 * script never needs anyone's password.
 *
 * Both cookies are required. auth_redirect() validates with an empty scheme
 * (wp-includes/pluggable.php), which falls back to the *auth* cookie rather
 * than the logged-in one — so a logged-in cookie alone gets bounced to
 * wp-login.php with reauth=1, looking exactly like a bad password.
 */
function authCookies() {
	const out = wp( `
		$user = get_users( [ "role" => "administrator", "number" => 1 ] );
		if ( ! $user ) {
			echo "NO_ADMIN";
		} else {
			$id = $user[0]->ID;
			$exp = time() + 3600;
			$token = WP_Session_Tokens::get_instance( $id )->create( $exp );
			echo AUTH_COOKIE . "\t" . wp_generate_auth_cookie( $id, $exp, "auth", $token ) . "\n";
			echo LOGGED_IN_COOKIE . "\t" . wp_generate_auth_cookie( $id, $exp, "logged_in", $token );
		}
	` );

	if ( out === 'NO_ADMIN' || ! out.includes( '\t' ) ) {
		throw new Error( `Could not mint auth cookies. wp-cli said: ${ out }` );
	}

	const host = new URL( SITE ).hostname;

	// Tab-separated: the cookie value itself contains '|' separators.
	return out.split( '\n' ).map( ( line ) => {
		const [ name, value ] = line.trim().split( '\t' );
		return { name, value, domain: host, path: '/' };
	} );
}

/** Seed obviously-fictional blocked submissions so the log viewer has content. */
function seedLogs() {
	const ids = wp( `
		global $wpdb;
		$t = \\Simple_Spam_Shield\\Core\\Database_Manager::table_name();
		$rows = [
			[ "honeypot",   "honeypot,link_limit", "comment",      "Submission rejected.", "Cheap meds, best prices: http://example.invalid http://example.invalid http://example.invalid http://example.invalid", "203.0.113.14", "Mozilla/5.0 (compatible; SpamBot/2.1)" ],
			[ "link_limit", "link_limit",          "jetpack_form", "Too many links in the submission.", "Great site! Visit http://example.invalid and http://example.invalid and http://example.invalid and http://example.invalid", "203.0.113.87", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36" ],
			[ "time_gate",  "time_gate",           "woo_review",   "Submission completed too quickly.", "Amazing product buy now", "198.51.100.32", "python-requests/2.31.0" ],
			[ "duplicate",  "duplicate",           "comment",      "Duplicate submission detected — please wait before resubmitting.", "Thanks for the great post!", "198.51.100.5", "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15" ],
			[ "honeypot",   "honeypot",            "comment",      "Submission rejected.", "SEO services, guaranteed first page ranking", "203.0.113.201", "Mozilla/5.0 (compatible; Bot/1.0)" ],
		];
		$ids = [];
		$i = 0;
		foreach ( $rows as $r ) {
			$wpdb->insert( $t, [
				"blocked_at"     => gmdate( "Y-m-d H:i:s", time() - ( ++$i * 5400 ) ),
				"guard"          => $r[0],
				"guards_matched" => $r[1],
				"context"        => $r[2],
				"reason"         => $r[3],
				"content"        => $r[4],
				"ip_address"     => $r[5],
				"user_agent"     => $r[6],
			] );
			$ids[] = (int) $wpdb->insert_id;
		}
		echo implode( ",", $ids );
	` );

	return ids.split( ',' ).filter( Boolean );
}

function removeSeededLogs( ids ) {
	if ( ! ids.length ) {
		return;
	}
	// Delete exactly the rows we made, by id — never by a pattern that could
	// also match the site owner's own log entries.
	wp( `
		global $wpdb;
		$t = \\Simple_Spam_Shield\\Core\\Database_Manager::table_name();
		$ids = array_map( "intval", [ ${ ids.join( ',' ) } ] );
		$in = implode( ",", $ids );
		echo (int) $wpdb->query( "DELETE FROM {$t} WHERE id IN ({$in})" );
	` );
}

async function main() {
	mkdirSync( OUT_DIR, { recursive: true } );

	const cookies = authCookies();
	const seeded  = seedLogs();
	console.log( `seeded ${ seeded.length } example log rows` );

	const browser = await chromium.launch();
	const context = await browser.newContext( {
		viewport: { width: WIDTH, height: 900 },
		deviceScaleFactor: SCALE,
	} );

	// A dev site loaded with plugins can take 20s+ to render wp-admin, well
	// past Playwright's 30s default once assets are counted.
	context.setDefaultTimeout( NAV_TIMEOUT );
	context.setDefaultNavigationTimeout( NAV_TIMEOUT );

	await context.addCookies( cookies );

	const page = await context.newPage();

	try {
		let currentUrl = null;

		for ( const shot of SHOTS ) {
			// The tabs are client-side, so four of the five shots come from one
			// page load. On a site this slow that is worth the extra bookkeeping.
			if ( shot.url !== currentUrl ) {
				await page.goto( shot.url, { waitUntil: 'domcontentloaded' } );

				if ( page.url().includes( 'wp-login.php' ) ) {
					throw new Error( 'Not authenticated — the generated cookies were rejected.' );
				}

				await page.waitForLoadState( 'load' );
				currentUrl = shot.url;

				// Published shots frame the content area only — no admin bar, no
				// sidebar, no notices. The admin bar in particular can carry
				// debug output (Query Monitor's timings) that must never ship.
				await page.addStyleTag( {
					content: `#wpadminbar, .update-nag, #screen-meta-links,
					          .notice:not(.inline), .updated:not(.inline), .error:not(.inline)
					              { display: none !important; }
					          html.wp-toolbar { padding-top: 0 !important; }`,
				} );
			}

			if ( shot.tab ) {
				const selector = `.nav-tab[data-sss-tab="${ shot.tab }"]`;
				const tab = page.locator( selector );

				// Fail loudly. A silently missed tab captures whichever tab the
				// page restored from localStorage — plausible-looking and wrong,
				// which has bitten this script's predecessor before.
				if ( await tab.count() === 0 ) {
					throw new Error( `Tab "${ shot.tab }" not found (${ selector }). Did the tab id change?` );
				}

				await tab.click();
				await page.waitForFunction(
					( id ) => {
						const el = document.querySelector( `.nav-tab[data-sss-tab="${ id }"]` );
						return el && el.classList.contains( 'nav-tab-active' );
					},
					shot.tab
				);
			}

			// Drop rows left over from local testing on the dev site — a
			// loopback IP and a WP-CLI user agent are noise in a listing image.
			// Presentation only: nothing is deleted from the site's own logs.
			if ( shot.url === LOGS_URL ) {
				await page.evaluate( () => {
					document.querySelectorAll( '#the-list tr' ).forEach( ( row ) => {
						if ( /127\.0\.0\.1|::1|WP CLI/.test( row.textContent || '' ) ) {
							row.remove();
						}
					} );
				} );
			}

			// Element screenshot, not fullPage: a full page here is dominated by
			// the admin sidebar's height and comes out mostly whitespace, which
			// the listing page then shrinks to mush.
			const body = page.locator( '#wpbody-content' );
			if ( await body.count() === 0 ) {
				throw new Error( 'Could not find #wpbody-content to frame the shot.' );
			}

			const file = path.join( OUT_DIR, `screenshot-${ shot.n }.png` );
			await body.screenshot( { path: file } );
			console.log( `screenshot-${ shot.n }.png  ${ shot.name }` );
		}
	} finally {
		await browser.close();
		removeSeededLogs( seeded );
		console.log( 'seeded log rows removed' );
	}
}

main().catch( ( err ) => {
	console.error( `\nFAILED: ${ err.message }` );
	process.exit( 1 );
} );
