/* Travel by Luxe — Calendly scheduler-widget listener.
 * Mirrors the GTM custom-HTML tag we used previously: listens to postMessage
 * events from Calendly's embedded widget and pushes dataLayer events that
 * align with our standard form-tracking taxonomy.
 *
 * Mapping (Calendly → ours):
 *   profile_page_viewed     → meeting_scheduler_loaded
 *   event_type_viewed       → meeting_type_selected
 *   date_and_time_selected  → meeting_time_selected
 *   event_scheduled         → form_submission, then a second event named
 *                             after the configured form_intent (default
 *                             `meeting_booked`) — mirrors how TBL Forms
 *                             fires the intent param right after submission.
 *
 * Each Calendly event fires at most once per page-load. */
(function () {
	'use strict';
	if (typeof window === 'undefined') { return; }
	window.dataLayer = window.dataLayer || [];

	var cfg = window.TD_CALENDLY || {};
	var meta = {
		form_type:   cfg.form_type   || 'calendly_scheduler_widget',
		form_name:   cfg.form_name   || 'Calendly Scheduler Widget',
		form_id:     cfg.form_id     || 'calendly_scheduler_widget',
		form_intent: cfg.form_intent || 'meeting_booked'
	};

	// Calendly event suffix → our event name (without the "meeting_" prefix).
	var MAP = {
		profile_page_viewed:    'scheduler_loaded',
		event_type_viewed:      'type_selected',
		date_and_time_selected: 'time_selected',
		event_scheduled:        'booked'
	};

	var fired = {};

	function tdPush(obj) {
		if (obj && typeof obj === 'object' && obj.event_origin === undefined) {
			obj.event_origin = 'td_wp_plugin';
		}
		window.dataLayer.push(obj);
	}

	// Pull the trailing scheduled-event id out of Calendly's payload URI.
	// e.g. "https://api.calendly.com/scheduled_events/<uuid>" → "<uuid>"
	function entryKey(payload) {
		try {
			var uri = payload && payload.event && payload.event.uri;
			if (!uri || typeof uri !== 'string') { return null; }
			var parts = uri.split('scheduled_events/');
			return parts.length > 1 ? parts[1] : null;
		} catch (e) { return null; }
	}

	window.addEventListener('message', function (e) {
		var data = e && e.data;
		if (!data || typeof data.event !== 'string' || data.event.indexOf('calendly') !== 0) { return; }

		var suffix = data.event.split('.')[1];
		var mapped = MAP[suffix];
		if (!mapped) { return; }
		if (fired[suffix]) { return; }
		fired[suffix] = true;

		var eventName = 'meeting_' + mapped;
		var entry     = null;
		if (eventName === 'meeting_booked') {
			eventName = 'form_submission';
			entry     = entryKey(data.payload);
		}

		tdPush({
			event:       eventName,
			form_type:   meta.form_type,
			form_name:   meta.form_name,
			form_id:     meta.form_id,
			form_intent: meta.form_intent,
			entry_key:   entry
		});

		// Fire the intent as its own event immediately after form_submission —
		// matches TBL Forms behaviour (e.g. generate_lead after a lead form).
		if (eventName === 'form_submission' && meta.form_intent) {
			tdPush({
				event:       meta.form_intent,
				form_type:   meta.form_type,
				form_name:   meta.form_name,
				form_id:     meta.form_id,
				form_intent: meta.form_intent,
				entry_key:   entry
			});
		}
	});
})();
