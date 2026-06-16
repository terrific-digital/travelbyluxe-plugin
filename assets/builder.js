/* Terrific Forms — drag-and-drop admin builder */
(function () {
	'use strict';

	var FIELD_TYPES = [
		{ type: 'text', label: 'Text', icon: 'dashicons-editor-textcolor' },
		{ type: 'email', label: 'Email', icon: 'dashicons-email-alt' },
		{ type: 'tel', label: 'Phone', icon: 'dashicons-phone' },
		{ type: 'number', label: 'Number', icon: 'dashicons-calculator' },
		{ type: 'slider', label: 'Slider', icon: 'dashicons-leftright' },
		{ type: 'date', label: 'Date', icon: 'dashicons-calendar-alt' },
		{ type: 'textarea', label: 'Textarea', icon: 'dashicons-editor-paragraph' },
		{ type: 'select', label: 'Dropdown', icon: 'dashicons-arrow-down-alt2' },
		{ type: 'radio', label: 'Radio', icon: 'dashicons-marker' },
		{ type: 'checkbox', label: 'Checkboxes', icon: 'dashicons-yes' },
		{ type: 'heading', label: 'Heading', icon: 'dashicons-heading' },
		{ type: 'paragraph', label: 'Paragraph', icon: 'dashicons-media-text' },
		{ type: 'separator', label: 'Separator', icon: 'dashicons-minus' },
		{ type: 'custom_html', label: 'Custom HTML', icon: 'dashicons-editor-code' },
		{ type: 'hidden', label: 'Hidden', icon: 'dashicons-hidden' }
	];
	var HAS_OPTIONS = { select: 1, radio: 1, checkbox: 1 };
	// Static/display fields that carry no submitted value (and thus no data-layer key).
	var STATIC_TYPES = { heading: 1, paragraph: 1, separator: 1, custom_html: 1 };

	var root, hidden, def, fieldMap = {};
	var activeFieldsEl = null;   // the .tf-b-fields that a click-to-add field is dropped into
	var previewHidden = false;   // live-preview pane collapsed?
	var pairTarget = null;       // full-width card a dragged full-width field will pair into a row with

	function uid() { return 'f_' + Math.random().toString(36).slice(2, 8); }

	// Mark a step as the active target for click-to-add (highlighted only when multi-step).
	function setActive(stepElm) {
		if (!stepElm) { return; }
		activeFieldsEl = stepElm.querySelector('.tf-b-fields');
		if (root && currentType() === 'multistep') {
			root.querySelectorAll('.tf-b-step.is-active-step').forEach(function (s) { s.classList.remove('is-active-step'); });
			stepElm.classList.add('is-active-step');
		}
	}

	// The final step submits (no Next button), so show a "LAST STEP" tag there instead
	// of the Next-button text input. Re-evaluated whenever steps are added/removed.
	function refreshStepNextFields() {
		if (!root) { return; }
		var steps = root.querySelectorAll('.tf-b-step');
		steps.forEach(function (st, i) {
			var isLast = (i === steps.length - 1);
			var nextInput = st.querySelector('.tf-b-step-next');
			var lastTag = st.querySelector('.tf-b-step-last');
			if (nextInput) { nextInput.style.display = isLast ? 'none' : ''; }
			if (lastTag) { lastTag.style.display = isLast ? '' : 'none'; }
		});
	}

	// A fresh field object of the given type (used by both drag-add and click-add).
	function newField(type) {
		var f = {
			id: uid(), type: type, label: defaultLabel(type), key: '', placeholder: '',
			required: false, show_label: true, width: 'full',
			min: '', max: '', minlength: '', maxlength: '', multiple: false,
			min_from_today: '', max_from_today: '',
			step: '', default: '', prefix: '', suffix: '', show_scale: false, value_inline: false, html: '', country_code: true,
			options: HAS_OPTIONS[type] ? [{ label: 'Option 1', value: 'Option 1' }, { label: 'Option 2', value: 'Option 2' }] : []
		};
		f.key = slug(f.label);
		return f;
	}

	// Click-to-add: append a new field to the active step (or the last step), open it, scroll to it.
	function addFieldOfType(type) {
		var fieldsEl = (activeFieldsEl && document.body.contains(activeFieldsEl)) ? activeFieldsEl : null;
		if (!fieldsEl) { var all = root.querySelectorAll('.tf-b-fields'); fieldsEl = all[all.length - 1]; }
		if (!fieldsEl) { return; }
		var f = newField(type);
		fieldMap[f.id] = f;
		var card = fieldCard(f);
		fieldsEl.appendChild(card);
		sync();
		var eb = card.querySelector('.tf-b-edit');
		if (eb) { eb.click(); } // auto-expand so it's ready to configure
		try { card.scrollIntoView({ behavior: 'smooth', block: 'center' }); } catch (e) {}
	}

	// ---- Width helpers (WYSIWYG widths + drag-to-pair into a row) ----
	// A field that occupies a full row (and so can be paired with a neighbour).
	function isFullEl(el) {
		if (!el) { return false; }
		if (el.getAttribute('data-new-type')) { return true; } // palette clone = new full-width field
		var f = fieldMap[el.getAttribute('data-id')];
		return !!f && (!f.width || f.width === 'full');
	}
	function clearPairCue() { if (root) { root.querySelectorAll('.tf-b-pairing').forEach(function (e) { e.classList.remove('tf-b-pairing'); }); } }
	// Apply a width to an existing card: update the model, the card class, and its body control.
	function setCardWidth(cardEl, w) {
		var f = fieldMap[cardEl.getAttribute('data-id')];
		if (!f) { return; }
		f.width = w;
		cardEl.classList.remove('tf-bw-full', 'tf-bw-half', 'tf-bw-third');
		cardEl.classList.add('tf-bw-' + w);
		var sel = cardEl.querySelector('.tf-b-width');
		if (sel) { sel.value = w; }
	}
	// If a full-width card was dropped onto the right side of another full-width card,
	// turn both into halves and sit them next to each other.
	function maybePair(cardEl) {
		if (!pairTarget || !cardEl || cardEl === pairTarget) { return; }
		var a = fieldMap[cardEl.getAttribute('data-id')];
		var b = fieldMap[pairTarget.getAttribute('data-id')];
		if (!a || !b || a === b) { return; }
		if (b.width && b.width !== 'full') { return; }
		setCardWidth(cardEl, 'half');
		setCardWidth(pairTarget, 'half');
		if (cardEl.parentNode === pairTarget.parentNode) {
			pairTarget.parentNode.insertBefore(cardEl, pairTarget.nextSibling);
		}
	}
	function typeIcon(t) { for (var i = 0; i < FIELD_TYPES.length; i++) { if (FIELD_TYPES[i].type === t) { return FIELD_TYPES[i].icon; } } return 'dashicons-editor-textcolor'; }
	function slug(s) { return (s || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, ''); }
	function optLabel(o) { return (o && typeof o === 'object') ? (o.label || '') : (o || ''); }
	function optValue(o) {
		if (o && typeof o === 'object') { return (o.value !== undefined && o.value !== '') ? o.value : (o.label || ''); }
		return o || '';
	}
	function optRowHtml(o) {
		return '<tr>' +
			'<td><input type="text" class="tf-o-label" value="' + esc(optLabel(o)) + '"></td>' +
			'<td><input type="text" class="tf-o-value" value="' + esc(optValue(o)) + '" placeholder="(= label)"></td>' +
			'<td><button type="button" class="button-link tf-o-del" title="Remove">✕</button></td>' +
			'</tr>';
	}

	function loadDef() {
		try { def = JSON.parse(root.getAttribute('data-def')); } catch (e) { def = null; }
		if (!def || !def.steps) { def = { type: 'regular', steps: [{ title: '', fields: [] }] }; }
		def.type = currentType();
		// index fields
		def.steps.forEach(function (s) { (s.fields || []).forEach(function (f) { if (!f.id) { f.id = uid(); } fieldMap[f.id] = f; }); });
	}

	function currentType() {
		var r = document.querySelector('input[name="tf_type"]:checked');
		return r ? r.value : (def && def.type) || 'regular';
	}

	function sync() {
		// rebuild def.steps from DOM order, preserving field config from fieldMap
		var steps = [];
		root.querySelectorAll('.tf-b-step').forEach(function (stepEl) {
			var titleEl = stepEl.querySelector('.tf-b-step-title');
			if (!titleEl) { return; } // guard: only real step containers (not a stray matching element)
			var title = titleEl.value;
			var nextEl = stepEl.querySelector('.tf-b-step-next');
			var fields = [];
			stepEl.querySelectorAll('.tf-b-field').forEach(function (card) {
				var id = card.getAttribute('data-id');
				if (fieldMap[id]) { fields.push(fieldMap[id]); }
			});
			steps.push({ title: title, next_text: nextEl ? nextEl.value : '', fields: fields });
		});
		def.type = currentType();
		def.steps = steps.length ? steps : [{ title: '', fields: [] }];
		hidden.value = JSON.stringify({ type: def.type, steps: def.steps });
		schedulePreview();
	}

	// ---- Live preview (renders the real form server-side into an iframe) ----
	var previewTimer;
	function schedulePreview() { clearTimeout(previewTimer); previewTimer = setTimeout(updatePreview, 400); }
	function updatePreview() {
		if (previewHidden) { return; } // pane collapsed — skip the server round-trip
		var frame = document.getElementById('tf-preview-frame');
		if (!frame || typeof TF_BUILD === 'undefined') { return; }
		var submitEl = document.getElementById('tf_submit_text');
		var body = new URLSearchParams();
		body.append('action', 'tf_preview');
		body.append('nonce', TF_BUILD.nonce);
		body.append('definition', hidden.value);
		body.append('submit_text', submitEl ? submitEl.value : 'Submit');
		fetch(TF_BUILD.previewAjax, { method: 'POST', body: body, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.success) { return; }
				var links = (TF_BUILD.css || []).map(function (u) { return '<link rel="stylesheet" href="' + u + '">'; }).join('');
				frame.onload = function () { try { frame.style.height = (frame.contentDocument.body.scrollHeight + 28) + 'px'; } catch (e) {} };
				frame.srcdoc = '<!doctype html><html><head><meta charset="utf-8">' + links +
					'<style>body{margin:0;padding:18px;font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1d2327;background:#fff}</style>' +
					'</head><body>' + res.data.html + '</body></html>';
			})
			.catch(function () {});
	}

	function fieldCard(f) {
		if (!f.width) { f.width = 'full'; }
		var card = document.createElement('div');
		card.className = 'tf-b-field tf-bw-' + f.width;
		card.setAttribute('data-id', f.id);
		var optionsRow = '';
		if (f.type === 'select') {
			optionsRow =
				'<label class="tf-b-row tf-b-inline"><input type="checkbox" class="tf-b-multiple"' + (f.multiple ? ' checked' : '') + '> Allow multiple selection</label>' +
				'<div class="tf-b-row"><span class="tf-b-rowlabel">Options</span>' +
					'<table class="tf-b-opttable"><thead><tr><th>Label</th><th>Value</th><th></th></tr></thead>' +
						'<tbody class="tf-b-optrows">' + (f.options || []).map(optRowHtml).join('') + '</tbody></table>' +
					'<button type="button" class="button-link tf-b-addopt">+ Add option</button>' +
				'</div>';
		} else if (HAS_OPTIONS[f.type]) {
			optionsRow = '<label class="tf-b-row tf-b-opts-row">Options (one per line)<textarea class="tf-b-opts" rows="3">' + (f.options || []).map(optLabel).join('\n').replace(/</g, '&lt;') + '</textarea></label>';
		}
		var numberRow = (f.type === 'number') ?
			'<div class="tf-b-row2"><label class="tf-b-row">Min value<input type="number" class="tf-b-min" value="' + esc(f.min) + '"></label>' +
			'<label class="tf-b-row">Max value<input type="number" class="tf-b-max" value="' + esc(f.max) + '"></label></div>' : '';
		var sliderRow = (f.type === 'slider') ?
			'<div class="tf-b-row2"><label class="tf-b-row">Minimum<input type="number" class="tf-b-min" value="' + esc(f.min) + '" placeholder="0"></label>' +
			'<label class="tf-b-row">Maximum<input type="number" class="tf-b-max" value="' + esc(f.max) + '" placeholder="100"></label></div>' +
			'<div class="tf-b-row2"><label class="tf-b-row">Step<input type="number" min="0" step="any" class="tf-b-sstep" value="' + esc(f.step) + '" placeholder="1"></label>' +
			'<label class="tf-b-row">Default value<input type="number" class="tf-b-default" value="' + esc(f.default) + '" placeholder="(min)"></label></div>' +
			'<div class="tf-b-row2"><label class="tf-b-row">Value prefix<input type="text" class="tf-b-prefix" value="' + esc(f.prefix) + '" placeholder="e.g. $"></label>' +
			'<label class="tf-b-row">Value suffix<input type="text" class="tf-b-suffix" value="' + esc(f.suffix) + '" placeholder="e.g. days"></label></div>' +
			'<label class="tf-b-row tf-b-inline"><input type="checkbox" class="tf-b-scale"' + (f.show_scale ? ' checked' : '') + '> Show min/max scale</label>' +
			'<label class="tf-b-row tf-b-inline"><input type="checkbox" class="tf-b-vinline"' + (f.value_inline ? ' checked' : '') + '> Show value in the label (instead of a bubble)</label>' : '';
		var htmlRow = (f.type === 'custom_html') ?
			'<label class="tf-b-row">Custom HTML<textarea class="tf-b-html" rows="6" spellcheck="false" placeholder="&lt;div&gt;…&lt;/div&gt;" style="font-family:monospace">' + escArea(f.html) + '</textarea></label>' : '';
		var ccRow = (f.type === 'tel') ?
			'<label class="tf-b-row tf-b-inline"><input type="checkbox" class="tf-b-cc"' + ((f.country_code !== false) ? ' checked' : '') + '> Country-code selector</label>' : '';
		var lengthRow = ( ['text', 'email', 'tel', 'textarea'].indexOf(f.type) >= 0 ) ?
			'<div class="tf-b-row2"><label class="tf-b-row">Min length<input type="number" min="0" class="tf-b-minlen" value="' + esc(f.minlength) + '"></label>' +
			'<label class="tf-b-row">Max length<input type="number" min="0" class="tf-b-maxlen" value="' + esc(f.maxlength) + '"></label></div>' : '';
		var dateRow = (f.type === 'date') ?
			'<div class="tf-b-row2"><label class="tf-b-row">Earliest (days from today)<input type="number" class="tf-b-minfromtoday" value="' + esc(f.min_from_today) + '" placeholder="(no limit)"></label>' +
			'<label class="tf-b-row">Latest (days from today)<input type="number" class="tf-b-maxfromtoday" value="' + esc(f.max_from_today) + '" placeholder="(no limit)"></label></div>' +
			'<p class="tf-b-hint" style="margin-top:-6px"><strong>0</strong> = today. Blank = no limit. E.g. earliest 0 blocks past dates; latest 90 caps at 90 days out.</p>' : '';
		var noPlace = { heading: 1, paragraph: 1, separator: 1, custom_html: 1, hidden: 1, slider: 1 };
		var placeRow = noPlace[f.type] ? '' :
			'<label class="tf-b-row">Placeholder<input type="text" class="tf-b-ph" value="' + esc(f.placeholder) + '"></label>';
		var noReq = { heading: 1, paragraph: 1, separator: 1, custom_html: 1, hidden: 1, slider: 1 };
		var reqRow = noReq[f.type] ? '' :
			'<label class="tf-b-row tf-b-inline"><input type="checkbox" class="tf-b-req"' + (f.required ? ' checked' : '') + '> Required</label>';
		var widthRow = (f.type === 'hidden') ? '' :
			'<label class="tf-b-row">Width<select class="tf-b-width">' +
				'<option value="full"' + ('full' === f.width ? ' selected' : '') + '>Full width</option>' +
				'<option value="half"' + ('half' === f.width ? ' selected' : '') + '>Half (1/2)</option>' +
				'<option value="third"' + ('third' === f.width ? ' selected' : '') + '>Third (1/3)</option>' +
			'</select></label>';
		var noShowLabel = { heading: 1, paragraph: 1, separator: 1, custom_html: 1, hidden: 1 };
		var showLabelRow = noShowLabel[f.type] ? '' :
			'<label class="tf-b-row tf-b-inline"><input type="checkbox" class="tf-b-showlabel"' + ((f.show_label !== false) ? ' checked' : '') + '> Show label</label>';
		// Label/Key rows: static fields have no key; paragraph edits multi-line text; html/separator have no label.
		var labelRow;
		if (f.type === 'paragraph') {
			labelRow = '<label class="tf-b-row">Text<textarea class="tf-b-label" rows="3">' + escArea(f.label) + '</textarea></label>';
		} else if (f.type === 'custom_html' || f.type === 'separator') {
			labelRow = '';
		} else {
			labelRow = '<label class="tf-b-row">Label<input type="text" class="tf-b-label" value="' + esc(f.label) + '"></label>';
		}
		var keyRow = STATIC_TYPES[f.type] ? '' :
			'<label class="tf-b-row">Key (data layer)<input type="text" class="tf-b-key" value="' + esc(f.key) + '"></label>';
		card.innerHTML =
			'<div class="tf-b-field-head">' +
				'<span class="tf-b-handle dashicons dashicons-move"></span>' +
				'<span class="tf-b-type"><span class="dashicons ' + typeIcon(f.type) + '"></span> ' + f.type + '</span>' +
				'<span class="tf-b-field-label">' + esc(f.label || '(no label)') + '</span>' +
				'<span class="tf-b-acts">' +
					'<button type="button" class="tf-b-edit button-link" title="Edit"><span class="dashicons dashicons-edit"></span></button>' +
					'<button type="button" class="tf-b-dup button-link" title="Duplicate"><span class="dashicons dashicons-admin-page"></span></button>' +
					'<button type="button" class="tf-b-del button-link" title="Delete"><span class="dashicons dashicons-trash"></span></button>' +
				'</span>' +
			'</div>' +
			'<div class="tf-b-field-body" hidden>' +
				labelRow +
				showLabelRow +
				keyRow +
				placeRow + numberRow + sliderRow + lengthRow + dateRow + htmlRow + optionsRow + ccRow + widthRow + reqRow +
			'</div>';

		var editBtn = card.querySelector('.tf-b-edit');
		var fieldBody = card.querySelector('.tf-b-field-body');
		function setOpen(open) {
			fieldBody.hidden = !open;
			card.classList.toggle('is-editing', open);
			var ic = editBtn.querySelector('.dashicons');
			if (ic) { ic.className = 'dashicons ' + (open ? 'dashicons-arrow-up-alt2' : 'dashicons-edit'); }
			editBtn.title = open ? 'Minimize' : 'Edit';
		}
		editBtn.addEventListener('click', function (e) { e.stopPropagation(); setOpen(fieldBody.hidden); });
		// Clicking the field name also expands/collapses.
		var fieldLabelEl = card.querySelector('.tf-b-field-label');
		fieldLabelEl.style.cursor = 'pointer';
		fieldLabelEl.title = 'Click to expand/collapse';
		fieldLabelEl.addEventListener('click', function () { setOpen(fieldBody.hidden); });
		var wsel = card.querySelector('.tf-b-width');
		if (wsel) {
			wsel.addEventListener('change', function () {
				setCardWidth(card, wsel.value);
				sync();
			});
		}
		card.querySelector('.tf-b-dup').addEventListener('click', function () {
			var copy = JSON.parse(JSON.stringify(f));
			copy.id = uid();
			if (copy.key) { copy.key = copy.key + '_copy'; } // avoid a duplicate data-layer key
			fieldMap[copy.id] = copy;
			var nc = fieldCard(copy);
			card.parentNode.insertBefore(nc, card.nextSibling);
			sync();
		});
		card.querySelector('.tf-b-del').addEventListener('click', function () {
			delete fieldMap[f.id];
			card.remove();
			sync();
		});
		var lbl = card.querySelector('.tf-b-label');
		var keyEl = card.querySelector('.tf-b-key');
		if (lbl) {
			lbl.addEventListener('input', function () {
				f.label = lbl.value;
				card.querySelector('.tf-b-field-label').textContent = (f.label || '').slice(0, 40) || '(no label)';
				if (keyEl && (!keyEl.value || keyEl.getAttribute('data-auto') !== '0')) { keyEl.value = slug(f.label); f.key = keyEl.value; }
				sync();
			});
		}
		if (keyEl) { keyEl.addEventListener('input', function () { f.key = slug(keyEl.value); keyEl.setAttribute('data-auto', '0'); sync(); }); }
		// slider step / default value
		var stp = card.querySelector('.tf-b-sstep'); if (stp) { stp.addEventListener('input', function () { f.step = stp.value; sync(); }); }
		var dft = card.querySelector('.tf-b-default'); if (dft) { dft.addEventListener('input', function () { f.default = dft.value; sync(); }); }
		var pfx = card.querySelector('.tf-b-prefix'); if (pfx) { pfx.addEventListener('input', function () { f.prefix = pfx.value; sync(); }); }
		var sfx = card.querySelector('.tf-b-suffix'); if (sfx) { sfx.addEventListener('input', function () { f.suffix = sfx.value; sync(); }); }
		var scl = card.querySelector('.tf-b-scale'); if (scl) { scl.addEventListener('change', function () { f.show_scale = scl.checked; sync(); }); }
		var vin = card.querySelector('.tf-b-vinline'); if (vin) { vin.addEventListener('change', function () { f.value_inline = vin.checked; sync(); }); }
		// custom HTML content
		var htmlEl = card.querySelector('.tf-b-html'); if (htmlEl) { htmlEl.addEventListener('input', function () { f.html = htmlEl.value; sync(); }); }
		// phone: country-code selector toggle
		var ccEl = card.querySelector('.tf-b-cc'); if (ccEl) { ccEl.addEventListener('change', function () { f.country_code = ccEl.checked; sync(); }); }
		var ph = card.querySelector('.tf-b-ph'); if (ph) { ph.addEventListener('input', function () { f.placeholder = ph.value; sync(); }); }
		var req = card.querySelector('.tf-b-req'); if (req) { req.addEventListener('change', function () { f.required = req.checked; sync(); }); }
		var sl = card.querySelector('.tf-b-showlabel'); if (sl) { sl.addEventListener('change', function () { f.show_label = sl.checked; sync(); }); }
		// radio/checkbox: one label per line (value = label)
		var opts = card.querySelector('.tf-b-opts');
		if (opts) {
			opts.addEventListener('input', function () {
				f.options = opts.value.split('\n').map(function (s) { return s.trim(); }).filter(Boolean).map(function (l) { return { label: l, value: l }; });
				sync();
			});
		}
		// number value range
		var minV = card.querySelector('.tf-b-min'); if (minV) { minV.addEventListener('input', function () { f.min = minV.value; sync(); }); }
		var maxV = card.querySelector('.tf-b-max'); if (maxV) { maxV.addEventListener('input', function () { f.max = maxV.value; sync(); }); }
		// text length
		var minL = card.querySelector('.tf-b-minlen'); if (minL) { minL.addEventListener('input', function () { f.minlength = minL.value; sync(); }); }
		var maxL = card.querySelector('.tf-b-maxlen'); if (maxL) { maxL.addEventListener('input', function () { f.maxlength = maxL.value; sync(); }); }
		// date range constraints
		var mft = card.querySelector('.tf-b-minfromtoday'); if (mft) { mft.addEventListener('input', function () { f.min_from_today = mft.value; sync(); }); }
		var mxt = card.querySelector('.tf-b-maxfromtoday'); if (mxt) { mxt.addEventListener('input', function () { f.max_from_today = mxt.value; sync(); }); }
		// dropdown: multiple toggle
		var mult = card.querySelector('.tf-b-multiple'); if (mult) { mult.addEventListener('change', function () { f.multiple = mult.checked; sync(); }); }
		// dropdown: label/value options table
		var table = card.querySelector('.tf-b-opttable');
		if (table) {
			var tbody = table.querySelector('.tf-b-optrows');
			var readTable = function () {
				f.options = Array.prototype.map.call(tbody.querySelectorAll('tr'), function (tr) {
					return { label: tr.querySelector('.tf-o-label').value, value: tr.querySelector('.tf-o-value').value };
				}).filter(function (o) { return o.label !== '' || o.value !== ''; });
				sync();
			};
			table.addEventListener('input', readTable);
			tbody.addEventListener('click', function (e) {
				if (e.target.closest('.tf-o-del')) { e.target.closest('tr').remove(); readTable(); }
			});
			card.querySelector('.tf-b-addopt').addEventListener('click', function () {
				tbody.insertAdjacentHTML('beforeend', optRowHtml({ label: '', value: '' }));
			});
		}
		return card;
	}

	function stepEl(step, idx) {
		var multi = currentType() === 'multistep';
		var el = document.createElement('div');
		el.className = 'tf-b-step';
		el.innerHTML =
			'<div class="tf-b-step-head">' +
				'<span class="tf-b-step-n">' + (multi ? 'Step ' + (idx + 1) : 'Fields') + '</span>' +
				(multi ? '<input type="text" class="tf-b-step-title" placeholder="Step title (optional)" value="' + esc(step.title) + '">' : '<input type="hidden" class="tf-b-step-title" value="">') +
				(multi ? '<input type="text" class="tf-b-step-next" placeholder="Next button: Next →" title="Next button text for this step" value="' + esc(step.next_text) + '">' : '') +
				(multi ? '<span class="tf-b-step-last" title="The final step submits the form (uses the Submit button text)">LAST STEP</span>' : '') +
				(multi ? '<button type="button" class="tf-b-del-step button-link">Remove step</button>' : '') +
			'</div>' +
			'<div class="tf-b-fields"></div>';
		var list = el.querySelector('.tf-b-fields');
		(step.fields || []).forEach(function (f) { list.appendChild(fieldCard(f)); });
		var t = el.querySelector('.tf-b-step-title'); if (t) { t.addEventListener('input', sync); }
		var nx = el.querySelector('.tf-b-step-next'); if (nx) { nx.addEventListener('input', sync); }
		var ds = el.querySelector('.tf-b-del-step'); if (ds) { ds.addEventListener('click', function () { el.remove(); refreshStepNextFields(); sync(); }); }
		// Clicking anywhere in a step makes it the target for click-to-add.
		el.addEventListener('mousedown', function () { setActive(el); });
		makeSortable(list);
		return el;
	}

	function makeSortable(list) {
		Sortable.create(list, {
			group: 'tf-fields',
			handle: '.tf-b-handle',
			animation: 150,
			ghostClass: 'tf-b-ghost',
			supportPointer: false, // use mouse/touch events (so onMove clientX is reliable)
			// While dragging a full-width field over the RIGHT side of another full-width
			// field, mark it as a pair target (drop there to share a row at 50/50).
			onMove: function (evt, originalEvent) {
				clearPairCue();
				pairTarget = null;
				var related = evt.related;
				if (related && related.classList && related.classList.contains('tf-b-field') && isFullEl(evt.dragged) && isFullEl(related)) {
					var rect = related.getBoundingClientRect();
					var x = originalEvent ? originalEvent.clientX : 0;
					if (x > rect.left + rect.width * 0.5) {
						pairTarget = related;
						related.classList.add('tf-b-pairing');
					}
				}
				return true;
			},
			onAdd: function (evt) {
				var item = evt.item;
				var newType = item.getAttribute('data-new-type');
				if (newType) {
					// dropped from palette -> create a real field
					var f = newField(newType);
					fieldMap[f.id] = f;
					var card = fieldCard(f);
					item.parentNode.replaceChild(card, item);
					maybePair(card); // pair if dropped beside a full-width field
				}
				sync();
			},
			onEnd: function (evt) {
				maybePair(evt.item); // pair when an existing field is dropped beside another
				clearPairCue();
				pairTarget = null;
				sync();
			},
			onUpdate: sync,
			onSort: sync
		});
	}

	function defaultLabel(t) {
		var m = { text: 'Text', email: 'Email Address', tel: 'Phone', number: 'Number', slider: 'Slider', date: 'Date', textarea: 'Message', select: 'Select', radio: 'Choose one', checkbox: 'Select all that apply', heading: 'Section heading', paragraph: 'Your paragraph text…', separator: 'Separator', custom_html: 'Custom HTML', hidden: 'Hidden value' };
		return m[t] || 'Field';
	}

	function buildPalette() {
		var pal = document.createElement('div');
		pal.className = 'tf-b-palette';
		pal.innerHTML = '<h4>Fields</h4><p class="tf-b-hint">Click to add, or drag into the form &rarr;</p><div class="tf-b-palette-list"></div>';
		var list = pal.querySelector('.tf-b-palette-list');
		FIELD_TYPES.forEach(function (ft) {
			var it = document.createElement('div');
			it.className = 'tf-b-pal-item';
			it.setAttribute('data-new-type', ft.type);
			it.title = 'Click to add, or drag into the form';
			it.innerHTML = '<span class="dashicons ' + ft.icon + '"></span>' + ft.label;
			it.addEventListener('click', function () { addFieldOfType(ft.type); });
			list.appendChild(it);
		});
		Sortable.create(list, { group: { name: 'tf-fields', pull: 'clone', put: false }, sort: false, animation: 150, supportPointer: false });
		return pal;
	}

	function render() {
		root.innerHTML = '';

		var bar = document.createElement('div');
		bar.className = 'tf-b-toolbar';
		var prevToggle = document.createElement('button');
		prevToggle.type = 'button';
		prevToggle.className = 'button-link tf-b-prev-toggle';
		bar.appendChild(prevToggle);

		var wrap = document.createElement('div');
		wrap.className = 'tf-b-wrap';
		var canvas = document.createElement('div');
		canvas.className = 'tf-b-canvas';
		def.steps.forEach(function (s, i) { canvas.appendChild(stepEl(s, i)); });

		var multi = currentType() === 'multistep';
		if (multi) {
			var add = document.createElement('button');
			add.type = 'button';
			add.className = 'button tf-b-add-step';
			add.textContent = '+ Add step';
			add.addEventListener('click', function () {
				var el = stepEl({ title: '', fields: [] }, canvas.querySelectorAll('.tf-b-step').length);
				canvas.insertBefore(el, add); // keep the button last
				setActive(el);
				refreshStepNextFields();
				sync();
			});
			canvas.appendChild(add);
		}

		var pane = document.createElement('div');
		pane.className = 'tf-b-preview-pane';
		pane.innerHTML = '<span class="tf-b-resizer" title="Drag to resize the preview"></span>' +
			'<div class="tf-b-preview-inner">' +
				'<div class="tf-b-preview-head">Live preview</div>' +
				'<iframe id="tf-preview-frame" title="Form preview"></iframe>' +
			'</div>';
		// Restore a previously dragged width.
		var storedW = 0; try { storedW = parseInt(localStorage.getItem('tfBuilderPreviewW'), 10) || 0; } catch (e) {}
		if (storedW) { pane.style.flexBasis = storedW + 'px'; }

		wrap.appendChild(buildPalette());
		wrap.appendChild(canvas);
		wrap.appendChild(pane);
		root.appendChild(bar);
		root.appendChild(wrap);

		// Live-preview show/hide (persisted across sessions).
		try { previewHidden = localStorage.getItem('tfBuilderHidePreview') === '1'; } catch (e) { previewHidden = false; }
		function applyPreview() {
			wrap.classList.toggle('tf-b-no-preview', previewHidden);
			prevToggle.innerHTML = '<span class="dashicons dashicons-visibility"></span> ' + (previewHidden ? 'Show live preview' : 'Hide live preview');
		}
		applyPreview();
		prevToggle.addEventListener('click', function () {
			previewHidden = !previewHidden;
			try { localStorage.setItem('tfBuilderHidePreview', previewHidden ? '1' : '0'); } catch (e) {}
			applyPreview();
			if (!previewHidden) { updatePreview(); }
		});

		// Drag the divider on the preview's left edge to make it wider/narrower (persisted).
		var resizer = pane.querySelector('.tf-b-resizer');
		if (resizer) {
			resizer.addEventListener('mousedown', function (e) {
				e.preventDefault();
				var startX = e.clientX, startW = pane.offsetWidth;
				var maxW = Math.max(360, wrap.clientWidth - 360); // leave room for palette + canvas
				document.body.style.userSelect = 'none';
				function move(ev) {
					var w = Math.max(280, Math.min(maxW, startW + (startX - ev.clientX))); // drag left = wider
					pane.style.flexBasis = w + 'px';
				}
				function up() {
					document.removeEventListener('mousemove', move);
					document.removeEventListener('mouseup', up);
					document.body.style.userSelect = '';
					try { localStorage.setItem('tfBuilderPreviewW', String(pane.offsetWidth)); } catch (e2) {}
					updatePreview(); // re-fit the iframe height for the new width
				}
				document.addEventListener('mousemove', move);
				document.addEventListener('mouseup', up);
			});
		}

		// Default the click-to-add target to the first step.
		setActive(canvas.querySelector('.tf-b-step'));

		refreshStepNextFields();
		sync();
	}

	function esc(s) { return String(s == null ? '' : s).replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
	// For <textarea> bodies: escape < and & (no need to escape quotes inside element content).
	function escArea(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;'); }

	document.addEventListener('DOMContentLoaded', function () {
		root = document.getElementById('tf-builder');
		hidden = document.getElementById('tf_definition');
		if (!root || !hidden || typeof Sortable === 'undefined') { return; }
		loadDef();
		render();
		// Re-render when toggling regular/multistep
		document.querySelectorAll('input[name="tf_type"]').forEach(function (r) {
			r.addEventListener('change', function () { sync(); render(); });
		});
		// Refresh the live preview when the submit-button text changes.
		var submitText = document.getElementById('tf_submit_text');
		if (submitText) { submitText.addEventListener('input', schedulePreview); }
		// Safety: sync right before the post form submits
		var postForm = document.getElementById('post');
		if (postForm) { postForm.addEventListener('submit', sync); }
	});
})();
