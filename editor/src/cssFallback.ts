import type { MelDoc } from './types';

/**
 * JS mirror of core/css.rs + Renderer.php. Used until WASM loads,
 * and as fallback if .wasm 404s (e.g. not built yet).
 */
export function generateCssFallback(doc: MelDoc): string {
  let out = '.mel-page{box-sizing:border-box}.mel-container{display:flex;flex-direction:column}\n';
  out += globalsCss(doc);
  out += '.mel-loop{display:grid;gap:16px}.mel-cols-1{grid-template-columns:1fr}.mel-cols-2{grid-template-columns:repeat(2,1fr)}.mel-cols-3{grid-template-columns:repeat(3,1fr)}.mel-cols-4{grid-template-columns:repeat(4,1fr)}@media(max-width:767px){.mel-loop{grid-template-columns:1fr}}\n';
  out += '.mel-gallery{display:grid;gap:12px}.mel-gcols-3{grid-template-columns:repeat(3,1fr)}.mel-acc-item{border:1px solid #e2e8f0;border-radius:8px;margin-bottom:8px}.mel-counter{font-size:40px;font-weight:800}.mel-testimonial{border-left:4px solid #2563eb;padding:8px 16px}\n';
  out += '.mel-nav-list{display:flex;gap:16px;list-style:none;margin:0;padding:0}.mel-nav-vertical .mel-nav-list{flex-direction:column}.mel-nav-burger{display:none}\n';
  out += '.mel-badge{display:inline-block;background:#dc2626;color:#fff;font-size:12px;font-weight:700;padding:2px 8px;border-radius:999px;margin:8px 12px 0}.mel-price{font-size:16px;font-weight:700;margin:4px 12px}.mel-price del{color:#94a3b8;font-weight:400;margin-right:6px}.mel-price ins{text-decoration:none;background:none}.mel-stars{margin:0 12px;font-size:14px;color:#f59e0b;letter-spacing:2px}.mel-addcart{display:inline-block;background:#2563eb;color:#fff;font-weight:600;padding:8px 16px;border-radius:8px;text-decoration:none;margin:8px 12px 12px}\n';
  out += '.mel-ptitle{font-size:32px;margin:0 0 8px}.mel-pexcerpt{color:#475569;margin:8px 0}.mel-pimg img{width:100%;height:auto;display:block;border-radius:8px}.mel-pthumbs{display:flex;gap:8px;margin-top:8px}.mel-pthumbs img{width:72px;height:auto;border-radius:6px}\n';
  out += '.mel-menucart{display:inline-flex;align-items:center;gap:6px;text-decoration:none;color:inherit;font-weight:600}.mel-cart-icon{font-size:20px}.mel-cart-count{display:inline-block;min-width:20px;text-align:center;background:#2563eb;color:#fff;font-size:12px;font-weight:700;border-radius:999px;padding:1px 6px}.mel-cart-total{font-size:14px;color:#475569}\n';
  out += '.mel-anim{opacity:0}.mel-anim.mel-in{animation-duration:.7s;animation-fill-mode:both;animation-timing-function:ease}[data-anim="fade-up"].mel-in{animation-name:melUp}[data-anim="fade-in"].mel-in{animation-name:melIn}[data-anim="zoom-in"].mel-in{animation-name:melZoom}[data-anim="slide-left"].mel-in{animation-name:melLeft}[data-anim="slide-right"].mel-in{animation-name:melRight}@keyframes melUp{from{opacity:0;transform:translateY(28px)}to{opacity:1;transform:none}}@keyframes melIn{from{opacity:0}to{opacity:1}}@keyframes melZoom{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:none}}@keyframes melLeft{from{opacity:0;transform:translateX(36px)}to{opacity:1;transform:none}}@keyframes melRight{from{opacity:0;transform:translateX(-36px)}to{opacity:1;transform:none}}@media(prefers-reduced-motion:reduce){.mel-anim{opacity:1!important;animation:none!important}}\n';
  out += '.mel-countdown{display:flex;gap:12px}.mel-countdown span{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;text-align:center;min-width:72px}.mel-countdown b{display:block;font-size:28px}.mel-countdown small{color:#64748b}.mel-carousel{overflow:hidden}.mel-track{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory;padding-bottom:8px}.mel-slide{position:relative;flex:0 0 100%;scroll-snap-align:center}.mel-slide img{width:100%;height:auto;display:block;border-radius:8px}.mel-slide-cap{position:absolute;left:12px;bottom:12px;background:rgba(15,23,42,.65);color:#fff;padding:8px 12px;border-radius:8px;display:flex;flex-direction:column}.mel-price-table{border:1px solid #e2e8f0;border-radius:12px;padding:24px;text-align:center;background:#fff;max-width:340px}.mel-price-table.mel-hot{border-color:#2563eb;box-shadow:0 8px 24px rgba(37,99,235,.15)}.mel-pt-price{font-size:40px;font-weight:800}.mel-pt-price small{font-size:14px;font-weight:400;color:#64748b}.mel-pt-features{list-style:none;margin:16px 0;padding:0;display:flex;flex-direction:column;gap:8px}.mel-social{display:flex;gap:8px}.mel-social a{display:inline-flex;width:36px;height:36px;border-radius:50%;background:#f1f5f9;color:#0f172a;align-items:center;justify-content:center}.mel-social svg{width:18px;height:18px}.mel-stars-static{position:relative;display:inline-block;font-size:24px;line-height:1;letter-spacing:2px}.mel-stars-bg{color:#cbd5e1}.mel-stars-fg{position:absolute;left:0;top:0;overflow:hidden;white-space:nowrap;color:#f59e0b}\n';
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
  const colorOk = (v: any) =>
    typeof v === 'string' &&
    (/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/.test(v) || /^var\(--mel-[a-z0-9-]+\)$/.test(v));
  const decls = (s: any) => {
    let d = '';
    const l = s.layout || {};
    if (l.direction === 'row' || l.direction === 'column') d += `flex-direction:${l.direction};`;
    if (l.gap != null) d += `gap:${l.gap | 0}px;`;
    if (l.justify) d += `justify-content:${l.justify};`;
    if (l.align) d += `align-items:${l.align};`;
    if (colorOk(l.bg)) d += `background:${l.bg};`;
    if (l.padding != null) d += `padding:${l.padding | 0}px;`;
    if (l.radius != null) d += `border-radius:${l.radius | 0}px;`;
    const shadows: Record<string, string> = {
      sm: '0 1px 2px rgba(15,23,42,.08)',
      md: '0 4px 12px rgba(15,23,42,.12)',
      lg: '0 10px 28px rgba(15,23,42,.16)',
      xl: '0 20px 48px rgba(15,23,42,.2)',
    };
    if (shadows[l.shadow]) d += `box-shadow:${shadows[l.shadow]};`;
    if ((l.borderWidth | 0) > 0 && colorOk(l.borderColor)) {
      const bs = l.borderStyle === 'dashed' || l.borderStyle === 'dotted' ? l.borderStyle : 'solid';
      d += `border:${Math.max(1, Math.min(8, l.borderWidth | 0))}px ${bs} ${l.borderColor};`;
    }
    if (l.gradient && colorOk(l.gradient.from) && colorOk(l.gradient.to)) {
      const ga = Math.max(0, Math.min(360, (l.gradient.angle ?? 135) | 0));
      d += `background:linear-gradient(${ga}deg,${l.gradient.from},${l.gradient.to});`;
    }
    const t = s.typo || {};
    if (t.size != null) d += `font-size:${t.size | 0}px;`;
    if (t.weight != null) d += `font-weight:${t.weight | 0};`;
    if (colorOk(t.color)) d += `color:${t.color};`;
    if (typeof t.family === 'string') {
      const stacks: Record<string, string> = {
        'system-sans': `-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif`,
        'system-serif': `Georgia, 'Times New Roman', serif`,
        'system-mono': `ui-monospace, Menlo, Consolas, monospace`,
        'display': `Impact, 'Arial Narrow', sans-serif`,
        'handwriting': `'Comic Sans MS', 'Chalkboard SE', cursive`,
      };
      const f = resolveFont(t.family, stacks);
      if (f) d += `font-family:${f};`;
    }
    if (typeof s.customCss === 'string' && s.customCss.trim()) {
      const c = s.customCss.replace(/<\/?style[^>]*>/gi, '').replace(/<script[^>]*>.*?<\/script>/gis, '').slice(0, 2048).trim().replace(/;?$/, ';');
      d += c;
    }
    return d;
  };
  walk(doc.root);
  return out.slice(0, 100 * 1024);
}

