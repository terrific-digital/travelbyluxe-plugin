/* Terrific FAQ — admin builder: add / reorder / remove question+answer items. */
(function () {
	'use strict';
	var root, hidden, listEl;

	function esc(s) { return String(s == null ? '' : s).replace(/"/g, '&quot;').replace(/</g, '&lt;'); }
	function escArea(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;'); }

	function load() {
		var items;
		try { items = JSON.parse(root.getAttribute('data-items')); } catch (e) { items = null; }
		return Array.isArray(items) ? items : [];
	}

	function sync() {
		var out = [];
		listEl.querySelectorAll('.td-faq-card').forEach(function (card) {
			out.push({
				q: card.querySelector('.td-faq-q-input').value,
				a: card.querySelector('.td-faq-a-input').value
			});
		});
		hidden.value = JSON.stringify(out);
	}

	function card(it) {
		var c = document.createElement('div');
		c.className = 'td-faq-card';
		c.innerHTML =
			'<div class="td-faq-card-head">' +
				'<span class="td-faq-handle dashicons dashicons-move" title="Drag to reorder"></span>' +
				'<input type="text" class="td-faq-q-input" placeholder="Question" value="' + esc(it.q) + '">' +
				'<button type="button" class="td-faq-del button-link" title="Remove question">✕</button>' +
			'</div>' +
			'<textarea class="td-faq-a-input" rows="3" placeholder="Answer (basic HTML allowed)">' + escArea(it.a) + '</textarea>';
		c.querySelector('.td-faq-q-input').addEventListener('input', sync);
		c.querySelector('.td-faq-a-input').addEventListener('input', sync);
		c.querySelector('.td-faq-del').addEventListener('click', function () { c.remove(); sync(); });
		return c;
	}

	function render() {
		root.innerHTML = '';
		listEl = document.createElement('div');
		listEl.className = 'td-faq-list';
		load().forEach(function (it) { listEl.appendChild(card(it)); });

		var add = document.createElement('button');
		add.type = 'button';
		add.className = 'button td-faq-add';
		add.textContent = '+ Add question';
		add.addEventListener('click', function () {
			var c = card({ q: '', a: '' });
			listEl.appendChild(c);
			var q = c.querySelector('.td-faq-q-input'); if (q) { q.focus(); }
			sync();
		});

		root.appendChild(listEl);
		root.appendChild(add);

		if (typeof Sortable !== 'undefined') {
			Sortable.create(listEl, { handle: '.td-faq-handle', animation: 150, ghostClass: 'td-faq-ghost', supportPointer: false, onSort: sync, onEnd: sync });
		}
		sync();
	}

	document.addEventListener('DOMContentLoaded', function () {
		root = document.getElementById('td-faq-builder');
		hidden = document.getElementById('td_faq_items');
		if (!root || !hidden) { return; }
		render();
		var form = document.getElementById('post');
		if (form) { form.addEventListener('submit', sync); }
	});
})();
