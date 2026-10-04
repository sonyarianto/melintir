import { Fragment, useEffect, useState, type DragEvent, type FocusEvent, type KeyboardEvent } from 'react';
import type { MelNode } from './types';

/** Drag-and-drop context threaded from App (absent = static preview). */
export interface DndCtx {
  dragging: boolean;
  overSlot: string | null;
  onNodeDragStart: (id: string, e: DragEvent) => void;
  onSlotOver: (parentId: string, index: number, e: DragEvent) => void;
  onSlotLeave: () => void;
  onSlotDrop: (parentId: string, index: number, e: DragEvent) => void;
  onDragEnd: () => void;
}

/** Insertion gap rendered between siblings (and around them) while dragging. */
export function DropSlot({ parentId, index, dnd }: { parentId: string; index: number; dnd?: DndCtx }) {
  if (!dnd || !dnd.dragging) return null;
  const key = `${parentId}:${index}`;
  return (
    <div
      className={`mel-slot${dnd.overSlot === key ? ' mel-over' : ''}`}
      onDragOver={(e) => dnd.onSlotOver(parentId, index, e)}
      onDragLeave={dnd.onSlotLeave}
      onDrop={(e) => dnd.onSlotDrop(parentId, index, e)}
    />
  );
}

export function PreviewNode({ node, selected, selectedId, onSelect, onInlineEdit, dnd }: {
  node: MelNode;
  selected: boolean;
  selectedId?: string | null;
  onSelect: (id: string) => void;
  onInlineEdit?: (id: string, field: string, value: string) => void;
  dnd?: DndCtx;
}) {
  const isSel = selected || selectedId === node.id;
  const cls = `mel-${node.id}${isSel ? ' mel-selected' : ''}`;
  const guardEdit = (e: DragEvent) => {
    // Text being edited must never start a block drag.
    if ((e.target as HTMLElement).isContentEditable) {
      e.preventDefault();
      return true;
    }
    return false;
  };
  if (node.elType === 'container') {
    return (
      <div
        className={`mel-container ${cls}${dnd ? ' mel-draggable' : ''}`}
        draggable={!!dnd}
        onDragStart={(e) => { if (guardEdit(e)) return; e.stopPropagation(); dnd?.onNodeDragStart(node.id, e); }}
        onDragEnd={dnd?.onDragEnd}
        onClick={(e) => { e.stopPropagation(); onSelect(node.id); }}
      >
        {(node.elements || []).map((c, i) => (
          <Fragment key={c.id}>
            <DropSlot parentId={node.id} index={i} dnd={dnd} />
            <PreviewNode node={c} selected={false} selectedId={selectedId} onSelect={onSelect} onInlineEdit={onInlineEdit} dnd={dnd} />
          </Fragment>
        ))}
        <DropSlot parentId={node.id} index={(node.elements || []).length} dnd={dnd} />
        {node.elements.length === 0 && <div className="mel-empty">Empty container — drop blocks here</div>}
      </div>
    );
  }
  const s = node.settings || {};
  const wrap = (inner: React.ReactNode) => (
    <div
      className={`${cls}${dnd ? ' mel-draggable' : ''}`}
      draggable={!!dnd}
      onDragStart={(e) => { if (guardEdit(e)) return; e.stopPropagation(); dnd?.onNodeDragStart(node.id, e); }}
      onDragEnd={dnd?.onDragEnd}
      onClick={(e) => { e.stopPropagation(); onSelect(node.id); }}
    >{inner}</div>
  );
  /** Plain-text widgets become editable once selected; blur commits. */
  const editable = (field: string, current: string, multiline: boolean) => {
    if (!onInlineEdit || !isSel) return null;
    return {
      contentEditable: true,
      suppressContentEditableWarning: true,
      onBlur: (e: FocusEvent<HTMLElement>) => {
        const v = e.currentTarget.textContent || '';
        if (v !== current) onInlineEdit(node.id, field, v);
      },
      onKeyDown: multiline ? undefined : (e: KeyboardEvent<HTMLElement>) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          (e.target as HTMLElement).blur();
        }
      },
    };
  };
  switch (node.widgetType) {
    case 'heading': {
      const Tag = (s.tag || 'h2') as any;
      return wrap(<Tag className="mel-heading" {...editable('text', s.text || '', false)}>{s.text}</Tag>);
    }
    case 'text': return wrap(<div className="mel-text" dangerouslySetInnerHTML={{ __html: s.html || '' }} />);
    case 'image': return wrap(<figure className="mel-image">{s.url ? <img src={s.url} alt={s.alt || ''} loading="lazy" /> : 'No image'}</figure>);
    case 'button': return wrap(<div className="mel-btn-wrap"><span className="mel-btn" {...editable('text', s.text || '', false)}>{s.text}</span></div>);
    case 'video': return wrap(<div className="mel-video">🎬 {s.url || 'No URL'}</div>);
    case 'divider': return wrap(<hr className="mel-divider" />);
    case 'spacer': return wrap(<div className="mel-spacer" style={{ height: 24 }} />);
    case 'icon-box': return wrap(<div className="mel-iconbox"><h3>{s.title}</h3><div>{s.desc}</div></div>);
    case 'tabs': return wrap(<div className="mel-tabs">{(s.tabs || []).map((t: any, i: number) => <span key={i} className="mel-tab">{t.title}</span>)}</div>);
    case 'form': return wrap(
      <div className="mel-form">
        {(s.fields || []).map((f: any, i: number) => (
          <span key={i} className="mel-field"><span>{f.label}{f.required ? ' *' : ''}</span></span>
        ))}
        <span className="mel-btn">{s.buttonText || 'Send'}</span>
      </div>
    );
    case 'loop': return wrap(<LoopPreview settings={s} />);
    case 'accordion': return wrap(
      <div className="mel-accordion">
        {(s.items || []).map((t: any, i: number) => (
          <details key={i} className="mel-acc-item" open={i === 0}><summary>{t.title}</summary><div>{t.content}</div></details>
        ))}
      </div>
    );
    case 'gallery': return wrap(
      <div className={`mel-gallery mel-gcols-${Math.max(1, Math.min(6, +s.columns || 3))}`}>
        {(s.images || []).map((im: any, i: number) => (
          <figure key={i} className="mel-gimg">{im.url ? <img src={im.url} alt={im.alt || ''} loading="lazy" /> : 'No image'}</figure>
        ))}
      </div>
    );
    case 'counter': return wrap(<div className="mel-counter">{s.prefix || ''}{s.number ?? 0}{s.suffix || ''}</div>);
    case 'testimonial': return wrap(
      <figure className="mel-testimonial">
        <blockquote>{s.quote}</blockquote>
        <figcaption><span className="mel-tname">{s.name}</span> <span className="mel-trole">{s.role}</span></figcaption>
      </figure>
    );
    case 'nav': return wrap(<NavPreview settings={s} />);
    case 'products': return wrap(<ProductsPreview settings={s} />);
    case 'product-title': return wrap(<div className="mel-ptitle">[Product title]</div>);
    case 'product-price': return wrap(<div className="mel-price">$0.00</div>);
    case 'product-cart': return wrap(<span className="mel-addcart">Add to cart</span>);
    case 'product-rating': return wrap(<div className="mel-stars">★★★★★</div>);
    case 'product-image': return wrap(<div className="mel-pimg">🛍 product image</div>);
    case 'product-excerpt': return wrap(<div className="mel-pexcerpt">Short description shows on the product page.</div>);
    case 'menu-cart': return wrap(<span className="mel-menucart"><span className="mel-cart-icon">🛒</span>{s.showCount !== false && <span className="mel-cart-count">0</span>}{!!s.showTotal && <span className="mel-cart-total">$0.00</span>}</span>);
    case 'woo-cart': return wrap(<div className="mel-wooembed">🛒 Cart shows here (WooCommerce)</div>);
    case 'woo-checkout': return wrap(<div className="mel-wooembed">💳 Checkout shows here (WooCommerce)</div>);
    case 'countdown': return wrap(
      <div className="mel-countdown">
        {[['7', 'days'], ['00', 'hrs'], ['00', 'min'], ['00', 'sec']].map(([n, l]) => (
          <span key={l}><b>{n}</b><small>{l}</small></span>
        ))}
      </div>
    );
    case 'carousel': return wrap(
      <div className="mel-carousel"><div className="mel-track">
        {(s.slides || []).map((sl: any, i: number) => (
          <div key={i} className="mel-slide">
            {sl.url ? <img src={sl.url} alt={sl.alt || ''} loading="lazy" /> : 'No image'}
            {(sl.heading || sl.text) && <div className="mel-slide-cap">{sl.heading && <strong>{sl.heading}</strong>}{sl.text && <span>{sl.text}</span>}</div>}
          </div>
        ))}
      </div></div>
    );
    case 'price-table': return wrap(
      <div className={`mel-price-table${s.highlight ? ' mel-hot' : ''}`}>
        {s.title && <h3>{s.title}</h3>}
        <div className="mel-pt-price">{s.currency}{s.price}<small>{s.period}</small></div>
        <ul className="mel-pt-features">{(Array.isArray(s.features) ? s.features : []).map((f: string, i: number) => <li key={i}>{f}</li>)}</ul>
        {s.buttonText && <span className="mel-addcart">{s.buttonText}</span>}
      </div>
    );
    case 'social': return wrap(
      <div className="mel-social">
        {(s.items || []).map((it: any, i: number) => (
          <span key={i} className="mel-social" title={it.network}>{socialGlyph(it.network)}</span>
        ))}
      </div>
    );
    case 'star-rating': {
      const pct = Math.max(0, Math.min(5, +s.rating || 0)) / 5 * 100;
      return wrap(
        <div className="mel-stars-static"><span className="mel-stars-bg">★★★★★</span><span className="mel-stars-fg" style={{ width: `${pct}%` }}>★★★★★</span></div>
      );
    }
    default: return wrap(<div>?</div>);
  }
}

