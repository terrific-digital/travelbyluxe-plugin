/* Terrific Digital — Submissions list-table flag icons.
 * AJAX-toggles a row's flag (test / spam / none) when an icon in the Flag
 * column is clicked. Same-flag click clears; different-flag click switches. */
(function () {
	'use strict';

	function setRowFlag(cell, newFlag) {
		cell.classList.remove('tf-flag-is-test', 'tf-flag-is-spam');
		if (newFlag === 'test') { cell.classList.add('tf-flag-is-test'); }
		if (newFlag === 'spam') { cell.classList.add('tf-flag-is-spam'); }
		cell.dataset.flag = newFlag || '';
	}

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.tf-flag-btn');
		if (!btn) { return; }
		var cell = btn.closest('.tf-flag-cell');
		if (!cell || !window.TD_SUBS) { return; }

		e.preventDefault();
		var id     = cell.dataset.id;
		var target = btn.dataset.set;
		var cur    = cell.dataset.flag || '';
		// Click the active icon → clear. Click the other → switch.
		var next   = (cur === target) ? '' : target;

		// Optimistic UI: flip immediately, revert on error.
		setRowFlag(cell, next);
		cell.classList.add('tf-flag-busy');

		var body = new URLSearchParams();
		body.append('action', 'tf_flag');
		body.append('_ajax_nonce', TD_SUBS.nonce);
		body.append('id', id);
		body.append('flag', next);

		fetch(TD_SUBS.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				cell.classList.remove('tf-flag-busy');
				if (!res || !res.success) {
					setRowFlag(cell, cur); // revert
					alert('Could not update flag.');
				}
			})
			.catch(function () {
				cell.classList.remove('tf-flag-busy');
				setRowFlag(cell, cur);
				alert('Could not update flag.');
			});
	});
})();
