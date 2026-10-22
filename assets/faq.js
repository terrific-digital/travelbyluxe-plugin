/* TBL FAQ — front-end accordion. Opening an item fires a dataLayer event
 * { event:'faq_item_click', faq_title:'<question lowercased>' } once per item. */
(function () {
	'use strict';
	window.dataLayer = window.dataLayer || [];

	// Push to the dataLayer, stamping every plugin event with its origin.
	function tdPush(obj) {
		if (obj && typeof obj === 'object' && obj.event_origin === undefined) { obj.event_origin = 'td_wp_plugin'; }
		window.dataLayer.push(obj);
	}

	// Open/close an item via the .is-open class (CSS animates the panel).
	function setOpen(item, btn, open) {
		btn.setAttribute('aria-expanded', open ? 'true' : 'false');
		item.classList.toggle('is-open', open);
		if (open && !item.__faqFired) {
			item.__faqFired = true; // fire once per FAQ item, on first open
			var title = (item.getAttribute('data-faq-title') || '').trim().toLowerCase();
			tdPush({ event: 'faq_item_click', faq_title: title });
		}
	}

	function init(faq) {
		if (faq.__tdFaqInit) { return; }
		faq.__tdFaqInit = true;
		var single = faq.getAttribute('data-single') === '1'; // open one at a time?
		var items = Array.prototype.slice.call(faq.querySelectorAll('.td-faq-item'));
		items.forEach(function (item) {
			var btn = item.querySelector('.td-faq-q');
			if (!btn) { return; }
			btn.addEventListener('click', function () {
				var willOpen = btn.getAttribute('aria-expanded') !== 'true';
				if (willOpen && single) {
					// close any other open item first
					items.forEach(function (other) {
						if (other !== item && other.classList.contains('is-open')) {
							setOpen(other, other.querySelector('.td-faq-q'), false);
						}
					});
				}
				setOpen(item, btn, willOpen);
			});
		});
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.td-faq').forEach(init);
	});
})();
