/**
 * Plugin-Liste: beim "Deaktivieren" von Kontor Shop fragen, was beim
 * spaeteren Loeschen passieren soll (Admin\UninstallChoice).
 */
(function () {
    'use strict';

    var cfg = window.kontorShopUninstall;

    if (!cfg) {
        return;
    }

    var row = document.querySelector('tr[data-plugin="' + cfg.plugin + '"]');
    var link = row ? row.querySelector('.deactivate a') : null;

    if (!link || typeof HTMLDialogElement === 'undefined') {
        return;
    }

    var t = cfg.i18n;

    function option(value) {
        return '<label class="ks-uc__option">'
            + '<input type="radio" name="ks-uninstall-mode" value="' + value + '"' + (cfg.mode === value ? ' checked' : '') + (cfg.forced ? ' disabled' : '') + ' /> '
            + '<strong></strong><br /><span class="description"></span></label>';
    }

    var dialog = document.createElement('dialog');
    dialog.setAttribute('aria-labelledby', 'ks-uninstall-title');
    dialog.className = 'ks-uc';
    dialog.innerHTML = '<h2 id="ks-uninstall-title" class="ks-uc__title"></h2><p class="ks-intro"></p>'
        + option('keep') + option('purge')
        + '<p class="ks-forced notice notice-warning inline ks-uc__notice" hidden></p>'
        + '<p class="ks-error notice notice-error inline ks-uc__notice" hidden></p>'
        + '<p class="ks-uc__actions">'
        + '<button type="button" class="button ks-cancel"></button> <button type="button" class="button button-primary ks-confirm"></button></p>';

    // Texte per textContent (keine HTML-Einschleusung ueber Uebersetzungen)
    dialog.querySelector('h2').textContent = t.title;
    dialog.querySelector('.ks-intro').textContent = t.intro;
    var labels = dialog.querySelectorAll('label');
    labels[0].querySelector('strong').textContent = t.keep;
    labels[0].querySelector('.description').textContent = t.keepHint;
    labels[1].querySelector('strong').textContent = t.purge;
    labels[1].querySelector('.description').textContent = t.purgeHint;
    dialog.querySelector('.ks-cancel').textContent = t.cancel;
    dialog.querySelector('.ks-confirm').textContent = t.confirm;

    if (cfg.forced) {
        var forced = dialog.querySelector('.ks-forced');
        forced.textContent = t.forced;
        forced.hidden = false;
    }

    document.body.appendChild(dialog);

    dialog.querySelector('.ks-cancel').addEventListener('click', function () {
        dialog.close();
    });

    dialog.querySelector('.ks-confirm').addEventListener('click', function () {
        var target = link.href;
        var checked = dialog.querySelector('input[name="ks-uninstall-mode"]:checked');

        if (cfg.forced || !checked) {
            window.location.href = target;
            return;
        }

        var body = new URLSearchParams({ action: cfg.action, nonce: cfg.nonce, mode: checked.value });
        this.disabled = true;

        fetch(cfg.ajax, { method: 'POST', credentials: 'same-origin', body: body })
            .then(function (response) { return response.json(); })
            .then(function (json) {
                if (!json || !json.success) {
                    throw new Error('save failed');
                }

                window.location.href = target;
            })
            .catch(function () {
                var error = dialog.querySelector('.ks-error');
                error.textContent = t.error;
                error.hidden = false;
                dialog.querySelector('.ks-confirm').disabled = false;
            });
    });

    link.addEventListener('click', function (event) {
        event.preventDefault();
        dialog.showModal();
    });
}());
