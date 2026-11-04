/**
 * Stand nach dem Kauf (Admin\Upgrade\ProStatus):
 * - wartet Freemius auf die Bestaetigungs-Mail: alle 5 Sekunden nachfragen und
 *   neu laden, sobald sich etwas aendert;
 * - Lizenz aktiv, aber noch Free: Pro sofort im Hintergrund installieren
 *   (kontor_shop_pro_install), dann zur Uebersicht mit "Willkommen bei Pro".
 *   Laeuft die Installation schon in einem anderen Tab, hier nur mitwarten.
 *   Fehler: klare Meldung, "Nochmal versuchen" und der Weg ueber Freemius.
 */
(function () {
	'use strict';

	var cfg = window.kontorShopProStatus || {};
	var i18n = cfg.i18n || {};
	if (!window.fetch) { return; }

	function post(data) {
		var body = new FormData();
		Object.keys(data).forEach(function (key) { body.append(key, data[key]); });
		return fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body }).then(function (response) { return response.json(); });
	}

	function watch(state, interval, onChange) {
		var tries = 0;
		var timer = window.setInterval(function () {
			if (++tries > 360) { window.clearInterval(timer); return; }
			post({ action: cfg.action, nonce: cfg.nonce }).then(function (json) {
				if (json && json.success && json.data.state !== state) {
					window.clearInterval(timer);
					onChange(json.data.state);
				}
			}).catch(function () { /* naechster Versuch */ });
		}, interval);
	}

	if (cfg.state === 'install' && cfg.install && cfg.install.action) {
		var card = document.querySelector('[data-ks-ps-install]');
		if (!card) { return; }

		var title = card.querySelector('[data-ks-ps-title]');
		var progress = card.querySelector('[data-ks-ps-progress]');
		var msg = card.querySelector('[data-ks-ps-msg]');
		var retry = card.querySelector('[data-ks-ps-retry]');
		var startTitle = title.textContent;
		var startMsg = msg.textContent;

		var done = function () { window.location.href = cfg.done; };

		var fail = function (text) {
			card.classList.add('ks-ps--wait');
			card.classList.remove('ks-ps--done');
			title.textContent = i18n.failedTitle;
			progress.hidden = false;
			progress.querySelector('.ks-ps__spinner').hidden = true;
			msg.textContent = text || i18n.unknown;
			retry.hidden = false;
		};

		var install = function () {
			card.classList.add('ks-ps--done');
			card.classList.remove('ks-ps--wait');
			title.textContent = startTitle;
			msg.textContent = startMsg;
			progress.querySelector('.ks-ps__spinner').hidden = false;
			retry.hidden = true;

			post(cfg.install).then(function (json) {
				if (json && json.success) { done(); return; }
				var error = (json && json.data) || {};
				if (error.code === 'install_running') {
					msg.textContent = i18n.running;
					watch('install', 3000, done);
					return;
				}
				fail(error.message);
			}).catch(function () { fail(''); });
		};

		retry.addEventListener('click', install);
		install();
		return;
	}

	if (cfg.state === 'pending') {
		watch('pending', 5000, function () { window.location.reload(); });
	}
})();
