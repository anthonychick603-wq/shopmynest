/**
 * MyNest Trust & Growth Suite — front-end vanilla JS.
 * No build step, no dependencies. Talks to the `nest-trust/v1` REST API
 * using the nonce + REST URL supplied via wp_localize_script (NestTrustConfig).
 */
( function () {
	'use strict';

	if ( typeof window.NestTrustConfig === 'undefined' ) {
		return;
	}

	var CONFIG = window.NestTrustConfig;

	/**
	 * Small fetch() wrapper that adds the REST nonce header and parses JSON.
	 */
	function nestTrustFetch( path, options ) {
		options = options || {};
		options.headers = options.headers || {};
		options.headers[ 'X-WP-Nonce' ] = CONFIG.nonce;
		options.headers[ 'Content-Type' ] = 'application/json';
		options.credentials = 'same-origin';

		return fetch( CONFIG.restUrl.replace( /\/$/, '' ) + '/' + path.replace( /^\//, '' ), options )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					if ( ! response.ok ) {
						var message = ( data && data.message ) ? data.message : CONFIG.i18n.error;
						return Promise.reject( new Error( message ) );
					}
					return data;
				} );
			} );
	}

	/* ------------------------------------------------------------------
	 * Favorite heart buttons.
	 * ------------------------------------------------------------------ */
	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '.tnm-trust-favorite-btn' );
		if ( ! btn ) {
			return;
		}

		event.preventDefault();

		if ( ! CONFIG.isLoggedIn ) {
			window.location.href = ( CONFIG.checkoutUrl ? CONFIG.checkoutUrl.split( '?' )[ 0 ] : '/' );
			return;
		}

		var productId = btn.getAttribute( 'data-product-id' );
		var wasFavorited = btn.classList.contains( 'is-favorited' );
		var countEl = btn.querySelector( '.tnm-trust-favorite-count' );
		var previousCount = countEl ? parseInt( countEl.textContent, 10 ) || 0 : 0;

		// Optimistic UI update.
		btn.classList.toggle( 'is-favorited' );
		btn.setAttribute( 'aria-pressed', wasFavorited ? 'false' : 'true' );
		if ( countEl ) {
			countEl.textContent = wasFavorited ? Math.max( 0, previousCount - 1 ) : previousCount + 1;
		}

		nestTrustFetch( 'favorites', {
			method: 'POST',
			body: JSON.stringify( { product_id: parseInt( productId, 10 ) } ),
		} ).then( function ( data ) {
			btn.classList.toggle( 'is-favorited', !! data.favorited );
			btn.setAttribute( 'aria-pressed', data.favorited ? 'true' : 'false' );
			if ( countEl && typeof data.count !== 'undefined' ) {
				countEl.textContent = data.count;
			}
		} ).catch( function () {
			// Roll back optimistic update on failure.
			btn.classList.toggle( 'is-favorited', wasFavorited );
			btn.setAttribute( 'aria-pressed', wasFavorited ? 'true' : 'false' );
			if ( countEl ) {
				countEl.textContent = previousCount;
			}
		} );
	} );

	/* ------------------------------------------------------------------
	 * My Disputes (buyer).
	 * ------------------------------------------------------------------ */
	var myDisputesRoot = document.getElementById( 'tnm-trust-my-disputes' );
	if ( myDisputesRoot ) {
		var disputesListEl = document.getElementById( 'tnm-trust-disputes-list' );
		var submitBtn = document.getElementById( 'tnm-trust-submit-dispute' );
		var messageEl = document.getElementById( 'tnm-trust-dispute-form-message' );

		function renderDisputeCard( dispute ) {
			var card = document.createElement( 'div' );
			card.className = 'tnm-trust-dispute-card';
			card.innerHTML =
				'<h4>Dispute #' + dispute.id + ' — Order #' + dispute.order_id + '</h4>' +
				'<p><span class="tnm-trust-status-pill status-' + dispute.status + '">' + dispute.status.replace( /_/g, ' ' ) + '</span></p>' +
				'<p>' + ( dispute.description || '' ).replace( /</g, '&lt;' ) + '</p>' +
				( dispute.resolution_note ? '<p><strong>Resolution note:</strong> ' + dispute.resolution_note.replace( /</g, '&lt;' ) + '</p>' : '' );
			return card;
		}

		function loadMyDisputes() {
			nestTrustFetch( 'disputes' ).then( function ( disputes ) {
				disputesListEl.innerHTML = '';
				if ( ! disputes.length ) {
					disputesListEl.innerHTML = '<p>No disputes yet.</p>';
					return;
				}
				disputes.forEach( function ( dispute ) {
					disputesListEl.appendChild( renderDisputeCard( dispute ) );
				} );
			} ).catch( function () {
				disputesListEl.innerHTML = '<p>' + CONFIG.i18n.error + '</p>';
			} );
		}

		if ( submitBtn ) {
			submitBtn.addEventListener( 'click', function () {
				var orderId = document.getElementById( 'tnm-trust-dispute-order-id' ).value;
				var reason = document.getElementById( 'tnm-trust-dispute-reason' ).value;
				var description = document.getElementById( 'tnm-trust-dispute-description' ).value;

				if ( ! orderId || ! description ) {
					messageEl.textContent = 'Please provide an order ID and description.';
					messageEl.className = 'tnm-trust-form-message is-error';
					return;
				}

				messageEl.textContent = CONFIG.i18n.loading;
				messageEl.className = 'tnm-trust-form-message';

				nestTrustFetch( 'disputes', {
					method: 'POST',
					body: JSON.stringify( {
						order_id: parseInt( orderId, 10 ),
						reason: reason,
						description: description,
					} ),
				} ).then( function ( data ) {
					messageEl.textContent = data.warning ? data.warning : 'Dispute submitted.';
					messageEl.className = 'tnm-trust-form-message is-success';
					loadMyDisputes();
				} ).catch( function ( err ) {
					messageEl.textContent = err.message || CONFIG.i18n.error;
					messageEl.className = 'tnm-trust-form-message is-error';
				} );
			} );
		}

		loadMyDisputes();
	}

	/* ------------------------------------------------------------------
	 * Seller Disputes.
	 * ------------------------------------------------------------------ */
	var sellerDisputesRoot = document.getElementById( 'tnm-trust-seller-disputes' );
	if ( sellerDisputesRoot ) {
		var sellerListEl = document.getElementById( 'tnm-trust-seller-disputes-list' );

		nestTrustFetch( 'disputes' ).then( function ( disputes ) {
			sellerListEl.innerHTML = '';
			if ( ! disputes.length ) {
				sellerListEl.innerHTML = '<p>No disputes against your orders.</p>';
				return;
			}
			disputes.forEach( function ( dispute ) {
				var card = document.createElement( 'div' );
				card.className = 'tnm-trust-dispute-card';
				card.innerHTML =
					'<h4>Dispute #' + dispute.id + ' — Order #' + dispute.order_id + '</h4>' +
					'<p><span class="tnm-trust-status-pill status-' + dispute.status + '">' + dispute.status.replace( /_/g, ' ' ) + '</span></p>' +
					'<p>' + ( dispute.description || '' ).replace( /</g, '&lt;' ) + '</p>' +
					'<textarea class="tnm-trust-input tnm-trust-response-note" placeholder="Your response / offer to resolve"></textarea>' +
					'<button type="button" class="tnm-trust-btn tnm-trust-respond-btn" data-id="' + dispute.id + '">Respond</button>';
				sellerListEl.appendChild( card );
			} );
		} ).catch( function () {
			sellerListEl.innerHTML = '<p>' + CONFIG.i18n.error + '</p>';
		} );

		sellerListEl && sellerListEl.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '.tnm-trust-respond-btn' );
			if ( ! btn ) {
				return;
			}
			var card = btn.closest( '.tnm-trust-dispute-card' );
			var note = card.querySelector( '.tnm-trust-response-note' ).value;

			nestTrustFetch( 'disputes/' + btn.getAttribute( 'data-id' ), {
				method: 'PUT',
				body: JSON.stringify( { resolution_note: note } ),
			} ).then( function () {
				btn.textContent = 'Response sent';
				btn.disabled = true;
			} ).catch( function ( err ) {
				alert( err.message || CONFIG.i18n.error ); // eslint-disable-line no-alert
			} );
		} );
	}

	/* ------------------------------------------------------------------
	 * Personalized feed.
	 * ------------------------------------------------------------------ */
	var feedRoot = document.getElementById( 'tnm-trust-feed' );
	if ( feedRoot ) {
		var currentPage = 1;
		var loadMoreBtn = document.getElementById( 'tnm-trust-feed-load-more' );

		function renderFeedItem( item ) {
			var el = document.createElement( 'div' );
			el.className = 'tnm-trust-feed-item';
			el.innerHTML =
				'<a href="' + item.permalink + '"><img src="' + item.image + '" alt="" loading="lazy" /></a>' +
				'<div class="tnm-trust-feed-item-body">' +
				'<a class="tnm-trust-feed-item-title" href="' + item.permalink + '">' + item.name + '</a>' +
				'<span class="tnm-trust-feed-item-price">' + item.price_html + '</span>' +
				'<button type="button" class="tnm-trust-favorite-btn" data-product-id="' + item.id + '">' +
				'<svg viewBox="0 0 24 24" width="18" height="18" class="tnm-trust-heart-icon" aria-hidden="true"><path d="M12 21s-6.7-4.35-9.33-8.2C.86 10.1 1.4 6.6 4.2 5.02c2.28-1.28 4.87-.6 6.3 1.32.4.53.8 1.16 1.5 1.16.7 0 1.1-.63 1.5-1.16 1.43-1.92 4.02-2.6 6.3-1.32 2.8 1.58 3.34 5.08 1.53 7.78C18.7 16.65 12 21 12 21z" fill="currentColor"></path></svg>' +
				'<span class="tnm-trust-favorite-count">' + ( item.favorites_count || 0 ) + '</span>' +
				'</button>' +
				'</div>';
			return el;
		}

		function loadFeed( page, append ) {
			var params = new URLSearchParams( window.location.search );
			var category = params.get( 'category' ) || '';

			var qs = 'feed?page=' + page + '&per_page=20' + ( category ? '&category=' + encodeURIComponent( category ) : '' );

			nestTrustFetch( qs ).then( function ( data ) {
				if ( ! append ) {
					feedRoot.innerHTML = '';
				}
				if ( ! data.items.length && ! append ) {
					feedRoot.innerHTML = '<p>No products found.</p>';
				}
				data.items.forEach( function ( item ) {
					feedRoot.appendChild( renderFeedItem( item ) );
				} );

				if ( loadMoreBtn ) {
					var loadedSoFar = page * data.per_page;
					loadMoreBtn.style.display = loadedSoFar < data.total ? 'block' : 'none';
				}
			} ).catch( function () {
				feedRoot.innerHTML = '<p>' + CONFIG.i18n.error + '</p>';
			} );
		}

		if ( loadMoreBtn ) {
			loadMoreBtn.addEventListener( 'click', function () {
				currentPage++;
				loadFeed( currentPage, true );
			} );
		}

		loadFeed( currentPage, false );
	}

}() );
