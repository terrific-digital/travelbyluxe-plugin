/* Terrific FAQ — front-end accordion. Opening an item fires a dataLayer event
 * { event:'faq_item_click', faq_title:'<question lowercased>' } once per item. */
(function () {
	'use strict';
	window.dataLayer = window.dataLayer || [];

	// Push to the dataLayer, stamping every plugin event with its origin.
	function tdPush(obj) {
		if (obj && typeof obj === 'object' && obj.event_origin === undefined) { obj.event_origin = 'td_wp_plugin'; }
		window.dataLayer.push(obj);
	}

	function toggle(item, btn, panel) {
		var open = btn.getAttribute('aria-expanded') === 'true';
		if (open) {
			btn.setAttribute('aria-expanded', 'false');
			panel.hidden = true;
			item.classList.remove('is-open');
			return;
		}
		btn.setAttribute('aria-expanded', 'true');
		panel.hidden = false;
		item.classList.add('is-open');
		// Fire once per FAQ item, on first open.
		if (!item.__faqFired) {
			item.__faqFired = true;
			var title = (item.getAttribute('data-faq-title') || '').trim().toLowerCase();
			tdPush({ event: 'faq_item_click', faq_title: title });
		}
	}

	function init(faq) {
		if (faq.__tdFaqInit) { return; }
		faq.__tdFaqInit = true;
		faq.querySelectorAll('.td-faq-item').forEach(function (item) {
			var btn = item.querySelector('.td-faq-q');
			var panel = item.querySelector('.td-faq-a');
			if (!btn || !panel) { return; }
			btn.addEventListener('click', function () { toggle(item, btn, panel); });
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.td-faq').forEach(init);
	});
})();
