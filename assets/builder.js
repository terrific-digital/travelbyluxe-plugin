/* Terrific Forms — drag-and-drop admin builder */
(function () {
	'use strict';

	var FIELD_TYPES = [
		{ type: 'text', label: 'Text' },
		{ type: 'email', label: 'Email' },
		{ type: 'tel', label: 'Phone' },
		{ type: 'number', label: 'Number' },
		{ type: 'date', label: 'Date' },
		{ type: 'textarea', label: 'Textarea' },
		{ type: 'select', label: 'Dropdown' },
		{ type: 'radio', label: 'Radio' },
		{ type: 'checkbox', label: 'Checkboxes' },
		{ type: 'heading', label: 'Heading' },
		{ type: 'hidden', label: 'Hidden' }
	];
	var HAS_OPTIONS = { select: 1, radio: 1, checkbox: 1 };

	var root, hidden, def, fieldMap = {};

	function uid() { return 'f_' + Math.random().toString(36).slice(2, 8); }
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
			var title = stepEl.querySelector('.tf-b-step-title').value;
			var fields = [];
			stepEl.querySelectorAll('.tf-b-field').forEach(function (card) {
				var id = card.getAttribute('data-id');
				if (fieldMap[id]) { fields.push(fieldMap[id]); }
			});
			steps.push({ title: title, fields: fields });
		});
		def.type = currentType();
		def.steps = steps.length ? steps : [{ title: '', fields: [] }];
		hidden.value = JSON.stringify({ type: def.type, steps: def.steps });
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
		var lengthRow = ( ['text', 'email', 'tel', 'textarea'].indexOf(f.type) >= 0 ) ?
			'<div class="tf-b-row2"><label class="tf-b-row">Min length<input type="number" min="0" class="tf-b-minlen" value="' + esc(f.minlength) + '"></label>' +
			'<label class="tf-b-row">Max length<input type="number" min="0" class="tf-b-maxlen" value="' + esc(f.maxlength) + '"></label></div>' : '';
		var placeRow = (f.type === 'heading' || f.type === 'hidden') ? '' :
			'<label class="tf-b-row">Placeholder<input type="text" class="tf-b-ph" value="' + esc(f.placeholder) + '"></label>';
		var reqRow = (f.type === 'heading' || f.type === 'hidden') ? '' :
			'<label class="tf-b-row tf-b-inline"><input type="checkbox" class="tf-b-req"' + (f.required ? ' checked' : '') + '> Required</label>';
		var widthRow = (f.type === 'hidden') ? '' :
			'<label class="tf-b-row">Width<select class="tf-b-width">' +
				'<option value="full"' + ('full' === f.width ? ' selected' : '') + '>Full width</option>' +
				'<option value="half"' + ('half' === f.width ? ' selected' : '') + '>Half (1/2)</option>' +
				'<option value="third"' + ('third' === f.width ? ' selected' : '') + '>Third (1/3)</option>' +
			'</select></label>';
		var showLabelRow = (f.type === 'heading' || f.type === 'hidden') ? '' :
			'<label class="tf-b-row tf-b-inline"><input type="checkbox" class="tf-b-showlabel"' + ((f.show_label !== false) ? ' checked' : '') + '> Show label</label>';
		var widthBadge = ('full' !== f.width) ? '<span class="tf-b-wbadge">' + (f.width === 'half' ? '½' : '⅓') + '</span>' : '';
		card.innerHTML =
			'<div class="tf-b-field-head">' +
				'<span class="tf-b-handle dashicons dashicons-move"></span>' +
				'<span class="tf-b-type">' + f.type + '</span>' +
				'<span class="tf-b-field-label">' + esc(f.label || '(no label)') + '</span>' +
				'<span class="tf-b-wbadge-wrap">' + widthBadge + '</span>' +
				'<button type="button" class="tf-b-edit button-link">Edit</button>' +
				'<button type="button" class="tf-b-del button-link">Delete</button>' +
			'</div>' +
			'<div class="tf-b-field-body" hidden>' +
				'<label class="tf-b-row">Label<input type="text" class="tf-b-label" value="' + esc(f.label) + '"></label>' +
				showLabelRow +
				'<label class="tf-b-row">Key (data layer)<input type="text" class="tf-b-key" value="' + esc(f.key) + '"></label>' +
				placeRow + numberRow + lengthRow + optionsRow + widthRow + reqRow +
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
				if (wrap) { wrap.innerHTML = 'full' !== f.width ? '<span class="tf-b-wbadge">' + (f.width === 'half' ? '½' : '⅓') + '</span>' : ''; }
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
		lbl.addEventListener('input', function () {
			f.label = lbl.value;
			card.querySelector('.tf-b-field-label').textContent = f.label || '(no label)';
			if (!keyEl.value || keyEl.getAttribute('data-auto') !== '0') { keyEl.value = slug(f.label); f.key = keyEl.value; }
			sync();
		});
		keyEl.addEventListener('input', function () { f.key = slug(keyEl.value); keyEl.setAttribute('data-auto', '0'); sync(); });
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
				(multi ? '<button type="button" class="tf-b-del-step button-link">Remove step</button>' : '') +
			'</div>' +
			'<div class="tf-b-fields"></div>';
		var list = el.querySelector('.tf-b-fields');
		(step.fields || []).forEach(function (f) { list.appendChild(fieldCard(f)); });
		var t = el.querySelector('.tf-b-step-title'); if (t) { t.addEventListener('input', sync); }
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
		var m = { text: 'Text', email: 'Email Address', tel: 'Phone', number: 'Number', date: 'Date', textarea: 'Message', select: 'Select', radio: 'Choose one', checkbox: 'Select all that apply', heading: 'Section heading', hidden: 'Hidden value' };
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
			it.innerHTML = '<span class="dashicons dashicons-plus-alt2"></span>' + ft.label;
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
		// Safety: sync right before the post form submits
		var postForm = document.getElementById('post');
		if (postForm) { postForm.addEventListener('submit', sync); }
	});
})();
