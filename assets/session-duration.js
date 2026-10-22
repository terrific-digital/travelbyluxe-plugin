/* Travel by Luxe — Session Duration tracking.
 * Counts seconds the visitor has spent in the current tab (sessionStorage,
 * survives same-tab navigation) and pushes `session_duration_<n>` to the
 * dataLayer when each configured milestone is first reached.
 * Each milestone fires once per session — even across multiple page loads. */
(function () {
	'use strict';
	if (typeof window === 'undefined') { return; }
	window.dataLayer = window.dataLayer || [];

	var cfg = window.TD_SESSION_DURATION || {};
	var milestones = (cfg.milestones || []).map(function (n) { return parseInt(n, 10); })
		.filter(function (n) { return n > 0; })
		.sort(function (a, b) { return a - b; });
	if (!milestones.length) { return; }

	var COUNT_KEY = 'td_session_duration';
	var FIRED_KEY = 'td_session_duration_fired';
	var maxMilestone = milestones[milestones.length - 1];

	function read(key, fallback) {
		try { var v = sessionStorage.getItem(key); return v == null ? fallback : v; }
		catch (e) { return fallback; }
	}
	function write(key, value) {
		try { sessionStorage.setItem(key, value); } catch (e) {}
	}

	function loadFired() {
		var raw = read(FIRED_KEY, '[]');
		try {
			var arr = JSON.parse(raw);
			return Array.isArray(arr) ? arr : [];
		} catch (e) { return []; }
	}

	function tdPush(obj) {
		if (obj && typeof obj === 'object' && obj.event_origin === undefined) {
			obj.event_origin = 'td_wp_plugin';
		}
		window.dataLayer.push(obj);
	}

	var seconds = parseInt(read(COUNT_KEY, '0'), 10) || 0;
	var fired   = loadFired();

	function tick() {
		seconds++;
		write(COUNT_KEY, String(seconds));

		for (var i = 0; i < milestones.length; i++) {
			var m = milestones[i];
			if (seconds >= m && fired.indexOf(m) === -1) {
				fired.push(m);
				write(FIRED_KEY, JSON.stringify(fired));
				tdPush({ event: 'session_duration_' + m });
			}
		}

		// Stop ticking once we've passed the largest milestone.
		if (seconds < maxMilestone) {
			setTimeout(tick, 1000);
		}
	}

	// Only schedule if there's still a milestone left to fire.
	if (seconds < maxMilestone || fired.length < milestones.length) {
		setTimeout(tick, 1000);
	}
})();
