/**
 * Client-seitige Filter fuer den Katalog (kein Reload noetig, alle wirken
 * kombiniert): Shoptyp-Umschalter (nur auf der Gesamt-Katalogseite),
 * Kategorie-Filter und Produktsuche. Der gewaehlte Shoptyp steht in der
 * Adresse (?shop=slug), damit Links darauf teilbar sind. Bewusst ohne
 * Framework/Build-Schritt (Abschnitt 5.2).
 */
(function () {
    'use strict';

    function activeValue(wrapper, selector, attribute) {
        var active = wrapper.querySelector(selector + '.is-active');

        return active ? active.getAttribute(attribute) || '' : '';
    }

    function setActive(wrapper, selector, button) {
        wrapper.querySelectorAll(selector).forEach(function (candidate) {
            var isActive = candidate === button;

            candidate.classList.toggle('is-active', isActive);
            candidate.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    function apply(wrapper) {
        var shop = activeValue(wrapper, '.kontor-shop-catalog__shop', 'data-shop-filter');

        // Kategorie-Buttons anderer Shoptypen ausblenden; war eine davon aktiv, zurueck auf "Alle".
        wrapper.querySelectorAll('.kontor-shop-catalog__filter[data-shop-type]').forEach(function (button) {
            button.hidden = shop !== '' && button.getAttribute('data-shop-type') !== shop;

            if (button.hidden && button.classList.contains('is-active')) {
                setActive(wrapper, '.kontor-shop-catalog__filter', wrapper.querySelector('.kontor-shop-catalog__filter[data-category-filter=""]'));
            }
        });

        var slug = activeValue(wrapper, '.kontor-shop-catalog__filter', 'data-category-filter');
        var searchField = wrapper.querySelector('[data-catalog-search]');
        var terms = searchField ? searchField.value.toLowerCase().split(/\s+/).filter(Boolean) : [];
        var visible = 0;

        wrapper.querySelectorAll('.kontor-shop-product').forEach(function (card) {
            var haystack = card.getAttribute('data-search') || '';
            var matchesShop = shop === '' || card.getAttribute('data-shop-type') === shop;
            var matchesCategory = slug === '' || card.getAttribute('data-category') === slug;
            var matchesSearch = terms.every(function (term) { return haystack.indexOf(term) !== -1; });
            var show = matchesShop && matchesCategory && matchesSearch;

            card.hidden = !show;

            if (show) {
                visible++;
            }
        });

        var empty = wrapper.querySelector('.kontor-shop-catalog__empty');

        if (empty) {
            empty.hidden = visible > 0;
        }
    }

    /** Farbe des gewaehlten Shoptyps fuer Buttons/Akzente uebernehmen. */
    function applyAccent(wrapper, button) {
        if (!wrapper.hasAttribute('data-default-style')) {
            wrapper.setAttribute('data-default-style', wrapper.getAttribute('style') || '');
        }

        var accent = button ? button.getAttribute('data-accent') : '';
        var onAccent = button ? button.getAttribute('data-on-accent') : '';
        var base = wrapper.getAttribute('data-default-style');

        wrapper.setAttribute('style', accent
            ? base + ';--kontor-shop-primary:' + accent + ';' + (onAccent ? '--kontor-shop-on-primary:' + onAccent + ';' : '')
            : base);
    }

    function rememberShop(slug) {
        if (!window.history || !window.history.replaceState || !window.URL) {
            return;
        }

        var url = new URL(window.location.href);

        if (slug) {
            url.searchParams.set('shop', slug);
        } else {
            url.searchParams.delete('shop');
        }

        window.history.replaceState(null, '', url.toString());
    }

    function selectShop(wrapper, button, remember) {
        setActive(wrapper, '.kontor-shop-catalog__shop', button);
        applyAccent(wrapper, button);
        apply(wrapper);

        if (remember) {
            rememberShop(button.getAttribute('data-shop-filter') || '');
        }
    }

    document.addEventListener('click', function (event) {
        var shopButton = event.target.closest('.kontor-shop-catalog__shop');
        var button = shopButton || event.target.closest('.kontor-shop-catalog__filter');

        if (!button) {
            return;
        }

        var wrapper = button.closest('.kontor-shop-catalog');

        if (!wrapper) {
            return;
        }

        if (shopButton) {
            selectShop(wrapper, shopButton, true);

            return;
        }

        setActive(wrapper, '.kontor-shop-catalog__filter', button);
        apply(wrapper);
    });

    document.addEventListener('input', function (event) {
        if (!event.target.matches || !event.target.matches('[data-catalog-search]')) {
            return;
        }

        var wrapper = event.target.closest('.kontor-shop-catalog');

        if (wrapper) {
            apply(wrapper);
        }
    });

    // Vorauswahl aus der Adresse (?shop=jagd), z. B. aus einem geteilten Link oder Menue.
    function init() {
        var wanted = window.URL ? new URL(window.location.href).searchParams.get('shop') : '';

        if (!wanted) {
            return;
        }

        document.querySelectorAll('.kontor-shop-catalog').forEach(function (wrapper) {
            wrapper.querySelectorAll('.kontor-shop-catalog__shop').forEach(function (button) {
                if (button.getAttribute('data-shop-filter') === wanted) {
                    selectShop(wrapper, button, false);
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Karten-Effekt "Spotlight" (shop.css): Mausposition relativ zur Karte als
    // --ks-mx/--ks-my, daran richten sich Rand-Licht und Schein aus. Ein
    // Listener am Dokument, damit auch nachgeladene Karten mitmachen; nur mit
    // echter Maus und nur, wenn der Effekt eingeschaltet ist.
    if (window.matchMedia && window.matchMedia('(hover: hover)').matches
        && document.body && document.body.classList.contains('kontor-shop-fx-spotlight')) {
        document.addEventListener('pointermove', function (event) {
            var card = event.target.closest && event.target.closest('.kontor-shop-product');

            if (!card) {
                return;
            }

            var rect = card.getBoundingClientRect();

            card.style.setProperty('--ks-mx', (event.clientX - rect.left) + 'px');
            card.style.setProperty('--ks-my', (event.clientY - rect.top) + 'px');
        }, { passive: true });
    }
})();