function globalsCss(doc: MelDoc): string {
  const colors = (doc as any)?.globals?.colors;
  if (!colors || typeof colors !== 'object') return '';
  let decls = '';
  for (const [name, value] of Object.entries(colors).slice(0, 20)) {
    const clean = String(name).replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 32);
    if (clean && typeof value === 'string' && /^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/.test(value)) {
      decls += `--mel-${clean}:${value};`;
    }
  }
  const stacks: Record<string, string> = {
    'system-sans': `-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif`,
    'system-serif': `Georgia, 'Times New Roman', serif`,
    'system-mono': `ui-monospace, Menlo, Consolas, monospace`,
    'display': `Impact, 'Arial Narrow', sans-serif`,
    'handwriting': `'Comic Sans MS', 'Chalkboard SE', cursive`,
  };
  const fonts = (doc as any)?.globals?.fonts;
  if (fonts && typeof fonts === 'object') {
    for (const [name, stack] of Object.entries(fonts).slice(0, 20)) {
      const clean = String(name).replace(/[^a-zA-Z0-9_-]/g, '').slice(0, 32);
      const resolved = typeof stack === 'string' ? resolveFont(stack, stacks) : null;
      if (clean && resolved) decls += `--mel-font-${clean}:${resolved};`;
    }
  }
  return decls ? `:root{${decls}}\n` : '';
}

function resolveFont(s: string, stacks: Record<string, string>): string | null {
  if (stacks[s]) return stacks[s];
  if (/^var\(--mel-font-[a-z0-9-]+\)$/.test(s)) return s;
  const t = s.trim().slice(0, 200);
  if (t && /^[a-zA-Z0-9 ,'"-]+$/.test(t)) return t;
  return null;
}
