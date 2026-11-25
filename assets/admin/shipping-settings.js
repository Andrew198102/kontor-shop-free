/**
 * Einstellungen → Versand (Admin\Settings\ShippingSection):
 * - Auslandslaender als Kaertchen: Haken raus = zurueck in "+ Land hinzufuegen",
 *   Haken rein = zu den gewaehlten; das Inland ist nie Auslandsland.
 * - Zeile "Ausland" nur, wenn ins Ausland geliefert wird.
 * - "Versandkostenfrei ab" per Haekchen.
 * - Live-Vorschau "So rechnet die Kasse" und die Versandzeile der Artikelseite
 *   (gleiche Regeln wie Modules\Shipping\ShippingCalculator und
 *   Modules\Catalog\ServiceInfo).
 */
(function () {
	'use strict';

	var form = document.querySelector('form.ks-ship');
	var configEl = form && form.querySelector('[data-ks-ship-config]');

	if (!form || !configEl) {
		return;
	}

	var config = JSON.parse(configEl.textContent || '{}');
	var t = config.i18n || {};
	var names = config.countries || {};

	var $ = function (sel) { return form.querySelector(sel); };
	var $$ = function (sel) { return Array.prototype.slice.call(form.querySelectorAll(sel)); };

	var home = $('[data-ks-home]');
	var abroadOn = $('[data-ks-abroad-on]');
	var abroadBox = $('[data-ks-abroad]');
	var chips = $('[data-ks-chips]');
	var pool = $('[data-ks-pool]');
	var add = $('[data-ks-add]');
	var freeOn = $('[data-ks-free-on]');
	var free = $('[data-ks-free]');
	var freeAbroad = $('[data-ks-free-abroad]');
	var freeAbroadLine = $('[data-ks-free-abroad-line]');
	var pickup = $('[data-ks-pickup]');
	var preview = $('[data-ks-preview]');

	function fmt(template) {
		var args = Array.prototype.slice.call(arguments, 1);
		var i = 0;

		return String(template).replace(/%(\d)\$s|%s/g, function (m, n) {
			return n ? (args[Number(n) - 1] !== undefined ? args[Number(n) - 1] : '') : (args[i++] !== undefined ? args[i - 1] : '');
		});
	}

	function num(value) {
		var s = String(value == null ? '' : value).trim().replace(',', '.');

		return s === '' || isNaN(Number(s)) ? null : Math.max(0, Number(s));
	}

	/** Wie LabelCard::price_label: "5,90 €", "12 €". */
	function money(n) {
		return (Math.abs(n - Math.round(n)) < 0.005 ? String(Math.round(n)) : n.toFixed(2).replace('.', ',')) + ' €';
	}

	function rateLabel(n) {
		return n > 0 ? money(n) : t.free;
	}

	function list(items) {
		if (items.length <= 1) {
			return items[0] || '';
		}

		return fmt(t.and, items.slice(0, -1).join(', '), items[items.length - 1]);
	}

	function rate(role) {
		var input = $('[data-ks-rate="' + role + '"]');

		return input ? num(input.value) : null;
	}

	function abroadCodes() {
		if (!abroadOn || !abroadOn.checked || !chips) {
			return [];
		}

		return $$('[data-ks-chips] .ks-chip input:checked').map(function (input) { return input.value; }).filter(function (code) { return code !== home.value; });
	}

	/** Kaertchen in die passende Liste schieben (gewaehlt: vor "+ Land hinzufuegen"). */
	function place(chip) {
		var input = chip.querySelector('input');

		if (input.checked) {
			chips.insertBefore(chip, add);
		} else {
			var code = chip.getAttribute('data-code');
			var next = null;

			// Im Vorrat in der festen Reihenfolge der Laenderliste.
			Object.keys(names).some(function (other) {
				if (other === code) {
					return false;
				}

				var found = pool.querySelector('.ks-chip[data-code="' + other + '"]');

				if (found && Object.keys(names).indexOf(other) > Object.keys(names).indexOf(code)) {
					next = found;

					return true;
				}

				return false;
			});

			pool.insertBefore(chip, next);
		}
	}

	function syncHome() {
		$$('.ks-chip').forEach(function (chip) {
			var isHome = chip.getAttribute('data-code') === home.value;
			var input = chip.querySelector('input');

			if (isHome && input.checked) {
				input.checked = false;
				place(chip);
			}

			chip.hidden = isHome;
		});
	}

	function update() {
		var codes = abroadCodes();
		var showAbroad = codes.length > 0;
		var homeName = names[home.value] || home.value;
		var abroadNames = codes.map(function (code) { return names[code] || code; });

		if (abroadBox) {
			abroadBox.hidden = !abroadOn.checked;
		}

		$$('[data-ks-abroad-row]').forEach(function (el) { el.hidden = !showAbroad; });
		$$('[data-ks-home-name]').forEach(function (el) { el.textContent = homeName; });
		$$('[data-ks-abroad-names]').forEach(function (el) { el.textContent = list(abroadNames); });

		if (free && freeOn) {
			free.disabled = !freeOn.checked;
		}

		if (freeAbroadLine) {
			freeAbroadLine.hidden = !(freeOn && freeOn.checked && showAbroad);
		}

		// ---------- Rechnen ----------
		var dom = rate('all-home') || 0;
		var away = rate('all-abroad');
		var hasMembers = !!$('[data-ks-rate="members-home"]');
		var mh = rate('members-home');
		var ma = rate('members-abroad');

		away = away === null ? dom : away;

		var memDom = mh === null ? dom : Math.min(mh, dom);
		var memAway = ma === null ? away : Math.min(ma, away);
		var threshold = freeOn && freeOn.checked ? num(free.value) : null;
		var freeAway = !freeAbroad || freeAbroad.checked;
		var sample = threshold && threshold <= 30 ? Math.max(5, Math.floor(threshold / 2)) : 30;
		var firstAbroad = codes.length ? (codes.length > 1 ? fmt(t.andOthers, abroadNames[0]) : abroadNames[0]) : '';
		var rows = [[fmt(t.guest, homeName), dom]];

		if (hasMembers) {
			rows.push([fmt(t.member, homeName), memDom]);
		}

		if (showAbroad) {
			rows.push([fmt(t.guest, firstAbroad), away]);

			if (hasMembers) {
				rows.push([fmt(t.member, firstAbroad), memAway]);
			}
		}

		if (threshold) {
			rows.push([fmt(t.freeHome, money(threshold), homeName), 0]);

			if (showAbroad) {
				rows.push([fmt(t.freeAbroad, money(threshold)), freeAway ? 0 : away]);
			}
		}

		if (pickup && pickup.checked) {
			rows.push([t.pickup, 0]);
		}

		var warn = $('[data-ks-member-warn]');

		if (warn) {
			warn.hidden = !((mh !== null && mh > dom) || (showAbroad && ma !== null && ma > away));
		}

		if (!preview) {
			return;
		}

		preview.hidden = false;
		$('[data-ks-sample]').textContent = fmt(t.sample, money(sample));

		var ul = $('[data-ks-examples]');

		ul.textContent = '';
		rows.forEach(function (row) {
			var li = document.createElement('li');
			var who = document.createElement('span');
			var price = document.createElement('strong');

			who.textContent = row[0];
			price.textContent = rateLabel(row[1]);
			price.className = row[1] <= 0 ? 'is-free' : '';
			li.appendChild(who);
			li.appendChild(price);
			ul.appendChild(li);
		});

		// ---------- Versandzeile der Artikelseite ----------
		var line;
		var all = [homeName].concat(showAbroad ? abroadNames : []);

		if (showAbroad && Math.abs(away - dom) > 0.004) {
			line = fmt(t.shipTo, homeName, rateLabel(dom)) + ', ' + fmt(t.to, list(abroadNames), rateLabel(away));
		} else if (dom <= 0) {
			line = fmt(t.freeTo, list(all));
		} else {
			line = fmt(t.shipTo, list(all), money(dom));
		}

		if (threshold && !(dom <= 0 && (!showAbroad || away <= 0))) {
			line += ' – ' + (showAbroad && !freeAway ? fmt(t.freeFromTo, money(threshold), homeName) : fmt(t.freeFrom, money(threshold)));
		}

		if (hasMembers && (memDom < dom || (showAbroad && memAway < away))) {
			if (memDom <= 0 && (!showAbroad || memAway <= 0)) {
				line += ' – ' + t.membersFree;
			} else if (!showAbroad || Math.abs(memDom - memAway) < 0.005) {
				line += ' – ' + fmt(t.members, rateLabel(memDom));
			} else {
				line += ' – ' + fmt(t.membersSplit, homeName, rateLabel(memDom), rateLabel(memAway));
			}
		}

		$('[data-ks-line]').textContent = line + (pickup && pickup.checked ? '. ' + t.orPickup + '.' : '');
	}

	$$('.ks-chip input').forEach(function (input) {
		input.addEventListener('change', function () {
			place(input.closest('.ks-chip'));
			update();
		});
	});

	var eu = $('[data-ks-eu]');

	if (eu) {
		eu.addEventListener('click', function () {
			(eu.getAttribute('data-codes') || '').split(',').forEach(function (code) {
				var chip = pool.querySelector('.ks-chip[data-code="' + code + '"]');

				if (chip && code !== home.value) {
					chip.querySelector('input').checked = true;
					place(chip);
				}
			});

			add.open = false;
			form.dispatchEvent(new Event('input', { bubbles: true }));
			update();
		});
	}

	home.addEventListener('change', function () { syncHome(); update(); });
	form.addEventListener('input', update);
	form.addEventListener('change', update);

	syncHome();
	update();
}());
