/**
 * Onsite Spam Guard — tabbed settings.
 *
 * All panels live in a single form (one Save submits everything), so switching
 * tabs never loses unsaved changes. The active tab is remembered across the
 * save redirect via sessionStorage. Enhancement only; without this script every
 * panel is visible and the form still works.
 */
( function () {
	'use strict';

	var container = document.querySelector( '.onsite-spam-guard-settings' );
	if ( ! container ) {
		return;
	}

	var tabs = container.querySelectorAll( '.onsite-spam-guard-tabs .nav-tab' );
	var panels = container.querySelectorAll( '.onsite-spam-guard-tab-panel' );
	if ( ! tabs.length || ! panels.length ) {
		return;
	}

	function activate( id ) {
		tabs.forEach( function ( tab ) {
			tab.classList.toggle( 'nav-tab-active', tab.getAttribute( 'data-sss-tab' ) === id );
		} );
		panels.forEach( function ( panel ) {
			panel.classList.toggle( 'is-active', panel.getAttribute( 'data-sss-panel' ) === id );
		} );
		try {
			window.sessionStorage.setItem( 'sssActiveTab', id );
		} catch ( e ) {}
	}

	container.classList.add( 'has-js-tabs' );

	tabs.forEach( function ( tab ) {
		tab.addEventListener( 'click', function ( event ) {
			event.preventDefault();
			activate( tab.getAttribute( 'data-sss-tab' ) );
		} );
	} );

	// Restore the last-used tab (survives the save redirect), else the first.
	var saved = null;
	try {
		saved = window.sessionStorage.getItem( 'sssActiveTab' );
	} catch ( e ) {}

	var valid = saved && container.querySelector( '.nav-tab[data-sss-tab="' + saved + '"]' );
	activate( valid ? saved : tabs[ 0 ].getAttribute( 'data-sss-tab' ) );
} )();
