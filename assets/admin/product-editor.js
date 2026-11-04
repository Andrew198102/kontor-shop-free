/**
 * Artikelformular: Varianten (Merkmale -> Kombinationstabelle), Bildergalerie
 * und Grundpreis-Vorschau. Bewusst ohne Build-Schritt. Massgeblich ist der
 * Server (VariantRepository::sync()) - dieses Skript baut die Tabelle nur fuer
 * die Eingabe auf und behaelt dabei bereits getippte Werte bei.
 */
(function () {
	'use strict';

	var cfg = window.kontorShopProductEditor || { i18n: {}, maxCombinations: 200, units: {} };
	var t = function (key, fallback) { return (cfg.i18n && cfg.i18n[key]) || fallback; };

	/* ---------- reine Hilfsfunktionen (spiegeln Domain\Catalog\VariantMatrix) ---------- */

	function clean(value) {
		return String(value || '').replace(/\s+/g, ' ').trim().slice(0, 100);
	}

	function fold(value) {
		return clean(value).toLowerCase();
	}

	function parseValues(text) {
		var seen = {};
		// Komma zwischen zwei Ziffern = Dezimalkomma ("0,5 l"), reine Zahlenlisten ("38,39,40") werden getrennt
		var parts = [];
		String(text || '').split(/[;\r\n]+|(?<!\d),|,(?!\d)/).forEach(function (part) {
			if (/^\s*\d+(?:,\d+){2,}\s*$/.test(part)) {
				parts.push.apply(parts, part.split(','));
			} else {
				parts.push(part);
			}
		});
		return parts.map(clean).filter(function (value) {
			var key = fold(value);
			if (!value || seen[key]) { return false; }
			seen[key] = true;
			return true;
		});
	}

	function combinations(options) {
		if (!options.length) { return []; }
		var result = [[]];
		options.forEach(function (option) {
			var next = [];
			result.forEach(function (prefix) {
				option.values.forEach(function (value) { next.push(prefix.concat([value])); });
			});
			result = next;
		});
		return result;
	}

	function keyOf(values) {
		return values.map(fold).filter(Boolean).join('\u001F');
	}

	function num(value) {
		var n = parseFloat(String(value || '').replace(',', '.'));
		return isNaN(n) ? 0 : n;
	}

	function money(value) {
		return value.toFixed(2).replace('.', ',');
	}

	/** Inhalt aus einem Variantenwert ("0,5 l", "500 g"), umgerechnet in die Einheit des Artikels. */
	function amountFromValues(values, unit) {
		var target = UNIT[unit];
		if (!target) { return null; }
		for (var i = 0; i < values.length; i++) {
			var m = /^\s*(\d+(?:[.,]\d+)?)\s*(g|kg|ml|cl|l|cm|m|m2|m²)\s*$/i.exec(values[i]);
			if (!m) { continue; }
			var source = UNIT[m[2].toLowerCase().replace('²', '2')];
			if (!source || source[0] !== target[0]) { continue; }
			var amount = num(m[1]) / source[1] * target[1];
			return amount > 0 ? Math.round(amount * 1000) / 1000 : null;
		}
		return null;
	}

	/* ---------- Grundpreis-Vorschau ---------- */

	var UNIT = { g: ['kg', 1000], kg: ['kg', 1], ml: ['l', 1000], cl: ['l', 100], l: ['l', 1], cm: ['m', 100], m: ['m', 1], m2: ['m²', 1] };

	function unitPreview() {
		var out = document.getElementById('ks-unit-preview');
		var amount = document.getElementById('ks-p-unit-amount');
		var unit = document.getElementById('ks-p-unit');
		var price = document.getElementById('ks-p-price');
		var table = document.querySelector('[data-ks-variants]');
		if (!out || !amount || !unit || !price) { return; }

		var u = UNIT[unit.value];
		var a = num(amount.value);

		if (table) {
			table.classList.toggle('has-unit', !!u);
			var label = table.querySelector('[data-ks-unit-label]');
			if (label) { label.textContent = u ? '(' + (cfg.units[unit.value] || unit.value) + ')' : ''; }

			// Platzhalter "Inhalt": aus dem Variantennamen, sonst Inhalt des Artikels
			table.querySelectorAll('[data-ks-variant-row]').forEach(function (row) {
				var input = row.querySelector('[data-ks-amount]');
				if (!input) { return; }
				var values = Array.prototype.map.call(row.querySelectorAll('[data-ks-variant-values] input'), function (el) { return el.value; });
				var derived = u ? amountFromValues(values, unit.value) : null;
				input.placeholder = derived !== null ? String(derived).replace('.', ',') : amount.value;
				input.title = derived !== null ? t('amountAuto', 'Automatically from the variant name') : '';
			});
		}

		// Tipp: Varianten heissen nach Inhalt (0,5 l ...), aber keine Einheit gewaehlt
		var hint = document.getElementById('ks-unit-hint');
		if (hint && table) {
			var looksLikeAmounts = Array.prototype.some.call(table.querySelectorAll('[data-ks-variant-values] input'), function (el) {
				return /^\s*\d+(?:[.,]\d+)?\s*(g|kg|ml|cl|l|cm|m|m2|m²)\s*$/i.test(el.value);
			});
			hint.hidden = !!u || !looksLikeAmounts || table.querySelector('[data-ks-variants-setup]').hidden;
		}

		if (!u || a <= 0) { out.textContent = ''; return; }

		var inRef = a / u[1];
		if (Math.abs(inRef - 1) < 0.000001) {
			out.textContent = t('unitSame', 'Unit price = selling price');
			return;
		}

		out.textContent = t('unitPrice', 'Unit price:') + ' ' + money(num(price.value) / inRef) + ' € / ' + u[0];
	}

	/* ---------- Varianten ---------- */

	function initVariants(card) {
		var toggle = card.querySelector('[data-ks-variants-toggle]');
		var setup = card.querySelector('[data-ks-variants-setup]');
		var body = card.querySelector('[data-ks-variant-rows]');
		var table = card.querySelector('[data-ks-variant-table]');
		var template = card.querySelector('[data-ks-variant-template]');
		var counter = card.querySelector('[data-ks-variants-count]');
		var scope = card.querySelector('[data-ks-bulk-scope]');
		var activeAll = card.querySelector('[data-ks-active-all]');
		var nextIndex = body.querySelectorAll('[data-ks-variant-row]').length;
		var simpleOnly = document.querySelectorAll('[data-ks-simple-only]');
		var variantsOnly = document.querySelectorAll('[data-ks-variants-only]');
		var addOption = card.querySelector('[data-ks-option-add]');
		var optionRows = Array.prototype.slice.call(card.querySelectorAll('[data-ks-option]'));
		var renamedNote = '';

		function valuesInput(optionRow) {
			return optionRow.querySelectorAll('input')[1];
		}

		function readOptions() {
			var options = [];
			var names = {};
			optionRows.forEach(function (row) {
				var inputs = row.querySelectorAll('input');
				var name = clean(inputs[0].value) || t('defaultName', 'Variant');
				var values = parseValues(inputs[1].value);
				if (!values.length || names[fold(name)]) { return; }
				names[fold(name)] = true;
				options.push({ name: name, values: values.slice(0, 30) });
			});
			return options;
		}

		function rows() {
			return Array.prototype.slice.call(body.querySelectorAll('[data-ks-variant-row]'));
		}

		function rowValues(row) {
			return Array.prototype.map.call(row.querySelectorAll('[data-ks-variant-values] input'), function (el) { return el.value; });
		}

		function setRowValues(row, values) {
			var hidden = row.querySelector('[data-ks-variant-values]');
			var base = row.querySelector('input[name$="[sku]"]').name.replace(/\[sku\]$/, '');
			row.querySelector('[data-ks-variant-label]').textContent = values.join(' / ');
			row.setAttribute('data-key', keyOf(values));
			hidden.innerHTML = '';
			values.forEach(function (value) {
				var input = document.createElement('input');
				input.type = 'hidden';
				input.name = base + '[values][]';
				input.value = value;
				hidden.appendChild(input);
			});
		}

		function hasSavedVariants() {
			return rows().some(function (row) {
				var id = row.querySelector('[data-ks-variant-id-field]');
				return id && parseInt(id.value, 10) > 0;
			});
		}

		function syncToggle() {
			setup.hidden = !toggle.checked;
			card.classList.toggle('is-on', toggle.checked);
			simpleOnly.forEach(function (el) { el.hidden = toggle.checked; });
			variantsOnly.forEach(function (el) { el.hidden = !toggle.checked; });
			refreshAddOption();
			unitPreview();
		}

		/** "+ weiteres Merkmal" nur, solange noch ein ausgeblendetes Merkmal frei ist. */
		function refreshAddOption() {
			if (addOption) { addOption.hidden = !card.querySelector('[data-ks-option-spare][hidden]'); }
		}

		/** Auswahl "Preis für ..." aus den aktuellen Merkmalen. */
		function refreshScope() {
			var current = scope.value;
			scope.innerHTML = '';
			var all = document.createElement('option');
			all.value = '';
			all.textContent = t('scopeAll', 'all variants');
			scope.appendChild(all);
			readOptions().forEach(function (option, index) {
				option.values.forEach(function (value) {
					var el = document.createElement('option');
					el.value = index + '\u001F' + fold(value);
					el.textContent = option.name + ': ' + value;
					scope.appendChild(el);
				});
			});
			scope.value = current;
			if (scope.value !== current) { scope.value = ''; }
		}

		function refreshActiveAll() {
			var boxes = body.querySelectorAll('input[type="checkbox"][name$="[active]"]');
			var checked = body.querySelectorAll('input[type="checkbox"][name$="[active]"]:checked');
			activeAll.checked = boxes.length > 0 && checked.length === boxes.length;
			activeAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
		}

		function build() {
			var options = readOptions();
			var combos = combinations(options);
			var existing = {};

			rows().forEach(function (row) {
				existing[row.getAttribute('data-key')] = row;
			});

			if (combos.length > cfg.maxCombinations) {
				counter.textContent = t('tooMany', 'Too many combinations: %1$d (at most %2$s).').replace('%1$d', combos.length).replace('%2$s', cfg.maxCombinations);
				counter.classList.add('ks-stock--out');
				return;
			}

			counter.classList.remove('ks-stock--out');

			var fragment = document.createDocumentFragment();

			combos.forEach(function (values) {
				var key = keyOf(values);
				var row = existing[key];

				if (row) {
					delete existing[key];
				} else {
					var holder = document.createElement('tbody');
					holder.innerHTML = template.innerHTML.replace(/__INDEX__/g, 'n' + (nextIndex++));
					row = holder.firstElementChild;
				}

				// Werte (Schreibweise) immer aus den Merkmalen uebernehmen
				setRowValues(row, values);
				fragment.appendChild(row);
			});

			body.innerHTML = '';
			body.appendChild(fragment);

			var dropped = Object.keys(existing).length;
			counter.textContent = combos.length
				? t('count', '%d variants').replace('%d', combos.length)
					+ (dropped ? ' · ' + t('dropped', '%d will be removed when saving').replace('%d', dropped) : '')
					+ (renamedNote ? ' · ' + renamedNote : '')
				: t('none', 'Please enter attributes with values.');
			renamedNote = '';

			refreshScope();
			refreshActiveAll();
			unitPreview();
		}

		/**
		 * Umbenennen erkennen: gleiche Anzahl Werte, und an einer Stelle steht ein
		 * neuer Wert statt eines weggefallenen ("Rot" -> "Dunkelrot"). Die Zeilen
		 * behalten dann ihre Variante (ID) - samt Bestand, Preis und Bild.
		 * Reines Umsortieren ist kein Umbenennen.
		 */
		function applyRenames(optionRow) {
			var input = valuesInput(optionRow);
			var before = input.ksBefore || [];
			var after = parseValues(input.value);
			input.ksBefore = after;

			if (!before.length || before.length !== after.length) { return; }

			var beforeSet = {};
			var afterSet = {};
			before.forEach(function (v) { beforeSet[fold(v)] = true; });
			after.forEach(function (v) { afterSet[fold(v)] = true; });

			var map = {};
			var names = [];
			before.forEach(function (oldValue, i) {
				if (!afterSet[fold(oldValue)] && !beforeSet[fold(after[i])]) {
					map[fold(oldValue)] = after[i];
					names.push('„' + oldValue + '“ → „' + after[i] + '“');
				}
			});

			if (!names.length) { return; }

			// Position dieses Merkmals unter den Merkmalen mit Werten
			var position = 0;
			for (var i = 0; i < optionRows.length && optionRows[i] !== optionRow; i++) {
				if ((valuesInput(optionRows[i]).ksBefore || []).length) { position++; }
			}

			rows().forEach(function (row) {
				var values = rowValues(row);
				var replacement = map[fold(values[position] || '')];
				if (replacement !== undefined) {
					values[position] = replacement;
					setRowValues(row, values);
				}
			});

			renamedNote = t('renamed', 'renamed: %s (stock is kept)').replace('%s', names.join(', '));
		}

		optionRows.forEach(function (row) {
			valuesInput(row).ksBefore = parseValues(valuesInput(row).value);
		});

		toggle.addEventListener('change', function () {
			if (!toggle.checked && hasSavedVariants() && !window.confirm(t('confirmOff', 'Really turn off variants?'))) {
				toggle.checked = true;
				return;
			}
			syncToggle();
			if (toggle.checked && !body.children.length) { build(); }
		});

		card.addEventListener('click', function (event) {
			var preset = event.target.closest('[data-ks-preset]');
			if (preset) {
				event.preventDefault();
				var row = preset.closest('[data-ks-option]');
				var inputs = row.querySelectorAll('input');
				if (!clean(inputs[0].value)) { inputs[0].value = preset.getAttribute('data-ks-preset-name'); }
				inputs[1].value = preset.getAttribute('data-ks-preset');
				inputs[1].ksBefore = parseValues(inputs[1].value);
				build();
				return;
			}

			if (event.target.closest('[data-ks-option-add]')) {
				event.preventDefault();
				var spare = card.querySelector('[data-ks-option-spare][hidden]');
				if (spare) {
					spare.hidden = false;
					spare.querySelector('input').focus();
				}
				refreshAddOption();
				return;
			}

			if (event.target.closest('[data-ks-variants-build]')) {
				event.preventDefault();
				build();
				return;
			}

			if (event.target.closest('[data-ks-bulk-apply]') || event.target.closest('[data-ks-bulk-clear]')) {
				event.preventDefault();
				var clear = !!event.target.closest('[data-ks-bulk-clear]');
				var priceInput = card.querySelector('[data-ks-bulk-price]');
				var value = clear ? '' : clean(priceInput.value);
				if (!clear && !value) { priceInput.focus(); return; }
				var parts = scope.value ? scope.value.split('\u001F') : null;
				var changed = 0;
				rows().forEach(function (row) {
					if (parts && fold(rowValues(row)[parseInt(parts[0], 10)] || '') !== parts[1]) { return; }
					row.querySelector('input[name$="[price]"]').value = value;
					changed++;
				});
				counter.textContent = t('bulkDone', 'Price set for %d variants.').replace('%d', changed);
				return;
			}

			if (event.target.closest('[data-ks-bulk-sku]')) {
				event.preventDefault();
				var skuField = document.getElementById('ks-p-sku');
				var nameField = document.getElementById('ks-p-name');
				var base = clean(skuField && skuField.value) || clean(nameField && nameField.value).slice(0, 12);
				var filled = 0;
				rows().forEach(function (row) {
					var input = row.querySelector('input[name$="[sku]"]');
					if (clean(input.value)) { return; }
					input.value = [base].concat(rowValues(row)).map(skuPart).filter(Boolean).join('-').slice(0, 64);
					filled++;
				});
				counter.textContent = t('skuDone', '%d SKUs generated.').replace('%d', filled);
			}
		});

		card.addEventListener('change', function (event) {
			var optionRow = event.target.closest('[data-ks-option]');
			if (optionRow) {
				if (event.target === valuesInput(optionRow)) { applyRenames(optionRow); }
				build();
				return;
			}

			if (event.target.matches('[data-ks-active-all]')) {
				body.querySelectorAll('input[type="checkbox"][name$="[active]"]').forEach(function (box) { box.checked = event.target.checked; });
				return;
			}

			if (event.target.matches('input[type="checkbox"][name$="[active]"]')) {
				refreshActiveAll();
				return;
			}

			if (event.target.matches('[data-ks-gtin-toggle]')) {
				table.classList.toggle('show-gtin', event.target.checked);
			}
		});

		// Enter in einem Merkmals- oder Sammelfeld soll nicht das ganze Formular absenden
		card.addEventListener('keydown', function (event) {
			if (event.key !== 'Enter') { return; }
			var optionRow = event.target.closest('[data-ks-option]');
			if (optionRow) {
				event.preventDefault();
				if (event.target === valuesInput(optionRow)) { applyRenames(optionRow); }
				build();
			} else if (event.target.matches('[data-ks-bulk-price]')) {
				event.preventDefault();
				card.querySelector('[data-ks-bulk-apply]').click();
			}
		});

		// Platzhalter "Artikelpreis" in den Preisfeldern aktuell halten
		var price = document.getElementById('ks-p-price');
		if (price) {
			price.addEventListener('input', function () {
				card.querySelectorAll('[data-ks-price-placeholder]').forEach(function (input) {
					input.placeholder = price.value;
				});
			});
		}

		refreshScope();
		refreshActiveAll();
		syncToggle();
	}

	/** Teil einer Artikelnummer: Grossbuchstaben/Ziffern, Umlaute umschrieben ("Grün" -> "GRUEN", "0,5 l" -> "05L"). */
	function skuPart(value) {
		return clean(value).toUpperCase()
			.replace(/Ä/g, 'AE').replace(/Ö/g, 'OE').replace(/Ü/g, 'UE').replace(/ß/g, 'SS')
			.replace(/[^A-Z0-9]+/g, '');
	}

	/* ---------- Bildauswahl ueber die Mediathek ---------- */

	function openLibrary(multiple, onSelect) {
		if (!window.wp || !window.wp.media) { return; }
		var frame = window.wp.media({
			title: multiple ? t('galleryTitle', 'Add images') : t('variantImageTitle', 'Image for the variant'),
			library: { type: 'image' },
			multiple: multiple ? 'add' : false
		});
		frame.on('select', function () {
			onSelect(frame.state().get('selection').toJSON());
		});
		frame.open();
	}

	function thumbUrl(attachment) {
		var sizes = attachment.sizes || {};
		return (sizes.thumbnail && sizes.thumbnail.url) || (sizes.medium && sizes.medium.url) || attachment.url;
	}

	/* ---------- Bild einer Variante: zuerst aus den Bildern des Artikels ---------- */

	var popover = null;

	function closePopover() {
		if (popover) {
			popover.remove();
			popover = null;
		}
	}

	function setVariantImage(box, id, url) {
		var input = box.querySelector('input[type="hidden"]');
		var button = box.querySelector('[data-ks-vimg-pick]');
		var remove = box.querySelector('[data-ks-vimg-clear]');

		input.value = id ? String(id) : '';
		button.innerHTML = '';

		if (id) {
			var img = document.createElement('img');
			img.src = url;
			img.alt = '';
			button.appendChild(img);
		} else {
			button.innerHTML = '<span aria-hidden="true">+</span>';
		}

		remove.hidden = !id;
		box.classList.toggle('has-image', !!id);
	}

	/** Hauptbild + Galerie aus dem Formular (so wie gerade eingestellt, auch ungespeichert). */
	function productImages() {
		var list = [];
		var main = document.querySelector('[data-ks-main-image] .ks-media');

		if (main) {
			var id = main.querySelector('.ks-media__id').value;
			var img = main.querySelector('.ks-media__preview img');
			if (id && img) { list.push({ id: id, url: img.getAttribute('src') }); }
		}

		document.querySelectorAll('[data-ks-gallery-list] .ks-gallery__item').forEach(function (item) {
			var id = item.getAttribute('data-id');
			if (id && id !== '0') { list.push({ id: id, url: item.querySelector('img').getAttribute('src') }); }
		});

		return list;
	}

	function openPopover(box) {
		closePopover();

		var images = productImages();

		if (!images.length) {
			openLibrary(false, function (selection) {
				if (selection.length) { setVariantImage(box, selection[0].id, thumbUrl(selection[0])); }
			});
			return;
		}

		popover = document.createElement('div');
		popover.className = 'ks-vimg-popover';
		popover.setAttribute('role', 'dialog');
		popover.setAttribute('aria-label', t('variantImageTitle', 'Image for the variant'));

		var title = document.createElement('p');
		title.className = 'ks-vimg-popover__title';
		title.textContent = t('fromProduct', 'Choose the product image:');
		popover.appendChild(title);

		var grid = document.createElement('div');
		grid.className = 'ks-vimg-popover__grid';
		images.forEach(function (image) {
			var option = document.createElement('button');
			option.type = 'button';
			option.className = 'ks-vimg-popover__option';
			option.setAttribute('data-id', image.id);
			option.innerHTML = '<img alt="" />';
			option.querySelector('img').src = image.url;
			option.addEventListener('click', function () {
				setVariantImage(box, image.id, image.url);
				closePopover();
				box.querySelector('[data-ks-vimg-pick]').focus();
			});
			grid.appendChild(option);
		});
		popover.appendChild(grid);

		var library = document.createElement('button');
		library.type = 'button';
		library.className = 'button-link';
		library.textContent = t('fromLibrary', 'Another image from the media library …');
		library.addEventListener('click', function () {
			closePopover();
			openLibrary(false, function (selection) {
				if (selection.length) { setVariantImage(box, selection[0].id, thumbUrl(selection[0])); }
			});
		});
		popover.appendChild(library);

		box.appendChild(popover);
		var first = popover.querySelector('button');
		if (first) { first.focus(); }
	}

	document.addEventListener('click', function (event) {
		if (popover && !event.target.closest('.ks-vimg-popover') && !event.target.closest('[data-ks-vimg-pick]')) {
			closePopover();
		}

		var pick = event.target.closest('[data-ks-vimg-pick]');
		var clear = event.target.closest('[data-ks-vimg-clear]');
		if (!pick && !clear) { return; }
		event.preventDefault();
		var box = event.target.closest('[data-ks-vimg]');

		if (clear) {
			closePopover();
			setVariantImage(box, null, '');
			return;
		}

		if (popover && popover.parentNode === box) {
			closePopover();
			return;
		}

		openPopover(box);
	});

	document.addEventListener('keydown', function (event) {
		if (event.key === 'Escape' && popover) {
			var box = popover.parentNode;
			closePopover();
			box.querySelector('[data-ks-vimg-pick]').focus();
		}
	});

	/* ---------- Galerie ---------- */

	function initGallery(root) {
		var list = root.querySelector('[data-ks-gallery-list]');
		var template = root.querySelector('[data-ks-gallery-template]');
		var dragged = null;

		function has(id) {
			return !!list.querySelector('[data-id="' + id + '"]');
		}

		function add(id, url, before) {
			if (!id || has(id)) { return; }
			var holder = document.createElement('ul');
			holder.innerHTML = template.innerHTML;
			var item = holder.firstElementChild;
			item.setAttribute('data-id', String(id));
			item.querySelector('img').src = url;
			item.querySelector('input').value = String(id);
			list.insertBefore(item, before || null);
		}

		function mainPicker() {
			return document.querySelector('[data-ks-main-image] .ks-media');
		}

		root.addEventListener('click', function (event) {
			if (event.target.closest('[data-ks-gallery-add]')) {
				event.preventDefault();
				openLibrary(true, function (selection) {
					var main = mainPicker();
					var mainId = main ? main.querySelector('.ks-media__id').value : '';
					selection.forEach(function (attachment) {
						if (String(attachment.id) !== mainId) { add(attachment.id, thumbUrl(attachment)); }
					});
				});
				return;
			}

			var item = event.target.closest('.ks-gallery__item');
			if (!item) { return; }

			if (event.target.closest('[data-ks-gallery-remove]')) {
				event.preventDefault();
				item.remove();
				return;
			}

			var move = event.target.closest('[data-ks-gallery-move]');
			if (move) {
				event.preventDefault();
				var forward = move.getAttribute('data-ks-gallery-move') === '1';
				var sibling = forward ? item.nextElementSibling : item.previousElementSibling;
				if (sibling) {
					forward ? list.insertBefore(sibling, item) : list.insertBefore(item, sibling);
					move.focus();
				}
				return;
			}

			if (event.target.closest('[data-ks-gallery-main]')) {
				event.preventDefault();
				var main = mainPicker();
				if (!main) { return; }
				var idField = main.querySelector('.ks-media__id');
				var preview = main.querySelector('.ks-media__preview');
				var oldId = idField.value;
				var oldImg = preview.querySelector('img');
				var oldUrl = oldImg ? oldImg.getAttribute('src') : '';
				var newId = item.getAttribute('data-id');
				var newUrl = item.querySelector('img').getAttribute('src');

				// Bisheriges Hauptbild wandert an die Stelle des neuen in die Galerie
				if (oldId && oldId !== newId) { add(oldId, oldUrl, item); }
				item.remove();

				idField.value = newId;
				main.classList.add('has-image');
				preview.innerHTML = '';
				var img = document.createElement('img');
				img.className = 'ks-media__img';
				img.alt = '';
				img.src = newUrl;
				preview.appendChild(img);
				var remove = main.querySelector('.ks-media__remove');
				if (remove) { remove.hidden = false; }
			}
		});

		/* Hochladen: Dateien hineinziehen oder waehlen (REST /wp/v2/media, wie das Hauptbild) */
		var media = window.kontorShopMedia || { i18n: {}, types: [], maxBytes: 0 };
		var status = root.querySelector('[data-ks-gallery-status]');
		var drop = root.querySelector('[data-ks-gallery-drop]');
		var fileInput = root.querySelector('[data-ks-gallery-file]');
		var uploading = 0;

		function say(message, isError) {
			if (!status) { return; }
			status.textContent = message || '';
			status.classList.toggle('is-error', !!isError);
		}

		function upload(file) {
			var type = file.type || '';
			if (media.types && media.types.length && media.types.indexOf(type) === -1) {
				say(file.name + ': ' + (media.i18n.badType || 'File type not supported.'), true);
				return;
			}
			if (media.maxBytes && file.size > media.maxBytes) {
				say(file.name + ': ' + t('tooBig', 'File is too large.'), true);
				return;
			}

			var holder = document.createElement('ul');
			holder.innerHTML = template.innerHTML;
			var item = holder.firstElementChild;
			item.classList.add('is-uploading');
			item.setAttribute('draggable', 'false');
			item.querySelector('input').disabled = true;
			item.querySelector('img').src = URL.createObjectURL(file);
			var bar = document.createElement('span');
			bar.className = 'ks-gallery__progress';
			item.appendChild(bar);
			list.appendChild(item);

			var data = new FormData();
			data.append('file', file, file.name);
			data.append('title', String(file.name).replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' '));
			data.append('kontor_shop_upload', '1');
			var nameField = document.getElementById('ks-p-name');
			if (nameField && nameField.value.trim()) { data.append('alt_text', nameField.value.trim()); }

			var xhr = new XMLHttpRequest();
			uploading++;
			xhr.open('POST', media.restUrl);
			xhr.setRequestHeader('X-WP-Nonce', media.nonce);
			xhr.upload.onprogress = function (e) {
				if (e.lengthComputable) { bar.style.width = Math.round(e.loaded / e.total * 100) + '%'; }
			};
			xhr.onloadend = function () {
				uploading--;
				var body = null;
				try { body = JSON.parse(xhr.responseText); } catch (e) { body = null; }

				if (xhr.status >= 200 && xhr.status < 300 && body && body.id) {
					var sizes = (body.media_details && body.media_details.sizes) || {};
					var url = (sizes.thumbnail && sizes.thumbnail.source_url) || body.source_url;
					item.classList.remove('is-uploading');
					item.setAttribute('draggable', 'true');
					item.setAttribute('data-id', String(body.id));
					item.querySelector('img').src = url;
					var input = item.querySelector('input');
					input.disabled = false;
					input.value = String(body.id);
					bar.remove();
					if (!uploading) { say(t('uploaded', 'Uploaded. Apply with “Save”.'), false); }
					return;
				}

				item.remove();
				say(file.name + ': ' + (body && body.message ? body.message : t('uploadFailed', 'Upload failed.')), true);
			};
			xhr.send(data);
		}

		function handle(files) {
			say(t('uploading', 'Uploading …'), false);
			Array.prototype.forEach.call(files, upload);
		}

		if (drop) {
			root.addEventListener('dragover', function (event) {
				var files = event.dataTransfer && Array.prototype.indexOf.call(event.dataTransfer.types || [], 'Files') !== -1;
				if (!files) { return; }
				event.preventDefault();
				event.dataTransfer.dropEffect = 'copy';
				drop.classList.add('is-dragover');
			});
			root.addEventListener('dragleave', function (event) {
				if (!root.contains(event.relatedTarget)) { drop.classList.remove('is-dragover'); }
			});
			root.addEventListener('drop', function (event) {
				drop.classList.remove('is-dragover');
				if (!event.dataTransfer || !event.dataTransfer.files || !event.dataTransfer.files.length) { return; }
				event.preventDefault();
				handle(event.dataTransfer.files);
			});
			root.querySelector('[data-ks-gallery-upload]').addEventListener('click', function (event) {
				event.preventDefault();
				fileInput.click();
			});
			fileInput.addEventListener('change', function () {
				handle(fileInput.files);
				fileInput.value = '';
			});
		}

		// Speichern waehrend eines Uploads verhindern
		var form = root.closest('form');
		if (form) {
			form.addEventListener('submit', function (event) {
				if (uploading > 0) {
					event.preventDefault();
					say(t('busy', 'Please wait until all images have been uploaded.'), true);
				}
			});
		}

		list.addEventListener('dragstart', function (event) {
			dragged = event.target.closest('.ks-gallery__item');
			if (dragged) { dragged.classList.add('is-dragging'); event.dataTransfer.effectAllowed = 'move'; }
		});
		list.addEventListener('dragover', function (event) {
			if (!dragged) { return; }
			event.preventDefault();
			var over = event.target.closest('.ks-gallery__item');
			if (!over || over === dragged) { return; }
			var rect = over.getBoundingClientRect();
			var after = event.clientX > rect.left + rect.width / 2;
			list.insertBefore(dragged, after ? over.nextSibling : over);
		});
		list.addEventListener('dragend', function () {
			if (dragged) { dragged.classList.remove('is-dragging'); }
			dragged = null;
		});
	}

	/* ---------- Kopfleiste und Seitenleiste ---------- */

	function initChrome(form) {
		var head = form.querySelector('.ks-ed-head');
		if (!head) { return; }

		// Hilfetexte ein-/ausblenden (merkt sich der Browser)
		var help = head.querySelector('[data-ks-help-toggle]');
		var KEY = 'kontorShopShowHelp';
		if (help) {
			try { help.checked = window.localStorage.getItem(KEY) === '1'; } catch (e) { /* Speicher gesperrt */ }
			form.classList.toggle('ks-show-help', help.checked);
			help.addEventListener('change', function (event) {
				event.stopPropagation(); // kein "ungespeichert" durch diesen Schalter
				form.classList.toggle('ks-show-help', help.checked);
				try { window.localStorage.setItem(KEY, help.checked ? '1' : '0'); } catch (e) { /* Speicher gesperrt */ }
			});
			help.addEventListener('input', function (event) { event.stopPropagation(); });
		}

		// Name in der Kopfleiste
		var title = head.querySelector('[data-ks-head-name]');
		var name = document.getElementById('ks-p-name');
		if (title && name) {
			name.addEventListener('input', function () {
				title.textContent = name.value.trim() || title.getAttribute('data-empty');
			});
		}

		// Sichtbar / ausgeblendet
		var visible = head.querySelector('[data-ks-head-visible]');
		var active = form.querySelector('[data-ks-active-toggle]');
		if (visible && active) {
			active.addEventListener('change', function () {
				visible.textContent = visible.getAttribute(active.checked ? 'data-on' : 'data-off');
				visible.className = 'ks-badge ks-badge--' + (active.checked ? 'ok' : 'muted');
			});
		}

		// Auslaufartikel
		var discBadge = head.querySelector('[data-ks-head-discontinued]');
		var disc = form.querySelector('[data-ks-discontinued]');
		if (discBadge && disc) {
			disc.addEventListener('change', function () { discBadge.hidden = !disc.checked; });
		}

		// Anzahl angebotener Varianten
		var variants = head.querySelector('[data-ks-head-variants]');
		var card = form.querySelector('[data-ks-variants]');
		if (variants && card) {
			var toggle = card.querySelector('[data-ks-variants-toggle]');
			var body = card.querySelector('[data-ks-variant-rows]');
			var countVariants = function () {
				var n = body.querySelectorAll('input[type="checkbox"][name$="[active]"]:checked').length;
				variants.hidden = !toggle.checked || !n;
				variants.textContent = variants.getAttribute('data-label').replace('%d', n);
			};
			card.addEventListener('change', countVariants);
			new MutationObserver(countVariants).observe(body, { childList: true });
			countVariants();
		}

		// Lagerfarbe
		var warehouse = document.getElementById('ks-p-warehouse');
		var dot = form.querySelector('[data-ks-wh-dot]');
		if (warehouse && dot) {
			warehouse.addEventListener('change', function () {
				var option = warehouse.options[warehouse.selectedIndex];
				dot.className = dot.className.replace(/\bks-wh--\d+\b/g, '').trim() + ' ' + ((option && option.getAttribute('data-ks-wh-class')) || 'ks-wh--0');
			});
		}

		// Vorschau der Shop-Adresse
		var slug = document.getElementById('ks-p-slug');
		var preview = form.querySelector('[data-ks-slug-preview] b');
		if (slug && preview) {
			slug.addEventListener('input', function () {
				var value = slug.value.trim().toLowerCase().replace(/\s+/g, '-');
				preview.textContent = value || preview.parentNode.getAttribute('data-empty');
			});
		}

		// Grundpreis-Bereich oeffnen, wenn der Tipp darauf verweist
		var unitDetails = form.querySelector('[data-ks-unit-details]');
		var unitHint = document.getElementById('ks-unit-hint');
		if (unitDetails && unitHint) {
			unitHint.addEventListener('click', function () { unitDetails.open = true; });
		}
	}

	/* ---------- Start ---------- */

	document.querySelectorAll('[data-ks-variants]').forEach(initVariants);
	document.querySelectorAll('[data-ks-gallery]').forEach(initGallery);

	var form = document.querySelector('form.ks-edit');
	if (form) {
		initChrome(form);
		form.addEventListener('input', unitPreview);
		form.addEventListener('change', unitPreview);
		unitPreview();
	}
})();
