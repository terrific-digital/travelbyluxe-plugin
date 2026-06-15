/* Terrific Digital — Store Session Data.
 * Records a `td_session_data` object once at the start of a browsing session:
 *   { timestamp, url, ref }
 * Persisted in sessionStorage or a session cookie (chosen in plugin Settings).
 */
(function () {
	'use strict';
	var cfg = window.TD_SESSION || {};
	var KEY = cfg.key || 'td_session_data';
	var useCookie = cfg.storage === 'cookie';

	function readCookie(name) {
		var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
		return match ? decodeURIComponent(match[1]) : null;
	}

	function exists() {
		try {
			return useCookie ? readCookie(KEY) !== null : sessionStorage.getItem(KEY) !== null;
		} catch (e) {
			return false;
		}
	}

	function save(obj) {
		var json = JSON.stringify(obj);
		try {
			if (useCookie) {
				// No Expires/Max-Age => a session cookie (cleared when the browser closes).
				var secure = location.protocol === 'https:' ? '; Secure' : '';
				document.cookie = KEY + '=' + encodeURIComponent(json) + '; path=/; SameSite=Lax' + secure;
			} else {
				sessionStorage.setItem(KEY, json);
			}
		} catch (e) {}
	}

	// Only write at the start of the session — never overwrite on later page views.
	if (!exists()) {
		save({
			timestamp: new Date().toISOString(),
			url: location.href,
			ref: document.referrer || ''
		});
	}
})();
