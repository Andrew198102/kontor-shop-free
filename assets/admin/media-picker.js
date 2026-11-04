/**
 * Bildfeld mit Drag-and-drop-Upload (Admin\MediaPicker).
 *
 * Upload ueber die WordPress-REST-Schnittstelle (POST /wp/v2/media) per
 * XMLHttpRequest, weil nur XHR echte Upload-Fortschritte meldet. Ohne Plupload
 * und ohne die WP-Medien-Oberflaeche; die Mediathek (wp.media) bleibt als
 * Zweitweg zum Auswaehlen vorhandener Bilder. Bewusst ohne Build-Schritt.
 * Die reinen Hilfsfunktionen (util) sind unabhaengig vom DOM und per Node
 * testbar (tests/media-picker.test.js).
 */
(function () {
    'use strict';

    var EXTENSION_TYPES = { jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif', webp: 'image/webp' };
    var UPLOAD_TIMEOUT_MS = 120000;

    /* ---------- reine Hilfsfunktionen ---------- */

    var util = {
        formatBytes: function (bytes) {
            if (bytes >= 1048576) {
                return (Math.round(bytes / 104857.6) / 10).toString().replace('.', ',') + ' MB';
            }

            return Math.max(1, Math.round(bytes / 1024)) + ' KB';
        },

        /** Ersetzt %s/%d bzw. %1$s/%2$s der Reihe nach. */
        format: function (template, args) {
            var index = 0;

            return String(template).replace(/%(\d+\$)?[sd]/g, function (match, position) {
                var value = position ? args[parseInt(position, 10) - 1] : args[index++];

                return value === undefined ? '' : String(value);
            });
        },

        /** @returns {string|null} Fehlercode ('badType'|'tooBig') oder null, wenn die Datei passt */
        validateFile: function (file, allowedTypes, maxBytes) {
            var type = file.type;

            if (!type) {
                var ext = (file.name.split('.').pop() || '').toLowerCase();

                type = EXTENSION_TYPES[ext] || '';
            }

            if (allowedTypes.indexOf(type) === -1) {
                return 'badType';
            }

            if (maxBytes > 0 && file.size > maxBytes) {
                return 'tooBig';
            }

            return null;
        },

        /** Bestes Vorschaubild aus einer REST-Antwort (medium -> medium_large -> Original). */
        previewUrl: function (attachment) {
            var sizes = attachment && attachment.media_details && attachment.media_details.sizes;

            if (sizes) {
                var order = ['medium', 'medium_large', 'large', 'full'];

                for (var i = 0; i < order.length; i++) {
                    if (sizes[order[i]] && sizes[order[i]].source_url) {
                        return sizes[order[i]].source_url;
                    }
                }
            }

            return attachment && attachment.source_url ? attachment.source_url : '';
        },

        fileTitle: function (name) {
            return String(name).replace(/\.[^.]+$/, '').replace(/[-_]+/g, ' ').trim();
        },

        /** Ordnet einen HTTP-Status einem Textschluessel zu. */
        errorKey: function (status) {
            if (status === 0) {
                return 'network';
            }

            if (status === 401 || status === 403) {
                return 'expired';
            }

            if (status === 404 || status === 405) {
                return 'restBlocked';
            }

            return 'failed';
        }
    };

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = util;
    }

    if (typeof document === 'undefined' || !document.addEventListener) {
        return;
    }

    /* ---------- DOM ---------- */

    var config = window.kontorShopMedia || { i18n: {}, types: [], maxBytes: 0 };
    var t = config.i18n || {};

    function $(root, selector) {
        return root.querySelector(selector);
    }

    function pickerOf(node) {
        return node && node.closest ? node.closest('.ks-media') : null;
    }

    function state(picker) {
        if (!picker.ksState) {
            picker.ksState = { xhr: null, undo: null, uploaded: {}, ask: null };
        }

        return picker.ksState;
    }

    function setStatus(picker, message, kind) {
        var el = $(picker, '.ks-media__status');

        el.textContent = message || '';
        el.classList.toggle('is-error', kind === 'error');
        el.classList.toggle('is-success', kind === 'success');
    }

    function isUploading(picker) {
        return !!state(picker).xhr;
    }

    function setProgress(picker, percent, processing) {
        var wrap = $(picker, '.ks-media__progress');
        var bar = $(picker, '.ks-media__bar');

        wrap.hidden = percent === null;
        wrap.classList.toggle('is-processing', !!processing);

        if (percent !== null) {
            bar.style.width = processing ? '' : percent + '%';
            bar.setAttribute('aria-valuenow', String(Math.round(percent)));
        }
    }

    function setUploading(picker, uploading) {
        $(picker, '.ks-dropzone').classList.toggle('is-uploading', uploading);
        $(picker, '.ks-media__cancel').hidden = !uploading;
        $(picker, '.ks-media__library').disabled = uploading;
        $(picker, '.ks-media__remove').disabled = uploading;
    }

    function updateHint(picker) {
        var hint = $(picker, '.ks-dropzone__hint');
        var has = picker.classList.contains('has-image');

        hint.textContent = has
            ? t.replaceHint || ''
            : util.format(t.hint || '%s', [util.formatBytes(config.maxBytes || 0)]);
    }

    /**
     * Zeigt ein Bild als Vorschau; bis es geladen ist, schimmert die Flaeche.
     */
    function showPreview(picker, url) {
        var box = $(picker, '.ks-media__preview');

        box.innerHTML = '';

        if (!url) {
            return;
        }

        var img = document.createElement('img');

        img.className = 'ks-media__img';
        img.alt = t.previewAlt || '';
        box.classList.add('is-loading');
        img.onload = img.onerror = function () { box.classList.remove('is-loading'); };
        img.src = url;
        box.appendChild(img);
    }

    function applyImage(picker, id, url) {
        var previous = $(picker, '.ks-media__id').value;

        if (previous !== '' && previous !== String(id)) {
            askAboutDroppedUpload(picker, previous, '');
        } else {
            hideAsk(picker);
        }

        $(picker, '.ks-media__id').value = String(id);
        picker.classList.add('has-image');
        showPreview(picker, url);
        $(picker, '.ks-media__remove').hidden = false;
        $(picker, '.ks-media__undo').hidden = true;
        state(picker).undo = null;
        updateHint(picker);
    }

    function clearImage(picker) {
        var idField = $(picker, '.ks-media__id');
        var img = $(picker, '.ks-media__img');

        if (idField.value !== '') {
            state(picker).undo = { id: idField.value, url: img ? img.getAttribute('src') : '' };
            askAboutDroppedUpload(picker, idField.value, '');
        }

        idField.value = '';
        picker.classList.remove('has-image');
        showPreview(picker, '');
        $(picker, '.ks-media__remove').hidden = true;
        $(picker, '.ks-media__undo').hidden = !state(picker).undo;
        updateHint(picker);
        setStatus(picker, t.removed, null);
    }

    function undoRemove(picker) {
        var undo = state(picker).undo;

        if (!undo) {
            return;
        }

        applyImage(picker, undo.id, undo.url);
        setStatus(picker, '', null);
    }

    /* ---------- Nachfrage: gerade hochgeladenes Bild loeschen oder behalten? ---------- */

    function restCall(method, url) {
        return fetch(url, {
            method: method,
            headers: { 'X-WP-Nonce': config.nonce },
            credentials: 'same-origin'
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            return response.json();
        });
    }

    function hideAsk(picker) {
        state(picker).ask = null;
        $(picker, '.ks-media__ask').hidden = true;
    }

    /**
     * Ein in dieser Sitzung hochgeladenes, noch nicht gespeichertes Bild wird
     * ersetzt/entfernt: sofort fragen, ob es aus der Mediathek verschwinden soll.
     * (Bilder aus frueheren Sitzungen behandelt der Server nach dem Speichern.)
     */
    function askAboutDroppedUpload(picker, id, name) {
        var st = state(picker);

        if (!id || !st.uploaded[id]) {
            return;
        }

        st.ask = id;
        $(picker, '.ks-media__ask-text').textContent = util.format(t.askText || '%s', [st.uploaded[id] || name || '']);
        $(picker, '.ks-media__ask').hidden = false;
    }

    document.addEventListener('click', function (event) {
        var del = event.target.closest ? event.target.closest('.ks-media__ask-delete') : null;
        var keep = event.target.closest ? event.target.closest('.ks-media__ask-keep') : null;

        if (!del && !keep) {
            return;
        }

        var picker = pickerOf(event.target);
        var id = state(picker).ask;

        event.preventDefault();

        if (!id) {
            return;
        }

        var url = (config.mediaUrl || '') + '/' + id;

        hideAsk(picker);

        if (del) {
            restCall('DELETE', url)
                .then(function () {
                    delete state(picker).uploaded[id];

                    // Ein geloeschtes Bild darf nicht ueber "Rueckgaengig" zurueckkehren.
                    if (state(picker).undo && String(state(picker).undo.id) === String(id)) {
                        state(picker).undo = null;
                        $(picker, '.ks-media__undo').hidden = true;
                    }

                    setStatus(picker, t.deleted, 'success');
                })
                .catch(function () { setStatus(picker, t.deleteFailed, 'error'); });
        } else {
            restCall('POST', url + '/keep')
                .then(function () { setStatus(picker, t.kept, null); })
                .catch(function () { setStatus(picker, t.kept, null); });
        }
    }, true);

    /* ---------- Upload ---------- */

    function upload(picker, file) {
        var problem = util.validateFile(file, config.types || [], config.maxBytes || 0);

        if (problem === 'badType') {
            setStatus(picker, t.badType, 'error');

            return;
        }

        if (problem === 'tooBig') {
            setStatus(picker, util.format(t.tooBig || '', [util.formatBytes(file.size), util.formatBytes(config.maxBytes)]), 'error');

            return;
        }

        var nameField = document.getElementById('ks-p-name');
        var data = new FormData();

        data.append('file', file, file.name);
        data.append('title', util.fileTitle(file.name));
        data.append('kontor_shop_upload', '1'); // markiert das Bild als vom Shop verwaltet (Aufraeumen)

        if (nameField && nameField.value.trim() !== '') {
            data.append('alt_text', nameField.value.trim());
        }

        var xhr = new XMLHttpRequest();

        state(picker).xhr = xhr;
        setUploading(picker, true);
        setStatus(picker, util.format(t.uploading || '%d', [0]), null);
        setProgress(picker, 0, false);

        xhr.open('POST', config.restUrl);
        xhr.setRequestHeader('X-WP-Nonce', config.nonce);
        xhr.timeout = UPLOAD_TIMEOUT_MS;
        xhr.responseType = 'text';

        xhr.upload.onprogress = function (event) {
            if (!event.lengthComputable) {
                return;
            }

            var percent = Math.min(100, (event.loaded / event.total) * 100);

            setProgress(picker, percent, false);
            setStatus(picker, util.format(t.uploading || '%d', [Math.round(percent)]), null);
        };

        // Bytes uebertragen, Server arbeitet noch (Vorschaubilder erzeugen).
        xhr.upload.onload = function () {
            setProgress(picker, 100, true);
            setStatus(picker, t.processing, null);
        };

        function finish() {
            state(picker).xhr = null;
            setUploading(picker, false);
            setProgress(picker, null, false);
        }

        xhr.onload = function () {
            var body = null;

            try {
                body = JSON.parse(xhr.responseText);
            } catch (e) {
                body = null;
            }

            finish();

            if (xhr.status >= 200 && xhr.status < 300 && body && body.id) {
                state(picker).uploaded[body.id] = file.name;
                applyImage(picker, body.id, util.previewUrl(body));
                setStatus(picker, util.format(t.done || '%s', [file.name]), 'success');

                return;
            }

            var key = util.errorKey(xhr.status);
            var message = key === 'failed'
                ? util.format(t.failed || '%s', [body && body.message ? body.message : 'HTTP ' + xhr.status])
                : t[key];

            setStatus(picker, message, 'error');
        };

        xhr.onerror = function () { finish(); setStatus(picker, t.network, 'error'); };
        xhr.ontimeout = function () { finish(); setStatus(picker, t.timeout, 'error'); };
        xhr.onabort = function () { finish(); setStatus(picker, t.cancelled, null); };

        xhr.send(data);
    }

    function handleFiles(picker, files) {
        if (!files || !files.length || isUploading(picker)) {
            return;
        }

        // Mehrere Dateien gezogen: es wird nur die erste verwendet (ein Artikel hat ein Bild).
        upload(picker, files[0]);
    }

    /* ---------- Ereignisse (delegiert) ---------- */

    document.addEventListener('click', function (event) {
        var picker = pickerOf(event.target);

        if (!picker) {
            return;
        }

        if (event.target.closest('.ks-media__remove')) {
            event.preventDefault();
            clearImage(picker);

            return;
        }

        if (event.target.closest('.ks-media__undo')) {
            event.preventDefault();
            undoRemove(picker);

            return;
        }

        if (event.target.closest('.ks-media__cancel')) {
            event.preventDefault();

            if (state(picker).xhr) {
                state(picker).xhr.abort();
            }

            return;
        }

        if (event.target.closest('.ks-media__library')) {
            event.preventDefault();
            openLibrary(picker);

            return;
        }

        if (event.target.closest('.ks-dropzone') && !isUploading(picker)) {
            $(picker, '.ks-media__file').click();
        }
    });

    document.addEventListener('keydown', function (event) {
        var zone = event.target.closest ? event.target.closest('.ks-dropzone') : null;

        if (zone && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            zone.click();
        }
    });

    document.addEventListener('change', function (event) {
        if (!event.target.classList || !event.target.classList.contains('ks-media__file')) {
            return;
        }

        var picker = pickerOf(event.target);

        handleFiles(picker, event.target.files);
        event.target.value = ''; // gleiche Datei spaeter erneut waehlbar
    });

    ['dragenter', 'dragover'].forEach(function (name) {
        document.addEventListener(name, function (event) {
            var hasFiles = event.dataTransfer && Array.prototype.indexOf.call(event.dataTransfer.types || [], 'Files') !== -1;

            if (!hasFiles) {
                return;
            }

            // Sonst oeffnet der Browser die Datei und verlaesst die Seite, wenn man die Zone verfehlt.
            event.preventDefault();

            // Die Bildergalerie hat ihre eigene Ablage (product-editor.js).
            if (event.target.closest && event.target.closest('[data-ks-gallery]')) {
                return;
            }

            var zone = event.target.closest ? event.target.closest('.ks-dropzone') : null;

            document.querySelectorAll('.ks-dropzone.is-dragover').forEach(function (other) {
                if (other !== zone) {
                    other.classList.remove('is-dragover');
                }
            });

            if (zone) {
                zone.classList.add('is-dragover');
                event.dataTransfer.dropEffect = 'copy';
            } else {
                event.dataTransfer.dropEffect = 'none';
            }
        });
    });

    document.addEventListener('dragleave', function (event) {
        var zone = event.target.closest ? event.target.closest('.ks-dropzone') : null;

        if (zone && !zone.contains(event.relatedTarget)) {
            zone.classList.remove('is-dragover');
        }
    });

    document.addEventListener('drop', function (event) {
        var hasFiles = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files.length;

        if (!hasFiles) {
            return;
        }

        event.preventDefault();

        if (event.target.closest && event.target.closest('[data-ks-gallery]')) {
            return; // Galerie: product-editor.js
        }

        var zone = event.target.closest ? event.target.closest('.ks-dropzone') : null;

        document.querySelectorAll('.ks-dropzone.is-dragover').forEach(function (other) { other.classList.remove('is-dragover'); });

        if (!zone) {
            return;
        }

        var picker = pickerOf(zone);

        handleFiles(picker, event.dataTransfer.files);
    });

    // Speichern waehrend eines Uploads verhindern (sonst waere die Bild-ID noch leer/alt).
    document.addEventListener('submit', function (event) {
        var busy = null;

        event.target.querySelectorAll('.ks-media').forEach(function (picker) {
            if (isUploading(picker)) {
                busy = picker;
            }
        });

        if (busy) {
            event.preventDefault();
            setStatus(busy, t.busy, 'error');
        }
    }, true);

    window.addEventListener('beforeunload', function (event) {
        var uploading = false;

        document.querySelectorAll('.ks-media').forEach(function (picker) {
            uploading = uploading || isUploading(picker);
        });

        if (uploading) {
            event.preventDefault();
            event.returnValue = '';
        }
    });

    /* ---------- Mediathek (Zweitweg) ---------- */

    function openLibrary(picker) {
        if (!window.wp || !window.wp.media) {
            return;
        }

        var frame = window.wp.media({
            title: t.libraryTitle || '',
            library: { type: 'image' },
            multiple: false
        });

        frame.on('select', function () {
            var attachment = frame.state().get('selection').first().toJSON();
            var sizes = attachment.sizes || {};
            var url = (sizes.medium && sizes.medium.url) || (sizes.large && sizes.large.url) || attachment.url;

            applyImage(picker, attachment.id, url);
            setStatus(picker, t.selected, 'success');
        });

        frame.open();
    }

    document.querySelectorAll('.ks-media').forEach(updateHint);
})();
