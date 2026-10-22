/* Travel by Luxe — Store Session Data.
 * Records a `td_session_data` object once at the start of a browsing session:
 *   { timestamp, url, ref }
 * Persisted in sessionStorage or a session cookie (chosen in plugin Settings).
 *
 * The landing `url` is normalized first: when browsers/ad-blockers strip the
 * "official" tracking params (utm_*, gclid, msclkid, …) we fall back to our own
 * counterpart params (td_*, hsa_*) carried on the URL and rebuild the standard
 * ones from them — but only when the official param isn't already present
 * (the surviving official value always wins). Mirrors the GTM backup function.
 */
(function () {
	'use strict';
	var cfg = window.TD_SESSION || {};
	var KEY = cfg.key || 'td_session_data';
	var useCookie = cfg.storage === 'cookie';

	// Backup param -> canonical param. td_* shadow the standard click/utm params;
	// hsa_* (HubSpot ads auto-tagging) are normalized into the utm/td_* scheme.
	var PARAM_MAP = {
		td_src: 'utm_source', td_med: 'utm_medium', td_cmp: 'utm_campaign', td_cmp_id: 'utm_id',
		td_kw: 'utm_term', td_grp: 'utm_content', td_ga_cid: 'gclid', td_ba_cid: 'msclkid',
		td_ma_cid: 'msclkid', td_ob_cid: 'dicbo', td_tt_cid: 'ttclid', td_ra_cid: 'rdt_cid',
		td_ya_cid: 'yclid', hsa_cam: 'utm_id', hsa_grp: 'td_grp_id', hsa_ad: 'td_ad_id',
		hsa_tgt: 'td_kw_mt', hsa_src: 'td_net', hsa_kw: 'utm_term', hsa_mt: 'td_kw_mt'
	};

	// Rebuild official params from their backups (whole-key match; official wins).
	function normalizeUrl(href) {
		try {
			var u = new URL(href);
			var sp = u.searchParams;
			Object.keys(PARAM_MAP).forEach(function (backup) {
				var canonical = PARAM_MAP[backup];
				if (sp.has(backup) && !sp.has(canonical)) {
					sp.set(canonical, sp.get(backup)); // first value of the backup param
					sp.delete(backup);
				}
			});
			u.search = sp.toString();
			return u.href;
		} catch (e) {
			return href; // bad URL / no URLSearchParams support: store as-is
		}
	}

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
			url: normalizeUrl(location.href),
			ref: document.referrer || ''
		});
	}
})();
