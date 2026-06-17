/* Terrific Digital — automatic link-click dataLayer events.
 * Pushes:
 *   email_link_click  — for href^="mailto:"
 *   phone_link_click  — for href^="tel:"
 *   social_link_click — for known social-network hosts (facebook, instagram, …)
 * Payload always includes link_text + link_url; social adds social_platform.
 * One capture-phase listener on document so dynamically-added links work too. */
(function () {
	'use strict';
	window.dataLayer = window.dataLayer || [];

	function tdPush(obj) {
		if (obj && typeof obj === 'object' && obj.event_origin === undefined) {
			obj.event_origin = 'td_wp_plugin';
		}
		window.dataLayer.push(obj);
	}

	// host substring → canonical platform name. Ordered: longer keys first so
	// e.g. "fb.com" matches before a bare "facebook" check would.
	var SOCIAL = [
		['facebook.com',  'facebook'],
		['fb.com',        'facebook'],
		['fb.me',         'facebook'],
		['instagram.com', 'instagram'],
		['linkedin.com',  'linkedin'],
		['lnkd.in',       'linkedin'],
		['tiktok.com',    'tiktok'],
		['x.com',         'x'],
		['twitter.com',   'x'],
		['t.co',          'x'],
		['youtube.com',   'youtube'],
		['youtu.be',      'youtube'],
		['pinterest.com', 'pinterest'],
		['pin.it',        'pinterest'],
		['threads.net',   'threads'],
		['threads.com',   'threads'],
		['snapchat.com',  'snapchat'],
		['whatsapp.com',  'whatsapp'],
		['wa.me',         'whatsapp'],
		['telegram.org',  'telegram'],
		['t.me',          'telegram'],
		['reddit.com',    'reddit'],
		['github.com',    'github'],
		['discord.com',   'discord'],
		['discord.gg',    'discord'],
		['medium.com',    'medium'],
		['vimeo.com',     'vimeo'],
		['twitch.tv',     'twitch']
	];

	function socialPlatform(host) {
		host = (host || '').toLowerCase();
		if (!host) { return ''; }
		for (var i = 0; i < SOCIAL.length; i++) {
			var needle = SOCIAL[i][0];
			if (host === needle || host.endsWith('.' + needle) || host.indexOf(needle) !== -1) {
				return SOCIAL[i][1];
			}
		}
		return '';
	}

	// Visible text of the link, trimmed. Falls back to aria-label / title /
	// img alt / the URL itself so the event always carries something useful.
	function linkText(a) {
		var txt = (a.textContent || '').replace(/\s+/g, ' ').trim();
		if (txt) { return txt; }
		var aria = a.getAttribute('aria-label');
		if (aria && aria.trim()) { return aria.trim(); }
		var title = a.getAttribute('title');
		if (title && title.trim()) { return title.trim(); }
		var img = a.querySelector('img[alt]');
		if (img && img.alt && img.alt.trim()) { return img.alt.trim(); }
		return a.getAttribute('href') || '';
	}

	document.addEventListener('click', function (e) {
		// Walk up to the anchor — handles clicks on inner <span>/<img>/etc.
		var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
		if (!a) { return; }

		var href = a.getAttribute('href') || '';
		if (!href) { return; }

		var lower = href.toLowerCase();
		var text  = linkText(a);

		if (lower.indexOf('mailto:') === 0) {
			tdPush({ event: 'email_link_click', link_text: text, link_url: href });
			return;
		}
		if (lower.indexOf('tel:') === 0) {
			tdPush({ event: 'phone_link_click', link_text: text, link_url: href });
			return;
		}

		// Only http(s) / protocol-relative / same-origin links can be social.
		// `new URL` resolves relative hrefs against the current page.
		var host = '';
		try {
			host = new URL(href, window.location.href).hostname;
		} catch (err) { return; }

		var platform = socialPlatform(host);
		if (platform) {
			tdPush({
				event:           'social_link_click',
				social_platform: platform,
				link_text:       text,
				link_url:        a.href || href
			});
		}
	}, true); // capture phase: still fires if a child handler stops propagation
})();
