/**
 * Kontor Shop - Einstellungen -> Design: Live-Vorschau.
 * Liest die (ungespeicherten) Formularwerte, laesst den Server dasselbe CSS
 * rechnen wie im Frontend (DesignModule::ajax_preview) und schickt es per
 * postMessage an die Shop-Seite im Rahmen. Zeigt ausserdem Kontrast der
 * Button-Schrift und den kopierbaren CSS-Block fuer Webmaster.
 */
(function () {
    'use strict';

    var config = window.kontorShopDesign;
    var form = document.querySelector('.ks-design-form');

    if (!config || !form) {
        return;
    }

    var frame = document.querySelector('[data-ks-preview-frame]');
    var snippet = document.querySelector('[data-ks-snippet]');
    var contrast = document.querySelector('[data-ks-contrast]');
    var radiusOut = document.querySelector('[data-ks-radius-out]');
    var timer = null;
    var last = null;
    var requestId = 0;

    function value(name) {
        var field = form.elements[name];

        if (!field) {
            return '';
        }

        if (field.type === 'checkbox') {
            return field.checked ? field.value : '';
        }

        return field.value || '';
    }

    function design() {
        return {
            primary_color: value('primary_mode') === 'own' ? value('primary_color') : '',
            cart_icon_color: value('cart_mode') === 'own' ? value('cart_icon_color') : '',
            style: value('style'),
            radius: value('radius_custom') ? value('radius') : '',
            scheme: value('scheme'),
            columns: value('columns'),
            image_ratio: value('image_ratio'),
            heading_font: value('heading_font')
        };
    }

    function send() {
        if (!last || !frame || !frame.contentWindow || !config.origin) {
            return;
        }

        frame.contentWindow.postMessage({
            type: 'kontor-shop-design',
            css: last.css,
            customCss: last.customCss,
            effect: last.effect
        }, config.origin);
    }

    function format(template, a, b) {
        return template.replace('%1$s', a).replace('%2$s', b);
    }

    function render(data) {
        last = data;

        if (snippet) {
            snippet.textContent = data.snippet;
        }

        if (contrast) {
            if (data.contrast === null || data.contrast === undefined) {
                contrast.textContent = '';
                contrast.className = 'ks-contrast';
            } else {
                var good = data.contrast >= 4.5;
                var color = data.onPrimary === config.darkText ? config.i18n.dark : config.i18n.white;
                var ratio = String(data.contrast).replace('.', ',');

                contrast.innerHTML = '';
                var dot = document.createElement('span');
                dot.className = 'ks-contrast__sample';
                dot.style.background = data.primary;
                dot.style.color = data.onPrimary;
                dot.textContent = 'Aa';
                contrast.appendChild(dot);
                contrast.appendChild(document.createTextNode(' ' + format(good ? config.i18n.contrastGood : config.i18n.contrastBad, color, ratio)));
                contrast.className = 'ks-contrast ' + (good ? 'is-good' : 'is-bad');
            }
        }

        send();
    }

    function update() {
        var body = new FormData();
        var values = design();
        var id = ++requestId;

        body.append('action', config.action);
        body.append('nonce', config.nonce);
        Object.keys(values).forEach(function (key) {
            body.append('design[' + key + ']', values[key]);
        });
        body.append('card_effect', value('card_effect'));

        if (form.elements.custom_css) {
            body.append('custom_css', form.elements.custom_css.value);
        }

        fetch(config.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                if (id === requestId && json && json.success) {
                    render(json.data);
                }
            })
            .catch(function () { /* Vorschau ist Zusatz - Formular funktioniert ohne */ });
    }

    function schedule() {
        window.clearTimeout(timer);
        timer = window.setTimeout(update, 180);
    }

    function syncRadius() {
        if (radiusOut && form.elements.radius) {
            radiusOut.textContent = form.elements.radius.value + ' ' + config.i18n.px;
        }
    }

    form.addEventListener('input', function (event) {
        var target = event.target;

        // Farbe/Regler beruehrt -> "eigene" automatisch waehlen.
        var mode = target.getAttribute && target.getAttribute('data-ks-mode');

        if (mode && form.elements[mode]) {
            var modeField = form.elements[mode];

            if (modeField.type === 'checkbox') {
                modeField.checked = true;
            } else {
                Array.prototype.forEach.call(form.querySelectorAll('input[name="' + mode + '"]'), function (radio) {
                    radio.checked = radio.value === 'own';
                });
            }
        }

        if (target.name === 'radius') {
            syncRadius();
        }

        if (target.hasAttribute && target.hasAttribute('data-ks-design')) {
            schedule();
        }
    });

    form.addEventListener('change', function (event) {
        if (event.target.hasAttribute && event.target.hasAttribute('data-ks-design')) {
            schedule();
        }
    });

    var useTheme = document.querySelector('[data-ks-use-theme]');

    if (useTheme) {
        useTheme.addEventListener('click', function () {
            var radio = form.querySelector('input[name="primary_mode"][value="theme"]');

            if (radio) {
                radio.checked = true;
                radio.dispatchEvent(new Event('change', { bubbles: true }));
            }

            var cart = form.querySelector('input[name="cart_mode"][value="primary"]');

            if (cart) {
                cart.checked = true;
                cart.dispatchEvent(new Event('change', { bubbles: true }));
            }

            var box = useTheme.closest('.ks-design__legacy');

            if (box) {
                box.hidden = true;
            }
        });
    }

    // "Computer" rendert die Seite 1280 px breit und verkleinert sie auf die Spaltenbreite,
    // damit das Theme seine Desktop-Ansicht zeigt (sonst saehe man in der schmalen Spalte die Handy-Ansicht).
    var DESKTOP_WIDTH = 1280;
    var wrap = document.querySelector('.ks-design__frame-wrap');

    function fit() {
        if (!wrap || !frame) {
            return;
        }

        var phone = wrap.classList.contains('is-phone');
        var height = Math.min(window.innerHeight * 0.75, 900);
        var scale = phone ? 1 : Math.min(1, wrap.clientWidth / DESKTOP_WIDTH);

        frame.style.width = phone ? '' : (DESKTOP_WIDTH + 'px');
        frame.style.height = Math.round(height / scale) + 'px';
        frame.style.transform = scale < 1 ? 'scale(' + scale + ')' : '';
        wrap.style.height = Math.round(height) + 'px';
    }

    Array.prototype.forEach.call(document.querySelectorAll('input[name="ks_preview_width"]'), function (radio) {
        radio.addEventListener('change', function () {
            if (wrap) {
                wrap.classList.toggle('is-phone', radio.value === 'phone' && radio.checked);
                window.requestAnimationFrame(fit);
            }
        });
    });

    window.addEventListener('resize', fit);
    fit();

    var copy = document.querySelector('[data-ks-copy-snippet]');

    if (copy && snippet) {
        copy.addEventListener('click', function () {
            function select() {
                var range = document.createRange();
                range.selectNodeContents(snippet);
                var selection = window.getSelection();
                selection.removeAllRanges();
                selection.addRange(range);
                copy.textContent = config.i18n.copyManual;
            }

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(snippet.textContent).then(function () {
                    copy.textContent = config.i18n.copied;
                }, select);
            } else {
                select();
            }
        });
    }

    if (frame) {
        frame.addEventListener('load', send);
    }

    window.addEventListener('message', function (event) {
        if (event.origin === config.origin && event.data && event.data.type === 'kontor-shop-design-ready') {
            send();
        }
    });

    syncRadius();
    update();
})();
