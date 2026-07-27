/**
 * MyNest Trust & Growth Suite — wp-admin JS.
 * No build step, no dependencies. Adds a lightweight confirmation prompt
 * before submitting dispute-resolution and Pro Seller toggle forms.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var resolveForms = document.querySelectorAll( '.tnm-trust-admin-dispute-card form' );

		resolveForms.forEach( function ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				var confirmed = window.confirm( 'Resolve this dispute with the selected outcome? This may trigger a WooCommerce refund.' ); // eslint-disable-line no-alert
				if ( ! confirmed ) {
					event.preventDefault();
				}
			} );
		} );
	} );
}() );