/** Live grid preview from real posts via wp/v2; placeholders when offline. */
function LoopPreview({ settings }: { settings: Record<string, any> }) {
  const [posts, setPosts] = useState<any[] | null>(null);
  const postType = settings.postType || 'post';
  const perPage = Math.max(1, Math.min(20, +settings.postsPerPage || 6));
  const columns = Math.max(1, Math.min(4, +settings.columns || 3));

  useEffect(() => {
    setPosts(null);
    const d: any = (window as any).MelintirData;
    const base = typeof d?.restUrl === 'string' ? d.restUrl.split('/melintir/v1')[0] : null;
    const endpoint = postType === 'page' ? '/wp/v2/pages' : '/wp/v2/posts';
    if (!base) return;
    // Plain permalinks use ?rest_route= (append with &), pretty use /wp-json (append with ?).
    const query = `per_page=${perPage}&_fields=id,title,excerpt,featured_media,link`;
    const url = base.includes('?') ? `${base}${endpoint}&${query}` : `${base}${endpoint}?${query}`;
    let alive = true;
    fetch(url, { headers: d?.nonce ? { 'X-WP-Nonce': d.nonce } : {} })
      .then((r) => (r.ok ? r.json() : []))
      .then((j) => alive && setPosts(Array.isArray(j) ? j : []))
      .catch(() => alive && setPosts([]));
    return () => { alive = false; };
  }, [postType, perPage]);

  const items = posts === null
    ? Array.from({ length: Math.min(perPage, 3) }, (_, i) => ({ id: `ph-${i}`, phantom: true }))
    : posts;
  return (
    <div className={`mel-loop mel-cols-${columns}`}>
      {items.length === 0 && <p className="mel-loop-empty">No posts found.</p>}
      {items.map((p: any) => (
        <article key={p.id} className="mel-card">
          {settings.showImage !== false && !p.phantom && <div className="mel-card-img">🖼</div>}
          {settings.showImage !== false && p.phantom && <div className="mel-card-img">…</div>}
          {settings.showTitle !== false && (
            <h3 className="mel-card-title">{p.phantom ? 'Post title' : stripTags(p.title?.rendered || '')}</h3>
          )}
          {settings.showExcerpt !== false && (
            <div className="mel-card-ex">{p.phantom ? 'Excerpt…' : stripTags(p.excerpt?.rendered || '').slice(0, 80)}</div>
          )}
        </article>
      ))}
    </div>
  );
}

