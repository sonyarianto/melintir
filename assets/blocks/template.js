/**
 * Melintir Template block (melintir/template).
 * Embeds a Theme Template inside Gutenberg/FSE content. Plain JS on purpose:
 * no build step, only WP-core script dependencies. Rendering is server-side
 * (PHP render callback), so the editor shows a placeholder, never a preview
 * that could drift from the frontend.
 */
(function (blocks, element, components, i18n) {
  if (!blocks || !element || !components) return;
  var el = element.createElement;
  var SelectControl = components.SelectControl;
  var Placeholder = components.Placeholder;
  var __ = i18n ? i18n.__ : function (s) { return s; };

  var cache = null;
  function loadTemplates() {
    if (cache) return cache;
    var root = window.wpApiSettings ? window.wpApiSettings.root : '/wp-json/';
    var nonce = window.wpApiSettings ? window.wpApiSettings.nonce : '';
    cache = fetch(root + 'melintir/v1/block-templates', {
      headers: nonce ? { 'X-WP-Nonce': nonce } : {},
    })
      .then(function (r) { return r.ok ? r.json() : []; })
      .then(function (j) { return Array.isArray(j) ? j : []; })
      .catch(function () { return []; });
    return cache;
  }

  blocks.registerBlockType('melintir/template', {
    title: __('Melintir Template', 'melintir'),
    description: __('Embed a Melintir theme template.', 'melintir'),
    icon: 'layout',
    category: 'widgets',
    attributes: {
      templateId: { type: 'number', default: 0 },
    },
    edit: function (props) {
      var attrs = props.attributes;
      var setAttributes = props.setAttributes;
      var state = element.useState(null);
      var templates = state[0];
      var setTemplates = state[1];
      element.useEffect(function () {
        var alive = true;
        loadTemplates().then(function (list) {
          if (alive) setTemplates(list);
        });
        return function () { alive = false; };
      }, []);
      var options = [{ value: 0, label: __('— Select —', 'melintir') }].concat(
        (templates || []).map(function (t) {
          return { value: t.id, label: t.title + (t.location ? ' (' + t.location + ')' : '') };
        })
      );
      var current = templates
        ? templates.filter(function (t) { return t.id === attrs.templateId; })[0]
        : null;
      return el(
        Placeholder,
        { icon: 'layout', label: __('Melintir Template', 'melintir') },
        templates === null
          ? el('p', null, __('Loading…', 'melintir'))
          : el(SelectControl, {
              value: attrs.templateId,
              options: options,
              onChange: function (v) { setAttributes({ templateId: parseInt(v, 10) || 0 }); },
            }),
        current ? el('p', null, current.title) : null
      );
    },
    save: function () {
      return null;
    },
  });
})(window.wp.blocks, window.wp.element, window.wp.components, window.wp.i18n);
