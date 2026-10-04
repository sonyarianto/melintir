import type { MelDoc } from './types';

/**
 * JS mirror of core/css.rs + Renderer.php. Used until WASM loads,
 * and as fallback if .wasm 404s (e.g. not built yet).
 */
export function generateCssFallback(doc: MelDoc): string {
  let out = '.mel-page{box-sizing:border-box}.mel-container{display:flex;flex-direction:column}\n';
  out += '.mel-loop{display:grid;gap:16px}.mel-cols-1{grid-template-columns:1fr}.mel-cols-2{grid-template-columns:repeat(2,1fr)}.mel-cols-3{grid-template-columns:repeat(3,1fr)}.mel-cols-4{grid-template-columns:repeat(4,1fr)}@media(max-width:767px){.mel-loop{grid-template-columns:1fr}}\n';
  out += '.mel-gallery{display:grid;gap:12px}.mel-gcols-3{grid-template-columns:repeat(3,1fr)}.mel-acc-item{border:1px solid #e2e8f0;border-radius:8px;margin-bottom:8px}.mel-counter{font-size:40px;font-weight:800}.mel-testimonial{border-left:4px solid #2563eb;padding:8px 16px}\n';
  const walk = (n: any) => {
    const sel = `.mel-${String(n.id).replace(/[^a-zA-Z0-9_-]/g, '')}`;
    const decl = decls(n.style || {});
    if (decl) out += `${sel}{${decl}}\n`;
    for (const [bp, max] of [['tablet', 1024], ['mobile', 767]] as const) {
      const s = n.style?.[bp] || n.style?.responsive?.[bp];
      if (s) {
        const d = decls(s);
        if (d) out += `@media(max-width:${max}px){${sel}{${d}}}\n`;
      }
    }
    (n.elements || []).forEach(walk);
  };
  const decls = (s: any) => {
    let d = '';
    const l = s.layout || {};
    if (l.direction === 'row' || l.direction === 'column') d += `flex-direction:${l.direction};`;
    if (l.gap != null) d += `gap:${l.gap | 0}px;`;
    if (l.justify) d += `justify-content:${l.justify};`;
    if (l.align) d += `align-items:${l.align};`;
    if (l.bg) d += `background:${l.bg};`;
    if (l.padding != null) d += `padding:${l.padding | 0}px;`;
    if (l.radius != null) d += `border-radius:${l.radius | 0}px;`;
    const t = s.typo || {};
    if (t.size != null) d += `font-size:${t.size | 0}px;`;
    if (t.weight != null) d += `font-weight:${t.weight | 0};`;
    if (t.color) d += `color:${t.color};`;
    if (typeof s.customCss === 'string' && s.customCss.trim()) {
      const c = s.customCss.replace(/<\/?style[^>]*>/gi, '').replace(/<script[^>]*>.*?<\/script>/gis, '').slice(0, 2048).trim().replace(/;?$/, ';');
      d += c;
    }
    return d;
  };
  walk(doc.root);
  return out.slice(0, 100 * 1024);
}
