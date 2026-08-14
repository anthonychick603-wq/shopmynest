/**
 * v3.7.36 — MyNest Marketplace block-checkout integration (SERVER-FIRST).
 * Adds on-page visible debug panel (bottom-right) so we can trace exactly
 * where the client-side flow stops when checkout appears to hang.
 *
 * Registers the mnu_marketplace payment method with the WooCommerce Blocks
 * Cart & Checkout. Flow:
 *
 *   1. Mount a DEFERRED-MODE Stripe Payment Element on load (no
 *      client_secret yet — Elements uses { mode, currency, amount } and
 *      does NOT charge anything).
 *   2. On onPaymentSetup (fired by WC Blocks when the shopper clicks
 *      Place Order), run stripe.elements.submit() to validate the card
 *      form only, then return SUCCESS to WC Blocks so it proceeds with
 *      the /wc/store/v1/checkout Store API call.
 *   3. WC Blocks creates the WC_Order and calls MNU_Woo_Gateway::process_payment
 *      which returns { result: 'success', redirect: 'mnu_confirm://...' }.
 *   4. Blocks resolves that "redirect" URL by setting
 *      window.location = redirect. We intercept BEFORE that navigation
 *      via onCheckoutSuccess.
 *   5. In onCheckoutSuccess we parse the mnu_confirm sentinel, call
 *      stripe.confirmPayment({ elements, clientSecret, redirect: 'if_required' }),
 *      then POST /wp-json/mnu/v1/finalize-order which verifies the intent
 *      with Stripe and completes the order. On success we redirect to
 *      the returned thankyou URL.
 *
 * Critical: the card is NEVER confirmed unless a real WC_Order exists.
 * Previously (v3.7.35.4 and earlier) the intent was created client-side
 * and confirmPayment ran before process_payment, which meant a
 * Store API failure between order-create and process_payment orphaned
 * a card charge with no linked order.
 */
