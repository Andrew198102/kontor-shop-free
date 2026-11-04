/* Kontor Shop - Bestandsseite: Buchen-Popover, Mindestbestand, Auswahl, Lieferung, Nachbestellliste, Inventur, CSV-Downloads. */
(function () {
	'use strict';

	var admin = window.kontorShopAdmin || { ajaxUrl: '', nonce: '' };
	var cfg = window.kontorShopStock || { types: { in: [], out: [], notes: [] }, i18n: {} };
	var t = function (key, fallback) { return (cfg.i18n && cfg.i18n[key]) || fallback; };
	var fmt = function (text) { var args = Array.prototype.slice.call(arguments, 1), i = 0; return text.replace(/%(\d\$)?d/g, function (m, pos) { return String(pos ? args[parseInt(pos, 10) - 1] : args[i++]); }); };

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', admin.nonce);
		Object.keys(data).forEach(function (key) { body.append(key, data[key]); });
		return fetch(admin.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) { return response.json(); })
			.catch(function () { return { success: false, data: { message: t('networkError', 'Connection error.') } }; });
	}

	function notify(message, type) {
		var host = document.querySelector('.ks-wrap .wp-header-end');
		if (!host) { return; }
		var old = document.querySelector('.ks-ajax-notice');
		if (old) { old.remove(); }
		var box = document.createElement('div');
		box.className = 'notice notice-' + (type || 'success') + ' ks-ajax-notice';
		var p = document.createElement('p');
		p.textContent = message;
		box.appendChild(p);
		host.insertAdjacentElement('afterend', box);
		window.setTimeout(function () { if (box.parentNode) { box.remove(); } }, 7000);
	}

	function el(tag, attrs, text) {
		var node = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (key) { node.setAttribute(key, attrs[key]); });
		if (text !== undefined) { node.textContent = text; }
		return node;
	}

	function replaceItem(html) {
		if (!html) { return; }
		var holder = document.createElement('table');
		holder.innerHTML = html;
		var fresh = holder.querySelector('tbody');
		var old = fresh && document.querySelector('[data-ks-stock-item="' + fresh.getAttribute('data-ks-stock-item') + '"]');
		if (!old) { return; }
		var wasOpen = old.querySelector('[data-ks-stock-expand][aria-expanded="true"]');
		var checked = old.querySelector('[data-ks-stock-select]:checked');
		old.replaceWith(fresh);
		if (wasOpen) { toggleVariants(fresh.querySelector('[data-ks-stock-expand]'), true); }
		if (checked) { fresh.querySelector('[data-ks-stock-select]').checked = true; fresh.classList.add('is-selected'); }
		fresh.classList.add('ks-flash');
	}

	function toggleVariants(button, open) {
		if (!button) { return; }
		var item = button.closest('[data-ks-stock-item]');
		var show = open === undefined ? button.getAttribute('aria-expanded') !== 'true' : open;
		item.querySelectorAll('.ks-stock-vrow').forEach(function (row) { row.hidden = !show; });
		button.setAttribute('aria-expanded', show ? 'true' : 'false');
		button.textContent = show ? t('collapse', '▾ collapse') : t('expand', '▸ Variants');
	}

	/* ---------- Buchen-Popover ---------- */

	var pop = null;
	var ctx = null;

	function itemData(productId) {
		var item = document.querySelector('[data-ks-stock-item="' + productId + '"]');
		try { return JSON.parse(item.getAttribute('data-ks-stock')); } catch (e) { return null; }
	}

	function currentBalance() {
		if (!ctx.data.variants.length) { return ctx.data.balance; }
		if (ctx.variant === null) { return null; }
		var found = ctx.data.variants.filter(function (v) { return v.id === ctx.variant; })[0];
		return found ? found.balance : null;
	}

	function openPop(button) {
		closePop();
		var data = itemData(button.getAttribute('data-product'));
		if (!data) { return; }
		var dir = button.getAttribute('data-ks-book');
		var fixedVariant = button.hasAttribute('data-variant') ? parseInt(button.getAttribute('data-variant'), 10) : null;
		var variants = data.variants.filter(function (v) { return dir === 'out' || !v.outOnly; });
		ctx = { data: { id: data.id, name: data.name, balance: data.balance, variants: variants }, dir: dir, variant: fixedVariant, type: (cfg.types[dir][0] || [''])[0], anchor: button };
		if (ctx.variant === null && variants.length === 1) { ctx.variant = variants[0].id; }

		pop = el('div', { 'class': 'ks-pop', role: 'dialog', 'aria-label': dir === 'in' ? t('bookIn', 'Book stock in') : t('bookOut', 'Book stock out') });
		pop.appendChild(el('h3', {}, dir === 'in' ? t('bookIn', 'Book stock in') : t('bookOut', 'Book stock out')));
		pop.appendChild(el('div', { 'class': 'ks-pop__ctx' }, data.name));

		var types = el('div', { 'class': 'ks-pop__choices', role: 'group', 'aria-label': t('what', 'What happened?') });
		cfg.types[dir].forEach(function (pair) {
			var b = el('button', { type: 'button', 'data-type': pair[0], 'aria-pressed': pair[0] === ctx.type ? 'true' : 'false' }, pair[1]);
			types.appendChild(b);
		});
		pop.appendChild(types);

		if (variants.length) {
			var vp = el('div', { 'class': 'ks-pop__variants', role: 'group', 'aria-label': t('variant', 'Variant') });
			variants.forEach(function (v) {
				var b = el('button', { type: 'button', 'data-v': String(v.id), 'aria-pressed': v.id === ctx.variant ? 'true' : 'false' });
				b.appendChild(document.createTextNode(v.label + ' '));
				b.appendChild(el('span', { 'class': 'ks-muted' }, String(v.balance)));
				vp.appendChild(b);
			});
			pop.appendChild(vp);
		}

		var row = el('div', { 'class': 'ks-pop__qty' });
		var step = el('span', { 'class': 'ks-step' });
		step.appendChild(el('button', { type: 'button', 'data-step': '-1', 'aria-label': t('less', 'less') }, '−'));
		var qty = el('input', { type: 'number', min: '1', step: '1', value: '1', 'aria-label': t('quantity', 'Quantity'), 'data-ks-pop-qty': '' });
		step.appendChild(qty);
		step.appendChild(el('button', { type: 'button', 'data-step': '1', 'aria-label': t('more', 'more') }, '+'));
		row.appendChild(step);
		row.appendChild(el('span', { 'class': 'ks-pop__preview', 'data-ks-pop-preview': '' }));
		pop.appendChild(row);

		pop.appendChild(el('textarea', { rows: '2', 'class': 'large-text', 'data-ks-pop-note': '', 'aria-label': 'Notiz' }));
		var err = el('p', { 'class': 'ks-pop__error', 'data-ks-pop-error': '', role: 'alert' });
		pop.appendChild(err);

		var foot = el('div', { 'class': 'ks-pop__foot' });
		foot.appendChild(el('span', { 'class': 'ks-muted' }, t('keys', 'Enter books · Esc closes')));
		foot.appendChild(el('button', { type: 'button', 'class': 'button', 'data-pop': 'close' }, t('cancel', 'Cancel')));
		foot.appendChild(el('button', { type: 'button', 'class': 'button button-primary', 'data-pop': 'save' }, t('book', 'Book')));
		pop.appendChild(foot);

		document.body.appendChild(pop);
		updatePop();
		place();
		qty.focus();
		qty.select();
	}

	function place() {
		if (!pop || !ctx) { return; }
		var r = ctx.anchor.getBoundingClientRect();
		var width = pop.offsetWidth;
		var vw = document.documentElement.clientWidth;
		var left = Math.min(r.right - width, vw - width - 16);
		pop.style.left = (window.scrollX + Math.max(16, left)) + 'px';
		var top = r.bottom + 6;
		if (top + pop.offsetHeight > window.innerHeight && r.top - pop.offsetHeight - 6 > 0) { top = r.top - pop.offsetHeight - 6; }
		pop.style.top = (window.scrollY + top) + 'px';
	}

	function delta() {
		var q = Math.max(0, parseInt(pop.querySelector('[data-ks-pop-qty]').value, 10) || 0);
		var neg = ctx.dir === 'out';
		return neg ? -q : q;
	}

	function updatePop() {
		var needsNote = cfg.types.notes.indexOf(ctx.type) !== -1;
		pop.querySelector('[data-ks-pop-note]').placeholder = needsNote ? t('noteRequired', 'Reason for the correction (required)') : t('notePlaceholder', 'Note (optional)');
		pop.querySelectorAll('[data-type]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-type') === ctx.type ? 'true' : 'false'); });
		pop.querySelectorAll('[data-v]').forEach(function (b) { b.setAttribute('aria-pressed', parseInt(b.getAttribute('data-v'), 10) === ctx.variant ? 'true' : 'false'); });
		var preview = pop.querySelector('[data-ks-pop-preview]');
		var bal = currentBalance();
		preview.textContent = '';
		if (bal === null) {
			preview.appendChild(el('span', { 'class': 'ks-muted' }, t('pickVariant', 'Please choose a variant.')));
			return;
		}
		var after = bal + delta();
		preview.appendChild(document.createTextNode(t('stockNow', 'Stock') + ' ' + bal + ' → '));
		preview.appendChild(el('strong', { 'class': after < 0 ? 'ks-stock--out' : '' }, String(after)));
		if (after < 0) { preview.title = t('negative', 'Warning: stock will go negative.'); } else { preview.removeAttribute('title'); }
	}

	function showError(text) {
		pop.querySelector('[data-ks-pop-error]').textContent = text;
	}

	function savePop() {
		var qty = parseInt(pop.querySelector('[data-ks-pop-qty]').value, 10) || 0;
		var note = pop.querySelector('[data-ks-pop-note]').value.trim();
		if (ctx.data.variants.length && ctx.variant === null) { showError(t('pickVariant', 'Please choose a variant.')); return; }
		if (qty <= 0) { showError(t('qtyMissing', 'The quantity must be greater than 0.')); return; }
		if (cfg.types.notes.indexOf(ctx.type) !== -1 && !note) { showError(t('noteMissing', 'A note is required for a correction.')); pop.querySelector('[data-ks-pop-note]').focus(); return; }
		var save = pop.querySelector('[data-pop="save"]');
		save.disabled = true;
		var anchorItem = ctx.data.id;
		post('kontor_shop_stock_adjust', {
			product_id: ctx.data.id,
			variant_id: ctx.variant === null ? 0 : ctx.variant,
			move: ctx.type,
			quantity: qty,
			note: note
		}).then(function (result) {
			if (!result.success) {
				if (pop) { save.disabled = false; showError((result.data && result.data.message) || t('saveError', 'Booking failed.')); }
				return;
			}
			closePop();
			replaceItem(result.data.row);
			notify(result.data.message, 'success');
			var focus = document.querySelector('[data-ks-stock-item="' + anchorItem + '"] [data-ks-book]');
			if (focus) { focus.focus(); }
		});
	}

	function closePop() {
		if (pop) { pop.remove(); }
		pop = null;
		ctx = null;
	}

	document.addEventListener('click', function (event) {
		var book = event.target.closest('[data-ks-book]');
		if (book) { event.preventDefault(); openPop(book); return; }
		if (pop && !pop.contains(event.target)) { closePop(); return; }
		if (!pop) { return; }
		var b = event.target.closest('button');
		if (!b) { return; }
		if (b.hasAttribute('data-type')) { ctx.type = b.getAttribute('data-type'); updatePop(); }
		else if (b.hasAttribute('data-v')) { ctx.variant = parseInt(b.getAttribute('data-v'), 10); updatePop(); }
		else if (b.hasAttribute('data-step')) {
			var q = pop.querySelector('[data-ks-pop-qty]');
			q.value = Math.max(1, (parseInt(q.value, 10) || 0) + parseInt(b.getAttribute('data-step'), 10));
			updatePop();
		}
		else if (b.getAttribute('data-pop') === 'close') { closePop(); }
		else if (b.getAttribute('data-pop') === 'save') { savePop(); }
	});
	document.addEventListener('input', function (event) { if (pop && event.target.hasAttribute('data-ks-pop-qty')) { updatePop(); } });
	document.addEventListener('keydown', function (event) {
		if (!pop) { return; }
		if (event.key === 'Escape') { var a = ctx.anchor; closePop(); if (a && a.isConnected) { a.focus(); } return; }
		if (event.key === 'Enter' && pop.contains(event.target) && !event.shiftKey && event.target.tagName !== 'BUTTON') { event.preventDefault(); savePop(); }
	});
	window.addEventListener('resize', place);

	/* ---------- Varianten aufklappen ---------- */

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-ks-stock-expand]');
		if (button) { toggleVariants(button); }
	});

	/* ---------- Mindestbestand in der Zelle ---------- */

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-ks-min]');
		if (!button || button.querySelector('input')) { return; }
		var id = button.getAttribute('data-ks-min');
		var input = el('input', { type: 'number', min: '0', step: '1', 'class': 'small-text', 'aria-label': t('minLabel', 'Minimum stock') });
		input.value = button.getAttribute('data-value');
		var original = button.innerHTML;
		button.textContent = '';
		button.appendChild(input);
		input.focus();
		input.select();
		var done = false;
		function finish(save) {
			if (done) { return; }
			done = true;
			if (!save || input.value === button.getAttribute('data-value')) { button.innerHTML = original; return; }
			button.classList.add('is-busy');
			post('kontor_shop_stock_min', { product_id: id, min_stock: input.value }).then(function (result) {
				if (!result.success) {
					button.innerHTML = original;
					button.classList.remove('is-busy');
					notify((result.data && result.data.message) || t('saveError', 'Saving failed.'), 'error');
					return;
				}
				replaceItem(result.data.row);
				notify(result.data.message, 'success');
			});
		}
		input.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') { e.preventDefault(); finish(true); }
			if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); finish(false); button.focus(); }
		});
		input.addEventListener('blur', function () { finish(true); });
		input.addEventListener('click', function (e) { e.stopPropagation(); });
	});

	/* ---------- Auswahl + Sammelaktionen ---------- */

	var bulk = document.querySelector('[data-ks-stock-bulk]');

	function selectedIds() {
		return Array.prototype.map.call(document.querySelectorAll('[data-ks-stock-select]:checked'), function (cb) { return cb.value; });
	}

	function syncBulk() {
		if (!bulk) { return; }
		var ids = selectedIds();
		bulk.hidden = ids.length === 0;
		bulk.querySelector('[data-ks-bulk-count]').textContent = fmt(t('selected', '%d selected'), ids.length);
		document.querySelectorAll('[data-ks-stock-select]').forEach(function (cb) { cb.closest('[data-ks-stock-item]').classList.toggle('is-selected', cb.checked); });
		var all = document.querySelector('[data-ks-stock-all]');
		var boxes = document.querySelectorAll('[data-ks-stock-select]');
		if (all) { all.checked = boxes.length > 0 && ids.length === boxes.length; }
	}

	document.addEventListener('change', function (event) {
		if (event.target.hasAttribute('data-ks-stock-all')) {
			document.querySelectorAll('[data-ks-stock-select]').forEach(function (cb) { cb.checked = event.target.checked; });
		}
		if (event.target.hasAttribute('data-ks-stock-all') || event.target.hasAttribute('data-ks-stock-select')) { syncBulk(); }
	});

	function openDialog(id) {
		var dialog = document.getElementById(id);
		if (dialog && typeof dialog.showModal === 'function' && !dialog.open) { dialog.showModal(); }
		return dialog;
	}

	if (bulk) {
		bulk.addEventListener('click', function (event) {
			var b = event.target.closest('[data-ks-bulk]');
			if (!b) { return; }
			var ids = selectedIds();
			var action = b.getAttribute('data-ks-bulk');
			if (action === 'clear') {
				document.querySelectorAll('[data-ks-stock-select]').forEach(function (cb) { cb.checked = false; });
				syncBulk();
			} else if (action === 'receive') {
				receiveReset();
				ids.forEach(function (id) {
					document.querySelectorAll('[data-ks-receive-pick] option[data-product="' + id + '"]').forEach(function (opt) { receiveAdd(opt, 0); });
				});
				openDialog('ks-stock-receive');
			} else if (action === 'min') {
				var dialog = document.getElementById('ks-stock-min');
				dialog.querySelector('[data-ks-min-ids]').value = ids.join(',');
				dialog.querySelector('[data-ks-min-summary]').textContent = fmt(t('minSummary', 'For %d selected products.'), ids.length);
				openDialog('ks-stock-min');
			} else {
				// Knoepfe von Erweiterungen (kontor_shop/stock/bulk_actions), z. B. Pro: Nachbestellliste.
				document.dispatchEvent(new CustomEvent('kontor-shop:stock-bulk', { detail: { action: action, ids: ids } }));
			}
		});
	}

	/* ---------- Lieferung erfassen ---------- */

	var receive = document.querySelector('[data-ks-receive]');
	var receiveCount = 0;

	function receiveRows() { return receive.querySelector('[data-ks-receive-rows]'); }

	function receiveReset() {
		if (!receive) { return; }
		receiveRows().querySelectorAll('tr:not(.ks-receive-empty)').forEach(function (row) { row.remove(); });
		receiveSync();
	}

	function receiveSync() {
		var rows = receiveRows();
		var sum = 0;
		rows.querySelectorAll('[data-ks-receive-qty]').forEach(function (input) {
			var qty = Math.max(0, parseInt(input.value, 10) || 0);
			sum += qty;
			var row = input.closest('tr');
			row.querySelector('[data-ks-after]').textContent = String(parseInt(row.getAttribute('data-balance'), 10) + qty);
		});
		rows.querySelector('.ks-receive-empty').hidden = rows.querySelectorAll('[data-ks-receive-qty]').length > 0;
		receive.querySelector('[data-ks-receive-submit]').textContent = sum > 0 ? fmt(t('receivePcs', 'Book delivery (%d pcs.)'), sum) : t('receiveBtn', 'Book delivery');
	}

	function receiveAdd(option, qty) {
		var parts = option.value.split(':');
		var rows = receiveRows();
		var existing = rows.querySelector('tr[data-key="' + option.value + '"]');
		if (existing) {
			var input = existing.querySelector('[data-ks-receive-qty]');
			input.value = (parseInt(input.value, 10) || 0) + (qty || 1);
			receiveSync();
			return input;
		}
		var i = receiveCount++;
		var tr = el('tr', { 'data-key': option.value, 'data-balance': option.getAttribute('data-balance') });
		var name = el('td', {}, option.getAttribute('data-name'));
		name.appendChild(el('input', { type: 'hidden', name: 'lines[' + i + '][product]', value: parts[0] }));
		name.appendChild(el('input', { type: 'hidden', name: 'lines[' + i + '][variant]', value: parts[1] }));
		tr.appendChild(name);
		tr.appendChild(el('td', { 'class': 'ks-col-num' }, option.getAttribute('data-balance')));
		var qtyCell = el('td', { 'class': 'ks-col-num' });
		var qtyInput = el('input', { type: 'number', min: '0', step: '1', 'class': 'small-text', name: 'lines[' + i + '][qty]', id: 'ks-receive-q-' + i, 'data-ks-receive-qty': '', 'aria-label': option.getAttribute('data-name') });
		qtyInput.value = String(qty === undefined ? 1 : qty || '');
		qtyCell.appendChild(qtyInput);
		tr.appendChild(qtyCell);
		tr.appendChild(el('td', { 'class': 'ks-col-num', 'data-ks-after': '' }));
		var rm = el('td', {});
		rm.appendChild(el('button', { type: 'button', 'class': 'button-link ks-receive-remove', 'data-ks-receive-remove': '', 'aria-label': t('remove', 'Remove row') }, '×'));
		tr.appendChild(rm);
		rows.appendChild(tr);
		receiveSync();
		return qtyInput;
	}

	if (receive) {
		var pick = receive.querySelector('[data-ks-receive-pick]');
		var scan = receive.querySelector('[data-ks-receive-scan]');
		pick.addEventListener('change', function () {
			var opt = pick.options[pick.selectedIndex];
			if (opt && opt.value) { var input = receiveAdd(opt, 1); pick.value = ''; input.focus(); input.select(); }
		});
		scan.addEventListener('keydown', function (event) {
			if (event.key !== 'Enter') { return; }
			event.preventDefault();
			var code = scan.value.trim().toLowerCase();
			if (!code) { return; }
			var match = Array.prototype.filter.call(pick.options, function (o) { return o.value && (' ' + o.getAttribute('data-codes') + ' ').indexOf(' ' + code + ' ') !== -1; })[0];
			if (!match) { notify(t('noCode', 'No matching SKU/EAN found.'), 'warning'); scan.select(); return; }
			receiveAdd(match, 1);
			scan.value = '';
		});
		receive.addEventListener('input', function (event) { if (event.target.hasAttribute('data-ks-receive-qty')) { receiveSync(); } });
		receive.addEventListener('click', function (event) {
			var rm = event.target.closest('[data-ks-receive-remove]');
			if (rm) { rm.closest('tr').remove(); receiveSync(); }
		});
		document.querySelectorAll('[data-ks-receive-open]').forEach(function (b) { b.addEventListener('click', function () { window.setTimeout(function () { pick.focus(); }, 0); }); });
	}

	/* ---------- Fuer Erweiterungen (z. B. Pro: Nachbestellliste) ---------- */

	window.kontorShopStockUi = {
		t: t,
		notify: notify,
		openDialog: openDialog,
		receiveReset: receiveReset,
		receiveAdd: receiveAdd,
		receiveForm: function () { return receive; }
	};

	/* ---------- CSV-Downloads mit Warteanzeige ---------- */

	// Laedt die Datei per fetch, damit der Knopf bis zum fertigen Export einen
	// Spinner zeigt (ein normaler Download-Link gibt keine Rueckmeldung). Ohne
	// JS bleiben Link bzw. Formular ein ganz normaler Download.
	function download(url, button, onError) {
		if (button.classList.contains('is-busy')) { return Promise.resolve(false); }
		var original = button.innerHTML;
		button.classList.add('is-busy');
		button.setAttribute('aria-busy', 'true');
		button.textContent = t('preparing', 'Creating …');
		button.appendChild(el('span', { 'class': 'spinner is-active ks-busy-spinner', 'aria-hidden': 'true' }));

		var reset = function () {
			button.classList.remove('is-busy');
			button.removeAttribute('aria-busy');
			button.innerHTML = original;
		};

		return fetch(url, { credentials: 'same-origin' }).then(function (response) {
			var type = response.headers.get('Content-Type') || '';
			if (!response.ok || type.indexOf('text/csv') !== 0) { throw new Error('export'); }
			var match = /filename="([^"]+)"/.exec(response.headers.get('Content-Disposition') || '');
			return response.blob().then(function (blob) {
				var link = el('a', { href: URL.createObjectURL(blob), download: match ? match[1] : 'export.csv' });
				document.body.appendChild(link);
				link.click();
				window.setTimeout(function () { URL.revokeObjectURL(link.href); link.remove(); }, 1000);
				reset();
				return true;
			});
		}).catch(function () {
			reset();
			(onError || function (text) { notify(text, 'error'); })(t('downloadFail', 'Export failed. Please reload the page and try again.'));
			return false;
		});
	}

	document.addEventListener('click', function (event) {
		var link = event.target.closest('a[data-ks-download]');
		if (!link || !window.fetch || event.ctrlKey || event.metaKey || event.shiftKey) { return; }
		event.preventDefault();
		download(link.href, link).then(function (ok) { if (ok) { notify(t('downloaded', 'File downloaded.'), 'success'); } });
	});

	document.querySelectorAll('[data-ks-download-form]').forEach(function (form) {
		var error = form.querySelector('[data-ks-download-error]');
		form.addEventListener('submit', function (event) {
			if (!window.fetch) { return; }
			event.preventDefault();
			if (error) { error.hidden = true; }
			var query = new URLSearchParams(new FormData(form)).toString();
			var showError = function (text) { if (error) { error.textContent = text; error.hidden = false; } else { notify(text, 'error'); } };
			download(form.action + '?' + query, form.querySelector('[type="submit"]'), showError).then(function (ok) {
				if (!ok) { return; }
				var dialog = form.closest('dialog');
				if (dialog && dialog.open) { dialog.close(); }
				notify(t('downloaded', 'File downloaded.'), 'success');
			});
		});
	});

	/* ---------- Inventur ---------- */

	var count = document.querySelector('[data-ks-count]');

	if (count) {
		var key = count.getAttribute('data-ks-count-key');
		var store = {
			read: function () { try { return JSON.parse(window.sessionStorage.getItem(key) || '{}'); } catch (e) { return {}; } },
			write: function (value) { try { window.sessionStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* nur Komfort */ } },
			clear: function () { try { window.sessionStorage.removeItem(key); } catch (e) { /* nur Komfort */ } }
		};

		var countSync = function () {
			var done = 0;
			var diffs = 0;
			var draft = {};
			count.querySelectorAll('.ks-count-input').forEach(function (input) {
				var out = input.closest('tr').querySelector('[data-ks-diff]');
				if (input.value === '') { out.textContent = '–'; out.className = 'ks-diff'; return; }
				draft[input.name] = input.value;
				done++;
				var diff = (parseInt(input.value, 10) || 0) - parseInt(input.getAttribute('data-soll'), 10);
				if (diff !== 0) { diffs++; }
				out.textContent = diff === 0 ? t('fits', 'matches') : (diff > 0 ? '+' : '') + diff;
				out.className = 'ks-diff ' + (diff > 0 ? 'is-pos' : diff < 0 ? 'is-neg' : 'is-zero');
			});
			store.write(draft);
			count.querySelector('[data-ks-count-summary]').textContent = done ? fmt(t('countSummary', '%1$d counted · %2$d differences'), done, diffs) : t('counted', 'Nothing counted yet.');
		};

		try { window.sessionStorage.removeItem('ks-count-commit'); } catch (e) { /* nur Komfort */ }
		var draft = store.read();
		count.querySelectorAll('.ks-count-input').forEach(function (input) { if (draft[input.name] !== undefined) { input.value = draft[input.name]; } });
		countSync();
		count.addEventListener('input', countSync);
		count.addEventListener('keydown', function (event) {
			if (event.key !== 'Enter' || !event.target.classList.contains('ks-count-input')) { return; }
			event.preventDefault();
			var inputs = Array.prototype.slice.call(count.querySelectorAll('.ks-count-input'));
			var next = inputs[inputs.indexOf(event.target) + 1];
			if (next) { next.focus(); next.select(); }
		});
		// Nach erfolgreichem Buchen landet man auf der Uebersicht; erst dort wird der Entwurf verworfen.
		count.addEventListener('submit', function () {
			try { window.sessionStorage.setItem('ks-count-commit', key); } catch (e) { /* nur Komfort */ }
			// Buchen kann bei vielen Differenzen dauern: Doppelklick sperren, Spinner zeigen.
			var submit = count.querySelector('[type="submit"]');
			window.setTimeout(function () { submit.disabled = true; }, 0);
			submit.classList.add('is-busy');
			submit.textContent = t('booking', 'Booking …');
			submit.appendChild(el('span', { 'class': 'spinner is-active ks-busy-spinner', 'aria-hidden': 'true' }));
		});
	} else {
		try {
			var committed = window.sessionStorage.getItem('ks-count-commit');
			if (committed) {
				window.sessionStorage.removeItem('ks-count-commit');
				if (!document.querySelector('.ks-wrap .notice-error')) { window.sessionStorage.removeItem(committed); }
			}
		} catch (e) { /* nur Komfort */ }
	}

	syncBulk();
}());
