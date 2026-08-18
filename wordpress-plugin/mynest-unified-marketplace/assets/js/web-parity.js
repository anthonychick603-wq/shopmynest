/**
 * MyNest Web Parity — client for the web copies of app-only features.
 *
 * Endpoints called:
 *   POST   /wp-json/the-nest/v1/saved-searches            (save alert pill)
 *   PUT    /wp-json/the-nest/v1/saved-searches/{id}       (toggle notify)
 *   DELETE /wp-json/the-nest/v1/saved-searches/{id}       (delete row)
 *   POST   /wp-json/the-nest/v1/blog/posts/{id}/favorite  (heart a blog post)
 *   DELETE /wp-json/the-nest/v1/blog/posts/{id}/favorite  (unheart)
 *   GET    /wp-json/the-nest/v1/blog/posts/{id}/comments  (list)
 *   POST   /wp-json/the-nest/v1/blog/posts/{id}/comments  (add)
 *
 * Auth: same-origin cookies + X-WP-Nonce (wp_rest).
 */
( function () {
	'use strict';

	var cfg = window.MNUWebParity || {};

	function api( path, opts ) {
		opts = opts || {};
		var url = cfg.restRoot + path.replace( /^\/+/, '' );
		var init = {
			method: opts.method || 'GET',
			credentials: 'same-origin',
			headers: Object.assign( { 'X-WP-Nonce': cfg.restNonce || '', Accept: 'application/json' }, opts.headers || {} ),
		};
		if ( opts.body != null ) {
			init.body = typeof opts.body === 'string' ? opts.body : JSON.stringify( opts.body );
			if ( ! init.headers['Content-Type'] ) init.headers['Content-Type'] = 'application/json';
		}
		return fetch( url, init ).then( function ( r ) {
			var isJson = ( r.headers.get( 'content-type' ) || '' ).indexOf( 'application/json' ) !== -1;
			return ( isJson ? r.json() : r.text() ).then( function ( body ) {
				if ( ! r.ok ) {
					var msg = ( body && body.message ) ? body.message : ( r.statusText || 'Request failed' );
					var err = new Error( msg );
					err.status = r.status;
					err.body = body;
					throw err;
				}
				return body;
			} );
		} );
	}

	/* --------------------------------------------------- Save alert pill */

	function wireSaveAlert() {
		var btn = document.querySelector( '[data-mnu-save-search]' );
		if ( ! btn ) return;
		var msg = document.querySelector( '[data-mnu-save-search-msg]' );
		btn.addEventListener( 'click', function () {
			if ( btn.disabled ) return;
			var payload;
			try { payload = JSON.parse( btn.getAttribute( 'data-payload' ) || '{}' ); } catch ( e ) { payload = {}; }
			btn.disabled = true;
			var oldLabel = btn.querySelector( '.mnu-save-alert-label' ).textContent;
			btn.querySelector( '.mnu-save-alert-label' ).textContent = 'Saving…';
			api( 'the-nest/v1/saved-searches', { method: 'POST', body: payload } )
				.then( function () {
					btn.querySelector( '.mnu-save-alert-label' ).textContent = 'Saved!';
					btn.classList.add( 'is-saved' );
					if ( msg ) {
						msg.innerHTML = 'We\'ll alert you when new listings match. <a href="' + cfg.pages.saved_searches + '">Manage saved searches</a>.';
					}
				} )
				.catch( function ( err ) {
					btn.querySelector( '.mnu-save-alert-label' ).textContent = oldLabel;
					btn.disabled = false;
					if ( msg ) msg.textContent = err.message || 'Could not save that search.';
				} );
		} );
	}

	/* -------------------------------------------- Saved-searches page */

	function wireSavedSearches() {
		var root = document.querySelector( '[data-mnu-saved-searches]' );
		if ( ! root ) return;
		root.addEventListener( 'click', function ( ev ) {
			var del = ev.target.closest( '[data-mnu-search-delete]' );
			if ( ! del ) return;
			ev.preventDefault();
			var row = del.closest( '[data-search-id]' );
			var id = row && row.getAttribute( 'data-search-id' );
			if ( ! id ) return;
			if ( ! window.confirm( 'Delete this saved search?' ) ) return;
			del.disabled = true;
			api( 'the-nest/v1/saved-searches/' + encodeURIComponent( id ), { method: 'DELETE' } )
				.then( function () { row.remove(); } )
				.catch( function () { del.disabled = false; window.alert( 'Could not delete that saved search. Try again.' ); } );
		} );
		root.addEventListener( 'change', function ( ev ) {
			var chk = ev.target.closest( '[data-mnu-search-notify]' );
			if ( ! chk ) return;
			var row = chk.closest( '[data-search-id]' );
			var id = row && row.getAttribute( 'data-search-id' );
			if ( ! id ) return;
			var label = chk.parentElement.querySelector( 'span' );
			var wanted = chk.checked;
			chk.disabled = true;
			api( 'the-nest/v1/saved-searches/' + encodeURIComponent( id ), { method: 'PUT', body: { notify: wanted } } )
				.then( function () {
					if ( label ) label.textContent = 'Alerts ' + ( wanted ? 'on' : 'off' );
				} )
				.catch( function () {
					chk.checked = ! wanted;
					window.alert( 'Could not update that saved search.' );
				} )
				.finally( function () { chk.disabled = false; } );
		} );
	}

	/* ---------------------------------------------- Blog single page */

	function loadBlogComments( postId, listEl ) {
		api( 'the-nest/v1/blog/posts/' + encodeURIComponent( postId ) + '/comments' )
			.then( function ( data ) {
				var items = ( data && Array.isArray( data.items ) ) ? data.items : ( Array.isArray( data ) ? data : [] );
				if ( ! items.length ) {
					listEl.innerHTML = '<p class="mnu-parity__empty">No comments yet. Say something kind.</p>';
					return;
				}
				var html = items.map( function ( c ) {
					var name = ( c.author && c.author.name ) ? c.author.name : ( c.author_name || 'Anonymous' );
					var avatar = ( c.author && c.author.avatar ) ? c.author.avatar : '';
					var content = String( c.content || '' ).replace( /</g, '&lt;' );
					var date = c.created_at || '';
					return '<article class="mnu-blog-comment">'
						+ ( avatar ? '<img class="mnu-blog-comment__avatar" src="' + avatar + '" alt="">' : '' )
						+ '<div class="mnu-blog-comment__body"><p class="mnu-blog-comment__author"><strong>' + name + '</strong> <span class="mnu-blog-comment__date">' + date.slice( 0, 10 ) + '</span></p>'
						+ '<p class="mnu-blog-comment__content">' + content + '</p></div></article>';
				} ).join( '' );
				listEl.innerHTML = html;
			} )
			.catch( function () {
				listEl.innerHTML = '<p class="mnu-parity__empty">Could not load comments.</p>';
			} );
	}

	function wireBlogSingle() {
		var root = document.querySelector( '[data-mnu-blog-single]' );
		if ( ! root ) return;
		var postId = root.getAttribute( 'data-post-id' );
		if ( ! postId ) return;

		var fav = root.querySelector( '[data-mnu-blog-fav]' );
		if ( fav ) {
			fav.addEventListener( 'click', function () {
				if ( fav.getAttribute( 'data-guest' ) === '1' ) {
					window.location = cfg.loginUrl;
					return;
				}
				if ( fav.disabled ) return;
				fav.disabled = true;
				var wasFaved = fav.classList.contains( 'is-faved' );
				var method = wasFaved ? 'DELETE' : 'POST';
				api( 'the-nest/v1/blog/posts/' + encodeURIComponent( postId ) + '/favorite', { method: method } )
					.then( function ( data ) {
						fav.classList.toggle( 'is-faved' );
						fav.setAttribute( 'aria-pressed', fav.classList.contains( 'is-faved' ) ? 'true' : 'false' );
						fav.querySelector( 'span[aria-hidden]' ).textContent = fav.classList.contains( 'is-faved' ) ? '♥' : '♡';
						var cnt = root.querySelector( '[data-mnu-blog-fav-count]' );
						if ( cnt && data && typeof data.count !== 'undefined' ) {
							cnt.textContent = String( data.count );
						} else if ( cnt ) {
							var cur = parseInt( cnt.textContent, 10 ) || 0;
							cnt.textContent = String( wasFaved ? Math.max( 0, cur - 1 ) : cur + 1 );
						}
					} )
					.catch( function () { window.alert( 'Could not update your favorite. Try again.' ); } )
					.finally( function () { fav.disabled = false; } );
			} );
		}

		var listEl = root.querySelector( '[data-mnu-blog-comments-list]' );
		if ( listEl ) loadBlogComments( postId, listEl );

		var form = root.querySelector( '[data-mnu-blog-comment-form]' );
		if ( form ) {
			form.addEventListener( 'submit', function ( ev ) {
				ev.preventDefault();
				var ta = form.querySelector( 'textarea[name="content"]' );
				var content = ( ta.value || '' ).trim();
				if ( ! content ) return;
				var btn = form.querySelector( 'button[type="submit"]' );
				btn.disabled = true;
				var oldLabel = btn.textContent;
				btn.textContent = 'Posting…';
				api( 'the-nest/v1/blog/posts/' + encodeURIComponent( postId ) + '/comments', { method: 'POST', body: { content: content } } )
					.then( function () {
						ta.value = '';
						if ( listEl ) loadBlogComments( postId, listEl );
					} )
					.catch( function ( err ) { window.alert( err.message || 'Could not post comment.' ); } )
					.finally( function () { btn.disabled = false; btn.textContent = oldLabel; } );
			} );
		}
	}

	function boot() {
		wireSaveAlert();
		wireSavedSearches();
		wireBlogSingle();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
