/**
 * Kontor Shop - Hilfe-Center: Kacheln beim Tippen filtern (ohne JavaScript
 * sucht das Formular auf dem Server).
 */
(function () {
    'use strict';

    var input = document.querySelector('[data-ks-help-search]');

    if (!input) {
        return;
    }

    var tiles = document.querySelectorAll('[data-ks-help-text]');
    var groups = document.querySelectorAll('[data-ks-help-group]');
    var none = document.querySelector('[data-ks-help-none]');

    function filter() {
        // Jedes Suchwort muss am Anfang eines Wortes stehen ("bar" findet "Barzahlung", nicht "verfügbar").
        var patterns = input.value.toLowerCase().trim().split(/\s+/).filter(Boolean).map(function (word) {
            return new RegExp('(^|[^\\p{L}\\p{N}])' + word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'u');
        });
        var visible = 0;

        Array.prototype.forEach.call(tiles, function (tile) {
            var text = tile.getAttribute('data-ks-help-text');
            var match = patterns.every(function (pattern) { return pattern.test(text); });
            tile.hidden = !match;
            visible += match ? 1 : 0;
        });

        Array.prototype.forEach.call(groups, function (group) {
            group.hidden = !group.querySelector('[data-ks-help-text]:not([hidden])');
        });

        if (none) {
            none.hidden = visible > 0;
        }
    }

    input.addEventListener('input', filter);
})();
