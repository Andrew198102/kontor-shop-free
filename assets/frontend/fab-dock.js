/**
 * FabDock v1 – gemeinsame Leiste fuer schwebende Knoepfe unten rechts
 * (Warenkorb, Spiel-Koerbchen, „nach oben“ …), damit sie sich nicht ueberlagern.
 *
 * Dieselbe Datei liegt in mehreren Paketen (Theme, Kontor Shop,
 * Meridian Hunt) – bitte inhaltlich synchron halten. Wer zuerst laedt, legt
 * das Dock an; spaetere Kopien tun nichts.
 *
 *   FabDock.add(element, order)  Knopf einreihen. order: kleiner = weiter unten
 *                                (Standard: Warenkorb 10, Spiel 20, nach oben 30).
 *                                data-fab-order am Element hat Vorrang (Theme kann umsortieren).
 *   FabDock.update()             Hoehe neu messen (--fab-dock-height auf <html>).
 *
 * Jeder Knopf steuert seine Sichtbarkeit selbst (hidden-Attribut oder Klasse
 * fab-dock-hidden); unsichtbare nehmen keinen Platz ein. Hinweise/Toasts sollten
 * bottom: calc(var(--fab-dock-height, 72px) + 16px) verwenden.
 */
(function () {
  'use strict';

  if (window.FabDock) {
    return;
  }

  var dock = null;
  var CSS = '.fab-dock{position:fixed;right:max(16px,env(safe-area-inset-right));bottom:max(16px,env(safe-area-inset-bottom));' +
    'z-index:99990;display:flex;flex-direction:column-reverse;align-items:center;gap:12px;pointer-events:none}' +
    '.fab-dock>*{position:relative!important;inset:auto!important;left:auto!important;right:auto!important;top:auto!important;bottom:auto!important;margin:0!important;pointer-events:auto}' +
    '.fab-dock>[hidden],.fab-dock>.fab-dock-hidden{display:none!important}';

  function ensure() {
    if (dock) {
      return dock;
    }
    var style = document.createElement('style');
    style.id = 'fab-dock-style';
    style.textContent = CSS;
    document.head.appendChild(style);

    dock = document.createElement('div');
    dock.className = 'fab-dock';
    dock.setAttribute('data-fab-dock', '1');
    document.body.appendChild(dock);

    if (window.ResizeObserver) {
      new ResizeObserver(update).observe(dock);
    }
    if (window.MutationObserver) {
      new MutationObserver(update).observe(dock, { attributes: true, subtree: true, childList: true, attributeFilter: ['hidden', 'class'] });
    }
    return dock;
  }

  function update() {
    if (!dock) {
      return;
    }
    var h = 0;
    Array.prototype.forEach.call(dock.children, function (c) {
      if (c.offsetParent !== null) {
        h += c.offsetHeight + 12;
      }
    });
    document.documentElement.style.setProperty('--fab-dock-height', (h > 0 ? h + 4 : 0) + 'px');
  }

  function add(el, order) {
    if (!el || el.nodeType !== 1) {
      return;
    }
    var o = el.getAttribute('data-fab-order');
    el.style.order = String(o !== null && o !== '' ? parseInt(o, 10) : (order || 50));
    var run = function () {
      ensure().appendChild(el);
      update();
    };
    if (document.body) {
      run();
    } else {
      document.addEventListener('DOMContentLoaded', run);
    }
  }

  window.FabDock = { add: add, update: update, version: 1 };
})();
