/**
 * Melintir frontend (<30KB budget, currently ~1KB).
 * Only interactive widgets need JS: tabs. Everything else is HTML+CSS.
 */
(function () {
  document.querySelectorAll('[data-tabs]').forEach(function (root) {
    var btns = root.querySelectorAll('[data-tab]');
    var panels = root.querySelectorAll('[data-panel]');
    btns.forEach(function (btn) {
      btn.addEventListener('click', function () {
        var i = btn.getAttribute('data-tab');
        btns.forEach(function (b) { b.setAttribute('aria-selected', b === btn ? 'true' : 'false'); });
        panels.forEach(function (p) {
          if (p.getAttribute('data-panel') === i) p.removeAttribute('hidden');
          else p.setAttribute('hidden', '');
        });
      });
    });
    if (btns[0]) btns[0].setAttribute('aria-selected', 'true');
  });
})();
