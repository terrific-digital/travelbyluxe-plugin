/* TBL FAQ — admin builder: add / reorder (drag) / duplicate / remove Q&A items.
 * Each item is a card: the question is editable inline in the header; the answer lives
 * in a collapsible body so a long list stays scannable and easy to reorder. */
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

	function card(it, expand) {
		var c = document.createElement('div');
		c.className = 'td-faq-card';
		c.innerHTML =
			'<div class="td-faq-card-head">' +
				'<span class="td-faq-handle dashicons dashicons-move" title="Drag to reorder"></span>' +
				'<input type="text" class="td-faq-q-input" placeholder="Question" value="' + esc(it.q) + '">' +
				'<span class="td-faq-acts">' +
					'<button type="button" class="td-faq-toggle button-link" title="Edit answer"><span class="dashicons dashicons-edit"></span></button>' +
					'<button type="button" class="td-faq-dup button-link" title="Duplicate"><span class="dashicons dashicons-admin-page"></span></button>' +
					'<button type="button" class="td-faq-del button-link" title="Delete"><span class="dashicons dashicons-trash"></span></button>' +
				'</span>' +
			'</div>' +
			'<div class="td-faq-body" hidden>' +
				'<label class="td-faq-a-label">Answer <span>(basic HTML allowed)</span></label>' +
				'<textarea class="td-faq-a-input" rows="4" placeholder="Type the answer…">' + escArea(it.a) + '</textarea>' +
			'</div>';

		var q = c.querySelector('.td-faq-q-input');
		var a = c.querySelector('.td-faq-a-input');
		var body = c.querySelector('.td-faq-body');
		var toggle = c.querySelector('.td-faq-toggle');

		function setOpen(open) {
			body.hidden = !open;
			c.classList.toggle('is-open', open);
			var ic = toggle.querySelector('.dashicons');
			if (ic) { ic.className = 'dashicons ' + (open ? 'dashicons-arrow-up-alt2' : 'dashicons-edit'); }
			toggle.title = open ? 'Hide answer' : 'Edit answer';
		}

		q.addEventListener('input', sync);
		a.addEventListener('input', sync);
		toggle.addEventListener('click', function () { setOpen(body.hidden); });
		c.querySelector('.td-faq-dup').addEventListener('click', function () {
			var copy = card({ q: q.value, a: a.value }, false);
			c.parentNode.insertBefore(copy, c.nextSibling);
			sync();
		});
		c.querySelector('.td-faq-del').addEventListener('click', function () { c.remove(); sync(); });

		if (expand) { setOpen(true); }
		return c;
	}

	function addCard(it, expand, focusIt) {
		var c = card(it, expand);
		listEl.appendChild(c);
		if (focusIt) { var q = c.querySelector('.td-faq-q-input'); if (q) { q.focus(); } }
		sync();
		return c;
	}

	function render() {
		root.innerHTML = '';
		listEl = document.createElement('div');
		listEl.className = 'td-faq-list';
		root.appendChild(listEl);

		var items = load();
		items.forEach(function (it) { listEl.appendChild(card(it, false)); });
		if (!items.length) { addCard({ q: '', a: '' }, true, false); } // start with one open item

		var add = document.createElement('button');
		add.type = 'button';
		add.className = 'button button-secondary td-faq-add';
		add.innerHTML = '<span class="dashicons dashicons-plus-alt2" style="vertical-align:text-bottom"></span> Add question';
		add.addEventListener('click', function () { addCard({ q: '', a: '' }, true, true); });
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
