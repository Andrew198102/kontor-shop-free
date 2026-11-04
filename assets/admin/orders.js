/* Kontor Shop - Bestellungen: Schnellansicht, Artikel-Akkordeon, Menue, Bezahlt-Popover, Auswahl. */
(function () {
	'use strict';

	var cfg = window.kontorShopOrders || { paidWith: [], i18n: {} };
	var t = function (key, fallback) { return (cfg.i18n && cfg.i18n[key]) || fallback; };
	var fmt = function (text) { var args = Array.prototype.slice.call(arguments, 1), i = 0; return text.replace(/%(\d\$)?d|%(\d\$)?s/g, function (m, a, b) { var pos = a || b; return String(pos ? args[parseInt(pos, 10) - 1] : args[i++]); }); };

	function el(tag, attrs, text) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, attrs[key]); });
		if (text !== undefined) { node.textContent = text; }
		return node;
	}

	function notify(message, type) {
		var host = document.querySelector('.ks-wrap .wp-header-end');
		if (!host) { return; }
		var box = el('div', { 'class': 'notice notice-' + (type || 'success') + ' ks-ajax-notice' });
		box.appendChild(el('p', {}, message));
		host.insertAdjacentElement('afterend', box);
		window.setTimeout(function () { if (box.parentNode) { box.remove(); } }, 6000);
	}

	function submitAction(orderId, action) {
		var form = document.getElementById('ks-order-action-form');
		if (!form) { return; }
		form.elements.order_id.value = orderId;
		form.elements.order_action.value = action;
		form.submit();
	}

	/* ---------- Schnellansicht + Artikel-Akkordeon ---------- */

	document.addEventListener('click', function (event) {
		var expand = event.target.closest('[data-ks-order-expand]');
		if (expand) {
			var quick = expand.closest('tbody').querySelector('.ks-order-quick');
			var open = expand.getAttribute('aria-expanded') !== 'true';
			quick.hidden = !open;
			expand.setAttribute('aria-expanded', open ? 'true' : 'false');
			expand.textContent = open ? '▾' : '▸';
			return;
		}

		var toggle = event.target.closest('[data-ks-items-toggle]');
		if (toggle) {
			var list = toggle.nextElementSibling;
			var show = toggle.getAttribute('aria-expanded') !== 'true';
			list.hidden = !show;
			toggle.setAttribute('aria-expanded', show ? 'true' : 'false');
			var arrow = toggle.querySelector('span[aria-hidden]');
			if (arrow) { arrow.textContent = show ? '▾' : '▸'; }
		}
	});

	/* ---------- ⋯-Menue ---------- */

	var openMenu = null;

	function closeMenu() {
		if (!openMenu) { return; }
		openMenu.hidden = true;
		var button = openMenu.previousElementSibling;
		if (button) { button.setAttribute('aria-expanded', 'false'); }
		openMenu = null;
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-ks-menu]');
		if (button) {
			var menu = button.nextElementSibling;
			var wasOpen = openMenu === menu;
			closeMenu();
			if (!wasOpen) {
				closePaid();
				menu.hidden = false;
				button.setAttribute('aria-expanded', 'true');
				openMenu = menu;
				var first = menu.querySelector('a, button');
				if (first) { first.focus(); }
			}
			return;
		}

		var action = event.target.closest('[data-ks-order-action]');
		if (action) {
			var question = action.getAttribute('data-confirm');
			if (question && !window.confirm(question)) { return; }
			submitAction(action.getAttribute('data-order'), action.getAttribute('data-ks-order-action'));
			return;
		}

		if (openMenu && !openMenu.contains(event.target)) { closeMenu(); }
	});

	/* ---------- Bezahlt-Popover ---------- */

	var pop = null;

	function closePaid() {
		if (pop) { pop.remove(); pop = null; }
	}

	function openPaid(button) {
		closePaid();
		closeMenu();
		var today = new Date();
		var iso = function (d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); };
		var chosen = (cfg.paidWith[0] || [''])[0];

		pop = el('div', { 'class': 'ks-pop', role: 'dialog', 'aria-label': t('paidTitle', 'Record payment') });
		pop.appendChild(el('h3', {}, t('paidTitle', 'Record payment')));
		pop.appendChild(el('div', { 'class': 'ks-pop__ctx' }, button.getAttribute('data-number') + ' · ' + button.getAttribute('data-total') + ' € · ' + t('reference', 'Ref.') + ' ' + button.getAttribute('data-reference')));
		pop.appendChild(el('p', { 'class': 'ks-pop__label' }, t('paidWith', 'Paid with')));

		var choices = el('div', { 'class': 'ks-pop__choices', role: 'group', 'aria-label': t('paidWith', 'Paid with') });
		cfg.paidWith.forEach(function (pair) {
			choices.appendChild(el('button', { type: 'button', 'data-with': pair[0], 'aria-pressed': pair[0] === chosen ? 'true' : 'false' }, pair[1]));
		});
		pop.appendChild(choices);

		var label = el('label', { 'class': 'ks-pop__label', 'for': 'ks-paid-on' }, t('paidOn', 'Payment received on'));
		pop.appendChild(label);
		var date = el('input', { type: 'date', id: 'ks-paid-on', value: iso(today), max: iso(today) });
		if (button.getAttribute('data-created')) { date.setAttribute('min', button.getAttribute('data-created')); }
		pop.appendChild(date);
		pop.appendChild(el('p', { 'class': 'ks-muted ks-pop__hint' }, t('paidHint', 'The customer gets the payment confirmation, the warehouse gets the shipping order.')));

		var foot = el('div', { 'class': 'ks-pop__foot' });
		foot.appendChild(el('button', { type: 'button', 'class': 'button', 'data-paid-cancel': '' }, t('cancel', 'Cancel')));
		foot.appendChild(el('button', { type: 'button', 'class': 'button button-primary', 'data-paid-save': '' }, t('markPaid', 'Mark as paid')));
		pop.appendChild(foot);

		pop.addEventListener('click', function (event) {
			var b = event.target.closest('button');
			if (!b) { return; }
			if (b.hasAttribute('data-with')) {
				chosen = b.getAttribute('data-with');
				choices.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
			} else if (b.hasAttribute('data-paid-cancel')) {
				closePaid();
				button.focus();
			} else if (b.hasAttribute('data-paid-save')) {
				var form = document.getElementById('ks-order-paid-form');
				form.elements.order_id.value = button.getAttribute('data-ks-paid');
				form.elements.paid_with.value = chosen;
				form.elements.paid_on.value = date.value;
				b.disabled = true;
				form.submit();
			}
		});

		document.body.appendChild(pop);
		var r = button.getBoundingClientRect();
		var width = pop.offsetWidth;
		pop.style.left = (window.scrollX + Math.max(16, Math.min(r.right - width, document.documentElement.clientWidth - width - 16))) + 'px';
		pop.style.top = (window.scrollY + r.bottom + 6) + 'px';
		var first = choices.querySelector('button');
		if (first) { first.focus(); }
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-ks-paid]');
		if (button) { event.stopPropagation(); openPaid(button); return; }
		if (pop && !pop.contains(event.target)) { closePaid(); }
	});

	document.addEventListener('keydown', function (event) {
		if (event.key !== 'Escape') { return; }
		if (pop) { closePaid(); }
		if (openMenu) { var b = openMenu.previousElementSibling; closeMenu(); if (b) { b.focus(); } }
	});

	/* ---------- Auswahl + Sammelaktionen ---------- */

	var bulk = document.querySelector('[data-ks-order-bulk]');

	function selected() {
		return Array.prototype.map.call(document.querySelectorAll('[data-ks-order-select]:checked'), function (cb) { return cb.value; });
	}

	function syncBulk() {
		if (!bulk) { return; }
		var ids = selected();
		bulk.hidden = ids.length === 0;
		bulk.querySelector('[data-ks-bulk-count]').textContent = fmt(t('selected', '%d selected'), ids.length);
		document.querySelectorAll('[data-ks-order-select]').forEach(function (cb) { cb.closest('tbody').classList.toggle('is-selected', cb.checked); });
		var all = document.querySelector('[data-ks-order-all]');
		var boxes = document.querySelectorAll('[data-ks-order-select]');
		if (all) { all.checked = boxes.length > 0 && ids.length === boxes.length; }
	}

	document.addEventListener('change', function (event) {
		if (event.target.hasAttribute('data-ks-order-all')) {
			document.querySelectorAll('[data-ks-order-select]').forEach(function (cb) { cb.checked = event.target.checked; });
		}
		if (event.target.hasAttribute('data-ks-order-all') || event.target.hasAttribute('data-ks-order-select')) { syncBulk(); }
	});

	if (bulk) {
		bulk.addEventListener('click', function (event) {
			var link = event.target.closest('[data-ks-order-bulk-link]');
			if (link) {
				event.preventDefault();
				var url = new URL(link.href, window.location.href);
				url.searchParams.set('ids', selected().join(','));
				window.location.href = url.toString();
				return;
			}
			var button = event.target.closest('[data-ks-order-bulk-action]');
			if (!button) { return; }
			var action = button.getAttribute('data-ks-order-bulk-action');
			if (action === 'clear') {
				document.querySelectorAll('[data-ks-order-select]').forEach(function (cb) { cb.checked = false; });
				syncBulk();
				return;
			}
			var ids = selected();
			if (!window.confirm(fmt(action === 'mark_paid' ? t('confirmPaid', 'Mark %d orders as paid?') : t('confirmShip', 'Mark %d orders completely as shipped/picked up?'), ids.length))) { return; }
			var form = document.getElementById('ks-order-bulk-form');
			form.elements.bulk_action.value = action;
			form.querySelectorAll('input[name="ids[]"]').forEach(function (input) { input.remove(); });
			ids.forEach(function (id) { form.appendChild(el('input', { type: 'hidden', name: 'ids[]', value: id })); });
			form.submit();
		});
	}

	/* ---------- Adresse kopieren ---------- */

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-ks-copy]');
		if (!button) { return; }
		var source = button.parentNode.querySelector('[data-ks-copy-source]');
		var text = source ? source.innerText.trim() : '';
		var fallback = function () {
			var range = document.createRange();
			range.selectNodeContents(source);
			var sel = window.getSelection();
			sel.removeAllRanges();
			sel.addRange(range);
			notify(t('copyManual', 'Address selected – now copy it.'), 'info');
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function () { notify(t('copied', 'Address copied.'), 'success'); }, fallback);
		} else { fallback(); }
	});

	/* ---------- Detailansicht: "Artikel stornieren" direkt aus dem Menue ---------- */

	var hashDialogs = { '#ks-cancel-items': 'dialog[id^="ks-cancel-items-"]', '#ks-return': 'dialog[id^="ks-return-"]' };
	if (hashDialogs[window.location.hash]) {
		var dialog = document.querySelector(hashDialogs[window.location.hash]);
		if (dialog && typeof dialog.showModal === 'function') { dialog.showModal(); }
	}

	syncBulk();
}());
