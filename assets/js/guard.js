/**
 * Onsite Spam Guard — front-end guard script.
 *
 * Injects hidden honeypot, nonce, timestamp, and behavioral data fields
 * into comment forms, WooCommerce review forms, and Jetpack contact form blocks.
 *
 * Behavioral tracking (mouse movements, clicks, time on page) ported from
 * Comment & Form Guard's public-scripts.js, rewritten without jQuery.
 */
( function () {
	'use strict';

	if ( typeof simpleSpamShieldGuard === 'undefined' ) {
		return;
	}

	// Built-ins plus any selectors registered by other plugins via the
	// simple_spam_shield_form_selectors filter.
	var selectors = simpleSpamShieldGuard.selectors || [];

	// Forms rendered by simple_spam_shield_field_markup() already carry this
	// honeypot field; we enhance them too (behavioral handler, missing fields).
	var HONEYPOT_NAME = 'simple_spam_shield_website_url';

	// --- Behavioral analysis tracking ---
	// Ported from Comment & Form Guard.
	var startTime = Date.now();
	var mouseMovements = 0;
	var clicks = 0;

	document.addEventListener( 'mousemove', function () {
		mouseMovements++;
	} );

	document.addEventListener( 'click', function () {
		clicks++;
	} );

	/**
	 * Build the behavioral data JSON string at submission time.
	 */
	function getBehavioralData() {
		var timeSpent = ( Date.now() - startTime ) / 1000;
		return JSON.stringify( {
			time_spent: parseFloat( timeSpent.toFixed( 2 ) ),
			mouse_movements: mouseMovements,
			clicks: clicks
		} );
	}

	/**
	 * Inject hidden fields into a form.
	 */
	function hasField( form, name ) {
		return !! form.querySelector( 'input[name="' + name + '"]' );
	}

	function addHidden( form, name, value ) {
		if ( hasField( form, name ) ) {
			return; // Already present (e.g. server-rendered) — leave it.
		}
		var input = document.createElement( 'input' );
		input.type  = 'hidden';
		input.name  = name;
		input.value = value;
		form.appendChild( input );
	}

	function injectFields( form ) {
		if ( form.dataset.simpleSpamShieldProtected ) {
			return;
		}
		form.dataset.simpleSpamShieldProtected = '1';

		// Honeypot field — looks like a legitimate "website" field to bots.
		// Skipped when the form already carries one (server-rendered markup).
		if ( ! hasField( form, HONEYPOT_NAME ) ) {
			var hp = document.createElement( 'div' );
			hp.className = 'onsite-spam-guard-hp-wrap';
			hp.setAttribute( 'aria-hidden', 'true' );
			hp.innerHTML =
				'<label for="simple_spam_shield_website_url">Website</label>' +
				'<input type="text" name="simple_spam_shield_website_url" id="simple_spam_shield_website_url" value="" tabindex="-1" autocomplete="off">';
			form.appendChild( hp );
		}

		// Signed token — the server-issued, HMAC-signed timestamp. The time
		// gate reads the signed issue time and the signature guard verifies
		// authenticity, so a stale/forged value cannot pass.
		addHidden( form, 'simple_spam_shield_form_loaded', simpleSpamShieldGuard.token );

		// Behavioral data field — populated at submit time.
		addHidden( form, 'simple_spam_shield_behavioral_data', '' );

		// Capture behavioral data just before form submission.
		form.addEventListener( 'submit', function () {
			var field = form.querySelector( 'input[name="simple_spam_shield_behavioral_data"]' );
			if ( field ) {
				field.value = getBehavioralData();
			}
		} );
	}

	/**
	 * Find and protect all matching forms, plus any form already carrying the
	 * honeypot field (rendered server-side via field_markup()).
	 */
	function protectForms() {
		selectors.forEach( function ( selector ) {
			document.querySelectorAll( selector ).forEach( injectFields );
		} );

		document.querySelectorAll( 'input[name="' + HONEYPOT_NAME + '"]' ).forEach( function ( input ) {
			var form = input.closest( 'form' );
			if ( form ) {
				injectFields( form );
			}
		} );
	}

	// Run on DOMContentLoaded.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', protectForms );
	} else {
		protectForms();
	}

	// Also observe for dynamically-inserted forms (e.g. AJAX-loaded reviews).
	// The callback fires on every DOM mutation, so coalesce bursts into a
	// single debounced rescan to avoid re-querying the whole document on
	// busy pages (infinite scroll, heavy client-side rendering).
	if ( typeof MutationObserver !== 'undefined' ) {
		var rescanTimer = null;
		var scheduleRescan = function () {
			if ( rescanTimer ) {
				return;
			}
			rescanTimer = window.setTimeout( function () {
				rescanTimer = null;
				protectForms();
			}, 200 );
		};

		var observer = new MutationObserver( function ( mutations ) {
			for ( var i = 0; i < mutations.length; i++ ) {
				if ( mutations[ i ].addedNodes.length ) {
					scheduleRescan();
					return;
				}
			}
		} );
		observer.observe( document.body, { childList: true, subtree: true } );
	}
} )();