/** Live product grid preview from the public Woo Store API; placeholders when Woo is off. */
function ProductsPreview({ settings }: { settings: Record<string, any> }) {
  const [items, setItems] = useState<any[] | null>(null);
  const perPage = Math.max(1, Math.min(20, +settings.count || 8));
  const columns = Math.max(1, Math.min(4, +settings.columns || 4));
  const category = +settings.category || 0;
  const orderBy = ['date', 'price', 'rating', 'popularity', 'title'].includes(settings.orderBy) ? settings.orderBy : 'date';

  useEffect(() => {
    setItems(null);
    const base = wpApiBase();
    if (!base) return;
    const d: any = (window as any).MelintirData;
    const cat = category > 0 ? `category=${category}&` : '';
    let alive = true;
    fetch(wpApiUrl(base, '/wc/store/v1/products') + `${cat}per_page=${perPage}&orderby=${orderBy}`, {
      headers: d?.nonce ? { 'X-WP-Nonce': d.nonce } : {},
    })
      .then((r) => (r.ok ? r.json() : []))
      .then((j) => alive && setItems(Array.isArray(j) ? j : []))
      .catch(() => alive && setItems([]));
    return () => { alive = false; };
  }, [perPage, category, orderBy]);

  if (items !== null && items.length === 0) {
    return <p className="mel-loop-empty">No products found (is WooCommerce active?).</p>;
  }
  const cards = items === null
    ? Array.from({ length: Math.min(perPage, 4) }, (_, i) => ({ id: `ph-${i}`, phantom: true }))
    : items;
  return (
    <div className={`mel-loop mel-cols-${columns}`}>
      {cards.map((p: any) => (
        <article key={p.id} className="mel-card">
          {settings.showImage !== false && (
            <div className="mel-card-img">{p.phantom ? '…' : (p.images?.[0]?.thumbnail ? <img src={p.images[0].thumbnail} alt="" loading="lazy" /> : '🛍')}</div>
          )}
          {settings.showBadge !== false && !p.phantom && p.on_sale && <span className="mel-badge">Sale!</span>}
          {settings.showTitle !== false && <h3 className="mel-card-title">{p.phantom ? 'Product name' : p.name}</h3>}
          {settings.showRating !== false && !p.phantom && +p.average_rating > 0 && (
            <div className="mel-stars">{'★'.repeat(Math.round(+p.average_rating))}</div>
          )}
          {settings.showPrice !== false && (
            p.phantom ? <div className="mel-price">$0.00</div>
            : <div className="mel-price" dangerouslySetInnerHTML={{ __html: p.price_html || '' }} />
          )}
          {settings.showCart !== false && !p.phantom && <span className="mel-addcart">Add to cart</span>}
        </article>
      ))}
    </div>
  );
}

