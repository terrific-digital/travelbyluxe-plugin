/* Travel by Luxe field types — behaviour for the countries picker, destination
   pills, budget floor, pill/card choice rules, collapsed textareas and {country}
   templates. Loads after form.js (which owns steps, validation and submit) and
   talks to it through DOM state and two events:
     tf:countries  (from the countries field)  detail.countries = ['Italy', …]
     tf:step       (from form.js showStep)      detail.step = n
   Ported from the static site's js/msf-form.js + js/form-destinations.js. */
(function () {
	'use strict';

	var CFG  = window.TF_TBL || {};
	var DATA = CFG.destinations || { order: [], countries: {} };
	var OPEN = CFG.openPills || [];
	var I18N = CFG.i18n || {};

	function t(key, fallback) { return I18N[key] || fallback; }
	function escAttr(s) { return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
	function escHtml(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
	function fieldOf(el) { return el.closest ? el.closest('.tf-field') : null; }
	function byKey(form, key) { return key ? form.querySelector('[data-key="' + key + '"]') : null; }
	function known(name) { return !!DATA.countries[name]; }
	function fire(form, name, detail) {
		try { form.dispatchEvent(new CustomEvent(name, { detail: detail })); } catch (e) {}
	}

	function formatList(names) {
		if (!names.length) { return ''; }
		if (names.length === 1) { return names[0]; }
		if (names.length === 2) { return names[0] + ' & ' + names[1]; }
		return names.slice(0, -1).join(', ') + ' & ' + names[names.length - 1];
	}

	// The shortcode's country="…" / countries="…" preset, in catalogue order.
	function presetOf(form) {
		return (form.getAttribute('data-preset-countries') || '').split(',').map(function (s) { return s.trim(); }).filter(known);
	}

	// The countries in play right now: the countries field if the form has one, else the preset.
	function activeCountries(form, key) {
		var el = byKey(form, key || 'trip_country');
		if (el && el.value) {
			var names = el.value.split(',').map(function (s) { return s.trim(); }).filter(known);
			if (names.length) { return names; }
		}
		return presetOf(form);
	}

	/* ------------------------------------------------------------ {country} */
	function applyTemplates(form, names) {
		var label = names.length ? formatList(names) : t('yourDestination', 'your destination');
		form.querySelectorAll('[data-template]').forEach(function (el) {
			el.textContent = el.getAttribute('data-template').replace(/\{country\}/g, label);
		});
	}

	/* ----------------------------------------------- choice rules (pills/cards)
	   data-exclusive — an answer that contradicts every other option (“Not sure
	   yet”): picking it clears the rest, picking any other clears it.
	   data-reveals   — opens the field's .tf-reveal text box (“Somewhere else”);
	   the box empties when no revealing option is left ticked.
	   data-group     — multi-country lists: the rules work within one country. */
	function syncSelected(opt) {
		var i = opt.querySelector('input');
		opt.classList.toggle('is-selected', !!(i && i.checked));
	}
	function syncGroup(group) { group.querySelectorAll('.tf-opt').forEach(syncSelected); }

	function toggleReveal(group) {
		var field = fieldOf(group);
		var target = field ? field.querySelector('.tf-reveal') : null;
		if (!target) { return; }
		var open = Array.prototype.some.call(group.querySelectorAll('.tf-opt[data-reveals] input'), function (i) { return i.checked; });
		if (open === !target.hidden) { return; }
		target.hidden = !open;
		if (open) { try { target.focus(); } catch (e) {} } else { target.value = ''; }
	}

	function applyChoiceRules(group, input) {
		var opt = input.closest('.tf-opt');
		if (!opt) { return; }
		var grp = opt.getAttribute('data-group');
		if (input.checked) {
			var clear = opt.hasAttribute('data-exclusive')
				? group.querySelectorAll('.tf-opt input:checked')
				: group.querySelectorAll('.tf-opt[data-exclusive] input:checked');
			Array.prototype.forEach.call(clear, function (other) {
				var o = other.closest('.tf-opt');
				if (o === opt) { return; }
				if (grp && o.getAttribute('data-group') !== grp) { return; }
				other.checked = false;
			});
		}
		syncGroup(group);
		toggleReveal(group);
	}

	function initChoices(form) {
		form.querySelectorAll('.tf-options').forEach(function (group) {
			syncGroup(group);
			group.addEventListener('change', function (e) {
				if (e.target && e.target.matches('input')) { applyChoiceRules(group, e.target); }
			});
		});
	}

	/* ------------------------------------------------------- countries field */
	function initCountries(form, wrap) {
		var trigger = wrap.querySelector('.tf-countries-trigger');
		var panel   = wrap.querySelector('.tf-countries-panel');
		var chips   = wrap.querySelector('.tf-countries-chips');
		var hint    = wrap.querySelector('.tf-countries-hint');
		var value   = wrap.querySelector('.tf-countries-value');
		var codes   = wrap.querySelector('.tf-countries-codes');
		var options = Array.prototype.slice.call(wrap.querySelectorAll('.tf-countries-option'));
		var max     = parseInt(wrap.getAttribute('data-max'), 10) || 0; // 0 = no limit
		var limit   = max > 0 ? max : options.length;
		var placeholder = trigger ? trigger.textContent : '';
		var selected = [];

		function fromDom() {
			selected = options.filter(function (o) { return o.querySelector('input').checked; }).map(function (o) { return o.querySelector('input').value; });
		}
		function order(names) { return DATA.order.filter(function (n) { return names.indexOf(n) !== -1; }); }
		function toggle(name, on) {
			var idx = selected.indexOf(name);
			if (on) {
				if (idx !== -1 || selected.length >= limit) { return; }
				selected.push(name);
			} else {
				if (idx === -1) { return; }
				selected.splice(idx, 1);
			}
			selected = order(selected);
			render();
			publish();
		}
		function render() {
			var atMax = selected.length >= limit;
			options.forEach(function (o) {
				var input = o.querySelector('input');
				var on = selected.indexOf(input.value) !== -1;
				input.checked = on;
				input.disabled = atMax && !on;
				o.classList.toggle('is-selected', on);
				o.classList.toggle('is-disabled', atMax && !on);
				o.setAttribute('aria-selected', on ? 'true' : 'false');
			});
			if (chips) {
				chips.innerHTML = '';
				chips.hidden = !selected.length;
				selected.forEach(function (name) {
					var chip = document.createElement('button');
					chip.type = 'button';
					chip.className = 'tf-countries-chip';
					chip.setAttribute('aria-label', 'Remove ' + name);
					chip.innerHTML = '<span>' + escHtml(name) + '</span><span class="tf-countries-chip-x" aria-hidden="true">&times;</span>';
					chip.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); toggle(name, false); });
					chips.appendChild(chip);
				});
			}
			if (trigger) {
				trigger.textContent = selected.length ? formatList(selected) : placeholder;
				trigger.classList.toggle('is-placeholder', !selected.length);
			}
			if (hint) {
				if (max <= 0) {
					hint.textContent = !selected.length ? t('selectCountries', 'Select one or more countries') : (selected.length === 1 ? t('oneSelected', '1 country selected') : selected.length + ' ' + t('nSelected', 'countries selected'));
				} else {
					var left = limit - selected.length;
					hint.textContent = !selected.length ? 'Select up to ' + limit : (left > 0 ? selected.length + ' of ' + limit + ' selected' : 'Maximum of ' + limit + ' selected');
				}
			}
		}
		function publish() {
			if (value) { value.value = selected.join(', '); }
			if (codes) { codes.value = selected.map(function (n) { return DATA.countries[n].code; }).join(', '); }
			var field = fieldOf(wrap);
			if (field && selected.length) { field.classList.remove('tf-has-error'); }
			fire(form, 'tf:countries', { countries: selected.slice() });
		}
		function setOpen(open) {
			if (!panel) { return; }
			panel.hidden = !open;
			if (trigger) { trigger.setAttribute('aria-expanded', open ? 'true' : 'false'); }
			wrap.classList.toggle('is-open', open);
		}

		wrap.addEventListener('change', function (e) {
			if (e.target && e.target.matches('.tf-countries-option input')) { toggle(e.target.value, e.target.checked); }
		});
		if (trigger) { trigger.addEventListener('click', function (e) { e.preventDefault(); setOpen(panel && panel.hidden); }); }
		document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) { setOpen(false); } });
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { setOpen(false); } });

		fromDom();
		selected = order(selected).slice(0, limit);
		render();
		publish();
	}

	/* ---------------------------------------------------- destinations field */
	function pillHtml(value, text, attrs, key) {
		return '<label class="tf-opt' + (attrs.open ? ' tf-opt--open' : '') + '"' +
			(attrs.group ? ' data-group="' + escAttr(attrs.group) + '"' : '') +
			(attrs.exclusive ? ' data-exclusive="1"' : '') +
			(attrs.reveals ? ' data-reveals="1"' : '') +
			'><input type="checkbox" name="' + escAttr(key) + '[]" value="' + escAttr(value) + '"> <span class="tf-opt-text">' + escHtml(text) + '</span></label>';
	}
	function openPillsHtml(group, key) {
		return OPEN.map(function (p) {
			var value = group ? group + ': ' + p.value : p.value;
			return pillHtml(value, p.value, { open: true, group: group, exclusive: !!p.exclusive, reveals: !!p.reveals }, key);
		}).join('');
	}
	function pillsHtml(names, key) {
		var multi = names.length > 1;
		var html = names.map(function (name) {
			var meta = DATA.countries[name];
			if (!meta) { return ''; }
			var block = multi ? '<div class="tf-dest-group">' + escHtml(name) + '</div>' : '';
			block += meta.regions.map(function (r) { return pillHtml(r, r, { group: multi ? name : '' }, key); }).join('');
			if (multi) { block += openPillsHtml(name, key); }
			return block;
		}).join('');
		if (!multi) { html += openPillsHtml('', key); }
		return html;
	}

	function initDestinations(form, wrap) {
		var pills = wrap.querySelector('.tf-dest-pills');
		var key   = wrap.getAttribute('data-key-base') || 'destinations';
		var ckey  = wrap.getAttribute('data-countries-key') || 'trip_country';
		var shown = wrap.getAttribute('data-rendered-for') || '';
		function rebuild(names) {
			var sig = names.join(',');
			if (sig === shown) { syncGroup(pills); return; }
			shown = sig;
			pills.innerHTML = pillsHtml(names, key);
			var other = wrap.querySelector('.tf-reveal');
			if (other) { other.hidden = true; other.value = ''; }
			syncGroup(pills);
		}
		form.addEventListener('tf:countries', function (e) {
			rebuild(e.detail.countries);
			applyTemplates(form, e.detail.countries);
		});
		var now = activeCountries(form, ckey);
		rebuild(now);
		applyTemplates(form, now);
	}

	/* ---------------------------------------------------------- budget field
	   Floor = adults×rate + children×rate, plus every day beyond the included
	   days at the per-day rates; a dearer country (France, Switzerland) in the
	   trip raises every rate. The slider's top is the floor × range multiplier. */
	function initBudget(form, wrap) {
		var range = wrap.querySelector('.tf-budget-range');
		if (!range) { return; }
		var out   = wrap.querySelector('.tf-range-val');
		var note  = wrap.querySelector('.tf-budget-note');
		var minLn = wrap.querySelector('.tf-budget-min');
		var scale = wrap.querySelectorAll('.tf-budget-scale span');
		var cur   = wrap.getAttribute('data-currency') || '';
		var rates, countryRates;
		try { rates = JSON.parse(wrap.getAttribute('data-rates')) || {}; } catch (e) { rates = {}; }
		try { countryRates = JSON.parse(wrap.getAttribute('data-country-rates')) || {}; } catch (e) { countryRates = {}; }
		var included = parseInt(wrap.getAttribute('data-included-days'), 10) || 7;
		var mult     = parseFloat(wrap.getAttribute('data-range-mult')) || 5;
		var keys = {
			adults:   wrap.getAttribute('data-adults-key') || 'adults',
			children: wrap.getAttribute('data-children-key') || 'children',
			days:     wrap.getAttribute('data-days-key') || 'trip_length',
			country:  wrap.getAttribute('data-country-key') || 'trip_country'
		};
		var hidden = {
			label: byKey(form, range.getAttribute('data-key') + '_label'),
			min:   byKey(form, range.getAttribute('data-key') + '_min'),
			atMin: byKey(form, range.getAttribute('data-key') + '_at_min')
		};
		var touched = false;

		function count(key, dflt) {
			var el = byKey(form, key);
			var v = el ? parseInt(el.value, 10) : NaN;
			return { value: isNaN(v) ? dflt : v, atMax: !!el && el.max !== '' && v === parseInt(el.max, 10), label: el ? el.getAttribute('data-max-label') : '' };
		}
		function money(v) { return cur + Math.round(v).toLocaleString('en-US'); }
		function moneyShort(v) { return cur + Math.round(v / 1000) + 'k'; }
		function daysLabel(v) {
			if (v === 1) { return '1 day'; }
			if (v === 7) { return '1 week'; }
			if (v === 14) { return '2 weeks'; }
			if (v === 21) { return '3 weeks'; }
			if (v === 30) { return '1 month'; }
			return v + ' days';
		}
		function partyLabel(a, c) {
			var s = (a.atMax && a.label ? a.label : String(a.value)) + (a.value === 1 ? ' adult' : ' adults');
			if (c.value < 1) { return s; }
			return s + ' and ' + (c.atMax && c.label ? c.label : String(c.value)) + (c.value === 1 ? ' child' : ' children');
		}
		function floorAt(r, a, c, extra) {
			return a * r.adult + c * r.child + extra * (a * r.adult_day + c * r.child_day);
		}
		function floor(a, c, days) {
			var extra = Math.max(0, days - included);
			var min = floorAt(rates, a, c, extra);
			activeCountries(form, keys.country).forEach(function (name) {
				if (countryRates[name]) { min = Math.max(min, floorAt(countryRates[name], a, c, extra)); }
			});
			return min;
		}
		function render() {
			var v = parseInt(range.value, 10), max = parseInt(range.max, 10), min = parseInt(range.min, 10);
			var label = v === max ? money(v) + '+' : money(v);
			if (out) { out.textContent = label; }
			if (hidden.label) { hidden.label.value = label; }
			if (hidden.min) { hidden.min.value = String(min); }
			if (hidden.atMin) { hidden.atMin.value = v === min ? 'yes' : 'no'; }
		}
		function update() {
			var a = count(keys.adults, 2), c = count(keys.children, 0), d = count(keys.days, included);
			var min = floor(a.value, c.value, d.value);
			var max = min * mult;
			var prev = parseInt(range.value, 10);
			range.min = String(min);
			range.max = String(max);
			range.value = String((touched && !isNaN(prev)) ? Math.min(Math.max(prev, min), max) : min);
			range.setAttribute('data-max-label', money(max) + '+');
			if (scale.length === 3) {
				scale[0].textContent = moneyShort(min);
				scale[1].textContent = moneyShort(min + (max - min) / 2);
				scale[2].textContent = moneyShort(max) + '+';
			}
			if (note) { note.textContent = t('budgetNote', 'Total trip investment based on') + ' ' + partyLabel(a, c) + ' ' + t('over', 'over') + ' ' + daysLabel(d.value); }
			if (minLn) { minLn.textContent = t('minimum', 'Minimum') + ' ' + money(min); }
			render();
		}

		[keys.adults, keys.children, keys.days].forEach(function (k) {
			var el = byKey(form, k);
			if (el) { el.addEventListener('input', update); }
		});
		form.addEventListener('tf:countries', update);
		form.addEventListener('tf:step', update);
		range.addEventListener('input', function () { touched = true; render(); });
		update();
	}

	/* --------------------------------------------------- collapsed textarea */
	function initCollapsed(form, btn) {
		var ta = document.getElementById(btn.getAttribute('aria-controls'));
		if (!ta) { return; }
		function resize() {
			var cs = window.getComputedStyle(ta);
			var line = parseFloat(cs.lineHeight) || parseFloat(cs.fontSize) * 1.5;
			var pad = parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom) + parseFloat(cs.borderTopWidth) + parseFloat(cs.borderBottomWidth);
			var minH = line * 2 + pad, maxH = line * 5 + pad;
			ta.style.height = 'auto';
			var next = Math.min(Math.max(ta.scrollHeight, minH), maxH);
			ta.style.height = next + 'px';
			ta.style.overflowY = ta.scrollHeight > maxH ? 'auto' : 'hidden';
		}
		btn.addEventListener('click', function (e) {
			e.preventDefault();
			btn.hidden = true;
			btn.setAttribute('aria-expanded', 'true');
			ta.hidden = false;
			resize();
			try { ta.focus(); } catch (err) {}
		});
		ta.addEventListener('input', resize);
	}

	/* ------------------------------------------------------- slider max label
	   "10+" / "8+" at the top of a count slider: shown in the readout (form.js
	   handles data-max-label itself) — nothing to do here beyond the initial paint. */

	function init(form) {
		if (form.__tblInit) { return; }
		form.__tblInit = true;
		initChoices(form);
		form.querySelectorAll('.tf-countries').forEach(function (w) { initCountries(form, w); });
		form.querySelectorAll('.tf-dest').forEach(function (w) { initDestinations(form, w); });
		form.querySelectorAll('.tf-budget').forEach(function (w) { initBudget(form, w); });
		form.querySelectorAll('.tf-collapsed-trigger').forEach(function (b) { initCollapsed(form, b); });
		if (!form.querySelector('.tf-dest')) { applyTemplates(form, activeCountries(form)); }
	}

	document.addEventListener('DOMContentLoaded', function () {
		document.querySelectorAll('.tf-form').forEach(init);
	});
	window.TBL_FIELDS = { init: init, destinations: DATA, formatCountryList: formatList };
})();
