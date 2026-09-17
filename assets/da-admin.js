/**
 * DevDome Analytics admin screen behaviour: tab switching, settings save, connect /
 * disconnect / reset flows and the async Overview metric tiles.
 *
 * Enqueued (handle `devdalyt-admin`) by DEVDALYT_Admin::enqueue_assets() on the plugin
 * page only. Config is read from the #da-app data-* attributes printed by render().
 */
( function () {
	var app = document.getElementById( 'da-app' );
	if ( ! app ) { return; }
	var REST = app.getAttribute( 'data-rest' ), NONCE = app.getAttribute( 'data-nonce' );
	function call( path, method, body ) {
		// rest_url() on a PLAIN-permalinks site (the WordPress default) is
		// index.php?rest_route=/devdome-analytics/v1/ — already carrying a "?". A path's own
		// query must then join with "&", or it becomes part of the rest_route value and the
		// request dies as rest_no_route (broken Overview tiles on every such site).
		var url = REST + ( -1 !== REST.indexOf( '?' ) ? path.replace( '?', '&' ) : path );
		return fetch( url, {
			method: method,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) { return r.json().then( function ( j ) { return { ok: r.ok, data: j }; } ); } );
	}
	function setText( id, v ) { var el = document.getElementById( id ); if ( el ) { el.textContent = v; } }
	// The server's own words when it gave any (a database error names the query; the guard's message says what to do).
	function why( r, fallback ) { var m = r && r.data && ( r.data.message || ( r.data.data && r.data.data.message ) ); return m && typeof m === 'string' ? fallback + ' ' + m : fallback; }
	// A failed action: red banner at the top of the app with "Report this error" (core 1.7.0). Replaces alert().
	// The banner goes away on the next tab switch or action, like every result banner (DESIGN.md 22).
	function fail( msg, screen ) {
		var old = app.querySelector( '.da-fail' ); if ( old ) { old.remove(); }
		var box = document.createElement( 'div' ); box.className = 'da-fail';
		box.setAttribute( 'style', 'margin:16px 24px 0;padding:10px 12px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#b91c1c;font-size:13px;' );
		box.appendChild( document.createTextNode( msg ) );
		if ( window.devdcorev1Report ) {
			var ctx = { plugin: 'devdome-analytics', version: app.getAttribute( 'data-version' ) || '', error: msg, screen: screen || 'Analytics' };
			var wrap = document.createElement( 'span' ); wrap.className = 'ddc-report';
			var btn = document.createElement( 'button' ); btn.type = 'button'; btn.className = 'ddc-report-btn'; btn.setAttribute( 'data-ddc-report', JSON.stringify( ctx ) ); btn.textContent = 'Report this error';
			wrap.appendChild( btn ); box.appendChild( wrap );
		}
		app.insertBefore( box, app.firstChild );
	}
	function clearFail() { var old = app.querySelector( '.da-fail' ); if ( old ) { old.remove(); } }
	// A failure found on an action that then reloads the page (partial disconnect): park the message, show it after the reload.
	function failAfterReload( msg, screen ) { try { var prev = JSON.parse( sessionStorage.getItem( 'da-fail' ) || 'null' ); if ( prev && prev.m ) { msg = prev.m + ' ' + msg; } sessionStorage.setItem( 'da-fail', JSON.stringify( { m: msg, s: screen } ) ); } catch ( e ) { fail( msg, screen ); } }
	try { var parked = sessionStorage.getItem( 'da-fail' ); if ( parked ) { sessionStorage.removeItem( 'da-fail' ); parked = JSON.parse( parked ); if ( parked && parked.m ) { fail( parked.m, parked.s ); } } } catch ( e ) {}

	// Tab switching (panels render once; switching is instant client-side, hash-synced).
	var tabs = app.querySelectorAll( '.dd-tab[data-dd-tab]' ), panels = app.querySelectorAll( '.dd-tabpanel[data-dd-panel]' );
	function showTab( name ) {
		var found = false;
		panels.forEach( function ( p ) { var on = p.getAttribute( 'data-dd-panel' ) === name; p.classList.toggle( 'is-active', on ); if ( on ) { found = true; } } );
		if ( ! found ) { return; }
		tabs.forEach( function ( t ) { t.classList.toggle( 'is-active', t.getAttribute( 'data-dd-tab' ) === name ); } );
	}
	tabs.forEach( function ( t ) {
		t.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var name = t.getAttribute( 'data-dd-tab' );
			clearFail();
			showTab( name );
			if ( window.history && history.replaceState ) { history.replaceState( null, '', '#' + name ); }
		} );
	} );
	window.addEventListener( 'hashchange', function () { var h = ( location.hash || '' ).replace( '#', '' ); if ( h ) { showTab( h ); } } );
	var hash0 = ( location.hash || '' ).replace( '#', '' );
	if ( hash0 ) { showTab( hash0 ); }

	// Sticky footer: save the Excluded roles list (the tracking switches save themselves on change).
	var saveBtn = document.getElementById( 'da-save' );
	if ( saveBtn ) { saveBtn.addEventListener( 'click', function () {
		var roles = [];
		app.querySelectorAll( '[data-role]' ).forEach( function ( cb ) { if ( cb.checked ) { roles.push( cb.getAttribute( 'data-role' ) ); } } );
		saveBtn.disabled = true;
		call( 'settings', 'POST', { excluded_roles: roles } ).then( function ( r ) {
			saveBtn.disabled = false;
			if ( ! r || ! r.ok || ! r.data || r.data.ok !== true ) { fail( why( r, 'The excluded roles could not be saved.' ), 'Analytics settings: excluded roles' ); return; }
			var n = document.getElementById( 'da-saved-note' );
			if ( n ) { n.style.display = ''; setTimeout( function () { n.style.display = 'none'; }, 2500 ); }
		} ).catch( function ( e ) { saveBtn.disabled = false; fail( 'The excluded roles could not be saved (' + ( e && e.message ? e.message : 'request failed' ) + ').', 'Analytics settings: excluded roles' ); } );
	} ); }

	// Overview metrics (async — never blocks page load). 1:1 with the dashboard.
	function fmtInt( n ) { return ( +n || 0 ).toLocaleString( 'en-US' ); }
	function fmtMMSS( s ) { s = Math.max( 0, Math.round( +s || 0 ) ); var m = Math.floor( s / 60 ), x = s % 60; return ( m < 10 ? '0' : '' ) + m + ':' + ( x < 10 ? '0' : '' ) + x; }
	function loadStats( days ) {
		call( 'stats?days=' + days, 'GET' ).then( function ( res ) {
			var d = ( res && res.data ) || {};
			// Not-connected (or failed) answers carry no numbers at all. Leave the tiles on
			// their "—" placeholders: painting absent values as hard zeros reads as "your
			// site really had zero visitors", which is a lie.
			if ( ! ( 'visitors' in d ) || null === d.visitors ) { return; } // null = DevDome did not answer: keep the placeholders, never paint zeros
			setText( 'da-m-visitors',        fmtInt( d.visitors ) );
			setText( 'da-m-live',            fmtInt( d.live ) );
			setText( 'da-m-bots',            fmtInt( d.bots ) );
			setText( 'da-m-clicks',          fmtInt( d.clicks ) );
			setText( 'da-m-pageviews',       fmtInt( d.pageviews ) );
			setText( 'da-m-ctr_visitors',    ( +d.ctr_visitors  || 0 ).toFixed( 2 ) + '%' );
			setText( 'da-m-ctr_pageviews',   ( +d.ctr_pageviews || 0 ).toFixed( 2 ) + '%' );
			setText( 'da-m-pages_per_visit', ( +d.pages_per_visit || 0 ).toFixed( 2 ) );
			setText( 'da-m-avg_session',     fmtMMSS( d.avg_session_seconds ) );
			setText( 'da-m-bounce_rate',     ( +d.bounce_rate || 0 ).toFixed( 1 ) + '%' );
		} ).catch( function () {} );
	}
	app.querySelectorAll( '.dd-dd' ).forEach( function ( dd ) {
			var trg = dd.querySelector( '.dd-dd-trigger' ), inp = dd.querySelector( 'input[type="hidden"]' ), lbl = dd.querySelector( '.dd-dd-label' );
			if ( ! trg ) { return; }
			trg.addEventListener( 'click', function ( e ) { e.stopPropagation(); var open = ! dd.classList.contains( 'is-open' ); app.querySelectorAll( '.dd-dd' ).forEach( function ( d ) { d.classList.remove( 'is-open' ); } ); dd.classList.toggle( 'is-open', open ); } );
			dd.querySelectorAll( '.dd-dd-opt' ).forEach( function ( o ) { o.addEventListener( 'click', function ( e ) { e.stopPropagation(); dd.querySelectorAll( '.dd-dd-opt' ).forEach( function ( x ) { x.classList.toggle( 'is-selected', x === o ); } ); if ( inp ) { inp.value = o.getAttribute( 'data-value' ); inp.dispatchEvent( new Event( 'change', { bubbles: true } ) ); } if ( lbl ) { lbl.textContent = o.textContent; } dd.classList.remove( 'is-open' ); } ); } );
		} );
		document.addEventListener( 'click', function () { app.querySelectorAll( '.dd-dd' ).forEach( function ( d ) { d.classList.remove( 'is-open' ); } ); } );
		var rangeSel = document.querySelector( '[name="da_range"]' );
	if ( rangeSel && '1' === app.getAttribute( 'data-connected' ) ) { loadStats( rangeSel.value ); rangeSel.addEventListener( 'change', function () { loadStats( rangeSel.value ); } ); }

	// Tracking switches → save on change.
	app.querySelectorAll( '[data-setting]' ).forEach( function ( cb ) {
		cb.addEventListener( 'change', function () {
			var key = cb.getAttribute( 'data-setting' ), on = cb.checked, body = {}; body[ key ] = on;
			// The pill follows the checkbox, and follows it back when the save fails (DeepSeek round 1).
			function pill( state ) { if ( key !== 'tracking' ) { return; } var p = document.getElementById( 'da-tracking-pill' ); if ( p ) { p.textContent = state ? 'On' : 'Off'; p.className = 'da-pill ' + ( state ? 'is-on' : 'is-off' ); } }
			pill( on );
			cb.disabled = true;
			call( 'settings', 'POST', body ).then( function ( r ) {
				cb.disabled = false;
				if ( ! r || ! r.ok || ! r.data || r.data.ok !== true ) { cb.checked = ! on; pill( ! on ); fail( why( r, 'The setting "' + key + '" could not be saved.' ), 'Analytics settings' ); return; } // a 200 {ok:false} is a failed save too (DeepSeek round 5)
				if ( r.data.notes && r.data.notes.length ) { fail( r.data.notes.join( ' ' ), 'Analytics settings' ); }
				if ( r.data.settings && typeof r.data.settings[ key ] !== 'undefined' && !! r.data.settings[ key ] !== on ) { cb.checked = !! r.data.settings[ key ]; pill( cb.checked ); if ( key !== 'first_party' ) { fail( 'The setting "' + key + '" did not stick. Please try again.', 'Analytics settings' ); } }
				// The server may resolve a switch differently than requested (first_party:
				// entitlement said no / could not be asked / site not connected) — mirror
				// the stored value so the UI never shows an ON switch that is really off.
				if ( r && r.data && typeof r.data[ key ] !== 'undefined' && !! r.data[ key ] !== on ) {
					cb.checked = !! r.data[ key ];
					// first_party: the refusal just stored a fresh entitlement verdict — reload so
					// PHP re-renders the row frozen with the gate strip, not a snapped-back switch.
					if ( key === 'first_party' ) { window.location.reload(); }
				}
			} ).catch( function ( e ) { cb.disabled = false; cb.checked = ! on; pill( ! on ); fail( 'The setting "' + key + '" could not be saved (' + ( e && e.message ? e.message : 'request failed' ) + ').', 'Analytics settings' ); } );
		} );
	} );

	// Manual Account-ID connect removed (2026-08-11): connecting goes through the
	// "Connect Via DevDome Account" flow only, so the account is always the signed-in one.

	var testBtn = null; // Test Connection removed (button + /test route gone); block below is inert.
	if ( testBtn ) { testBtn.addEventListener( 'click', function () {
		testBtn.disabled = true; testBtn.textContent = 'Testing…';
		call( 'test', 'POST' ).then( function ( res ) {
			alert( res.ok && res.data && res.data.ok ? 'Connection OK.' : ( ( res.data && res.data.message ) || 'Connection failed.' ) );
			testBtn.disabled = false; testBtn.textContent = 'Test connection';
		} ).catch( function () { testBtn.disabled = false; testBtn.textContent = 'Test connection'; } );
	} ); }

	var disconnectBtn = document.getElementById( 'da-disconnect' );
		var dDno = document.getElementById( 'da-disc-no' ), dDyes = document.getElementById( 'da-disc-yes' ), dDin = document.getElementById( 'da-disc-input' );
		if ( disconnectBtn ) { disconnectBtn.addEventListener( 'click', function () {
			var dc = document.getElementById( 'da-disc-confirm' );
			if ( dc ) { dc.style.display = 'block'; disconnectBtn.style.display = 'none'; }
			if ( dDin ) { dDin.value = ''; setTimeout( function () { dDin.focus(); }, 0 ); }
			if ( dDyes ) { dDyes.disabled = true; }
		} ); }
		// Disconnect: must type DISCONNECT to enable.
		if ( dDin ) { dDin.addEventListener( 'input', function () { if ( dDyes ) { dDyes.disabled = dDin.value.trim().toUpperCase() !== 'DISCONNECT'; } } ); }
		if ( dDno ) { dDno.addEventListener( 'click', function () { var dc = document.getElementById( 'da-disc-confirm' ), db = document.getElementById( 'da-disconnect' ), p = document.getElementById( 'da-purge' ); if ( dc ) { dc.style.display = 'none'; } if ( db ) { db.style.display = ''; } if ( p ) { p.checked = false; } if ( dDin ) { dDin.value = ''; } if ( dDyes ) { dDyes.disabled = true; } } ); }
		if ( dDyes ) { dDyes.addEventListener( 'click', function () { if ( dDin && dDin.value.trim().toUpperCase() !== 'DISCONNECT' ) { return; } var p = document.getElementById( 'da-purge' ); dDyes.disabled = true; call( 'disconnect', 'POST', { confirm: true, purge: !! ( p && p.checked ) } ).then( function ( r ) { if ( ! r || ! r.ok || ! r.data || r.data.ok !== true ) { throw new Error( why( r, 'Disconnect failed.' ) ); } if ( r.data.remote_unlinked === false ) { failAfterReload( 'Disconnected on this site, but the DevDome account server could not be told; the account may still list this site. Remove it at devdome.com or reconnect and disconnect again.', 'Analytics disconnect' ); } if ( p && p.checked && r.data.purged === false ) { failAfterReload( 'The collected data could not be deleted on DevDome. Use Reset analytics after reconnecting, or contact support.', 'Analytics disconnect' ); } window.location.reload(); } ).catch( function ( e ) { dDyes.disabled = false; fail( ( e && e.message ? e.message : 'Disconnect failed.' ) + ' Please try again.', 'Analytics disconnect' ); } ); } ); }
		// Reset: must type RESET to enable. Purges data, stays connected.
		var rBtn = document.getElementById( 'da-reset' ), rNo = document.getElementById( 'da-reset-no' ), rYes = document.getElementById( 'da-reset-yes' ), rIn = document.getElementById( 'da-reset-input' );
		if ( rBtn ) { rBtn.addEventListener( 'click', function () { var rc = document.getElementById( 'da-reset-confirm' ); if ( rc ) { rc.style.display = 'block'; rBtn.style.display = 'none'; } if ( rIn ) { rIn.value = ''; setTimeout( function () { rIn.focus(); }, 0 ); } if ( rYes ) { rYes.disabled = true; } } ); }
		if ( rIn ) { rIn.addEventListener( 'input', function () { if ( rYes ) { rYes.disabled = rIn.value.trim().toUpperCase() !== 'RESET'; } } ); }
		if ( rNo ) { rNo.addEventListener( 'click', function () { var rc = document.getElementById( 'da-reset-confirm' ); if ( rc ) { rc.style.display = 'none'; } if ( rBtn ) { rBtn.style.display = ''; } if ( rIn ) { rIn.value = ''; } if ( rYes ) { rYes.disabled = true; } } ); }
		if ( rYes ) { rYes.addEventListener( 'click', function () { if ( rIn && rIn.value.trim().toUpperCase() !== 'RESET' ) { return; } rYes.disabled = true; call( 'reset', 'POST', { confirm: true } ).then( function ( r ) { if ( ! r || ! r.ok || ! r.data || r.data.ok !== true ) { throw new Error( why( r, 'DevDome did not confirm the deletion; the collected data is still there. Try again in a minute.' ) ); } window.location.reload(); } ).catch( function ( e ) { rYes.disabled = false; fail( e && e.message ? e.message : 'DevDome did not confirm the deletion; the collected data is still there. Try again in a minute.', 'Analytics reset' ); } ); } ); }
	} )();