( function ( wp, wc ) {
	'use strict';

	// ---- Debug logger (v3.7.36) -------------------------------------------
	// On-page panel is disabled in production. To re-enable for
	// diagnostics, append ?mnu_debug=1 to the checkout URL.
	var _mnuDebugOn = false;
	try {
		_mnuDebugOn = /[?&]mnu_debug=1\b/.test( window.location.search );
	} catch ( e ) {}
	var _mnuPanel = null;
	function _mnuGetPanel() {
		if ( ! _mnuDebugOn ) return null;
		if ( _mnuPanel && document.body && document.body.contains( _mnuPanel ) ) return _mnuPanel;
		var p = document.getElementById( 'mnu-debug-log' );
		if ( ! p && document.body ) {
			p = document.createElement( 'div' );
			p.id = 'mnu-debug-log';
			p.style.cssText = 'position:fixed;right:8px;bottom:8px;z-index:2147483647;width:380px;max-height:40vh;overflow:auto;background:#111;color:#0f0;font:11px/1.4 ui-monospace,monospace;padding:8px 10px;border-radius:6px;box-shadow:0 4px 20px rgba(0,0,0,.4);opacity:.92';
			var hdr = document.createElement( 'div' );
			hdr.style.cssText = 'font-weight:bold;color:#7cf;margin-bottom:4px;border-bottom:1px solid #333;padding-bottom:4px;display:flex;justify-content:space-between';
			hdr.innerHTML = '<span>MNU debug 3.7.36</span><span id="mnu-debug-close" style="cursor:pointer;color:#f66">✕</span>';
			p.appendChild( hdr );
			var body = document.createElement( 'div' );
			body.id = 'mnu-debug-body';
			p.appendChild( body );
			document.body.appendChild( p );
			var close = p.querySelector( '#mnu-debug-close' );
			if ( close ) close.addEventListener( 'click', function () { p.style.display = 'none'; } );
		}
		_mnuPanel = p;
		return p;
	}
	function mnuLog( msg, data ) {
		if ( _mnuDebugOn ) {
			try {
				var p = _mnuGetPanel();
				if ( p ) {
					var body = p.querySelector( '#mnu-debug-body' );
					var t = new Date().toISOString().split( 'T' )[ 1 ].split( '.' )[ 0 ];
					var line = document.createElement( 'div' );
					var text = '[' + t + '] ' + msg;
					if ( typeof data !== 'undefined' ) {
						try {
							var d = ( typeof data === 'object' && data !== null ) ? JSON.stringify( data ) : String( data );
							text += '  ' + ( d.length > 300 ? d.slice( 0, 300 ) + '…' : d );
						} catch ( e ) {}
					}
					line.textContent = text;
					body.appendChild( line );
					body.scrollTop = body.scrollHeight;
				}
			} catch ( e ) {}
		}
		try { console.log( '[MNU] ' + msg, data ); } catch ( e ) {}
	}
	window.mnuLog = mnuLog;

	if ( ! wp || ! wp.element ) {
		mnuLog( 'wp.element unavailable — cannot register' );
		return;
	}
	if ( ! wc || ! wc.wcBlocksRegistry || ! wc.wcSettings ) {
		mnuLog( 'wcBlocksRegistry/wcSettings unavailable — cannot register' );
		return;
	}

	function getStripe() {
		return typeof window.Stripe === 'function' ? window.Stripe : null;
	}

	var el = wp.element.createElement;
	var useEffect = wp.element.useEffect;
	var useRef = wp.element.useRef;
	var useState = wp.element.useState;

	var settings = wc.wcSettings.getSetting( 'mnu_marketplace_data', {} );
	var label = settings.title || 'Credit / Debit Card';
	var description = settings.description || '';
	var publishableKey = settings.publishableKey || '';
	var currency = ( settings.currency || 'usd' ).toLowerCase();
	var finalizeUrl = settings.finalizeUrl || '';
	var restNonce = settings.restNonce || '';
	var checkoutUrl = settings.checkoutUrl || window.location.pathname;

	mnuLog( 'v3.7.36 registering payment method (server-first)', {
		hasStripe: !! getStripe(),
		hasKey: !! publishableKey,
		hasFinalize: !! finalizeUrl,
		checkoutUrl: checkoutUrl,
	} );

	function Content( props ) {
		var eventRegistration = props.eventRegistration;
		var emitResponse = props.emitResponse;
		var billing = props.billing || {};

		var containerRef = useRef( null );
		var stripeRef = useRef( null );
		var elementsRef = useRef( null );
		var paymentElementRef = useRef( null );
		// v3.7.36: hold latest event registration + emitResponse in refs so
		// the observers can be registered ONCE with an empty deps array. Blocks
		// passes fresh object identities on every render which caused an
		// infinite re-register loop that manifested in v3.7.35.7.
		var eventRegistrationRef = useRef( eventRegistration );
		var emitResponseRef = useRef( emitResponse );
		eventRegistrationRef.current = eventRegistration;
		emitResponseRef.current = emitResponse;
		var didRegisterPaymentSetupRef = useRef( false );
		var didRegisterCheckoutSuccessRef = useRef( false );
		// A ref (not state) so onCheckoutSuccess can read the latest total
		// without a rerender.
		var latestAmountRef = useRef( 100 );

		var _err = useState( '' );
		var errorMsg = _err[ 0 ];
		var setErrorMsg = _err[ 1 ];

		var _ready = useState( false );
		var isReady = _ready[ 0 ];
		var setReady = _ready[ 1 ];

		// Keep latestAmountRef in sync with cart total updates.
		useEffect( function () {
			var cartTotal = billing.cartTotal || billing.cartTotalItems || null;
			var minorTotal = 0;
			if ( cartTotal && typeof cartTotal.value !== 'undefined' ) {
				minorTotal = parseInt( cartTotal.value, 10 );
			}
			if ( ! minorTotal && billing.cartTotalItems ) {
				billing.cartTotalItems.forEach( function ( it ) {
					if ( it && typeof it.value !== 'undefined' ) {
						minorTotal += parseInt( it.value, 10 );
					}
				} );
			}
			// Stripe requires a minimum amount per currency for Elements to initialize
			// (USD minimum is 50 cents). The value passed here is only a UI hint for
			// eligible payment methods; the actual charge amount comes from the
			// server-created PaymentIntent. Floor to 50 cents so a low-priced item
			// (e.g. the $0.01 test product) plus still-loading shipping renders
			// the card form.
			if ( minorTotal > 0 ) latestAmountRef.current = Math.max( minorTotal, 50 );
		}, [ billing ] );

		// Mount Elements once on load in DEFERRED mode. No intent is
		// created here — the card form appears and validates locally.
		useEffect( function () {
			if ( ! publishableKey ) {
				setErrorMsg( 'Stripe is not configured on this site.' );
				return;
			}
			if ( stripeRef.current ) return;

			var attempts = 0;
			function tryInit() {
				var Ctor = getStripe();
				if ( ! Ctor ) {
					if ( attempts++ < 50 ) {
						setTimeout( tryInit, 100 );
						return;
					}
					setErrorMsg( 'Stripe.js failed to load. Please refresh the page.' );
					return;
				}
				startMount( Ctor );
			}
			tryInit();

			function startMount( Ctor ) {
				if ( stripeRef.current ) return;
				try {
					stripeRef.current = Ctor( publishableKey );
				} catch ( e ) {
					setErrorMsg( 'Could not initialize Stripe: ' + ( e.message || e ) );
					return;
				}

				// Deferred-mode Elements: mode/currency/amount instead of
				// clientSecret. No PaymentIntent is created here.
				elementsRef.current = stripeRef.current.elements( {
					mode: 'payment',
					amount: Math.max( latestAmountRef.current, 50 ),
					currency: currency,
					appearance: { theme: 'stripe' },
					paymentMethodCreation: 'manual',
				} );
				paymentElementRef.current = elementsRef.current.create( 'payment', {
					layout: 'tabs',
				} );

				paymentElementRef.current.on( 'loaderror', function ( ev ) {
					var msg = ( ev && ev.error && ev.error.message ) || 'Card entry form could not be loaded.';
					console.error( '[MNU] Stripe loaderror', ev && ev.error );
					setErrorMsg( msg );
				} );
				paymentElementRef.current.on( 'ready', function () {
					console.log( '[MNU] Payment Element ready (Stripe rendered fields)' );
				} );

				// Retry mount until the container ref exists.
				var mountAttempts = 0;
				function tryMount() {
					if ( containerRef.current ) {
						paymentElementRef.current.mount( containerRef.current );
						setReady( true );
						console.log( '[MNU] Payment Element mounted' );
						return;
					}
					if ( mountAttempts++ < 20 ) {
						setTimeout( tryMount, 100 );
					} else {
						setErrorMsg( 'Card entry form could not be displayed.' );
					}
				}
				tryMount();
			}
		}, [] );

		// Update Elements amount whenever the cart total changes.
		useEffect( function () {
			if ( ! elementsRef.current ) return;
			try {
				elementsRef.current.update( { amount: Math.max( latestAmountRef.current, 50 ) } );
			} catch ( e ) {
				// Non-fatal.
			}
		}, [ latestAmountRef.current ] );

		// onPaymentSetup: locally validate card details. Return SUCCESS
		// so Blocks proceeds to /wc/store/v1/checkout — the server will
		// create the intent from process_payment. We do NOT charge here.
		// Runs on every render but only registers once (ref-guarded).
		// Observer must live for the entire checkout session; we intentionally
		// do NOT unregister on unmount.
		useEffect( function () {
			if ( didRegisterPaymentSetupRef.current ) return;
			var er = eventRegistrationRef.current;
			if ( ! er || ! er.onPaymentSetup ) return; // silently wait for next render
			didRegisterPaymentSetupRef.current = true;
			mnuLog( 'registering onPaymentSetup observer (once)' );
			var unsub = er.onPaymentSetup( function () {
				var emitResponse = emitResponseRef.current;
				mnuLog( 'onPaymentSetup FIRED (Place Order clicked)' );
				if ( ! stripeRef.current || ! elementsRef.current ) {
					mnuLog( 'onPaymentSetup: stripe/elements NOT ready', { hasStripe: !! stripeRef.current, hasElements: !! elementsRef.current } );
					return {
						type: emitResponse.responseTypes.ERROR,
						message: 'Payment form is not ready.',
					};
				}
				return elementsRef.current.submit().then( function ( r ) {
					if ( r && r.error ) {
						mnuLog( 'elements.submit() error', { code: r.error.code, msg: r.error.message } );
						return {
							type: emitResponse.responseTypes.ERROR,
							message: r.error.message || 'Card details are incomplete.',
						};
					}
					mnuLog( 'elements.submit() OK — returning SUCCESS to Blocks; Blocks will POST /wc/store/v1/checkout' );
					return {
						type: emitResponse.responseTypes.SUCCESS,
						meta: {
							paymentMethodData: {
								_mnu_client_validated: '1',
							},
						},
					};
				} ).catch( function ( e ) {
					mnuLog( 'elements.submit() threw', String( e && e.message || e ) );
					return { type: emitResponse.responseTypes.ERROR, message: 'Card validation failed.' };
				} );
			} );
			return undefined; // no cleanup: observer must persist
		} ); // NO deps: runs each render, guarded by didRegisterPaymentSetupRef

		// flattenBlocksPaymentDetails — WC Blocks Store API rewrites
		// payment_details (associative array in PHP) as an indexed array of
		// { key, value } objects. It may also wrap the whole process_payment
		// return under paymentDetails or not. This helper turns any of those
		// shapes into a flat { key: value } map so the rest of the code can
		// just read `mnu.mnu_confirm`, `mnu.mnu_intent_id`, etc.
		function flattenBlocksPaymentDetails( pd ) {
			if ( ! pd ) return {};
			var out = {};
			function absorb( v ) {
				if ( ! v ) return;
				if ( Array.isArray( v ) ) {
					for ( var i = 0; i < v.length; i++ ) {
						var item = v[ i ];
						if ( item && typeof item === 'object' && 'key' in item && 'value' in item ) {
							out[ item.key ] = item.value;
						}
					}
				} else if ( typeof v === 'object' ) {
					for ( var k in v ) {
						if ( Object.prototype.hasOwnProperty.call( v, k ) ) {
							out[ k ] = v[ k ];
						}
					}
				}
			}
			// Handle: top-level flat, top-level wrapped .payment_details, or array-of-kv.
			absorb( pd );
			if ( pd && pd.payment_details ) absorb( pd.payment_details );
			return out;
		}

		// onCheckoutSuccess: fires AFTER Store API returns process_payment's
		// { result: success, redirect: mnu_confirm:// }. Intercept the
		// sentinel and run the actual confirmation + finalize.
		useEffect( function () {
			if ( didRegisterCheckoutSuccessRef.current ) return;
			var er = eventRegistrationRef.current;
			if ( ! er || ! er.onCheckoutSuccess ) return; // silently wait
			didRegisterCheckoutSuccessRef.current = true;
			mnuLog( 'registering onCheckoutSuccess observer (once)' );
			var unsub = er.onCheckoutSuccess( function ( ctx ) {
				var emitResponse = emitResponseRef.current;
				var redirect = ctx && ctx.redirectUrl;
				var pd = ctx && ctx.processingResponse && ctx.processingResponse.paymentDetails;
				// WC Blocks Store API converts payment_details into an array of
				// { key, value } objects (see CheckoutSchema::prepare_payment_details_for_response)
				// and wraps the whole process_payment return under paymentDetails.
				// Flatten every shape we might encounter into a single { key: value } map.
				var mnu = flattenBlocksPaymentDetails( pd );
				var pdSample = pd ? JSON.stringify( pd ).slice( 0, 300 ) : '';
				mnuLog( 'onCheckoutSuccess FIRED', { redirect: redirect, hasPaymentDetails: !! pd, pdKeys: pd ? Object.keys( pd ) : [], mnuKeys: mnu ? Object.keys( mnu ) : [], pdSample: pdSample } );

				// Preferred path (v3.7.36+): Blocks passes payment_details through untouched.
				if ( mnu && mnu.mnu_confirm === '1' && mnu.mnu_intent_id && mnu.mnu_client_secret && mnu.mnu_order_id && mnu.mnu_nonce ) {
					mnuLog( 'payment_details channel detected — running handleConfirm from paymentDetails' );
					return handleConfirm( mnu.mnu_intent_id, mnu.mnu_client_secret, parseInt( mnu.mnu_order_id, 10 ), mnu.mnu_nonce );
				}

				// Sentinel-in-pd.redirect path: WC Blocks strips non-http from
				// ctx.redirectUrl but preserves the raw string on processingResponse.paymentDetails.redirect.
				var pdRedirect = pd && typeof pd.redirect === 'string' ? pd.redirect : '';
				if ( pdRedirect.indexOf( 'mnu_confirm://' ) === 0 ) {
					mnuLog( 'sentinel detected in paymentDetails.redirect — running handleSentinel' );
					return handleSentinel( pdRedirect );
				}

				// Legacy fallback: sentinel via ctx.redirectUrl (classic checkout path).
				if ( typeof redirect === 'string' && redirect.indexOf( 'mnu_confirm://' ) === 0 ) {
					mnuLog( 'sentinel detected in redirect — running handleSentinel' );
					return handleSentinel( redirect );
				}

				mnuLog( 'onCheckoutSuccess: no mnu confirmation payload — letting Blocks handle it', { redirect: redirect } );
				return { type: emitResponse.responseTypes.SUCCESS };
			} );
			return undefined;
		} ); // NO deps: guarded by didRegisterCheckoutSuccessRef

		function handleSentinel( sentinel ) {
			var body = sentinel.slice( 'mnu_confirm://'.length );
			var parts = body.split( '|' );
			var intentId = decodeURIComponent( parts[ 0 ] || '' );
			var clientSecret = decodeURIComponent( parts[ 1 ] || '' );
			var orderId = parseInt( parts[ 2 ] || '0', 10 );
			var nonce = decodeURIComponent( parts[ 3 ] || '' );
			mnuLog( 'handleSentinel parsed', { intentId: intentId, orderId: orderId, hasSecret: !! clientSecret, hasNonce: !! nonce } );
			return handleConfirm( intentId, clientSecret, orderId, nonce );
		}

		function handleConfirm( intentId, clientSecret, orderId, nonce ) {
			var emitResponse = emitResponseRef.current;
			mnuLog( 'handleConfirm start', { intentId: intentId, orderId: orderId, hasSecret: !! clientSecret, hasNonce: !! nonce } );

			if ( ! intentId || ! clientSecret || ! orderId || ! nonce ) {
				mnuLog( 'handleSentinel: incomplete sentinel — ABORT' );
				return {
					type: emitResponse.responseTypes.ERROR,
					message: 'The payment handshake was incomplete. Please contact support if you were charged.',
				};
			}
			if ( ! stripeRef.current || ! elementsRef.current ) {
				mnuLog( 'handleSentinel: stripe/elements NOT ready — ABORT' );
				return {
					type: emitResponse.responseTypes.ERROR,
					message: 'Payment form is not ready.',
				};
			}
			mnuLog( 'calling stripe.confirmPayment (attaches card & charges)' );

			var returnUrl = checkoutUrl + ( checkoutUrl.indexOf( '?' ) >= 0 ? '&' : '?' ) + 'mnu_pi_return=1&order=' + orderId;

			return stripeRef.current
				.confirmPayment( {
					elements: elementsRef.current,
					clientSecret: clientSecret,
					confirmParams: { return_url: returnUrl },
					redirect: 'if_required',
				} )
				.then( function ( result ) {
					if ( result && result.error ) {
						mnuLog( 'confirmPayment ERROR', { code: result.error.code, msg: result.error.message } );
						throw new Error( result.error.message || 'Card was declined.' );
					}
					var pi = result && result.paymentIntent;
					mnuLog( 'confirmPayment OK', { status: pi && pi.status, id: pi && pi.id } );
					var ok = [ 'succeeded', 'processing', 'requires_capture' ];
					if ( ! pi || ok.indexOf( pi.status ) < 0 ) {
						throw new Error( 'Payment status: ' + ( pi && pi.status ) );
					}
					mnuLog( 'calling finalizeOrder REST' );
					return finalizeOrder( orderId, intentId, nonce );
				} )
				.then( function ( fin ) {
					mnuLog( 'finalizeOrder response', fin );
					if ( ! fin || fin.result !== 'success' || ! fin.redirect ) {
						throw new Error( ( fin && fin.message ) || 'The order could not be finalised. Please contact support if you were charged.' );
					}
					mnuLog( 'SUCCESS — navigating to ' + fin.redirect );
					return {
						type: emitResponse.responseTypes.SUCCESS,
						redirectUrl: fin.redirect,
					};
				} )
				.catch( function ( err ) {
					mnuLog( 'confirmation failed', String( err && err.message || err ) );
					console.error( '[MNU] confirmation failed', err );
					return {
						type: emitResponse.responseTypes.ERROR,
						message: err.message || 'Payment failed. Please try again or contact support.',
					};
				} );
		}

		function finalizeOrder( orderId, intentId, nonce ) {
			return fetch( finalizeUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': restNonce,
				},
				body: JSON.stringify( {
					order_id: orderId,
					intent_id: intentId,
					nonce: nonce,
				} ),
			} ).then( function ( r ) {
				return r.json().then( function ( j ) {
					if ( ! r.ok ) {
						throw new Error( ( j && j.message ) || 'Finalize failed (' + r.status + ').' );
					}
					return j;
				} );
			} );
		}

		return el(
			'div',
			{ style: { display: 'block' } },
			description
				? el( 'p', { style: { marginBottom: '.5rem' } }, description )
				: null,
			el( 'div', {
				ref: containerRef,
				style: {
					marginTop: '.5rem',
					padding: '.75rem',
					border: '1px solid #d4c9b6',
					borderRadius: '8px',
					background: '#fffdf7',
					minHeight: '48px',
				},
			} ),
			errorMsg
				? el(
					'div',
					{
						style: {
							color: '#c0392b',
							marginTop: '.5rem',
							fontSize: '.9rem',
						},
						role: 'alert',
					},
					errorMsg
				)
				: null,
			! isReady && ! errorMsg
				? el( 'p', { style: { marginTop: '.25rem', fontSize: '.85rem', color: '#666' } }, 'Loading secure payment form…' )
				: null
		);
	}

	function Label() {
		return el( 'span', {}, label );
	}

	wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'mnu_marketplace',
		label: el( Label ),
		content: el( Content ),
		edit: el( Content ),
		canMakePayment: function () {
			return !! publishableKey;
		},
		ariaLabel: label,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )( window.wp, window.wc );
