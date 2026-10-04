/**
 * Melintir frontend (<30KB budget, currently ~4.5KB).
 * Only interactive widgets need JS: tabs, popups, counters, countdowns.
 * Everything else is HTML+CSS.
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

  // Countdowns: <div data-countdown="UNIXTS"> with [data-d/h/m/s] cells.
  // Server paints the first values; this ticks every second until zero.
  document.querySelectorAll('[data-countdown]').forEach(function (root) {
    var target = parseInt(root.getAttribute('data-countdown'), 10) || 0;
    var d = root.querySelector('[data-d]');
    var h = root.querySelector('[data-h]');
    var m = root.querySelector('[data-m]');
    var s = root.querySelector('[data-s]');
    var pad = function (n) { return String(n).padStart(2, '0'); };
    (function tick() {
      var diff = Math.max(0, target - Math.floor(Date.now() / 1000));
      if (d) d.textContent = Math.floor(diff / 86400);
      if (h) h.textContent = pad(Math.floor(diff % 86400 / 3600));
      if (m) m.textContent = pad(Math.floor(diff % 3600 / 60));
      if (s) s.textContent = pad(diff % 60);
      if (diff > 0) window.setTimeout(tick, 1000);
    })();
  });

  // Scroll entrance: [data-anim] hides via .mel-anim only here (added at
  // runtime, so no-JS visitors never lose content), then reveals on intersect
  // with an optional data-delay. Honors reduced-motion via CSS.
  (function () {
    var els = document.querySelectorAll('[data-anim]');
    if (!els.length) return;
    var reveal = function (el) {
      var delay = parseInt(el.getAttribute('data-delay') || '0', 10) || 0;
      window.setTimeout(function () { el.classList.add('mel-in'); }, Math.max(0, Math.min(2000, delay)));
    };
    if (!('IntersectionObserver' in window)) {
      els.forEach(function (el) { el.classList.add('mel-anim'); reveal(el); });
      return;
    }
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        io.unobserve(entry.target);
        reveal(entry.target);
      });
    });
    els.forEach(function (el) {
      el.classList.add('mel-anim');
      io.observe(el);
    });
  })();

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
