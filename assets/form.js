/* Terrific Forms — frontend behaviour: steps, validation, dataLayer, submit */
(function () {
	'use strict';
	window.dataLayer = window.dataLayer || [];

	// Read a field's submitted value (handles phone composites + multi-select).
	function readValue(el) {
		if (el.classList.contains('tf-phone-num')) {
			var wrap = el.closest('.tf-phone');
			var cc = wrap ? wrap.querySelector('.tf-phone-cc') : null;
			var num = (el.value || '').trim();
			return num ? ((cc ? cc.value : '') + ' ' + num).trim() : '';
		}
		if (el.tagName === 'SELECT' && el.multiple) {
			return Array.prototype.map.call(el.selectedOptions, function (o) { return o.value; });
		}
		return (el.value || '').trim();
	}

	function collect(form) {
		var out = {};
		form.querySelectorAll('[data-key]').forEach(function (el) {
			if (el.classList.contains('tf-options')) { return; } // option groups handled below
			var key = el.getAttribute('data-key');
			if (!key) { return; }
			out[key] = readValue(el);
		});
		form.querySelectorAll('.tf-options[data-key]').forEach(function (group) {
			var key = group.getAttribute('data-key');
			var checked = group.querySelectorAll('input:checked');
			if (!checked.length) { out[key] = ''; return; }
			if (checked.length === 1 && checked[0].type === 'radio') {
				out[key] = checked[0].value;
			} else {
				out[key] = Array.prototype.map.call(checked, function (c) { return c.value; });
			}
		});
		return out;
	}

	function setError(el, msg) {
		var wrap = el.closest('.tf-field');
		var err = wrap ? wrap.querySelector('.tf-error') : null;
		if (msg) {
			if (wrap) { wrap.classList.add('tf-has-error'); }
			if (err) { err.textContent = msg; err.hidden = false; }
			return true;
		}
		if (wrap) { wrap.classList.remove('tf-has-error'); }
		if (err) { err.hidden = true; }
		return false;
	}

	function validateStep(stepEl) {
		var ok = true;
		// Required radio/checkbox groups.
		stepEl.querySelectorAll('.tf-options[data-required="1"]').forEach(function (g) {
			if (setError(g, g.querySelectorAll('input:checked').length === 0 ? 'Please select an option.' : '')) { ok = false; }
		});
		// Inputs / selects / textareas.
		stepEl.querySelectorAll('[data-key]').forEach(function (el) {
			if (el.classList.contains('tf-options')) { return; }
			var raw = (el.value || '');
			var has = (el.tagName === 'SELECT' && el.multiple) ? el.selectedOptions.length > 0 : raw.trim() !== '';
			var req = el.getAttribute('data-required') === '1';
			var msg = '';
			if (req && !has) {
				msg = 'This field is required.';
			} else if (has) {
				if (el.type === 'email' && !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(raw)) { msg = 'Enter a valid email address.'; }
				var minl = parseInt(el.getAttribute('data-minlength'), 10);
				var maxl = parseInt(el.getAttribute('data-maxlength'), 10);
				if (!msg && minl && raw.length < minl) { msg = 'Must be at least ' + minl + ' characters.'; }
				if (!msg && maxl && raw.length > maxl) { msg = 'Must be at most ' + maxl + ' characters.'; }
				if (!msg && el.type === 'number' && raw !== '') {
					var n = parseFloat(raw);
					var mn = el.getAttribute('data-min');
					var mx = el.getAttribute('data-max');
					if (mn !== null && mn !== '' && n < parseFloat(mn)) { msg = 'Must be ' + mn + ' or more.'; }
					if (!msg && mx !== null && mx !== '' && n > parseFloat(mx)) { msg = 'Must be ' + mx + ' or less.'; }
				}
			}
			if (setError(el, msg)) { ok = false; }
		});
		return ok;
	}

	function pushStep(form, stepNum) {
		var ev = {
			event: 'form_step',
			form_id: form.getAttribute('data-form-id'),
			form_name: form.getAttribute('data-form-name'),
			form_type: 'Terrific Form',
			form_step: stepNum,
			form_fields: collect(form)
		};
		var intent = form.getAttribute('data-form-intent');
		if (intent) { ev.form_intent = intent; }
		window.dataLayer.push(ev);
	}

	// Fired on every Next of a multi-step form: stores/updates the partial
	// submission server-side (so abandoned forms are still captured).
	function sendStep(form, stepNum) {
		if (form.getAttribute('data-multistep') !== '1') { return; }
		var body = new URLSearchParams();
		body.append('action', 'tf_step');
		body.append('nonce', window.TF_FORM.nonce);
		body.append('post_id', form.getAttribute('data-post-id'));
		body.append('step', stepNum);
		body.append('submission_token', form.__tfToken);
		body.append('fields', JSON.stringify(collect(form)));
		body.append('page_url', location.href);
		body.append('page_title', document.title || '');
		body.append('page_ref', document.referrer || '');
		body.append('platform', (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '');
		body.append('language', navigator.language || '');
		fetch(window.TF_FORM.ajax, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true }).catch(function () {});
	}

	function showStep(form, n) {
		var steps = form.querySelectorAll('.tf-step');
		var total = steps.length;
		steps.forEach(function (s) { s.classList.toggle('is-active', parseInt(s.getAttribute('data-step'), 10) === n); });
		var pct = Math.round((n / total) * 100);
		var fill = form.querySelector('.tf-progress-fill');
		var lbl = form.querySelector('.tf-progress-step');
		var pl = form.querySelector('.tf-progress-pct');
		if (fill) { fill.style.width = pct + '%'; }
		if (lbl) { lbl.textContent = 'Step ' + n + ' of ' + total; }
		if (pl) { pl.textContent = pct + '%'; }
		try { form.scrollIntoView({ behavior: 'smooth', block: 'start' }); } catch (e) {}
	}

	function init(form) {
		if (form.__tfInit) { return; }
		form.__tfInit = true;
		var multi = form.getAttribute('data-multistep') === '1';
		// Stable token for this fill — links every step + the final submit to one row.
		form.__tfToken = 'tfs_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);

		form.addEventListener('click', function (e) {
			var next = e.target.closest('.tf-next');
			var back = e.target.closest('.tf-back');
			if (next) {
				e.preventDefault();
				var cur = next.closest('.tf-step');
				var n = parseInt(next.getAttribute('data-step'), 10);
				if (!validateStep(cur)) { return; }
				pushStep(form, n);          // fire form_step on every progression
				sendStep(form, n);
				showStep(form, n + 1);
			} else if (back) {
				e.preventDefault();
				var b = parseInt(back.getAttribute('data-step'), 10);
				showStep(form, b - 1);
			}
		});

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var activeStep = form.querySelector('.tf-step.is-active') || form;
			if (!validateStep(activeStep)) { return; }
			var fields = collect(form);
			if (multi) {
				// final step also counts as a progression
				pushStep(form, parseInt(form.getAttribute('data-total-steps'), 10));
			}
			var btn = form.querySelector('.tf-submit');
			if (btn) { btn.disabled = true; btn.classList.add('tf-loading'); }

			var body = new URLSearchParams();
			body.append('action', 'tf_submit');
			body.append('nonce', window.TF_FORM.nonce);
			body.append('post_id', form.getAttribute('data-post-id'));
			body.append('submission_token', form.__tfToken);
			body.append('fields', JSON.stringify(fields));
			body.append('page_url', location.href);
			body.append('page_title', document.title || '');
			body.append('page_ref', document.referrer || '');
			body.append('platform', (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '');
			body.append('language', navigator.language || '');

			fetch(window.TF_FORM.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (res) {
					var subEv = { event: 'form_submission', form_id: form.getAttribute('data-form-id'), form_name: form.getAttribute('data-form-name'), form_type: 'Terrific Form', form_fields: fields };
						var subIntent = form.getAttribute('data-form-intent');
						if (subIntent) { subEv.form_intent = subIntent; }
						window.dataLayer.push(subEv);
					var redirect = res && res.data && res.data.redirect;
					if (redirect) { setTimeout(function () { window.location.href = redirect; }, 250); return; }
					form.querySelectorAll('.tf-step, .tf-progress').forEach(function (s) { s.style.display = 'none'; });
					var ty = form.querySelector('.tf-thankyou');
					if (ty) { ty.hidden = false; }
				})
				.catch(function () {
					if (btn) { btn.disabled = false; btn.classList.remove('tf-loading'); }
					alert('Something went wrong. Please try again.');
				});
		});
	}

	// Auto-select the phone country dropdown(s) by the visitor's IP (cache-safe AJAX).
	function geolocatePhones() {
		var selects = document.querySelectorAll('.tf-phone-cc');
		if (!selects.length || !window.TF_FORM) { return; }
		fetch(window.TF_FORM.ajax + '?action=tf_geo', { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				var cc = res && res.data && res.data.country_code;
				if (!cc) { return; }
				selects.forEach(function (sel) {
					var opt = sel.querySelector('option[data-cc="' + cc + '"]');
					if (opt) { sel.value = opt.value; }
				});
			})
			.catch(function () {});
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.tf-form').forEach(init);
		geolocatePhones();
	});
})();
