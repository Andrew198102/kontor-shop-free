/**
 * Kontor Shop > Pläne & Preise: Umschalter jährlich/einmalig (Preise und
 * Kauf-Links tauschen) und sanftes Einblenden beim Scrollen. Ohne JavaScript
 * gelten die Jahrespreise und -Links.
 */
(function () {
	'use strict';

	var root = document.querySelector('.ks-pp');
	if (!root) { return; }

	root.classList.add('is-js');

	root.addEventListener('click', function (event) {
		var button = event.target.closest('[data-ks-pp-cycle]');
		if (!button) { return; }

		var cycle = button.getAttribute('data-ks-pp-cycle');
		if (root.getAttribute('data-cycle') === cycle) { return; }

		root.setAttribute('data-cycle', cycle);
		root.querySelectorAll('[data-ks-pp-cycle]').forEach(function (other) {
			other.setAttribute('aria-pressed', other === button ? 'true' : 'false');
		});
		root.querySelectorAll('.ks-pp-buy').forEach(function (link) {
			link.href = link.getAttribute('data-' + cycle) || link.href;
		});
		root.querySelectorAll('[data-ks-pp-show="' + cycle + '"]').forEach(function (price) {
			price.classList.remove('is-switching');
			void price.offsetWidth; // Animation neu starten
			price.classList.add('is-switching');
		});
	});

	var items = root.querySelectorAll('.ks-pp-reveal');

	if (!('IntersectionObserver' in window)) {
		items.forEach(function (item) { item.classList.add('is-in'); });
		return;
	}

	var observer = new IntersectionObserver(function (entries) {
		entries.forEach(function (entry) {
			if (!entry.isIntersecting) { return; }
			entry.target.classList.add('is-in');
			observer.unobserve(entry.target);
		});
	}, { rootMargin: '0px 0px -8% 0px' });

	items.forEach(function (item, index) {
		item.style.setProperty('--d', (index % 4) * 0.07 + 's');
		observer.observe(item);
	});
})();
