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

	// Local YYYY-MM-DD (avoids the UTC shift of toISOString for date-only values).
	function ymd(d) {
		var m = d.getMonth() + 1, day = d.getDate();
		return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (day < 10 ? '0' + day : day);
	}
	// Honeypot value (a hidden field only bots fill).
	function honeypot(form) {
		var h = form.querySelector('.tf-hp input');
		return h ? h.value : '';
	}

	// Resolve a Google reCAPTCHA v3 token for the given action (empty string when
	// reCAPTCHA isn't enabled or grecaptcha failed to load — the server then decides).
	function recaptchaToken(action) {
		return new Promise(function (resolve) {
			var rc = window.TF_FORM && window.TF_FORM.recaptcha;
			if (!rc || !rc.site_key || !window.grecaptcha || !window.grecaptcha.execute) { resolve(''); return; }
			try {
				window.grecaptcha.ready(function () {
					window.grecaptcha.execute(rc.site_key, { action: action }).then(
						function (token) { resolve(token || ''); },
						function () { resolve(''); }
					);
				});
			} catch (e) { resolve(''); }
		});
	}

	// The td_session_data object (JSON string) from the "Store Session Data" feature,
	// read from sessionStorage or a cookie. Empty string if not collected.
	function sessionData() {
		var KEY = 'td_session_data', raw = null;
		try { raw = window.sessionStorage ? sessionStorage.getItem(KEY) : null; } catch (e) {}
		if (!raw) {
			var m = document.cookie.match(/(?:^|; )td_session_data=([^;]*)/);
			if (m) { try { raw = decodeURIComponent(m[1]); } catch (e) { raw = m[1]; } }
		}
		return raw || '';
	}

	/* ---------------------------------------------------- User-Provided Data (upd)
	 * Captures the visitor's personal details from any form fill into a single
	 * persisted object { fn, ln, em, ph, co } and pushes a `upd` dataLayer event
	 * whenever it changes — fired before the matching form_step/form_submission.
	 */
	var UPD = (window.TF_FORM && window.TF_FORM.upd) || {};
	var UPD_KEYS = ['fn', 'ln', 'em', 'ph', 'co'];
	// Field key/label keyword detection (matched against "key label", lowercased).
	var RE_FIRST = /\b(first[\s_-]?name|firstname|fname|given[\s_-]?name|forename|first)\b/;
	var RE_LAST = /\b(last[\s_-]?name|lastname|lname|sur[\s_-]?name|surname|family[\s_-]?name|last)\b/;
	var RE_FULL = /\b(full[\s_-]?name|fullname|your[\s_-]?name|name)\b/;
	var RE_COUNTRY = /\b(country|nation|co)\b/;

	function updEnabled() { return !!UPD.enabled; }
	function digitsOnly(s) { return (s || '').replace(/\D+/g, ''); }

	// A field's visible label text (for keyword matching), falling back to aria-label.
	function fieldLabel(el) {
		var wrap = el.closest('.tf-field');
		var lbl = wrap ? wrap.querySelector('.tf-label') : null;
		return (lbl ? lbl.textContent : (el.getAttribute('aria-label') || '')) || '';
	}

	function readUpd() {
		var raw = null, useCookie = UPD.storage === 'cookie', K = UPD.key || 'td_upd';
		try {
			if (useCookie) {
				var m = document.cookie.match(new RegExp('(?:^|; )' + K.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
				if (m) { raw = decodeURIComponent(m[1]); }
			} else if (window.localStorage) {
				raw = localStorage.getItem(K);
			}
		} catch (e) {}
		var obj = {};
		if (raw) { try { obj = JSON.parse(raw) || {}; } catch (e) { obj = {}; } }
		return obj;
	}

	function saveUpd(obj) {
		var json = JSON.stringify(obj), useCookie = UPD.storage === 'cookie', K = UPD.key || 'td_upd';
		try {
			if (useCookie) {
				// Max-Age 2147483647s (~68y): the conventional "as far out as it goes" cookie.
				var secure = location.protocol === 'https:' ? '; Secure' : '';
				document.cookie = K + '=' + encodeURIComponent(json) + '; path=/; max-age=2147483647; expires=Fri, 31 Dec 9999 23:59:59 GMT; SameSite=Lax' + secure;
			} else if (window.localStorage) {
				localStorage.setItem(K, json);
			}
		} catch (e) {}
	}

	// Inspect the form's current values and return the personal data slots found.
	function detectUpd(form) {
		var found = {};
		// Email — first non-empty email input.
		var em = form.querySelector('input[type="email"][data-key]');
		if (em && em.value.trim()) { found.em = em.value.trim().toLowerCase(); }
		// Phone — composite (.tf-phone-num + dial-code) or a plain tel input.
		var phNum = form.querySelector('.tf-phone-num[data-key]') || form.querySelector('input[type="tel"][data-key]');
		if (phNum && phNum.value.trim()) {
			var wrap = phNum.closest('.tf-phone');
			var cc = wrap ? wrap.querySelector('.tf-phone-cc') : null;
			found.ph = digitsOnly((cc ? cc.value : '') + phNum.value);
			if (cc && cc.options[cc.selectedIndex]) {
				var code = cc.options[cc.selectedIndex].getAttribute('data-cc');
				if (code) { found.co = code.toUpperCase(); }
			}
		}
		// Names + an explicit country field, detected by key/label keywords.
		form.querySelectorAll('[data-key]').forEach(function (el) {
			if (el.classList.contains('tf-options') || el.classList.contains('tf-phone-num')) { return; }
			if (el.type === 'email' || el.type === 'range' || el.type === 'hidden') { return; }
			var v = (el.value || '').trim();
			if (!v) { return; }
			var hay = ((el.getAttribute('data-key') || '') + ' ' + fieldLabel(el)).toLowerCase();
			if (found.fn === undefined && RE_FIRST.test(hay)) { found.fn = v.toLowerCase(); }
			else if (found.ln === undefined && RE_LAST.test(hay)) { found.ln = v.toLowerCase(); }
			else if ((found.fn === undefined || found.ln === undefined) && RE_FULL.test(hay)) {
				var parts = v.split(/\s+/);
				if (found.fn === undefined) { found.fn = (parts.shift() || '').toLowerCase(); }
				if (found.ln === undefined && parts.length) { found.ln = parts.join(' ').toLowerCase(); }
			}
			if (found.co === undefined && RE_COUNTRY.test(hay) && /^[a-z]{2}$/i.test(v)) {
				found.co = v.toUpperCase();
			}
		});
		return found;
	}

	// Merge freshly-found values into the persisted object; on any change, persist
	// and push the dataLayer event (callers invoke this BEFORE form_step/submission).
	function updateUpd(form) {
		if (!updEnabled()) { return; }
		var found = detectUpd(form);
		var cur = readUpd();
		var next = {}, changed = false;
		UPD_KEYS.forEach(function (k) {
			next[k] = (cur && typeof cur[k] === 'string') ? cur[k] : '';
			if (found[k] && found[k] !== next[k]) { next[k] = found[k]; changed = true; }
		});
		if (!changed) { return; }
		saveUpd(next);
		window.dataLayer.push({ event: UPD.event || 'upd', upd: next });
	}

	function collect(form) {
		var out = {};
		form.querySelectorAll('[data-key]').forEach(function (el) {
			if (el.classList.contains('tf-options')) { return; } // option groups handled below
			var key = el.getAttribute('data-key');
			if (!key) { return; }
			out[key] = readValue(el);
			// Phone with a country-code selector: also submit the selected country as an object
			// { code, name, ISO } under `<key>_country`.
			if (el.classList.contains('tf-phone-num')) {
				var pwrap = el.closest('.tf-phone');
				var pcc = pwrap ? pwrap.querySelector('.tf-phone-cc') : null;
				if (pcc && (el.value || '').trim()) {
					var copt = pcc.options[pcc.selectedIndex];
					out[key + '_country'] = {
						code: pcc.value,
						name: copt ? (copt.getAttribute('data-name') || '') : '',
						ISO: copt ? (copt.getAttribute('data-cc') || '') : ''
					};
				}
			}
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
				// Date bounds (min/max set on init from days-from-today; ISO strings compare lexicographically).
				if (!msg && el.type === 'date' && raw) {
					if (el.min && raw < el.min) { msg = 'Please choose a later date.'; }
					else if (el.max && raw > el.max) { msg = 'Please choose an earlier date.'; }
				}
			}
			if (setError(el, msg)) { ok = false; }
		});
		return ok;
	}

	// Fire `form_visible` once, the first time the form scrolls into view.
	function pushVisible(form) {
		if (form.__tfVisible) { return; }
		form.__tfVisible = true;
		var ev = {
			event: 'form_visible',
			form_id: form.getAttribute('data-form-id'),
			form_name: form.getAttribute('data-form-name'),
			form_type: 'Terrific Form'
		};
		var intent = form.getAttribute('data-form-intent');
		if (intent) { ev.form_intent = intent; }
		window.dataLayer.push(ev);
	}

	// Fire `form_started` once, the first time the user enters a value in any field.
	function pushStarted(form) {
		if (form.__tfStarted) { return; }
		form.__tfStarted = true;
		var ev = {
			event: 'form_started',
			form_id: form.getAttribute('data-form-id'),
			form_name: form.getAttribute('data-form-name'),
			form_type: 'Terrific Form'
		};
		var intent = form.getAttribute('data-form-intent');
		if (intent) { ev.form_intent = intent; }
		window.dataLayer.push(ev);
	}

	function pushStep(form, stepNum) {
		updateUpd(form); // push the `upd` event first, so it precedes form_step
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
		body.append('hp', honeypot(form));
		body.append('session', sessionData());
		body.append('fields', JSON.stringify(collect(form)));
		body.append('page_url', location.href);
		body.append('page_title', document.title || '');
		body.append('page_ref', document.referrer || '');
		body.append('platform', (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '');
		body.append('language', navigator.language || '');
		recaptchaToken('step').then(function (tok) {
			if (tok) { body.append('recaptcha_token', tok); }
			fetch(window.TF_FORM.ajax, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true }).catch(function () {});
		});
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

	// Earliest selectable start date = today + N days (N=0 → today, blocks the past).
	function startMinDate(daysFromToday) {
		var d = new Date(); d.setHours(0, 0, 0, 0);
		d.setDate(d.getDate() + daysFromToday);
		return ymd(d);
	}
	function init(form) {
		if (form.__tfInit) { return; }
		form.__tfInit = true;
		var multi = form.getAttribute('data-multistep') === '1';
		// Slider: live value readout (in the field's label), with prefix/suffix.
		form.querySelectorAll('.tf-range').forEach(function (r) {
			var field = r.closest('.tf-field');
			var out = field ? field.querySelector('.tf-range-val') : (r.parentNode && r.parentNode.querySelector('.tf-range-val'));
			if (!out) { return; }
			var pfx = r.getAttribute('data-prefix') || '';
			var sfx = r.getAttribute('data-suffix') || '';
			function fmt() {
				var n = Number(r.value);
				out.textContent = pfx + (isNaN(n) ? r.value : n.toLocaleString()) + sfx;
			}
			fmt();
			r.addEventListener('input', fmt);
		});
		// Single date field: apply earliest/latest bounds (days from today → actual dates).
		form.querySelectorAll('input[type="date"][data-key]').forEach(function (d) {
			var mn = parseInt(d.getAttribute('data-min-from-today'), 10);
			var mx = parseInt(d.getAttribute('data-max-from-today'), 10);
			if (!isNaN(mn)) { d.min = startMinDate(mn); }
			if (!isNaN(mx)) { d.max = startMinDate(mx); }
		});
		// Stable token for this fill — links every step + the final submit to one row.
		form.__tfToken = 'tfs_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 12);

		// form_visible: fire once when the form first scrolls into view.
		if ('IntersectionObserver' in window) {
			var io = new IntersectionObserver(function (entries) {
				for (var i = 0; i < entries.length; i++) {
					if (entries[i].isIntersecting) { pushVisible(form); io.disconnect(); break; }
				}
			}, { threshold: 0.01 });
			io.observe(form);
		} else {
			pushVisible(form); // no IntersectionObserver support: assume visible
		}

		// form_started: fire once on the first real value entered (ignore the honeypot).
		function onFirstInteract(e) {
			if (e.target && e.target.closest && e.target.closest('.tf-hp')) { return; }
			pushStarted(form);
		}
		form.addEventListener('input', onFirstInteract);
		form.addEventListener('change', onFirstInteract);

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
			updateUpd(form); // capture/push `upd` before the form_submission event
			var btn = form.querySelector('.tf-submit');
			if (btn) { btn.disabled = true; btn.classList.add('tf-loading'); }

			var body = new URLSearchParams();
			body.append('action', 'tf_submit');
			body.append('nonce', window.TF_FORM.nonce);
			body.append('post_id', form.getAttribute('data-post-id'));
			body.append('submission_token', form.__tfToken);
			body.append('hp', honeypot(form));
			body.append('session', sessionData());
			body.append('fields', JSON.stringify(fields));
			body.append('page_url', location.href);
			body.append('page_title', document.title || '');
			body.append('page_ref', document.referrer || '');
			body.append('platform', (navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '');
			body.append('language', navigator.language || '');

			recaptchaToken('submit').then(function (tok) {
				if (tok) { body.append('recaptcha_token', tok); }
				fetch(window.TF_FORM.ajax, { method: 'POST', body: body, credentials: 'same-origin' })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						var subIntent = form.getAttribute('data-form-intent');
						var subEv = { event: 'form_submission', form_id: form.getAttribute('data-form-id'), form_name: form.getAttribute('data-form-name'), form_type: 'Terrific Form', form_fields: fields };
						if (subIntent) { subEv.form_intent = subIntent; }
						window.dataLayer.push(subEv);
						// Fire the form's intent as its own event right after (e.g. generate_lead).
						if (subIntent) {
							window.dataLayer.push({
								event: subIntent,
								form_id: form.getAttribute('data-form-id'),
								form_name: form.getAttribute('data-form-name'),
								form_type: 'Terrific Form',
								form_intent: subIntent,
								form_fields: fields
							});
						}
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
