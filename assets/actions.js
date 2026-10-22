/* TBL Forms — Actions builder (add/remove/toggle webhook & redirect actions) */
( function () {
	'use strict';
	var root = document.getElementById( 'tf-actions' );
	var hidden = document.getElementById( 'tf_actions' );
	if ( ! root || ! hidden ) { return; }

	var data = window.TF_ACTIONS || { globalWebhook: '', settingsUrl: '', pages: [] };
	var actions = [];
	try { actions = JSON.parse( root.getAttribute( 'data-actions' ) ) || []; } catch ( e ) { actions = []; }
	if ( ! Array.isArray( actions ) ) { actions = []; }

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"]/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[ c ];
		} );
	}
	function sync() { hidden.value = JSON.stringify( actions ); }

	function isMultistep() {
		var r = document.querySelector( 'input[name="tf_type"]:checked' );
		return r ? r.value === 'multistep' : false;
	}

	function pageOptions( selected ) {
		var s = '<option value="0">— Select a page —</option>';
		( data.pages || [] ).forEach( function ( p ) {
			s += '<option value="' + esc( p.id ) + '"' + ( String( p.id ) === String( selected ) ? ' selected' : '' ) + '>' + esc( p.title ) + '</option>';
		} );
		return s;
	}

	function webhookBody( a, i ) {
		var src = a.config.source || 'custom';
		var globalLabel = data.globalWebhook
			? 'Use global webhook <code>' + esc( data.globalWebhook ) + '</code>'
			: 'Use global webhook <em>(not set — <a href="' + esc( data.settingsUrl ) + '" target="_blank">configure in Settings</a>)</em>';
		var html = '' +
			'<label class="tf-ac-radio"><input type="radio" name="tf-src-' + i + '" value="global" ' + ( src === 'global' ? 'checked' : '' ) + '> ' + globalLabel + '</label>' +
			'<label class="tf-ac-radio"><input type="radio" name="tf-src-' + i + '" value="custom" ' + ( src === 'custom' ? 'checked' : '' ) + '> Custom URL for this form</label>' +
			'<input type="url" class="widefat tf-ac-url" placeholder="https://hooks.example.com/…" value="' + esc( a.config.url || '' ) + '"' + ( src === 'custom' ? '' : ' disabled' ) + '>';
		// Multi-step only: when to fire the webhook.
		if ( isMultistep() ) {
			var trig = a.config.trigger === 'every' ? 'every' : 'final';
			html += '<label class="tf-ac-field"><span class="tf-ac-flabel">Send on</span>' +
				'<select class="tf-ac-trigger">' +
					'<option value="final"' + ( trig === 'final' ? ' selected' : '' ) + '>Final step only</option>' +
					'<option value="every"' + ( trig === 'every' ? ' selected' : '' ) + '>Every step</option>' +
				'</select></label>';
		}
		return html;
	}

	function redirectBody( a, i ) {
		var mode = a.config.mode || 'url';
		return '' +
			'<label class="tf-ac-radio"><input type="radio" name="tf-mode-' + i + '" value="url" ' + ( mode === 'url' ? 'checked' : '' ) + '> Redirect to a URL</label>' +
			'<input type="url" class="widefat tf-ac-rurl" placeholder="https://example.com/thank-you" value="' + esc( a.config.url || '' ) + '"' + ( mode === 'url' ? '' : ' disabled' ) + '>' +
			'<label class="tf-ac-radio"><input type="radio" name="tf-mode-' + i + '" value="page" ' + ( mode === 'page' ? 'checked' : '' ) + '> Redirect to a page</label>' +
			'<select class="tf-ac-page"' + ( mode === 'page' ? '' : ' disabled' ) + '>' + pageOptions( a.config.page_id || 0 ) + '</select>';
	}

	function datalayerBody( a, i ) {
		return '' +
			'<label class="tf-ac-field"><span class="tf-ac-flabel">Event name</span>' +
				'<input type="text" class="widefat tf-ac-event" placeholder="e.g. lead_qualified" value="' + esc( a.config.event || '' ) + '"></label>' +
			'<p class="description" style="margin:6px 0 0">Pushed to the dataLayer after <code>form_submission</code> and the form intent event, carrying the same parameters as the intent event.</p>';
	}

	function handoffBody() {
		return '<p class="description" style="margin:0">Before the redirect, keeps the answers in the browser (<code>sessionStorage.tblThankYou</code>) so the thank-you page can greet the guest by name and show what they asked for. Nothing is sent anywhere.</p>';
	}

	var TITLES = { webhook: '🔗 Webhook', redirect: '↪️ Redirect', datalayer: '📊 DataLayer Event', handoff: '🎁 Thank-you summary' };

	function card( a, i ) {
		var el = document.createElement( 'div' );
		el.className = 'tf-action-card' + ( a.enabled ? '' : ' is-off' );
		var title = TITLES[ a.type ] || a.type;
		var body = a.type === 'webhook' ? webhookBody( a, i ) : ( a.type === 'redirect' ? redirectBody( a, i ) : ( a.type === 'handoff' ? handoffBody() : datalayerBody( a, i ) ) );
		el.innerHTML =
			'<div class="tf-action-head">' +
				'<span class="tf-action-title">' + title + '</span>' +
				'<label class="tf-action-toggle"><input type="checkbox" class="tf-ac-enabled" ' + ( a.enabled ? 'checked' : '' ) + '> Enabled</label>' +
				'<button type="button" class="button-link tf-ac-remove" title="Remove action" aria-label="Remove action">✕</button>' +
			'</div>' +
			'<div class="tf-action-body">' + body + '</div>';

		el.querySelector( '.tf-ac-enabled' ).addEventListener( 'change', function ( e ) {
			a.enabled = e.target.checked;
			el.classList.toggle( 'is-off', ! a.enabled );
			sync();
		} );
		el.querySelector( '.tf-ac-remove' ).addEventListener( 'click', function () {
			actions.splice( i, 1);
			render();
		} );

		if ( a.type === 'webhook' ) {
			el.querySelectorAll( 'input[name="tf-src-' + i + '"]' ).forEach( function ( r ) {
				r.addEventListener( 'change', function () { a.config.source = r.value; render(); } );
			} );
			var u = el.querySelector( '.tf-ac-url' );
			if ( u ) { u.addEventListener( 'input', function () { a.config.url = u.value; sync(); } ); }
			var tg = el.querySelector( '.tf-ac-trigger' );
			if ( tg ) { tg.addEventListener( 'change', function () { a.config.trigger = tg.value; sync(); } ); }
		} else if ( a.type === 'redirect' ) {
			el.querySelectorAll( 'input[name="tf-mode-' + i + '"]' ).forEach( function ( r ) {
				r.addEventListener( 'change', function () { a.config.mode = r.value; render(); } );
			} );
			var ru = el.querySelector( '.tf-ac-rurl' );
			if ( ru ) { ru.addEventListener( 'input', function () { a.config.url = ru.value; sync(); } ); }
			var pg = el.querySelector( '.tf-ac-page' );
			if ( pg ) { pg.addEventListener( 'change', function () { a.config.page_id = pg.value; sync(); } ); }
		} else {
			var ev = el.querySelector( '.tf-ac-event' );
			if ( ev ) { ev.addEventListener( 'input', function () { a.config.event = ev.value; sync(); } ); }
		}
		return el;
	}

	function hasType( type ) {
		return actions.some( function ( a ) { return a.type === type; } );
	}

	function addAction( type ) {
		// webhook & redirect are singletons; datalayer events can be added repeatedly.
		if ( type !== 'datalayer' && hasType( type ) ) { return; }
		if ( type === 'webhook' ) {
			actions.push( { type: 'webhook', enabled: true, config: { source: data.globalWebhook ? 'global' : 'custom', url: '', trigger: 'final' } } );
		} else if ( type === 'redirect' ) {
			actions.push( { type: 'redirect', enabled: true, config: { mode: 'url', url: '', page_id: 0 } } );
		} else if ( type === 'handoff' ) {
			actions.push( { type: 'handoff', enabled: true, config: {} } );
		} else {
			actions.push( { type: 'datalayer', enabled: true, config: { event: '' } } );
		}
		render();
	}

	function render() {
		root.innerHTML = '';
		if ( ! actions.length ) {
			var empty = document.createElement( 'p' );
			empty.className = 'tf-actions-empty';
			empty.textContent = 'No actions yet. Add one below to run when the form is submitted.';
			root.appendChild( empty );
		} else {
			actions.forEach( function ( a, i ) { root.appendChild( card( a, i ) ); } );
		}
		var add = document.createElement( 'div' );
		add.className = 'tf-add-action';
		var hasWebhook = hasType( 'webhook' );
		var hasRedirect = hasType( 'redirect' );
		add.innerHTML =
			'<span class="tf-add-label">Add action:</span> ' +
			'<button type="button" class="button tf-add" data-type="webhook"' + ( hasWebhook ? ' disabled title="Already added"' : '' ) + '>＋ Webhook</button> ' +
			'<button type="button" class="button tf-add" data-type="redirect"' + ( hasRedirect ? ' disabled title="Already added"' : '' ) + '>＋ Redirect</button> ' +
			'<button type="button" class="button tf-add" data-type="datalayer">＋ DataLayer Event</button> ' +
			'<button type="button" class="button tf-add" data-type="handoff"' + ( hasType( 'handoff' ) ? ' disabled title="Already added"' : '' ) + '>＋ Thank-you summary</button>';
		add.querySelectorAll( '.tf-add' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () { addAction( b.getAttribute( 'data-type' ) ); } );
		} );
		root.appendChild( add );
		sync();
	}

	render();

	// Re-render so the webhook "Send on" option appears/disappears as the form type toggles.
	document.querySelectorAll( 'input[name="tf_type"]' ).forEach( function ( r ) {
		r.addEventListener( 'change', render );
	} );
} )();
