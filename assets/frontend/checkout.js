/**
 * Checkout: Komfort im Formular (alles auch ohne JavaScript nutzbar - der
 * Server prueft und berechnet unabhaengig davon jedes Mal neu):
 *  - Lieferadresse bei Selbstabholung, Rechnungsblock nur bei Bedarf einblenden
 *    (Pflichtfelder nur, wenn der Block sichtbar ist),
 *  - PLZ-Format je nach gewaehltem Land,
 *  - Versandkosten/Gesamtsumme live,
 *  - Fokus auf die Fehlerliste nach einem fehlgeschlagenen Absenden,
 *  - Overlay + Sperre beim Absenden (verhindert Doppelbestellungen; nicht fuer
 *    Knoepfe mit data-ks-no-overlay, z. B. Rabattcode einloesen in Pro).
 */
(function () {
    'use strict';

    // Bestaetigungsseite: Drucken und Bankdaten kopieren.
    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-ks-print]')) {
            window.print();

            return;
        }

        var copy = event.target.closest('[data-ks-copy]');

        if (!copy || !navigator.clipboard) {
            return;
        }

        navigator.clipboard.writeText(copy.getAttribute('data-ks-copy')).then(function () {
            var label = copy.textContent;

            copy.textContent = copy.getAttribute('data-ks-copied') || '✓';
            copy.classList.add('is-done');
            window.setTimeout(function () {
                copy.textContent = label;
                copy.classList.remove('is-done');
            }, 1500);
        });
    });

    var root = document.querySelector('.kontor-shop-checkout');

    if (!root) {
        return;
    }

    var form = root.querySelector('.kontor-shop-checkout__form');
    var pickup = root.querySelector('input[name="self_pickup"]');
    var delivery = root.querySelector('.kontor-shop-delivery');
    var billingToggle = root.querySelector('input[name="billing_different"]');
    var billing = root.querySelector('.kontor-shop-billing');
    var summary = root.querySelector('[data-checkout-summary]');

    function format(value) {
        return value.toLocaleString(document.documentElement.lang || 'de-AT', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        }) + ' €';
    }

    /** Block ein-/ausblenden; required nur bei sichtbaren Pflichtfeldern (data-required). */
    function toggleBlock(block, visible) {
        if (!block) {
            return;
        }

        block.hidden = !visible;

        block.querySelectorAll('[data-required]').forEach(function (input) {
            var isDeliveryOnly = input.name.indexOf('billing_') !== 0 && ['street', 'postal_code', 'city'].indexOf(input.name) !== -1;
            var isBillingOnly = input.name.indexOf('billing_') === 0 && input.name !== 'billing_name';

            if (isDeliveryOnly || isBillingOnly) {
                input.required = visible;
            }
        });
    }

    /** PLZ-Muster und Beispiel passend zum gewaehlten Land setzen. */
    function applyPostalPattern(select) {
        var option = select.options[select.selectedIndex];
        var prefix = select.name === 'billing_country' ? 'billing_' : '';
        var postal = root.querySelector('input[name="' + prefix + 'postal_code"]');

        if (!option || !postal) {
            return;
        }

        var pattern = option.getAttribute('data-pattern');
        var example = option.getAttribute('data-example');

        if (pattern) {
            postal.setAttribute('pattern', pattern);
        }

        postal.setAttribute('placeholder', example || '');
        postal.setAttribute('title', example ? 'z. B. ' + example : '');
    }

    function update() {
        var isPickup = pickup && pickup.checked;

        toggleBlock(delivery, !isPickup);

        if (billingToggle) {
            toggleBlock(billing, billingToggle.checked);
        }

        if (summary) {
            var items = parseFloat(summary.getAttribute('data-items')) || 0;
            var shipping = isPickup ? 0 : parseFloat(summary.getAttribute('data-shipping')) || 0;
            var shippingEl = summary.querySelector('[data-shipping-value]');
            var totalEl = summary.querySelector('[data-total-value]');

            if (shippingEl) {
                shippingEl.textContent = format(shipping);
            }

            if (totalEl) {
                totalEl.textContent = format(items + shipping);
            }
        }
    }

    if (pickup) {
        pickup.addEventListener('change', update);
    }

    if (billingToggle) {
        billingToggle.addEventListener('change', update);
    }

    root.querySelectorAll('select[data-country]').forEach(function (select) {
        select.addEventListener('change', function () { applyPostalPattern(select); });
        applyPostalPattern(select);
    });

    update();

    // Nach einem fehlgeschlagenen Absenden: Fehlerliste in Sicht und fokussiert (Screenreader lesen sie vor).
    var errorSummary = document.getElementById('kontor-shop-error-summary');

    if (errorSummary) {
        errorSummary.scrollIntoView({ block: 'center', behavior: 'smooth' });
        errorSummary.focus({ preventScroll: true });
    }

    // Fehler am Feld verschwindet, sobald der Kunde es aendert.
    root.addEventListener('input', function (event) {
        var field = event.target.closest ? event.target.closest('.kontor-shop-field.has-error') : null;

        if (field) {
            field.classList.remove('has-error');
            event.target.removeAttribute('aria-invalid');

            var message = field.querySelector('.kontor-shop-field__error');

            if (message) {
                message.remove();
            }
        }
    });

    /**
     * Beim Absenden: Overlay mit Spinner, Button gesperrt. Verhindert
     * Doppelklicks (doppelte Bestellung) und zeigt, dass etwas passiert - die
     * Verarbeitung (Bestand reservieren, Bestellung anlegen, Mails) kann einige
     * Sekunden dauern. submit feuert nur bei gueltigen Browser-Eingaben.
     */
    var overlay = null;

    function setSubmitting(active) {
        var button = form ? form.querySelector('.kontor-shop-checkout__submit') : null;

        root.classList.toggle('is-submitting', active);

        if (button) {
            button.disabled = active;
            button.setAttribute('aria-busy', active ? 'true' : 'false');
        }

        if (active && !overlay) {
            overlay = document.createElement('div');
            overlay.className = 'kontor-shop-overlay';
            overlay.setAttribute('role', 'status');
            overlay.setAttribute('aria-live', 'polite');
            overlay.innerHTML = '<div class="kontor-shop-overlay__box"><span class="kontor-shop-spinner kontor-shop-spinner--lg" aria-hidden="true"></span><p></p></div>';
            overlay.querySelector('p').textContent = root.getAttribute('data-processing') || '';
            document.body.appendChild(overlay);
        } else if (!active && overlay) {
            overlay.remove();
            overlay = null;
        }
    }

    if (form) {
        form.addEventListener('submit', function (event) {
            if (root.classList.contains('is-submitting')) {
                event.preventDefault();

                return;
            }

            // Knoepfe mit data-ks-no-overlay (z. B. Rabattcode einloesen, Pro) sind keine Bestellung: kein Overlay, keine Sperre.
            if (event.submitter && event.submitter.hasAttribute('data-ks-no-overlay')) {
                return;
            }

            // Erst nach dem aktuellen Event sperren, damit der Submit sicher ausgeloest wird.
            window.setTimeout(function () { setSubmitting(true); }, 0);
        });
    }

    // Zurueck-Taste/bfcache: Seite kommt eingefroren zurueck - Sperre wieder loesen.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            setSubmitting(false);
        }
    });
})();