/** Text glyphs for the social preview (frontend renders real SVGs). */
function socialGlyph(network: string): string {
  switch (network) {
    case 'x': return '𝕏';
    case 'instagram': return '◉';
    case 'youtube': return '▶';
    case 'facebook': return 'f';
    case 'linkedin': return 'in';
    default: return '●';
  }
}

function stripTags(html: string): string {
  const div = document.createElement('div');
  div.innerHTML = html;
  return div.textContent || '';
}

export interface WpMenu {
  id: number;
  name: string;
}

export function wpApiBase(): string | null {
  const d: any = (window as any).MelintirData;
  if (typeof d?.restUrl !== 'string') return null;
  return d.restUrl.split('/melintir/v1')[0];
}

export function wpApiUrl(base: string, path: string): string {
  return base.includes('?') ? `${base}${path}&` : `${base}${path}?`;
}

/** Live menu preview: item titles from wp/v2, placeholder when unassigned. */
function NavPreview({ settings }: { settings: Record<string, any> }) {
  const [items, setItems] = useState<any[] | null>(null);
  const menuId = +settings.menu || 0;

  useEffect(() => {
    setItems(null);
    const base = wpApiBase();
    if (!base || !menuId) return;
    let alive = true;
    const d: any = (window as any).MelintirData;
    fetch(wpApiUrl(base, '/wp/v2/menu-items') + `menus=${menuId}&per_page=20&_fields=id,title,url`, {
      headers: d?.nonce ? { 'X-WP-Nonce': d.nonce } : {},
    })
      .then((r) => (r.ok ? r.json() : []))
      .then((j) => alive && setItems(Array.isArray(j) ? j : []))
      .catch(() => alive && setItems([]));
    return () => { alive = false; };
  }, [menuId]);

  if (!menuId) return <nav className="mel-nav"><span className="mel-nav-empty">Select a menu</span></nav>;
  const layout = settings.layout === 'vertical' ? 'vertical' : 'horizontal';
  return (
    <nav className={`mel-nav mel-nav-${layout}`}>
      <ul className="mel-nav-list">
        {items === null && <li>…</li>}
        {items !== null && items.length === 0 && <li>Menu is empty</li>}
        {(items || []).map((it: any) => (
          <li key={it.id}><span>{stripTags(it.title?.rendered || '')}</span></li>
        ))}
      </ul>
    </nav>
  );
}
