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

  // Melintir popups: <div class="mel-popup" data-mode="load|click" ...> (see Theme::inject_popup).
  document.querySelectorAll('[data-popup]').forEach(function (root) {
    var id = root.getAttribute('data-popup');
    var mode = root.getAttribute('data-mode') || 'load';
    var delay = (parseInt(root.getAttribute('data-delay') || '3', 10) || 0) * 1000;
    var selector = root.getAttribute('data-selector') || '';
    var freq = root.getAttribute('data-frequency') || 'always';
    var key = 'mel-popup-' + id;
    var seen = function () {
      try {
        return freq === 'session' && window.sessionStorage.getItem(key);
      } catch (e) {
        return false;
      }
    };
    var mark = function () {
      try {
        if (freq === 'session') window.sessionStorage.setItem(key, '1');
      } catch (e) {}
    };
    var open = function () {
      if (seen()) return;
      root.hidden = false;
      document.body.classList.add('mel-lock');
      mark();
    };
    var close = function () {
      root.hidden = true;
      document.body.classList.remove('mel-lock');
    };
    root.querySelectorAll('[data-close]').forEach(function (b) {
      b.addEventListener('click', close);
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !root.hidden) close();
    });
    if (mode === 'click' && selector) {
      var targets = [];
      try {
        targets = document.querySelectorAll(selector);
      } catch (e) {
        targets = [];
      }
      targets.forEach(function (el) {
        el.addEventListener('click', function (ev) {
          ev.preventDefault();
          open();
        });
      });
    } else {
      window.setTimeout(open, delay);
    }
  });

  // Animated counters: <span data-count="1234"> in .mel-counter.
  var counters = document.querySelectorAll('[data-count]');
  if (!counters.length || !('IntersectionObserver' in window)) {
    counters.forEach(function (el) {
      el.textContent = el.getAttribute('data-count');
    });
    return;
  }
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (!entry.isIntersecting) return;
      var el = entry.target;
      io.unobserve(el);
      var target = parseInt(el.getAttribute('data-count'), 10) || 0;
      var t0 = null;
      var dur = 1200;
      function tick(t) {
        if (!t0) t0 = t;
        var p = Math.min(1, (t - t0) / dur);
        el.textContent = String(Math.round(target * (1 - Math.pow(1 - p, 3))));
        if (p < 1) requestAnimationFrame(tick);
      }
      requestAnimationFrame(tick);
    });
  });
  counters.forEach(function (el) {
    io.observe(el);
  });
})();
