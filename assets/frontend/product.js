/**
 * Artikel im Shop: Variantenauswahl (Preis, Grundpreis, Verfuegbarkeit und
 * Bild wechseln mit der Auswahl) und Bildergalerie der Artikelseite mit
 * Vollbildansicht. Bewusst ohne Framework/Build-Schritt (Abschnitt 5.2).
 * Ohne JavaScript bleiben Hauptbild, Auswahlfelder und Normalpreis stehen;
 * der Server verlangt beim Hinzufuegen ohnehin eine gueltige Variante.
 */
(function () {
    'use strict';

    var cfg = window.kontorShopProduct || {};

    function text(key, fallback) {
        return cfg[key] || fallback;
    }

    function rootOf(el) {
        return el.closest('.kontor-shop-product, .kontor-shop-detail') || document.body;
    }

    /* ---------- Bild tauschen (Katalogkarte oder Galerie) ---------- */

    function mainImage(root) {
        return root.querySelector('[data-ks-gallery-image]') || root.querySelector('[data-ks-media] img');
    }

    function setImage(root, img) {
        var el = mainImage(root);

        if (!el) {
            return;
        }

        if (!root.ksOriginalImage) {
            var zoom = root.querySelector('[data-ks-gallery-zoom]');

            root.ksOriginalImage = {
                url: el.getAttribute('src'),
                srcset: el.getAttribute('srcset') || '',
                alt: el.getAttribute('alt') || '',
                full: zoom ? zoom.getAttribute('href') : ''
            };
        }

        var target = img || root.ksOriginalImage;

        el.setAttribute('src', target.url);
        el.classList.remove('kontor-shop-img--placeholder');

        if (target.srcset) {
            el.setAttribute('srcset', target.srcset);
        } else {
            el.removeAttribute('srcset');
        }

        el.setAttribute('alt', target.alt || '');

        var link = root.querySelector('[data-ks-gallery-zoom]');

        if (link && target.full) {
            link.setAttribute('href', target.full);
        }

        root.querySelectorAll('[data-ks-gallery-thumb]').forEach(function (thumb) {
            var active = thumb.getAttribute('data-url') === target.url;

            thumb.classList.toggle('is-active', active);

            if (active) {
                thumb.setAttribute('aria-current', 'true');
            } else {
                thumb.removeAttribute('aria-current');
            }
        });
    }

    /* ---------- Variantenauswahl ---------- */

    function initPicker(picker) {
        var data;

        try {
            data = JSON.parse(picker.getAttribute('data-ks-variant-data') || '[]');
        } catch (e) {
            return;
        }

        var form = picker.closest('form');
        var root = rootOf(picker);
        var selects = Array.prototype.slice.call(picker.querySelectorAll('select[data-ks-option]'));
        var hidden = form ? form.querySelector('[data-ks-variant-id]') : null;
        var status = picker.querySelector('[data-ks-variant-status]');
        var button = form ? form.querySelector('button[type="submit"]') : null;
        var priceNode = root.querySelector('[data-ks-price]');
        var normal = priceNode ? priceNode.querySelector('.kontor-shop-product__price--normal') : null;
        var initialPrice = normal ? normal.textContent : '';
        var unitNode = root.querySelector('[data-ks-unit-price]');
        var initialUnit = unitNode ? unitNode.textContent : '';
        var availability = root.querySelector('.kontor-shop-product__availability');
        var productId = priceNode ? priceNode.getAttribute('data-product-id') : '';
        var imageSwapped = false;

        // Werte-Beschriftungen merken (fuer den Zusatz "ausverkauft")
        selects.forEach(function (select) {
            Array.prototype.forEach.call(select.options, function (option) {
                option.setAttribute('data-label', option.textContent);
            });
        });

        function values() {
            return selects.map(function (select) { return select.value; });
        }

        function matches(variant, chosen, skip) {
            return chosen.every(function (value, i) {
                return i === skip || !value || variant.o[i] === value;
            });
        }

        function refreshOptions(chosen) {
            selects.forEach(function (select, i) {
                Array.prototype.forEach.call(select.options, function (option) {
                    if (!option.value) {
                        return;
                    }

                    var candidates = data.filter(function (variant) {
                        return variant.o[i] === option.value && matches(variant, chosen, i);
                    });
                    var anyAvailable = candidates.some(function (variant) { return variant.ok; });

                    option.disabled = candidates.length === 0;
                    option.textContent = option.getAttribute('data-label') + (candidates.length && !anyAvailable ? ' – ' + text('soldOut', 'sold out') : '');
                });
            });
        }

        function setPrice(label, key) {
            if (!normal) {
                return;
            }

            normal.textContent = label;

            if (key) {
                priceNode.setAttribute('data-ks-price-key', key);
            } else {
                priceNode.removeAttribute('data-ks-price-key');
            }

            document.dispatchEvent(new CustomEvent('kontorShopPriceChanged', { detail: { node: priceNode } }));
        }

        function update() {
            var chosen = values();
            var complete = chosen.every(Boolean);
            var variant = null;

            if (complete) {
                variant = data.filter(function (candidate) { return matches(candidate, chosen, -1); })[0] || null;
            }

            refreshOptions(chosen);

            if (hidden) {
                hidden.value = variant && variant.ok ? String(variant.id) : '';
            }

            if (variant) {
                setPrice(variant.p, productId + ':' + variant.id);
            } else {
                setPrice(initialPrice, '');
            }

            if (unitNode) {
                unitNode.textContent = variant ? variant.u : initialUnit;
                unitNode.hidden = !unitNode.textContent;
            }

            if (status) {
                status.textContent = variant && !variant.ok ? text('variantOut', 'This variant is currently unavailable.') : '';
            }

            if (button) {
                button.disabled = !!(variant && !variant.ok);
            }

            if (availability) {
                availability.hidden = !!(variant && !variant.ok);
            }

            // Fuer "Benachrichtigen, wenn verfuegbar" (stock-request.js)
            root.dispatchEvent(new CustomEvent('kontorShopVariantChanged', { bubbles: true, detail: { variant: variant } }));

            if (variant && variant.img) {
                setImage(root, variant.img);
                imageSwapped = true;
            } else if (imageSwapped) {
                setImage(root, null);
                imageSwapped = false;
            }
        }

        // Merkmale mit nur einem Wert gleich vorauswaehlen
        selects.forEach(function (select) {
            var real = Array.prototype.filter.call(select.options, function (option) { return option.value; });

            if (!select.value && real.length === 1) {
                select.value = real[0].value;
            }
        });

        selects.forEach(function (select) {
            select.addEventListener('change', update);
        });

        update();
    }

    /* ---------- Galerie + Vollbild ---------- */

    var lightbox = null;

    function images(gallery) {
        var thumbs = gallery.querySelectorAll('[data-ks-gallery-thumb]');

        if (thumbs.length) {
            return Array.prototype.map.call(thumbs, function (thumb) {
                return { full: thumb.getAttribute('data-full'), alt: thumb.getAttribute('data-alt') };
            });
        }

        var zoom = gallery.querySelector('[data-ks-gallery-zoom]');
        var img = gallery.querySelector('[data-ks-gallery-image]');

        return zoom ? [{ full: zoom.getAttribute('href'), alt: img ? img.getAttribute('alt') : '' }] : [];
    }

    function openLightbox(list, index) {
        if (!list.length || typeof HTMLDialogElement === 'undefined') {
            return false;
        }

        if (!lightbox) {
            lightbox = document.createElement('dialog');
            lightbox.className = 'kontor-shop-lightbox';
            lightbox.innerHTML = '<img class="kontor-shop-lightbox__img" alt="" />'
                + '<button type="button" class="kontor-shop-lightbox__close" data-ks-lb="close" aria-label="' + text('close', 'Close') + '">&times;</button>'
                + '<button type="button" class="kontor-shop-lightbox__nav kontor-shop-lightbox__nav--prev" data-ks-lb="-1" aria-label="' + text('prev', 'Previous image') + '">&lsaquo;</button>'
                + '<button type="button" class="kontor-shop-lightbox__nav kontor-shop-lightbox__nav--next" data-ks-lb="1" aria-label="' + text('next', 'Next image') + '">&rsaquo;</button>'
                + '<p class="kontor-shop-lightbox__count" aria-live="polite"></p>';
            document.body.appendChild(lightbox);

            lightbox.addEventListener('click', function (event) {
                var action = event.target.getAttribute('data-ks-lb');

                if (action === 'close' || event.target === lightbox) {
                    lightbox.close();
                } else if (action) {
                    show(lightbox.ksIndex + parseInt(action, 10));
                }
            });

            lightbox.addEventListener('keydown', function (event) {
                if (event.key === 'ArrowLeft') { show(lightbox.ksIndex - 1); }
                if (event.key === 'ArrowRight') { show(lightbox.ksIndex + 1); }
            });

            lightbox.addEventListener('close', function () {
                document.documentElement.classList.remove('kontor-shop-noscroll');
            });
        }

        function show(i) {
            var total = lightbox.ksList.length;

            lightbox.ksIndex = (i + total) % total;

            var item = lightbox.ksList[lightbox.ksIndex];
            var img = lightbox.querySelector('.kontor-shop-lightbox__img');

            img.src = item.full;
            img.alt = item.alt || '';
            lightbox.querySelector('.kontor-shop-lightbox__count').textContent = total > 1 ? (lightbox.ksIndex + 1) + ' / ' + total : '';
            lightbox.querySelectorAll('.kontor-shop-lightbox__nav').forEach(function (nav) { nav.hidden = total < 2; });
        }

        lightbox.ksList = list;
        show(index);
        lightbox.showModal();
        document.documentElement.classList.add('kontor-shop-noscroll');

        return true;
    }

    document.addEventListener('click', function (event) {
        var thumb = event.target.closest('[data-ks-gallery-thumb]');

        if (thumb) {
            event.preventDefault();
            setImage(rootOf(thumb), {
                url: thumb.getAttribute('data-url'),
                srcset: thumb.getAttribute('data-srcset'),
                alt: thumb.getAttribute('data-alt'),
                full: thumb.getAttribute('data-full')
            });

            return;
        }

        var zoom = event.target.closest('[data-ks-gallery-zoom]');

        if (zoom) {
            var gallery = zoom.closest('[data-ks-gallery]');
            var list = images(gallery);
            var current = zoom.getAttribute('href');
            var index = 0;

            list.forEach(function (item, i) {
                if (item.full === current) { index = i; }
            });

            // Variantenbild, das nicht in der Galerie steht: einzeln zeigen
            if (list.length && list[index].full !== current) {
                list = [{ full: current, alt: (gallery.querySelector('[data-ks-gallery-image]') || {}).alt || '' }];
                index = 0;
            }

            if (openLightbox(list, index)) {
                event.preventDefault();
            }
        }
    });

    function init(scope) {
        scope.querySelectorAll('[data-ks-variant-data]').forEach(function (picker) {
            if (!picker.ksReady) {
                picker.ksReady = true;
                initPicker(picker);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(document); });
    } else {
        init(document);
    }
})();
