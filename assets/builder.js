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

	function uid() { return 'f_' + Math.random().toString(36).slice(2, 8); }
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
		var widthBadge = ('full' !== f.width) ? '<span class="tf-b-wbadge">' + (f.width === 'half' ? '½ width' : '⅓ width') + '</span>' : '';
		card.innerHTML =
			'<div class="tf-b-field-head">' +
				'<span class="tf-b-handle dashicons dashicons-move"></span>' +
				'<span class="tf-b-type"><span class="dashicons ' + typeIcon(f.type) + '"></span> ' + f.type + '</span>' +
				'<span class="tf-b-field-label">' + esc(f.label || '(no label)') + '</span>' +
				'<span class="tf-b-wbadge-wrap">' + widthBadge + '</span>' +
				'<button type="button" class="tf-b-edit button-link">Edit</button>' +
				'<button type="button" class="tf-b-del button-link">Delete</button>' +
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
			editBtn.textContent = open ? 'Minimize' : 'Edit';
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
				f.width = wsel.value;
				card.classList.remove('tf-bw-full', 'tf-bw-half', 'tf-bw-third');
				card.classList.add('tf-bw-' + f.width);
				var wrap = card.querySelector('.tf-b-wbadge-wrap');
				if (wrap) { wrap.innerHTML = 'full' !== f.width ? '<span class="tf-b-wbadge">' + (f.width === 'half' ? '½ width' : '⅓ width') + '</span>' : ''; }
				sync();
			});
		}
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
				(multi ? '<button type="button" class="tf-b-del-step button-link">Remove step</button>' : '') +
			'</div>' +
			'<div class="tf-b-fields"></div>';
		var list = el.querySelector('.tf-b-fields');
		(step.fields || []).forEach(function (f) { list.appendChild(fieldCard(f)); });
		var t = el.querySelector('.tf-b-step-title'); if (t) { t.addEventListener('input', sync); }
		var nx = el.querySelector('.tf-b-step-next'); if (nx) { nx.addEventListener('input', sync); }
		var ds = el.querySelector('.tf-b-del-step'); if (ds) { ds.addEventListener('click', function () { el.remove(); sync(); }); }
		makeSortable(list);
		return el;
	}

	function makeSortable(list) {
		Sortable.create(list, {
			group: 'tf-fields',
			handle: '.tf-b-handle',
			animation: 150,
			ghostClass: 'tf-b-ghost',
			onAdd: function (evt) {
				var item = evt.item;
				var newType = item.getAttribute('data-new-type');
				if (newType) {
					// dropped from palette -> create a real field
					var f = {
						id: uid(), type: newType, label: defaultLabel(newType), key: '', placeholder: '',
						required: false, show_label: true, width: 'full',
						min: '', max: '', minlength: '', maxlength: '', multiple: false,
						min_from_today: '', max_from_today: '',
						step: '', default: '', prefix: '', suffix: '', show_scale: false, value_inline: false, html: '', country_code: true,
						options: HAS_OPTIONS[newType] ? [ { label: 'Option 1', value: 'Option 1' }, { label: 'Option 2', value: 'Option 2' } ] : []
					};
					f.key = slug(f.label);
					fieldMap[f.id] = f;
					var card = fieldCard(f);
					item.parentNode.replaceChild(card, item);
				}
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
		pal.innerHTML = '<h4>Fields</h4><p class="tf-b-hint">Drag a field into the form &rarr;</p><div class="tf-b-palette-list"></div>';
		var list = pal.querySelector('.tf-b-palette-list');
		FIELD_TYPES.forEach(function (ft) {
			var it = document.createElement('div');
			it.className = 'tf-b-pal-item';
			it.setAttribute('data-new-type', ft.type);
			it.innerHTML = '<span class="dashicons ' + ft.icon + '"></span>' + ft.label;
			list.appendChild(it);
		});
		Sortable.create(list, { group: { name: 'tf-fields', pull: 'clone', put: false }, sort: false, animation: 150 });
		return pal;
	}

	function render() {
		root.innerHTML = '';
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
				canvas.appendChild(el);
				sync();
			});
			canvas.appendChild(add);
		}

		wrap.appendChild(buildPalette());
		wrap.appendChild(canvas);
		root.appendChild(wrap);
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
