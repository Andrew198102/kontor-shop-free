/**
 * Warenkorb-Interaktionen (Add/Update/Remove per AJAX) + Off-Canvas-Drawer
 * (oeffnen/schliessen, Fokus-Falle, Escape) + Mengen-Badge auf allen
 * Warenkorb-Icons der Seite. Bewusst ohne Framework/Build-Schritt
 * (Abschnitt 5.2).
 */
(function () {
    'use strict';

    var lastTrigger = null;

    var REQUEST_TIMEOUT_MS = 15000;
    var toastTimer = null;

    function text(key, fallback) {
        return (window.kontorShopCart && window.kontorShopCart[key]) || fallback;
    }

    /**
     * Kurze Rueckmeldung (role=status, wird von Screenreadern vorgelesen).
     */
    function toast(message, isError) {
        var el = document.getElementById('kontor-shop-toast');

        if (!el) {
            el = document.createElement('div');
            el.id = 'kontor-shop-toast';
            el.className = 'kontor-shop-toast';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            document.body.appendChild(el);
        }

        el.textContent = message;
        el.classList.toggle('is-error', !!isError);
        el.classList.add('is-visible');

        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () { el.classList.remove('is-visible'); }, isError ? 5000 : 2200);
    }

    function setBusy(busy) {
        document.querySelectorAll('.kontor-shop-cart').forEach(function (container) {
            container.classList.toggle('is-busy', busy);
            container.setAttribute('aria-busy', busy ? 'true' : 'false');
        });

        var drawerBody = document.getElementById('kontor-shop-cart-drawer-body');

        if (drawerBody) {
            drawerBody.setAttribute('aria-busy', busy ? 'true' : 'false');
        }
    }

    /**
     * POST an admin-ajax mit Timeout. Rejects bei Netzwerkfehler, Timeout oder
     * Server-Fehler - der Aufrufer zeigt dann eine Meldung.
     */
    function post(action, data) {
        var params = new URLSearchParams(data);
        params.append('action', action);
        params.append('nonce', window.kontorShopCart.nonce);

        var controller = 'AbortController' in window ? new AbortController() : null;
        var timer = controller ? window.setTimeout(function () { controller.abort(); }, REQUEST_TIMEOUT_MS) : null;

        return fetch(window.kontorShopCart.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString(),
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined
        })
            .then(function (response) {
                return response.json().catch(function () { return null; });
            })
            .then(function (json) {
                if (!json || !json.success) {
                    // Meldung des Servers (z. B. "ausverkauft", "Sitzung abgelaufen") anzeigen.
                    var failure = new Error(json && json.data && json.data.code === 'expired' ? 'expired' : 'request failed');

                    failure.serverMessage = json && json.data && json.data.message ? json.data.message : '';

                    throw failure;
                }

                refreshCart(json.data);

                if (json.data && json.data.notice) {
                    toast(json.data.notice, false);
                }

                return json.data;
            })
            .catch(function (error) {
                throw error && error.name === 'AbortError' ? new Error('timeout') : error;
            })
            .then(
                function (result) { window.clearTimeout(timer); return result; },
                function (error) { window.clearTimeout(timer); throw error; }
            );
    }

    function failureMessage(error) {
        if (error && error.serverMessage) {
            return error.serverMessage;
        }

        return error && error.message === 'timeout'
            ? text('timeout', 'The server isn\'t responding. Please check your connection and try again.')
            : text('error', 'Sorry, that didn\'t work. Please try again.');
    }

    /**
     * Button in Ladezustand setzen (Spinner + Sperre gegen Doppelklick).
     */
    function buttonLoading(button, loading) {
        var spinner = button.querySelector('.kontor-shop-spinner');

        button.disabled = loading;
        button.classList.toggle('is-loading', loading);
        button.setAttribute('aria-busy', loading ? 'true' : 'false');

        if (loading && !spinner) {
            spinner = document.createElement('span');
            spinner.className = 'kontor-shop-spinner';
            spinner.setAttribute('aria-hidden', 'true');
            button.insertBefore(spinner, button.firstChild);
        } else if (!loading && spinner) {
            spinner.remove();
        }
    }

    function refreshCart(data) {
        if (!data) {
            return;
        }

        document.querySelectorAll('.kontor-shop-cart').forEach(function (container) {
            container.outerHTML = data.html;
        });

        document.querySelectorAll('.kontor-shop-cart-icon__count').forEach(function (badge) {
            badge.textContent = String(data.count);
            badge.setAttribute('data-count', String(data.count));
        });

        document.querySelectorAll('.kontor-shop-cart-icon[data-hide-when-empty="1"]').forEach(function (button) {
            button.classList.toggle('is-empty', !data.count);
        });

        document.dispatchEvent(new CustomEvent('kontorShopCartUpdated', { detail: data }));
    }

    function flash(button, text, ok) {
        var original = button.getAttribute('data-label') || button.textContent;

        button.setAttribute('data-label', original);
        button.textContent = text;
        button.classList.toggle('is-error', !ok);
        button.classList.toggle('is-done', ok);

        window.setTimeout(function () {
            button.textContent = original;
            button.classList.remove('is-error', 'is-done');
        }, 1600);
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!form.classList || !form.classList.contains('kontor-shop-add-to-cart')) {
            return;
        }

        event.preventDefault();

        var button = form.querySelector('button[type="submit"]');

        if (button.disabled) {
            return;
        }

        var productId = form.querySelector('[name="product_id"]').value;
        var quantity = form.querySelector('[name="quantity"]').value;
        var variantField = form.querySelector('[name="variant_id"]');
        var variantId = variantField ? variantField.value : '0';

        // Artikel mit Varianten: erst waehlen lassen (Hinweis + Fokus auf die erste offene Auswahl)
        if (variantField && !variantId) {
            var open = form.querySelector('select[data-ks-option]:not([disabled])');

            Array.prototype.some.call(form.querySelectorAll('select[data-ks-option]'), function (select) {
                if (!select.value) { open = select; return true; }
                return false;
            });

            toast(text('chooseVariant', 'Please select a variant first.'), true);

            if (open) {
                open.focus();
            }

            return;
        }

        buttonLoading(button, true);

        post('kontor_shop_cart_add', { product_id: productId, variant_id: variantId, quantity: quantity })
            .then(function () {
                buttonLoading(button, false);
                flash(button, text('added', 'Added'), true);
            })
            .catch(function (error) {
                buttonLoading(button, false);
                flash(button, text('errorShort', 'Error'), false);
                toast(failureMessage(error), true);
            });
    });

    document.addEventListener('change', function (event) {
        var input = event.target;

        if (!input.classList || !input.classList.contains('kontor-shop-cart__qty')) {
            return;
        }

        var item = input.closest('[data-product-id]');

        setBusy(true);

        post('kontor_shop_cart_update', {
            product_id: item.getAttribute('data-product-id'),
            variant_id: item.getAttribute('data-variant-id') || '0',
            quantity: input.value
        }).catch(function (error) {
            setBusy(false);
            toast(failureMessage(error), true);
        });
    });

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.kontor-shop-cart__remove');

        if (button) {
            var item = button.closest('[data-product-id]');

            setBusy(true);

            post('kontor_shop_cart_remove', { product_id: item.getAttribute('data-product-id'), variant_id: item.getAttribute('data-variant-id') || '0' }).catch(function (error) {
                setBusy(false);
                toast(failureMessage(error), true);
            });

            return;
        }

        var toggle = event.target.closest('#kontor-shop-cart-toggle');

        if (toggle) {
            event.preventDefault();
            lastTrigger = toggle;
            openDrawer();

            return;
        }

        if (event.target.closest('#kontor-shop-cart-drawer-close') || event.target.id === 'kontor-shop-cart-drawer-backdrop') {
            closeDrawer();
        }
    });

    document.addEventListener('keydown', function (event) {
        var drawer = document.getElementById('kontor-shop-cart-drawer');

        if (!drawer || !drawer.classList.contains('is-open')) {
            return;
        }

        if (event.key === 'Escape') {
            closeDrawer();

            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        var focusable = drawer.querySelectorAll('a[href], button:not([disabled]), input:not([disabled])');

        if (!focusable.length) {
            return;
        }

        var first = focusable[0];
        var last = focusable[focusable.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    function openDrawer() {
        var drawer = document.getElementById('kontor-shop-cart-drawer');
        var backdrop = document.getElementById('kontor-shop-cart-drawer-backdrop');

        if (!drawer || !backdrop) {
            return;
        }

        drawer.classList.add('is-open');
        backdrop.classList.add('is-open');
        drawer.setAttribute('aria-hidden', 'false');
        document.documentElement.classList.add('kontor-shop-noscroll');

        var close = document.getElementById('kontor-shop-cart-drawer-close');

        if (close) {
            close.focus();
        }
    }

    function closeDrawer() {
        var drawer = document.getElementById('kontor-shop-cart-drawer');
        var backdrop = document.getElementById('kontor-shop-cart-drawer-backdrop');

        if (!drawer || !backdrop) {
            return;
        }

        drawer.classList.remove('is-open');
        backdrop.classList.remove('is-open');
        drawer.setAttribute('aria-hidden', 'true');
        document.documentElement.classList.remove('kontor-shop-noscroll');

        if (lastTrigger) {
            lastTrigger.focus();
        }
    }
    // Schwebenden Warenkorb-Knopf in die gemeinsame Knopf-Leiste einreihen (ganz unten),
    // damit „nach oben“ (Theme) und andere Plugins ihn nicht ueberdecken.
    (function () {
        var fab = document.getElementById('kontor-shop-cart-toggle');
        if (fab && window.FabDock && !fab.classList.contains('kontor-shop-cart-icon--inline')) {
            window.FabDock.add(fab, 10);
        }
    })();
})();
