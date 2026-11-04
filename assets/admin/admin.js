/* Kontor Shop - gemeinsames Admin-Skript: Schalter, Schnellbearbeitung, Sortieren, Buchen, Hilfen. */
(function () {
	'use strict';

	var cfg = window.kontorShopAdmin || { ajaxUrl: '', nonce: '', i18n: {} };
	var t = function (key, fallback) { return (cfg.i18n && cfg.i18n[key]) || fallback; };

	function post(action, data) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', cfg.nonce);
		Object.keys(data).forEach(function (key) {
			var value = data[key];
			if (Array.isArray(value)) {
				value.forEach(function (item) { body.append(key + '[]', item); });
			} else {
				body.append(key, value);
			}
		});
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) { return response.json(); })
			.catch(function () { return { success: false, data: { message: t('networkError', 'Connection error.') } }; });
	}

	function notify(message, type) {
		var host = document.querySelector('.ks-wrap .wp-header-end') || document.querySelector('.ks-wrap');
		if (!host) { return; }
		var old = document.querySelector('.ks-ajax-notice');
		if (old) { old.remove(); }
		var box = document.createElement('div');
		box.className = 'notice notice-' + (type || 'success') + ' is-dismissible ks-ajax-notice';
		var p = document.createElement('p');
		p.textContent = message;
		box.appendChild(p);
		host.insertAdjacentElement('afterend', box);
		window.setTimeout(function () { if (box.parentNode) { box.remove(); } }, 6000);
	}

	// Bestaetigungsdialoge (statt Inline-Handlern)
	document.addEventListener('click', function (event) {
		var el = event.target.closest('[data-ks-confirm]');
		if (el && !window.confirm(el.getAttribute('data-ks-confirm'))) {
			event.preventDefault();
		}
	});
	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (form.hasAttribute && form.hasAttribute('data-ks-confirm') && !window.confirm(form.getAttribute('data-ks-confirm'))) {
			event.preventDefault();
		}
	});

	// Mail-Tab: SMTP-Hinweis nur beim passenden Versandweg zeigen
	document.addEventListener('change', function (event) {
		if (event.target.id !== 'ks-mailer') { return; }
		document.querySelectorAll('[data-ks-mailer-only]').forEach(function (hint) {
			hint.hidden = hint.getAttribute('data-ks-mailer-only') !== event.target.value;
		});
	});

	// "Eintraege pro Seite" und Auswahllisten mit data-ks-autosubmit (z. B. Einstellungen am Handy) sofort anwenden
	document.addEventListener('change', function (event) {
		if (event.target.matches('.ks-per-page select, select[data-ks-autosubmit]')) { event.target.form.submit(); }
	});

	// Kopieren-Knoepfe (data-ks-copy="Text"); ohne Zwischenablage-Recht wird der Text zum Kopieren markiert
	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-ks-copy]');
		if (!button) { return; }
		var text = button.getAttribute('data-ks-copy');
		var label = button.textContent;
		var manual = function () {
			var area = document.createElement('textarea');
			area.value = text;
			area.readOnly = true;
			area.className = 'ks-copy-fallback';
			button.insertAdjacentElement('afterend', area);
			area.select();
			button.textContent = t('copyManual', 'Text selected – now copy with Ctrl/⌘+C.');
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(function () {
				button.textContent = t('copied', 'Copied.');
				window.setTimeout(function () { button.textContent = label; }, 2000);
			}, manual);
		} else {
			manual();
		}
	});

	// Felder, die beim Anklicken komplett markiert werden (z. B. neuer API-Token)
	document.addEventListener('focusin', function (event) {
		if (event.target.matches('[data-ks-select-all]')) { event.target.select(); }
	});

	// Sammelaktionen: Zusatzfelder je Aktion, Sicherheitsabfrage beim Loeschen
	document.addEventListener('change', function (event) {
		if (!event.target.matches('.ks-bulk-select')) { return; }
		var bar = event.target.closest('.ks-bulk');
		bar.querySelectorAll('.ks-bulk-extra').forEach(function (extra) {
			extra.hidden = !extra.classList.contains('ks-bulk-extra--' + event.target.value);
		});
	});
	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!form.classList || !form.classList.contains('ks-list-form')) { return; }
		var selects = form.querySelectorAll('.ks-bulk-select');
		var action = '';
		selects.forEach(function (select) { if (!action && select.value) { action = select.value; } });
		if (action === 'delete' && !window.confirm(t('confirmBulkDelete', 'Permanently delete the selected products?'))) {
			event.preventDefault();
		}
	});

	// Ein/Aus-Schalter (Sichtbarkeit / Aktiv)
	document.addEventListener('click', function (event) {
		var toggle = event.target.closest('[data-ks-toggle]');
		if (!toggle || toggle.classList.contains('is-busy')) { return; }
		var next = !toggle.classList.contains('is-on');
		var apply = function (state) {
			toggle.classList.toggle('is-on', state);
			toggle.setAttribute('aria-checked', state ? 'true' : 'false');
		};
		apply(next);
		toggle.classList.add('is-busy');
		post('kontor_shop_toggle_active', {
			entity: toggle.getAttribute('data-ks-toggle'),
			id: toggle.getAttribute('data-id'),
			active: next ? '1' : '0'
		}).then(function (result) {
			toggle.classList.remove('is-busy');
			if (!result.success) {
				apply(!next);
				notify((result.data && result.data.message) || t('saveError', 'Saving failed.'), 'error');
				return;
			}
			var row = toggle.closest('tr');
			if (row && row.hasAttribute('data-active')) { row.setAttribute('data-active', next ? '1' : '0'); }
		});
	});

	// Schnellbearbeitung (Artikelliste)
	function closeQuickEdit(row) {
		var editor = row.nextElementSibling;
		if (editor && editor.classList.contains('ks-quickedit-row')) { editor.remove(); }
		row.hidden = false;
	}
	document.addEventListener('click', function (event) {
		var open = event.target.closest('.ks-quickedit-open');
		if (open) {
			var row = open.closest('tr');
			var template = document.getElementById('ks-quickedit-template');
			if (!row || !template) { return; }
			document.querySelectorAll('.ks-quickedit-row').forEach(function (editor) { closeQuickEdit(editor.previousElementSibling); });
			var editor = template.content.firstElementChild.cloneNode(true);
			editor.querySelectorAll('[data-field]').forEach(function (input) {
				var name = input.getAttribute('data-field');
				var map = { name: 'name', sku: 'sku', price: 'price', category_id: 'category', min_stock: 'min', active: 'active' };
				var value = row.getAttribute('data-' + map[name]) || '';
				if (input.type === 'checkbox') { input.checked = value === '1'; } else { input.value = value; }
			});
			row.hidden = true;
			row.insertAdjacentElement('afterend', editor);
			var first = editor.querySelector('[data-field="name"]');
			if (first) { first.focus(); first.select(); }
			return;
		}

		var cancel = event.target.closest('.ks-qe-cancel');
		if (cancel) { closeQuickEdit(cancel.closest('tr').previousElementSibling); return; }

		var save = event.target.closest('.ks-qe-save');
		if (save) { saveQuickEdit(save.closest('tr')); }
	});
	document.addEventListener('keydown', function (event) {
		var editor = event.target.closest && event.target.closest('.ks-quickedit-row');
		if (!editor) { return; }
		if (event.key === 'Escape') { closeQuickEdit(editor.previousElementSibling); }
		if (event.key === 'Enter' && event.target.tagName !== 'BUTTON' && event.target.tagName !== 'SELECT') {
			event.preventDefault();
			saveQuickEdit(editor);
		}
	});
	function saveQuickEdit(editor) {
		var row = editor.previousElementSibling;
		var data = { id: row.getAttribute('data-id') };
		editor.querySelectorAll('[data-field]').forEach(function (input) {
			data[input.getAttribute('data-field')] = input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value;
		});
		var spinner = editor.querySelector('.spinner');
		var error = editor.querySelector('.ks-quickedit__error');
		error.textContent = '';
		spinner.classList.add('is-active');
		post('kontor_shop_product_quick_save', data).then(function (result) {
			spinner.classList.remove('is-active');
			if (!result.success) {
				error.textContent = (result.data && result.data.message) || t('saveError', 'Saving failed.');
				return;
			}
			var holder = document.createElement('tbody');
			holder.innerHTML = result.data.row;
			var fresh = holder.firstElementChild;
			editor.remove();
			row.replaceWith(fresh);
			fresh.classList.add('ks-flash');
			notify(t('saved', 'Saved.'), 'success');
		});
	}

	// Ungespeicherte Aenderungen in Formularen
	document.querySelectorAll('form[data-ks-dirty]').forEach(function (form) {
		var dirty = false;
		var flag = form.querySelector('[data-ks-dirty-flag]');
		var mark = function () {
			dirty = true;
			if (flag) { flag.hidden = false; }
		};
		form.addEventListener('input', mark);
		form.addEventListener('change', mark);
		form.addEventListener('submit', function () { dirty = false; });
		window.addEventListener('beforeunload', function (event) {
			if (dirty) { event.preventDefault(); event.returnValue = ''; }
		});
	});

	// Umsortieren (Zeilen ziehen oder Pfeiltasten-Buttons)
	document.querySelectorAll('table[data-ks-sortable]').forEach(function (table) {
		var entity = table.getAttribute('data-ks-sortable');
		var body = table.tBodies[0];
		var dragged = null;
		var save = function () {
			var ids = Array.prototype.map.call(body.querySelectorAll('tr[data-id]'), function (row) { return row.getAttribute('data-id'); });
			post('kontor_shop_reorder', { entity: entity, ids: ids }).then(function (result) {
				notify(result.success ? t('orderSaved', 'Sort order saved.') : t('saveError', 'Saving failed.'), result.success ? 'success' : 'error');
			});
		};
		body.addEventListener('dragstart', function (event) {
			dragged = event.target.closest('tr[data-id]');
			if (dragged) { dragged.classList.add('ks-is-dragging'); event.dataTransfer.effectAllowed = 'move'; }
		});
		body.addEventListener('dragover', function (event) {
			if (!dragged) { return; }
			event.preventDefault();
			var over = event.target.closest('tr[data-id]');
			body.querySelectorAll('.ks-drop-target').forEach(function (row) { row.classList.remove('ks-drop-target'); });
			if (over && over !== dragged) { over.classList.add('ks-drop-target'); }
		});
		body.addEventListener('drop', function (event) {
			var over = event.target.closest('tr[data-id]');
			if (!dragged || !over || over === dragged) { return; }
			event.preventDefault();
			var after = dragged.compareDocumentPosition(over) & Node.DOCUMENT_POSITION_FOLLOWING;
			over.parentNode.insertBefore(dragged, after ? over.nextSibling : over);
			save();
		});
		body.addEventListener('dragend', function () {
			if (dragged) { dragged.classList.remove('ks-is-dragging'); }
			body.querySelectorAll('.ks-drop-target').forEach(function (row) { row.classList.remove('ks-drop-target'); });
			dragged = null;
		});
		body.addEventListener('click', function (event) {
			var button = event.target.closest('[data-ks-move]');
			if (!button) { return; }
			var row = button.closest('tr');
			var up = button.getAttribute('data-ks-move') === 'up';
			var sibling = up ? row.previousElementSibling : row.nextElementSibling;
			if (!sibling) { return; }
			up ? body.insertBefore(row, sibling) : body.insertBefore(sibling, row);
			button.focus();
			save();
		});
	});

	// Bestand je Variante: Summe der eingetragenen Mengen
	document.addEventListener('input', function (event) {
		var form = event.target.closest('[data-ks-variant-booking]');
		if (!form) { return; }
		var sum = 0;
		form.querySelectorAll('input[name^="quantities["]').forEach(function (input) { sum += Math.abs(parseInt(input.value, 10) || 0); });
		var out = form.querySelector('[data-ks-booking-sum]');
		if (out) { out.textContent = String(sum); }
	});

	// Dialoge (z. B. "Versand erfassen"): oeffnen, schliessen, Klick auf den Hintergrund schliesst
	document.addEventListener('click', function (event) {
		var opener = event.target.closest('[data-ks-dialog-open]');
		if (opener) {
			var dialog = document.getElementById(opener.getAttribute('data-ks-dialog-open'));
			if (dialog && typeof dialog.showModal === 'function') {
				event.preventDefault();
				dialog.showModal();
				var wizard = dialog.querySelector('[data-ks-ship-wizard]');
				if (wizard) { shipWizardReset(wizard); return; }
				var first = dialog.querySelector('input[type="text"], select, input[type="checkbox"]');
				if (first) { first.focus(); }
			}
			return;
		}
		if (event.target.closest('[data-ks-dialog-close]')) {
			var open = event.target.closest('dialog');
			if (open) { open.close(); }
			return;
		}
		if (event.target.tagName === 'DIALOG' && event.target.classList.contains('ks-dialog')) {
			event.target.close();
		}
	});

	// Dialog "Artikel stornieren": Rueckzahlung bzw. neuen Betrag live vorschlagen
	document.addEventListener('change', function (event) {
		var form = event.target.closest('[data-ks-cancel-items]');
		if (!form || !event.target.matches('input[name="cancel_items[]"]')) { return; }
		var checked = form.querySelectorAll('input[name="cancel_items[]"]:checked');
		var sum = 0;
		checked.forEach(function (box) { sum += parseFloat(box.getAttribute('data-amount')) || 0; });
		if (checked.length > 0 && checked.length === parseInt(form.getAttribute('data-active'), 10)) {
			sum += parseFloat(form.getAttribute('data-shipping')) || 0;
		}
		var fmt = function (value) { return value.toFixed(2).replace('.', ','); };
		var refund = form.querySelector('[data-ks-refund]');
		if (refund) { refund.value = fmt(Math.min(sum, parseFloat(form.getAttribute('data-max')) || sum)); }
		var totalBox = form.querySelector('[data-ks-new-total]');
		if (totalBox) {
			var total = Math.max(0, (parseFloat(totalBox.getAttribute('data-ks-new-total')) || 0) - sum);
			totalBox.querySelector('[data-ks-new-total-value]').textContent = fmt(total) + ' €';
		}
	});

	// Dialog "Retoure erfassen": Rueckzahlung bzw. neuen Rechnungsbetrag live vorschlagen
	document.addEventListener('input', function (event) {
		var form = event.target.closest('[data-ks-return]');
		if (!form || !event.target.matches('[data-ks-return-qty]')) { return; }
		var sum = 0;
		var units = 0;
		form.querySelectorAll('[data-ks-return-qty]').forEach(function (field) {
			var max = parseInt(field.getAttribute('max'), 10) || 0;
			var qty = Math.min(Math.max(parseInt(field.value, 10) || 0, 0), max);
			units += qty;
			sum += qty * (parseFloat(field.getAttribute('data-unit')) || 0);
		});
		// Alles zurueck (und nichts mehr offen): Versand wird mit erstattet
		if (units > 0 && units === parseInt(form.getAttribute('data-remaining'), 10)) {
			sum += parseFloat(form.getAttribute('data-shipping')) || 0;
		}
		var fmt = function (value) { return value.toFixed(2).replace('.', ','); };
		var refund = form.querySelector('[data-ks-refund]');
		if (refund) { refund.value = fmt(Math.min(sum, parseFloat(form.getAttribute('data-max')) || sum)); }
		var totalBox = form.querySelector('[data-ks-new-total]');
		if (totalBox) {
			var total = Math.max(0, (parseFloat(totalBox.getAttribute('data-ks-new-total')) || 0) - sum);
			totalBox.querySelector('[data-ks-new-total-value]').textContent = fmt(total) + ' €';
		}
	});

	// Assistent "Versand erfassen": 1. Lager ankreuzen, 2. Artikel/Tracking je Lager, 3. pruefen
	function shipWizardText(form, key) {
		var tpl = form.querySelector('template[data-ks-i18n]');
		try { return (JSON.parse(tpl ? tpl.innerHTML : '{}') || {})[key] || key; } catch (e) { return key; }
	}
	// Ohne Teilversand mit nur einem Lager beginnt der Assistent bei Schritt 2.
	function shipWizardFirst(form) {
		return parseInt(form.getAttribute('data-first-step') || '1', 10);
	}
	// Artikel, die mitgehen: angehakte Checkboxen - oder alle, wenn es keine Haekchen gibt (kein Teilversand).
	function shipWizardIncluded(box) {
		return box.tagName !== 'INPUT' || box.checked;
	}
	function shipWizardChosen(form) {
		return Array.prototype.slice.call(form.querySelectorAll('input[name="ks_wh_choice"]:checked'));
	}
	function shipWizardSelect(form, ids) {
		form.querySelector('[data-ks-ship-warehouse]').value = ids.join(',');
		form.querySelectorAll('.ks-ship-group[data-wh]').forEach(function (group) {
			var active = ids.indexOf(group.getAttribute('data-wh')) !== -1;
			group.hidden = !active;
			group.querySelectorAll('input, select').forEach(function (field) { field.disabled = !active; });
		});
	}
	function shipWizardGo(form, step) {
		form.setAttribute('data-step-current', String(step));
		form.querySelectorAll('.ks-wizard-step').forEach(function (section) { section.hidden = section.getAttribute('data-step') !== String(step); });
		form.querySelectorAll('[data-step-pill]').forEach(function (pill) {
			var n = parseInt(pill.getAttribute('data-step-pill'), 10);
			pill.classList.toggle('is-current', n === step);
			pill.classList.toggle('is-done', n < step);
		});
		form.querySelector('[data-ks-wizard-back]').hidden = step <= shipWizardFirst(form);
		form.querySelector('[data-ks-wizard-next]').hidden = step === 3;
		form.querySelector('[data-ks-wizard-submit]').hidden = step !== 3;
		form.querySelector('[data-ks-wizard-error]').hidden = true;
		var focus = form.querySelector('.ks-wizard-step[data-step="' + step + '"] input:not([disabled]), [data-ks-wizard-' + (step === 3 ? 'submit' : 'next') + ']');
		if (focus) { focus.focus(); }
	}
	function shipWizardError(form, message) {
		var error = form.querySelector('[data-ks-wizard-error]');
		error.textContent = message;
		error.hidden = false;
	}
	function shipWizardSummary(form) {
		var esc = function (text) { var d = document.createElement('div'); d.textContent = text; return d.innerHTML; };
		var list = function (boxes) {
			return boxes.length ? '<ul>' + boxes.map(function (box) { return '<li>' + esc(box.getAttribute('data-ks-item-label')) + '</li>'; }).join('') + '</ul>' : '–';
		};
		var chosen = shipWizardChosen(form);
		var html = chosen.length > 1 ? '<p class="ks-ship-summary__warn">' + esc(shipWizardText(form, 'several').replace('%d', chosen.length)) + '</p>' : '';
		chosen.forEach(function (radio) {
			var group = form.querySelector('.ks-ship-group[data-wh="' + radio.value + '"]');
			var boxes = Array.prototype.slice.call(group.querySelectorAll('[data-ks-item-label]'));
			var rows = [];
			rows.push([shipWizardText(form, 'items'), list(boxes.filter(shipWizardIncluded))]);
			var open = boxes.filter(function (b) { return !shipWizardIncluded(b); });
			if (open.length) { rows.push([shipWizardText(form, 'open'), list(open)]); }
			var carrier = group.querySelector('[data-ks-carrier]');
			if (carrier) {
				var tracking = group.querySelector('[data-ks-tracking]').value.trim();
				rows.push([shipWizardText(form, 'carrier'), esc(carrier.options[carrier.selectedIndex].text)]);
				rows.push([shipWizardText(form, 'tracking'), tracking ? '<strong>' + esc(tracking) + '</strong>' : '<em>' + esc(shipWizardText(form, 'noTracking')) + '</em>']);
			}
			var whClass = esc(radio.getAttribute('data-ks-wh-class') || 'ks-wh--0');
			html += '<div class="ks-ship-summary__block ' + whClass + '">'
				+ '<p><span class="ks-wh-chip ' + whClass + '">' + esc(shipWizardText(form, 'warehouse') + ' ' + radio.getAttribute('data-ks-wh-name')) + '</span></p>'
				+ '<dl>' + rows.map(function (row) { return '<dt>' + esc(row[0]) + '</dt><dd>' + row[1] + '</dd>'; }).join('') + '</dl></div>';
		});
		var common = [];
		var note = form.querySelector('[data-ks-note]').value.trim();
		if (note) { common.push([shipWizardText(form, 'note'), esc(note)]); }
		common.push([shipWizardText(form, 'mail'), esc(shipWizardText(form, form.querySelector('[data-ks-notify]').checked ? 'yes' : 'no'))]);
		html += '<dl>' + common.map(function (row) { return '<dt>' + esc(row[0]) + '</dt><dd>' + row[1] + '</dd>'; }).join('') + '</dl>';
		form.querySelector('[data-ks-summary]').innerHTML = html;
	}
	function shipWizardNext(form) {
		var step = parseInt(form.getAttribute('data-step-current') || '1', 10);
		var chosen = shipWizardChosen(form);
		if (step === 1) {
			if (!chosen.length) { shipWizardError(form, shipWizardText(form, 'pickWh')); return; }
			shipWizardSelect(form, chosen.map(function (box) { return box.value; }));
			shipWizardGo(form, 2);
			return;
		}
		if (step === 2) {
			for (var i = 0; i < chosen.length; i++) {
				var items = Array.prototype.slice.call(form.querySelectorAll('.ks-ship-group[data-wh="' + chosen[i].value + '"] [data-ks-item-label]'));
				if (!items.some(shipWizardIncluded)) {
					shipWizardError(form, shipWizardText(form, 'pickItem').replace('%s', chosen[i].getAttribute('data-ks-wh-name')));
					return;
				}
			}
			shipWizardSummary(form);
			shipWizardGo(form, 3);
		}
	}
	function shipWizardReset(form) {
		var preset = form.getAttribute('data-preset');
		form.querySelectorAll('input[name="ks_wh_choice"]').forEach(function (box) { box.checked = preset === 'all' || box.value === preset; });
		form.querySelectorAll('.ks-ship-group[data-wh]').forEach(function (group) {
			group.hidden = true;
			group.querySelectorAll('input, select').forEach(function (field) { field.disabled = true; });
			group.querySelectorAll('input[name="items[]"]').forEach(function (box) { box.checked = true; });
		});
		form.querySelector('[data-ks-ship-warehouse]').value = '';
		if (shipWizardFirst(form) === 2) {
			shipWizardSelect(form, shipWizardChosen(form).map(function (box) { return box.value; }));
			shipWizardGo(form, 2);
			return;
		}
		shipWizardGo(form, 1);
	}
	document.addEventListener('click', function (event) {
		var form = event.target.closest('[data-ks-ship-wizard]');
		if (!form) { return; }
		if (event.target.closest('[data-ks-wizard-next]')) { shipWizardNext(form); }
		if (event.target.closest('[data-ks-wizard-back]')) {
			shipWizardGo(form, Math.max(shipWizardFirst(form), parseInt(form.getAttribute('data-step-current') || '1', 10) - 1));
		}
	});
	// Enter (z. B. im Trackingfeld) geht einen Schritt weiter statt vorzeitig zu speichern
	document.addEventListener('submit', function (event) {
		var form = event.target;
		if (!form.hasAttribute || !form.hasAttribute('data-ks-ship-wizard')) { return; }
		if (form.getAttribute('data-step-current') !== '3') {
			event.preventDefault();
			shipWizardNext(form);
		}
	});

	// E-Mail-Vorlagen: Platzhalter per Klick einfuegen (Betreff oder Editor, je nachdem was zuletzt aktiv war)
	var tplTarget = 'editor';
	document.addEventListener('focusin', function (event) {
		if (event.target.matches && event.target.matches('[data-ks-tpl-target]')) { tplTarget = event.target; }
		else if (event.target.id === 'ks_tpl_body') { tplTarget = 'editor'; }
	});
	document.addEventListener('click', function (event) {
		var chip = event.target.closest('[data-ks-placeholder]');
		if (!chip) { return; }
		event.preventDefault();
		var token = chip.getAttribute('data-ks-placeholder');
		var id = chip.getAttribute('data-ks-editor');
		var insertInto = function (field) {
			var start = field.selectionStart || 0;
			var end = field.selectionEnd || 0;
			field.value = field.value.slice(0, start) + token + field.value.slice(end);
			field.focus();
			field.selectionStart = field.selectionEnd = start + token.length;
			field.dispatchEvent(new Event('change', { bubbles: true }));
		};
		if (tplTarget !== 'editor' && tplTarget && tplTarget.isConnected) { insertInto(tplTarget); return; }
		var editor = window.tinymce && window.tinymce.get(id);
		var textarea = document.getElementById(id);
		if (editor && !editor.isHidden()) {
			editor.focus();
			editor.insertContent(token);
			editor.fire('change');
		} else if (textarea) {
			insertInto(textarea);
		}
	});
	// Sicherheitsabfrage fuer einzelne Buttons (z. B. "Auf Standard zuruecksetzen")
	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-ks-confirm-click]');
		if (button && !window.confirm(button.getAttribute('data-ks-confirm-click'))) { event.preventDefault(); }
	});
	document.addEventListener('change', function (event) {
		if (!event.target.matches('[data-ks-tpl-enabled]')) { return; }
		var warning = document.querySelector('[data-ks-tpl-off-warning]');
		if (warning) { warning.hidden = event.target.checked; }
	});

	// Listen filtern (z. B. Empfaenger-Benutzer in den E-Mail-Vorlagen)
	document.addEventListener('input', function (event) {
		var input = event.target.closest('[data-ks-filter-list]');
		if (!input) { return; }
		var needle = input.value.toLowerCase();
		document.querySelectorAll(input.getAttribute('data-ks-filter-list') + ' li').forEach(function (row) {
			row.hidden = needle !== '' && row.textContent.toLowerCase().indexOf(needle) === -1;
		});
	});

	// Benutzerliste filtern (Einstellungen -> Rollen)
	document.addEventListener('input', function (event) {
		var input = event.target.closest('[data-ks-filter]');
		if (!input) { return; }
		var needle = input.value.toLowerCase();
		document.querySelectorAll(input.getAttribute('data-ks-filter') + ' tbody tr').forEach(function (row) {
			row.hidden = needle !== '' && row.textContent.toLowerCase().indexOf(needle) === -1;
		});
	});
})();
