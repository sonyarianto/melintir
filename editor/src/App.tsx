import { Fragment, useEffect, useMemo, useState, type DragEvent } from 'react';
import { useEditor } from './store';
import { generateCss, initWasm, isWasm } from './wasm';
import { DropSlot, PreviewNode, wpApiBase, wpApiUrl, type DndCtx, type WpMenu } from './widgets';
import type { MelNode, WidgetType } from './types';

type BP = 'desktop' | 'tablet' | 'mobile';

const PALETTE: WidgetType[] = ['heading', 'text', 'image', 'button', 'video', 'divider', 'spacer', 'icon-box', 'tabs', 'form', 'loop', 'accordion', 'gallery', 'counter', 'testimonial', 'nav', 'products', 'product-title', 'product-price', 'product-cart', 'product-rating', 'product-image', 'product-excerpt', 'menu-cart', 'woo-cart', 'woo-checkout', 'countdown', 'carousel', 'price-table', 'social', 'star-rating'];

const PALETTE_CATS: { name: string; items: WidgetType[] }[] = [
  { name: 'Content', items: ['heading', 'text', 'image', 'button', 'video', 'divider', 'spacer', 'icon-box'] },
  { name: 'Interactive', items: ['tabs', 'accordion', 'gallery', 'counter', 'testimonial', 'nav'] },
  { name: 'Forms & Data', items: ['form', 'loop'] },
  { name: 'Commerce', items: ['products', 'product-title', 'product-price', 'product-cart', 'product-rating', 'product-image', 'product-excerpt', 'menu-cart', 'woo-cart', 'woo-checkout'] },
  { name: 'Marketing', items: ['countdown', 'carousel', 'price-table', 'social', 'star-rating'] },
];

declare global {
  interface Window {
    wp?: any;
  }
}

function pickImage(cb: (url: string, id: number) => void) {
  if (!window.wp?.media) {
    alert('Media library not available on this page.');
    return;
  }
  const frame = window.wp.media({ title: 'Pick image', multiple: false, library: { type: 'image' } });
  frame.on('select', () => {
    const att = frame.state().get('selection').first().toJSON();
    cb(att.url as string, att.id as number);
  });
  frame.open();
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
  return (
    <label>
      <span className="mel-flabel">{label}</span>
      {children}
    </label>
  );
}

function Num({ value, onChange }: { value: number | undefined; onChange: (v: number) => void }) {
  return <input type="number" value={value ?? ''} onChange={(e) => onChange(+e.target.value)} />;
}

export default function App() {
  const { doc, selectedId, setSelected, addWidget, addWidgetAt, insertNode, duplicateSelected, moveNode, nudgeSelected, updateNode, removeNode, undo, redo, load, dirty, setGlobals } = useEditor();
  const [wasmOk, setWasmOk] = useState(false);
  const [status, setStatus] = useState('loading…');
  const [saving, setSaving] = useState(false);
  const [bp, setBp] = useState<BP>('desktop');
  const [dragPayload, setDragPayload] = useState<string | null>(null); // 'move:ID' | 'new:TYPE'
  const [overSlot, setOverSlot] = useState<string | null>(null); // 'parentId:index'
  const [palSearch, setPalSearch] = useState('');
  const [templates, setTemplates] = useState<{ name: string; doc: any }[]>([]);
  const [patterns, setPatterns] = useState<{ id: string; name: string; node: MelNode }[]>([]);
  const [pname, setPname] = useState('');
  const [history, setHistory] = useState<{ autosave: { ts: number } | null; revisions: { ts: number }[] }>({ autosave: null, revisions: [] });

  useEffect(() => {
    initWasm().then(setWasmOk);
    const d: any = (window as any).MelintirData;
    if (d?.restUrl) {
      fetch(d.restUrl + '/load', { headers: { 'X-WP-Nonce': d.nonce } })
        .then((r) => r.json())
        .then((j) => {
          if (j?.doc?.root) {
            // Silent initial load: no undo checkpoint, stays clean.
            useEditor.setState({
              doc: typeof j.doc === 'string' ? JSON.parse(j.doc) : j.doc,
              past: [], future: [], dirty: false, selectedId: null,
            });
            setStatus('loaded');
          } else setStatus('new document');
        })
        .catch(() => setStatus('new document (offline)'));
    }
    if (d?.templatesUrl) {
      fetch(d.templatesUrl, { headers: { 'X-WP-Nonce': d.nonce } })
        .then((r) => r.json())
        .then((j) => Array.isArray(j) && setTemplates(j))
        .catch(() => {});
    }
    if (d?.patternsUrl) {
      fetch(d.patternsUrl, { headers: { 'X-WP-Nonce': d.nonce } })
        .then((r) => r.json())
        .then((j) => Array.isArray(j) && setPatterns(j))
        .catch(() => {});
    }
    refreshHistory();
  }, []);

  const refreshPatterns = async () => {
    const d: any = (window as any).MelintirData;
    if (!d?.patternsUrl) return;
    try {
      const r = await fetch(d.patternsUrl, { headers: { 'X-WP-Nonce': d.nonce } });
      const j = await r.json();
      if (Array.isArray(j)) setPatterns(j);
    } catch { /* offline */ }
  };

  const savePattern = async (node: MelNode | null) => {
    const d: any = (window as any).MelintirData;
    if (!d?.patternsUrl || !node) return;
    const fallback = node.widgetType || node.elType;
    const name = pname.trim() || `${fallback}`;
    try {
      const r = await fetch(d.patternsUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': d.nonce },
        body: JSON.stringify({ name, node }),
      });
      const j = await r.json();
      if (j?.ok) {
        setPname('');
        setStatus(`pattern saved: ${name}`);
        refreshPatterns();
      } else setStatus(`pattern save failed: ${JSON.stringify(j)}`);
    } catch (e: any) {
      setStatus('pattern save failed: ' + e.message);
    }
  };

  const deletePattern = async (id: string) => {
    const d: any = (window as any).MelintirData;
    if (!d?.patternsUrl) return;
    try {
      await fetch(`${d.patternsUrl}/${encodeURIComponent(id)}`, {
        method: 'DELETE',
        headers: { 'X-WP-Nonce': d.nonce },
      });
      refreshPatterns();
    } catch { /* offline */ }
  };

  const { css, ms } = useMemo(() => generateCss(doc), [doc, wasmOk]);

  const save = async () => {
    const d: any = (window as any).MelintirData;
    if (!d?.restUrl) {
      setStatus('no REST URL (dev mode?)');
      return;
    }
    setSaving(true);
    try {
      const r = await fetch(d.restUrl + '/save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': d.nonce },
        body: JSON.stringify({ doc }),
      });
      const j = await r.json();
      setStatus(j?.ok ? `saved (${Math.round(ms * 100) / 100}ms css)` : `error: ${JSON.stringify(j)}`);
      useEditor.setState({ dirty: false });
    } catch (e: any) {
      setStatus('save failed: ' + e.message);
    } finally {
      setSaving(false);
    }
  };

  const refreshHistory = async () => {
    const d: any = (window as any).MelintirData;
    if (!d?.restUrl) return;
    try {
      const r = await fetch(d.restUrl + '/history', { headers: { 'X-WP-Nonce': d.nonce } });
      const j = await r.json();
      if (j && !j.code) setHistory({ autosave: j.autosave || null, revisions: j.revisions || [] });
    } catch { /* offline */ }
  };

  const restoreRev = async (ts: number) => {
    const d: any = (window as any).MelintirData;
    if (!d?.restUrl) return;
    // Plain permalinks embed ?rest_route= : append the rev param with &.
    const sep = String(d.restUrl).includes('?') ? '&' : '?';
    try {
      const r = await fetch(d.restUrl + '/history' + sep + 'rev=' + ts, { headers: { 'X-WP-Nonce': d.nonce } });
      const j = await r.json();
      if (j?.doc?.root) {
        load(j.doc); // checkpoint-safe: current canvas stays reachable via undo.
        setStatus(`restored ${new Date(ts * 1000).toLocaleString()} (undo to go back)`);
      } else setStatus('restore failed');
    } catch (e: any) {
      setStatus('restore failed: ' + e.message);
    }
  };

  // Autosave every 45s while dirty. Never touches the published doc.
  useEffect(() => {
    const t = setInterval(async () => {
      const st = useEditor.getState();
      const d: any = (window as any).MelintirData;
      if (!st.dirty || !d?.restUrl) return;
      try {
        const r = await fetch(d.restUrl + '/autosave', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': d.nonce },
          body: JSON.stringify({ doc: st.doc }),
        });
        const j = await r.json();
        if (j?.ok) {
          setStatus(`autosaved ${new Date(j.ts * 1000).toLocaleTimeString()}`);
          refreshHistory();
        }
      } catch { /* offline */ }
    }, 45000);
    return () => clearInterval(t);
  }, []);

  const exportJson = () => {
    const d: any = (window as any).MelintirData;
    const blob = new Blob([JSON.stringify(doc, null, 2)], { type: 'application/json' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `melintir-${d?.postId || 'doc'}.json`;
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 5000);
  };

  const importJson = (file: File) => {
    file.text().then((text) => {
      try {
        const parsed = JSON.parse(text);
        const candidate = parsed?.root ? parsed : parsed?.doc?.root ? parsed.doc : null;
        if (!candidate?.root) throw new Error('bad shape');
        load(candidate);
        setStatus(`imported ${file.name}`);
      } catch {
        setStatus(`import failed: ${file.name} is not a Melintir doc`);
      }
    });
  };

  const isMelNode = (v: any) =>
    v && typeof v === 'object' && typeof v.id === 'string' &&
    (v.elType === 'container' || v.elType === 'widget');

  /** Copy the selected block as JSON text. OS clipboard => works cross-page. */
  const copySelected = async (node: MelNode | null) => {
    if (!node) {
      setStatus('select a block to copy');
      return;
    }
    try {
      await navigator.clipboard.writeText(JSON.stringify(node));
      setStatus(`copied ${node.widgetType || node.elType} (paste anywhere)`);
    } catch {
      setStatus('copy failed: clipboard unavailable (HTTPS or localhost required)');
    }
  };

  /** Paste a block (or full doc's top-level blocks) from clipboard as fresh copies. */
  const pasteClipboard = async () => {
    let text = '';
    try {
      text = await navigator.clipboard.readText();
    } catch {
      setStatus('paste failed: clipboard unavailable (HTTPS or localhost required)');
      return;
    }
    try {
      const parsed = JSON.parse(text);
      if (isMelNode(parsed)) {
        insertNode(parsed as MelNode);
        setStatus(`pasted ${parsed.widgetType || parsed.elType}`);
      } else if (Array.isArray(parsed?.root?.elements)) {
        parsed.root.elements.filter(isMelNode).forEach((n: MelNode) => insertNode(n));
        setStatus(`pasted ${parsed.root.elements.length} block(s)`);
      } else {
        throw new Error('bad shape');
      }
    } catch {
      setStatus('paste failed: clipboard has no Melintir block');
    }
  };

  // Ctrl/Cmd+C/V for blocks. Skipped while typing in a field.
  // Re-subscribed when selection changes so the handler never goes stale.
  const sel: MelNode | null = useMemo(() => {
    const find = (n: MelNode): MelNode | null =>
      n.id === selectedId ? n : (n.elements || []).map(find).find(Boolean) || null;
    return selectedId ? find(doc.root) : null;
  }, [doc, selectedId]);

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const t = e.target as HTMLElement | null;
      if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
      if (!(e.ctrlKey || e.metaKey) || e.altKey) return;
      if (e.key === 'c' || e.key === 'C') {
        e.preventDefault();
        copySelected(sel);
      } else if (e.key === 'v' || e.key === 'V') {
        e.preventDefault();
        pasteClipboard();
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [sel]);

  /** Drag-and-drop: palette buttons drag `new:TYPE`, canvas blocks `move:ID`. */
  const startPaletteDrag = (type: WidgetType) => (e: DragEvent) => {
    e.dataTransfer.setData('application/x-melintir', `new:${type}`);
    e.dataTransfer.effectAllowed = 'copy';
    setDragPayload(`new:${type}`);
    setOverSlot(null);
  };
  const startMove = (id: string, e: DragEvent) => {
    e.dataTransfer.setData('application/x-melintir', `move:${id}`);
    e.dataTransfer.effectAllowed = 'move';
    setDragPayload(`move:${id}`);
    setOverSlot(null);
    setSelected(id);
  };
  const slotOver = (parentId: string, index: number, e: DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    e.dataTransfer.dropEffect = dragPayload?.startsWith('new:') ? 'copy' : 'move';
    setOverSlot(`${parentId}:${index}`);
  };
  const slotDrop = (parentId: string, index: number, e: DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    const raw = e.dataTransfer.getData('application/x-melintir') || dragPayload || '';
    setDragPayload(null);
    setOverSlot(null);
    if (raw.startsWith('new:')) {
      const type = raw.slice(4) as WidgetType;
      if (!addWidgetAt(type, parentId, index)) setStatus('cannot drop there');
      else setStatus(`added ${type}`);
    } else if (raw.startsWith('move:')) {
      const id = raw.slice(5);
      if (!moveNode(id, parentId, index)) setStatus('cannot move there (depth limit or invalid target)');
      else setStatus('moved');
    }
  };
  const endDrag = () => {
    setDragPayload(null);
    setOverSlot(null);
  };
  const dnd: DndCtx = {
    dragging: dragPayload !== null,
    overSlot,
    onNodeDragStart: startMove,
    onSlotOver: slotOver,
    onSlotLeave: () => setOverSlot(null),
    onSlotDrop: slotDrop,
    onDragEnd: endDrag,
  };

  /** Merge a style patch into the active breakpoint scope. */
  const patchStyle = (node: MelNode, patch: Record<string, any>) => {
    const mergeInto = (target: Record<string, any>) => {
      const merged: Record<string, any> = { ...target };
      for (const k of Object.keys(patch)) {
        const oldV = target?.[k];
        const newV = patch[k];
        merged[k] = oldV && newV && typeof oldV === 'object' && typeof newV === 'object' && !Array.isArray(newV)
          ? { ...oldV, ...newV }
          : newV;
      }
      return merged;
    };
    if (bp === 'desktop') {
      updateNode(node.id, { style: mergeInto((node.style || {}) as any) as any });
    } else {
      const scope = mergeInto(((node.style as any)?.[bp] || {}) as any);
      updateNode(node.id, { style: { [bp]: scope } as any });
    }
  };

  /** Read effective style scope for display (falls back to desktop values). */
  const scopeOf = (node: MelNode) => {
    const base = node.style || {};
    const over = (base as any)?.[bp] || {};
    return {
      layout: { ...(base.layout || {}), ...(over.layout || {}) },
      typo: { ...(base.typo || {}), ...(over.typo || {}) },
    };
  };

  const setSetting = (node: MelNode, patch: Record<string, any>) =>
    updateNode(node.id, { settings: patch });

  return (
    <div className="mel-app">
      <style>{css}</style>
      <aside className="mel-panel">
        <h3>Melintir v{(window as any).MelintirData?.version || 'dev'} {isWasm() || wasmOk ? '⚡WASM' : 'JS-fallback'}</h3>
        <div className="mel-row">
          <button onClick={undo}>↩</button>
          <button onClick={redo}>↪</button>
          <button onClick={save} disabled={saving || !dirty}>{saving ? '…' : 'Save'}</button>
        </div>
        <div className="mel-row" role="tablist" aria-label="Breakpoint">
          {(['desktop', 'tablet', 'mobile'] as BP[]).map((b) => (
            <button key={b} role="tab" aria-selected={bp === b} className={bp === b ? 'mel-active' : ''} onClick={() => setBp(b)}>
              {b === 'desktop' ? '🖥' : b === 'tablet' ? '▦' : '📱'} {b}
            </button>
          ))}
        </div>
        <p className="mel-status">{status} · css {Math.round(ms * 100) / 100}ms · {bp}</p>
        <h4>Add</h4>
        <p className="mel-status">click to append, or drag onto the canvas.</p>
        <input
          placeholder="Search widgets…"
          value={palSearch}
          onChange={(e) => setPalSearch(e.target.value)}
          style={{ width: '100%', boxSizing: 'border-box', marginBottom: 8 }}
        />
        {palSearch ? (
          <div className="mel-grid">
            {PALETTE.filter((w) => w.includes(palSearch.toLowerCase())).map((w) => (
              <button key={w} draggable onDragStart={startPaletteDrag(w)} onDragEnd={endDrag} onClick={() => addWidget(w)}>{w}</button>
            ))}
          </div>
        ) : (
          PALETTE_CATS.map((c) => (
            <details key={c.name} open={c.name === 'Content'}>
              <summary>{c.name}</summary>
              <div className="mel-grid">
                {c.items.map((w) => (
                  <button key={w} draggable onDragStart={startPaletteDrag(w)} onDragEnd={endDrag} onClick={() => addWidget(w)}>{w}</button>
                ))}
              </div>
            </details>
          ))
        )}
        <GlobalsPanel colors={doc.globals?.colors || {}} fonts={doc.globals?.fonts || {}} onChange={setGlobals} onNotice={setStatus} />
        <h4>Templates</h4>
        <div className="mel-row">
          <button onClick={exportJson}>⬇ Export</button>
          <label className="mel-upload">
            ⬆ Import
            <input
              type="file"
              accept=".json,application/json"
              hidden
              onChange={(e) => {
                const f = e.target.files?.[0];
                if (f) importJson(f);
                e.target.value = '';
              }}
            />
          </label>
        </div>
        {templates.length > 0 && (
          <div className="mel-grid">
            {templates.map((t) => (
              <button key={t.name} title="Replace canvas with this template" onClick={() => { load(t.doc); setStatus(`template: ${t.name}`); }}>
                {t.name}
              </button>
            ))}
          </div>
        )}
        <h4>Patterns</h4>
        <p className="mel-status">select a block, save it, re-insert anywhere as a copy.</p>
        <div className="mel-row">
          <input placeholder="pattern name" value={pname} onChange={(e) => setPname(e.target.value)} />
          <button disabled={!sel} title={sel ? `Save ${sel.widgetType || sel.elType} as pattern` : 'Select a block first'} onClick={() => savePattern(sel)}>
            Save selected
          </button>
        </div>
        <h4>Clipboard</h4>
        <p className="mel-status">copy a block, paste it here or on another page (Ctrl+C / Ctrl+V).</p>
        <div className="mel-row">
          <button disabled={!sel} onClick={() => copySelected(sel)}>⧉ Copy selected</button>
          <button onClick={pasteClipboard}>📋 Paste</button>
        </div>
        <h4>Navigator</h4>
        <Navigator root={doc.root} selectedId={selectedId} onSelect={setSelected} />
        {patterns.length > 0 && (
          <div className="mel-history">
            {patterns.map((p) => (
              <div key={p.id} className="mel-row">
                <span className="mel-status">{p.name}</span>
                <button onClick={() => { insertNode(p.node); setStatus(`inserted: ${p.name}`); }}>Insert</button>
                <button onClick={() => deletePattern(p.id)}>✕</button>
              </div>
            ))}
          </div>
        )}
        <h4>History</h4>
        {history.autosave ? (
          <div className="mel-row">
            <span className="mel-status">autosave {new Date(history.autosave.ts * 1000).toLocaleString()}</span>
            <button onClick={() => restoreRev(history.autosave!.ts)}>Restore</button>
          </div>
        ) : (
          <p className="mel-status">no autosave yet</p>
        )}
        {history.revisions.length > 0 && (
          <div className="mel-history">
            {history.revisions.map((r) => (
              <div key={r.ts} className="mel-row">
                <span className="mel-status">{new Date(r.ts * 1000).toLocaleString()}</span>
                <button onClick={() => restoreRev(r.ts)}>Restore</button>
              </div>
            ))}
          </div>
        )}
        {sel && (
          <Inspector
            node={sel}
            bp={bp}
            scope={scopeOf(sel)}
            globals={doc.globals?.colors || {}}
            onStyle={(p) => patchStyle(sel, p)}
            onSetting={(p) => setSetting(sel, p)}
            onRemove={() => removeNode(sel.id)}
            onCopy={() => copySelected(sel)}
            onDuplicate={() => duplicateSelected()}
            onUp={() => nudgeSelected(-1)}
            onDown={() => nudgeSelected(1)}
          />
        )}
      </aside>
      <main className="mel-canvas" onClick={() => setSelected(null)}>
        <div className="mel-page" style={{ maxWidth: bp === 'mobile' ? 390 : bp === 'tablet' ? 768 : 1100 }}>
          {(doc.root.elements || []).map((n, i) => (
            <Fragment key={n.id}>
              <DropSlot parentId={doc.root.id} index={i} dnd={dnd} />
              <PreviewNode node={n} selected={n.id === selectedId} selectedId={selectedId} onSelect={setSelected} onInlineEdit={(id, field, value) => updateNode(id, { settings: { [field]: value } })} dnd={dnd} />
            </Fragment>
          ))}
          <DropSlot parentId={doc.root.id} index={(doc.root.elements || []).length} dnd={dnd} />
        </div>
      </main>
    </div>
  );
}

function Inspector({ node, bp, scope, globals, onStyle, onSetting, onRemove, onCopy, onDuplicate, onUp, onDown }: {
  node: MelNode;
  bp: BP;
  scope: { layout: any; typo: any };
  globals: Record<string, string>;
  onStyle: (p: Record<string, any>) => void;
  onSetting: (p: Record<string, any>) => void;
  onRemove: () => void;
  onCopy: () => void;
  onDuplicate: () => void;
  onUp: () => void;
  onDown: () => void;
}) {
  const s = node.settings || {};
  const L = scope.layout;
  const T = scope.typo;
  return (
    <div className="mel-inspector">
      <h4>
        {node.widgetType || node.elType}
        {bp !== 'desktop' && <span className="mel-bpbadge">· {bp}</span>}
        <button title="Move up" onClick={onUp}>↑</button>
        <button title="Move down" onClick={onDown}>↓</button>
        <button title="Copy block (Ctrl+C)" onClick={onCopy}>⧉</button>
        <button title="Duplicate (insert copy below)" onClick={onDuplicate}>❏</button>
        <button onClick={onRemove}>✕</button>
      </h4>

      {node.elType === 'container' && (
        <>
          <Field label="Direction">
            <select value={L.direction || 'column'} onChange={(e) => onStyle({ layout: { direction: e.target.value } })}>
              <option value="column">column</option>
              <option value="row">row</option>
            </select>
          </Field>
          <Field label="Gap (px)"><Num value={L.gap} onChange={(v) => onStyle({ layout: { gap: v } })} /></Field>
          <Field label="Padding (px)"><Num value={L.padding} onChange={(v) => onStyle({ layout: { padding: v } })} /></Field>
          <Field label="Background">
            <ColorField value={L.bg || ''} globals={globals} fallback="#ffffff" onChange={(v) => onStyle({ layout: { bg: v } })} />
          </Field>
          <Field label="Justify">
            <select value={L.justify || ''} onChange={(e) => onStyle({ layout: { justify: e.target.value || undefined } })}>
              <option value="">default</option>
              <option value="flex-start">start</option>
              <option value="center">center</option>
              <option value="flex-end">end</option>
              <option value="space-between">space-between</option>
            </select>
          </Field>
          <Field label="Align">
            <select value={L.align || ''} onChange={(e) => onStyle({ layout: { align: e.target.value || undefined } })}>
              <option value="">default</option>
              <option value="flex-start">start</option>
              <option value="center">center</option>
              <option value="flex-end">end</option>
              <option value="stretch">stretch</option>
            </select>
          </Field>
        </>
      )}

      {node.widgetType === 'heading' && (
        <>
          <Field label="Text"><input value={s.text || ''} onChange={(e) => onSetting({ text: e.target.value })} /></Field>
          <TagButtons current={s.text || ''} onPick={(v) => onSetting({ text: v })} />
          <Field label="Tag">
            <select value={s.tag || 'h2'} onChange={(e) => onSetting({ tag: e.target.value })}>
              {['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div'].map((t) => <option key={t} value={t}>{t}</option>)}
            </select>
          </Field>
          <Field label="Size (px)"><Num value={T.size} onChange={(v) => onStyle({ typo: { size: v } })} /></Field>
          <Field label="Weight">
            <select value={T.weight || 700} onChange={(e) => onStyle({ typo: { weight: +e.target.value } })}>
              {[400, 600, 700, 800].map((w) => <option key={w} value={w}>{w}</option>)}
            </select>
          </Field>
          <FontField value={T.family || ''} globals={globals} onChange={(v) => onStyle({ typo: { family: v } })} />
          <Field label="Color">
            <ColorField value={T.color || ''} globals={globals} fallback="#0f172a" onChange={(v) => onStyle({ typo: { color: v } })} />
          </Field>
        </>
      )}

      {node.widgetType === 'text' && (
        <>
          <Field label="HTML"><textarea rows={5} value={s.html || ''} onChange={(e) => onSetting({ html: e.target.value })} /></Field>
          <TagButtons current={s.html || ''} onPick={(v) => onSetting({ html: v })} />
        </>
      )}

      {node.widgetType === 'image' && (
        <>
          {s.url && <img src={s.url} alt="" style={{ maxWidth: '100%', borderRadius: 6 }} />}
          <div className="mel-row">
            <button onClick={() => pickImage((url, id) => onSetting({ url, id }))}>📚 Pick from library</button>
          </div>
          <Field label="URL"><input value={s.url || ''} onChange={(e) => onSetting({ url: e.target.value })} /></Field>
          <Field label="Alt"><input value={s.alt || ''} onChange={(e) => onSetting({ alt: e.target.value })} /></Field>
        </>
      )}

      {node.widgetType === 'button' && (
        <>
          <Field label="Text"><input value={s.text || ''} onChange={(e) => onSetting({ text: e.target.value })} /></Field>
          <Field label="URL"><input value={s.url || ''} onChange={(e) => onSetting({ url: e.target.value })} /></Field>
        </>
      )}

      {node.widgetType === 'video' && (
        <Field label="URL (YouTube/Vimeo/mp4)"><input value={s.url || ''} onChange={(e) => onSetting({ url: e.target.value })} /></Field>
      )}

      {node.widgetType === 'icon-box' && (
        <>
          <Field label="Title"><input value={s.title || ''} onChange={(e) => onSetting({ title: e.target.value })} /></Field>
          <Field label="Description"><textarea rows={3} value={s.desc || ''} onChange={(e) => onSetting({ desc: e.target.value })} /></Field>
          <Field label="Icon"><input value={s.icon || ''} onChange={(e) => onSetting({ icon: e.target.value })} /></Field>
        </>
      )}

      {node.widgetType === 'tabs' && (
        <>
          {(s.tabs || []).map((t: any, i: number) => (
            <div key={i} className="mel-tabedit">
              <Field label={`Tab ${i + 1} title`}>
                <input value={t.title || ''} onChange={(e) => {
                  const tabs = [...(s.tabs || [])];
                  tabs[i] = { ...tabs[i], title: e.target.value };
                  onSetting({ tabs });
                }} />
              </Field>
              <Field label="Content">
                <textarea rows={2} value={t.content || ''} onChange={(e) => {
                  const tabs = [...(s.tabs || [])];
                  tabs[i] = { ...tabs[i], content: e.target.value };
                  onSetting({ tabs });
                }} />
              </Field>
              <button onClick={() => onSetting({ tabs: (s.tabs || []).filter((_: any, j: number) => j !== i) })}>remove tab</button>
            </div>
          ))}
          <button onClick={() => onSetting({ tabs: [...(s.tabs || []), { title: 'New tab', content: 'Content' }] })}>+ add tab</button>
        </>
      )}

      {node.widgetType === 'spacer' && (
        <Field label="Height ≈ padding (px)"><Num value={L.padding} onChange={(v) => onStyle({ layout: { padding: v } })} /></Field>
      )}

      {node.widgetType === 'loop' && (
        <>
          <Field label="Post type">
            <select value={s.postType || 'post'} onChange={(e) => onSetting({ postType: e.target.value })}>
              <option value="post">posts</option>
              <option value="page">pages</option>
            </select>
          </Field>
          <Field label="Count (1–20)"><Num value={s.postsPerPage ?? 6} onChange={(v) => onSetting({ postsPerPage: Math.max(1, Math.min(20, v || 1)) })} /></Field>
          <Field label="Columns (1–4)"><Num value={s.columns ?? 3} onChange={(v) => onSetting({ columns: Math.max(1, Math.min(4, v || 1)) })} /></Field>
          <Field label="Order">
            <select value={s.order || 'DESC'} onChange={(e) => onSetting({ order: e.target.value })}>
              <option value="DESC">newest first</option>
              <option value="ASC">oldest first</option>
            </select>
          </Field>
          <div className="mel-row">
            {[['showImage', 'image'], ['showTitle', 'title'], ['showExcerpt', 'excerpt']].map(([k, label]) => (
              <label key={k}><input type="checkbox" checked={s[k] !== false} onChange={(e) => onSetting({ [k]: e.target.checked })} /> {label}</label>
            ))}
          </div>
        </>
      )}

      {node.widgetType === 'products' && (
        <>
          <ProductCatPicker value={+s.category || 0} onPick={(v) => onSetting({ category: v })} />
          <Field label="Count (1–20)"><Num value={s.count ?? 8} onChange={(v) => onSetting({ count: Math.max(1, Math.min(20, v || 1)) })} /></Field>
          <Field label="Columns (1–4)"><Num value={s.columns ?? 4} onChange={(v) => onSetting({ columns: Math.max(1, Math.min(4, v || 1)) })} /></Field>
          <Field label="Order by">
            <select value={s.orderBy || 'date'} onChange={(e) => onSetting({ orderBy: e.target.value })}>
              <option value="date">newest</option>
              <option value="price">price</option>
              <option value="rating">rating</option>
              <option value="popularity">popularity</option>
              <option value="title">title</option>
            </select>
          </Field>
          <Field label="Order">
            <select value={s.order || 'DESC'} onChange={(e) => onSetting({ order: e.target.value })}>
              <option value="DESC">descending</option>
              <option value="ASC">ascending</option>
            </select>
          </Field>
          <div className="mel-row">
            {[['showImage', 'image'], ['showTitle', 'title'], ['showPrice', 'price'], ['showRating', 'rating'], ['showBadge', 'badge'], ['showCart', 'cart']].map(([k, label]) => (
              <label key={k}><input type="checkbox" checked={s[k] !== false} onChange={(e) => onSetting({ [k]: e.target.checked })} /> {label}</label>
            ))}
          </div>
          <p className="mel-status">needs WooCommerce active on this site.</p>
        </>
      )}

      {node.widgetType === 'product-title' && (
        <Field label="Tag">
          <select value={s.tag || 'h1'} onChange={(e) => onSetting({ tag: e.target.value })}>
            {['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'div'].map((t) => <option key={t} value={t}>{t}</option>)}
          </select>
        </Field>
      )}

      {node.widgetType === 'product-image' && (
        <div className="mel-row">
          <label><input type="checkbox" checked={s.showThumbs !== false} onChange={(e) => onSetting({ showThumbs: e.target.checked })} /> thumbnails</label>
        </div>
      )}

      {(node.widgetType === 'product-title' || node.widgetType === 'product-price' || node.widgetType === 'product-cart' || node.widgetType === 'product-rating' || node.widgetType === 'product-image' || node.widgetType === 'product-excerpt') && (
        <p className="mel-status">shows the current product — use inside a Single Product template.</p>
      )}

      {node.widgetType === 'menu-cart' && (
        <>
          <div className="mel-row">
            <label><input type="checkbox" checked={s.showCount !== false} onChange={(e) => onSetting({ showCount: e.target.checked })} /> count</label>
            <label><input type="checkbox" checked={!!s.showTotal} onChange={(e) => onSetting({ showTotal: e.target.checked })} /> total</label>
          </div>
          <p className="mel-status">links to the WooCommerce cart page.</p>
        </>
      )}

      {(node.widgetType === 'woo-cart' || node.widgetType === 'woo-checkout') && (
        <p className="mel-status">embeds Woo's own {node.widgetType === 'woo-cart' ? 'cart' : 'checkout'} here — full functionality, Woo styles.</p>
      )}

      {node.widgetType === 'countdown' && (
        <Field label="Target date & time"><input type="datetime-local" value={s.target || ''} onChange={(e) => onSetting({ target: e.target.value })} /></Field>
      )}

      {node.widgetType === 'carousel' && (
        <>
          {(s.slides || []).map((sl: any, i: number) => (
            <div key={i} className="mel-tabedit">
              <Field label={`Slide ${i + 1} image URL`}>
                <input value={sl.url || ''} onChange={(e) => {
                  const slides = [...(s.slides || [])];
                  slides[i] = { ...slides[i], url: e.target.value };
                  onSetting({ slides });
                }} />
              </Field>
              <div className="mel-row">
                <button onClick={() => pickImage((url) => {
                  const slides = [...(s.slides || [])];
                  slides[i] = { ...slides[i], url };
                  onSetting({ slides });
                })}>📚 Pick</button>
                <button onClick={() => onSetting({ slides: (s.slides || []).filter((_: any, j: number) => j !== i) })}>remove</button>
              </div>
              <Field label="Heading">
                <input value={sl.heading || ''} onChange={(e) => {
                  const slides = [...(s.slides || [])];
                  slides[i] = { ...slides[i], heading: e.target.value };
                  onSetting({ slides });
                }} />
              </Field>
              <Field label="Caption">
                <input value={sl.text || ''} onChange={(e) => {
                  const slides = [...(s.slides || [])];
                  slides[i] = { ...slides[i], text: e.target.value };
                  onSetting({ slides });
                }} />
              </Field>
              <Field label="Link URL">
                <input value={sl.link || ''} onChange={(e) => {
                  const slides = [...(s.slides || [])];
                  slides[i] = { ...slides[i], link: e.target.value };
                  onSetting({ slides });
                }} />
              </Field>
            </div>
          ))}
          <button onClick={() => onSetting({ slides: [...(s.slides || []), { url: '', alt: '', heading: '', text: '', link: '' }] })}>+ add slide</button>
          <p className="mel-status">swipe / scroll-snap, no JS needed.</p>
        </>
      )}

      {node.widgetType === 'price-table' && (
        <>
          <Field label="Title"><input value={s.title || ''} onChange={(e) => onSetting({ title: e.target.value })} /></Field>
          <div className="mel-row">
            <Field label="Currency"><input value={s.currency || ''} onChange={(e) => onSetting({ currency: e.target.value })} /></Field>
            <Field label="Price"><input value={s.price || ''} onChange={(e) => onSetting({ price: e.target.value })} /></Field>
            <Field label="Period"><input value={s.period || ''} onChange={(e) => onSetting({ period: e.target.value })} /></Field>
          </div>
          <Field label="Features (one per line)">
            <textarea rows={4} value={(Array.isArray(s.features) ? s.features : []).join('\n')} onChange={(e) => onSetting({ features: e.target.value.split('\n') })} />
          </Field>
          <Field label="Button text"><input value={s.buttonText || ''} onChange={(e) => onSetting({ buttonText: e.target.value })} /></Field>
          <Field label="Button URL"><input value={s.buttonUrl || ''} onChange={(e) => onSetting({ buttonUrl: e.target.value })} /></Field>
          <div className="mel-row">
            <label><input type="checkbox" checked={!!s.highlight} onChange={(e) => onSetting({ highlight: e.target.checked })} /> highlighted</label>
          </div>
        </>
      )}

      {node.widgetType === 'social' && (
        <>
          {(s.items || []).map((it: any, i: number) => (
            <div key={i} className="mel-tabedit">
              <Field label="Network">
                <select value={it.network || 'x'} onChange={(e) => {
                  const items = [...(s.items || [])];
                  items[i] = { ...items[i], network: e.target.value };
                  onSetting({ items });
                }}>
                  {['facebook', 'x', 'instagram', 'youtube', 'linkedin'].map((n) => <option key={n} value={n}>{n}</option>)}
                </select>
              </Field>
              <Field label="URL">
                <input value={it.url || ''} onChange={(e) => {
                  const items = [...(s.items || [])];
                  items[i] = { ...items[i], url: e.target.value };
                  onSetting({ items });
                }} />
              </Field>
              <button onClick={() => onSetting({ items: (s.items || []).filter((_: any, j: number) => j !== i) })}>remove</button>
            </div>
          ))}
          <button onClick={() => onSetting({ items: [...(s.items || []), { network: 'x', url: '' }] })}>+ add link</button>
        </>
      )}

      {node.widgetType === 'star-rating' && (
        <Field label="Rating (0–5)"><Num value={s.rating ?? 5} onChange={(v) => onSetting({ rating: Math.max(0, Math.min(5, Math.round((v || 0) * 2) / 2)) })} /></Field>
      )}

      {node.widgetType === 'accordion' && (
        <>
          {(s.items || []).map((t: any, i: number) => (
            <div key={i} className="mel-tabedit">
              <Field label={`Item ${i + 1} title`}>
                <input value={t.title || ''} onChange={(e) => {
                  const items = [...(s.items || [])];
                  items[i] = { ...items[i], title: e.target.value };
                  onSetting({ items });
                }} />
              </Field>
              <Field label="Content">
                <textarea rows={2} value={t.content || ''} onChange={(e) => {
                  const items = [...(s.items || [])];
                  items[i] = { ...items[i], content: e.target.value };
                  onSetting({ items });
                }} />
              </Field>
              <button onClick={() => onSetting({ items: (s.items || []).filter((_: any, j: number) => j !== i) })}>remove item</button>
            </div>
          ))}
          <button onClick={() => onSetting({ items: [...(s.items || []), { title: 'New item', content: 'Content' }] })}>+ add item</button>
        </>
      )}

      {node.widgetType === 'gallery' && (
        <>
          <Field label="Columns (1–6)"><Num value={s.columns ?? 3} onChange={(v) => onSetting({ columns: Math.max(1, Math.min(6, v || 1)) })} /></Field>
          {(s.images || []).map((im: any, i: number) => (
            <div key={i} className="mel-tabedit">
              <Field label={`Image ${i + 1} URL`}>
                <input value={im.url || ''} onChange={(e) => {
                  const images = [...(s.images || [])];
                  images[i] = { ...images[i], url: e.target.value };
                  onSetting({ images });
                }} />
              </Field>
              <div className="mel-row">
                <button onClick={() => pickImage((url, id) => {
                  const images = [...(s.images || [])];
                  images[i] = { ...images[i], url, id };
                  onSetting({ images });
                })}>📚 Pick</button>
                <button onClick={() => onSetting({ images: (s.images || []).filter((_: any, j: number) => j !== i) })}>remove</button>
              </div>
            </div>
          ))}
          <button onClick={() => onSetting({ images: [...(s.images || []), { url: '', alt: '', id: 0 }] })}>+ add image</button>
        </>
      )}

      {node.widgetType === 'counter' && (
        <>
          <Field label="Number"><Num value={s.number ?? 0} onChange={(v) => onSetting({ number: v || 0 })} /></Field>
          <Field label="Prefix"><input value={s.prefix || ''} onChange={(e) => onSetting({ prefix: e.target.value })} /></Field>
          <Field label="Suffix"><input value={s.suffix || ''} onChange={(e) => onSetting({ suffix: e.target.value })} /></Field>
        </>
      )}

      {node.widgetType === 'testimonial' && (
        <>
          <Field label="Quote"><textarea rows={3} value={s.quote || ''} onChange={(e) => onSetting({ quote: e.target.value })} /></Field>
          <Field label="Name"><input value={s.name || ''} onChange={(e) => onSetting({ name: e.target.value })} /></Field>
          <Field label="Role"><input value={s.role || ''} onChange={(e) => onSetting({ role: e.target.value })} /></Field>
          <Field label="Avatar URL"><input value={s.avatar || ''} onChange={(e) => onSetting({ avatar: e.target.value })} /></Field>
        </>
      )}

      {node.widgetType === 'nav' && (
        <NavInspector node={node} onSetting={(p) => onSetting(p)} />
      )}

      <BoxFields layout={L} globals={globals} onStyle={(p) => onStyle(p)} />

      <AdvancedCss node={node} bp={bp} onStyle={(p) => onStyle(p)} />

      {node.widgetType === 'form' && (
        <>
          {(s.fields || []).map((f: any, i: number) => (
            <div key={i} className="mel-tabedit">
              <Field label="Label">
                <input value={f.label || ''} onChange={(e) => {
                  const fields = [...(s.fields || [])];
                  const slug = (e.target.value || '').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || `field_${i}`;
                  fields[i] = { ...fields[i], label: e.target.value, name: fields[i].name || slug };
                  onSetting({ fields });
                }} />
              </Field>
              <Field label="Type">
                <select value={f.type || 'text'} onChange={(e) => {
                  const fields = [...(s.fields || [])];
                  fields[i] = { ...fields[i], type: e.target.value };
                  onSetting({ fields });
                }}>
                  {['text', 'email', 'textarea', 'select'].map((t) => <option key={t} value={t}>{t}</option>)}
                </select>
              </Field>
              <div className="mel-row">
                <label><input type="checkbox" checked={!!f.required} onChange={(e) => {
                  const fields = [...(s.fields || [])];
                  fields[i] = { ...fields[i], required: e.target.checked };
                  onSetting({ fields });
                }} /> required</label>
                <button onClick={() => onSetting({ fields: (s.fields || []).filter((_: any, j: number) => j !== i) })}>remove</button>
              </div>
              {f.type === 'select' && (
                <Field label="Options (one per line)">
                  <textarea rows={3} value={(f.options || []).join('\n')} onChange={(e) => {
                    const fields = [...(s.fields || [])];
                    fields[i] = { ...fields[i], options: e.target.value.split('\n').map((o) => o.trim()).filter(Boolean) };
                    onSetting({ fields });
                  }} />
                </Field>
              )}
            </div>
          ))}
          <button onClick={() => onSetting({ fields: [...(s.fields || []), { label: 'New field', name: `field_${(s.fields || []).length}`, type: 'text', required: false, options: [] }] })}>+ add field</button>
          <Field label="Button text"><input value={s.buttonText || ''} onChange={(e) => onSetting({ buttonText: e.target.value })} /></Field>
          <Field label="Success message"><input value={s.successMsg || ''} onChange={(e) => onSetting({ successMsg: e.target.value })} /></Field>
          <Field label="Recipient email (blank = site admin)"><input type="email" placeholder="forms@example.com" value={s.to || ''} onChange={(e) => onSetting({ to: e.target.value })} /></Field>
          <div className="mel-row">
            <label><input type="checkbox" checked={!!s.turnstile} onChange={(e) => onSetting({ turnstile: e.target.checked })} /> Turnstile check (needs keys in Melintir → Settings)</label>
          </div>
        </>
      )}

      {(node.widgetType === 'text' || node.widgetType === 'button') && (
        <Field label="Text size (px)"><Num value={T.size} onChange={(v) => onStyle({ typo: { size: v } })} /></Field>
      )}
      {(node.widgetType === 'text' || node.widgetType === 'button') && (
        <FontField value={T.family || ''} globals={globals} onChange={(v) => onStyle({ typo: { family: v } })} />
      )}
    </div>
  );
}

/** Shadow / border / gradient for any node. Gradient replaces bg when both set. */
function BoxFields({ layout, globals, onStyle }: {
  layout: any;
  globals: Record<string, string>;
  onStyle: (p: Record<string, any>) => void;
}) {
  const L = layout || {};
  const G = L.gradient || {};
  const setGradient = (patch: Record<string, any>) =>
    onStyle({ layout: { gradient: { from: G.from, to: G.to, angle: G.angle, ...patch } } });
  return (
    <div className="mel-advanced">
      <h4>Box</h4>
      <Field label="Shadow">
        <select value={L.shadow || ''} onChange={(e) => onStyle({ layout: { shadow: e.target.value || undefined } })}>
          <option value="">none</option>
          <option value="sm">small</option>
          <option value="md">medium</option>
          <option value="lg">large</option>
          <option value="xl">extra large</option>
        </select>
      </Field>
      <div className="mel-row">
        <Field label="Border px"><Num value={L.borderWidth} onChange={(v) => onStyle({ layout: { borderWidth: Math.max(0, Math.min(8, v || 0)) } })} /></Field>
        <Field label="Style">
          <select value={L.borderStyle || 'solid'} onChange={(e) => onStyle({ layout: { borderStyle: e.target.value } })}>
            <option value="solid">solid</option>
            <option value="dashed">dashed</option>
            <option value="dotted">dotted</option>
          </select>
        </Field>
      </div>
      <Field label="Border color">
        <ColorField value={L.borderColor || ''} globals={globals} fallback="#0f172a" onChange={(v) => onStyle({ layout: { borderColor: v } })} />
      </Field>
      <div className="mel-row">
        <Field label="Gradient from">
          <ColorField value={G.from || ''} globals={globals} fallback="#2563eb" onChange={(v) => setGradient({ from: v })} />
        </Field>
        <Field label="to">
          <ColorField value={G.to || ''} globals={globals} fallback="#7c3aed" onChange={(v) => setGradient({ to: v })} />
        </Field>
        <Field label="°"><Num value={G.angle ?? 135} onChange={(v) => setGradient({ angle: Math.max(0, Math.min(360, v || 0)) })} /></Field>
      </div>
    </div>
  );
}

/** Declarations-only custom CSS, auto-scoped to .mel-{id} by the generators. */
function AdvancedCss({ node, bp, onStyle }: {
  node: MelNode;
  bp: BP;
  onStyle: (p: Record<string, any>) => void;
}) {
  const scope = bp === 'desktop' ? (node.style as any) : ((node.style as any)?.[bp] || {});
  return (
    <div className="mel-advanced">
      <h4>Advanced{bp !== 'desktop' && <span className="mel-bpbadge">· {bp}</span>}</h4>
      <Field label="Custom CSS (declarations)">
        <textarea
          rows={3}
          spellCheck={false}
          placeholder="transform: rotate(2deg)"
          value={typeof scope?.customCss === 'string' ? scope.customCss : ''}
          onChange={(e) => onStyle({ customCss: e.target.value })}
        />
      </Field>
    </div>
  );
}

/** Menu picker with live wp/v2 menu list. */
function NavInspector({ node, onSetting }: {
  node: MelNode;
  onSetting: (p: Record<string, any>) => void;
}) {
  const s = node.settings || {};
  const [menus, setMenus] = useState<WpMenu[]>([]);
  useEffect(() => {
    const base = wpApiBase();
    if (!base) return;
    const d: any = (window as any).MelintirData;
    fetch(wpApiUrl(base, '/wp/v2/menus') + 'per_page=50&_fields=id,name', {
      headers: d?.nonce ? { 'X-WP-Nonce': d.nonce } : {},
    })
      .then((r) => (r.ok ? r.json() : []))
      .then((j) => Array.isArray(j) && setMenus(j))
      .catch(() => {});
  }, []);
  return (
    <>
      <Field label="Menu">
        <select value={s.menu || 0} onChange={(e) => onSetting({ menu: +e.target.value })}>
          <option value={0}>— Select —</option>
          {menus.map((m) => <option key={m.id} value={m.id}>{m.name}</option>)}
        </select>
      </Field>
      <Field label="Layout">
        <select value={s.layout || 'horizontal'} onChange={(e) => onSetting({ layout: e.target.value })}>
          <option value="horizontal">horizontal</option>
          <option value="vertical">vertical</option>
        </select>
      </Field>
      <div className="mel-row">
        <label><input type="checkbox" checked={s.showToggle !== false} onChange={(e) => onSetting({ showToggle: e.target.checked })} /> hamburger on mobile</label>
      </div>
    </>
  );
}

/** Category dropdown fed by the public Woo Store API; falls back to raw ID input. */
function ProductCatPicker({ value, onPick }: {
  value: number;
  onPick: (v: number) => void;
}) {
  const [cats, setCats] = useState<{ id: number; name: string; count: number }[]>([]);
  useEffect(() => {
    const base = wpApiBase();
    if (!base) return;
    const d: any = (window as any).MelintirData;
    fetch(wpApiUrl(base, '/wc/store/v1/products/categories') + 'per_page=50', {
      headers: d?.nonce ? { 'X-WP-Nonce': d.nonce } : {},
    })
      .then((r) => (r.ok ? r.json() : []))
      .then((j) => Array.isArray(j) && setCats(
        j.filter((c: any) => c && c.id).map((c: any) => ({ id: +c.id, name: String(c.name), count: +c.count || 0 }))
      ))
      .catch(() => {});
  }, []);
  if (!cats.length) {
    return (
      <Field label="Category ID (0 = all)">
        <input type="number" value={value} onChange={(e) => onPick(Math.max(0, +e.target.value || 0))} />
      </Field>
    );
  }
  return (
    <Field label="Category">
      <select value={value} onChange={(e) => onPick(+e.target.value)}>
        <option value={0}>All products</option>
        {cats.map((c) => <option key={c.id} value={c.id}>{c.name} ({c.count})</option>)}
      </select>
    </Field>
  );
}

/** Tree outline: click to select, indent shows nesting. */
function Navigator({ root, selectedId, onSelect }: {
  root: MelNode;
  selectedId: string | null;
  onSelect: (id: string | null) => void;
}) {
  return (
    <div className="mel-navigator">
      {(root.elements || []).map((n) => (
        <NavItem key={n.id} node={n} depth={0} selectedId={selectedId} onSelect={onSelect} />
      ))}
      {(root.elements || []).length === 0 && <p className="mel-status">empty page</p>}
    </div>
  );
}

function NavItem({ node, depth, selectedId, onSelect }: {
  node: MelNode;
  depth: number;
  selectedId: string | null;
  onSelect: (id: string | null) => void;
}) {
  const label = node.widgetType || node.elType;
  return (
    <>
      <div
        className={`mel-navitem${node.id === selectedId ? ' mel-active' : ''}`}
        style={{ paddingLeft: 6 + depth * 14 }}
        onClick={() => onSelect(node.id)}
      >
        {node.elType === 'container' ? '▦' : '▫'} {label}
      </div>
      {(node.elements || []).map((c) => (
        <NavItem key={c.id} node={c} depth={depth + 1} selectedId={selectedId} onSelect={onSelect} />
      ))}
    </>
  );
}

const DYN_TAGS = ['site_title', 'site_tagline', 'post_title', 'post_date', 'post_excerpt', 'author_name'];

/** Append a {{tag}} to a text setting. Preview shows the raw tag; frontend resolves it. */
function TagButtons({ current, onPick }: { current: string; onPick: (v: string) => void }) {
  return (
    <div className="mel-row">
      {DYN_TAGS.map((t) => (
        <button key={t} title={`Insert {{${t}}}`} onClick={() => onPick(`${current || ''}{{${t}}}`)}>
          {`{{${t}}}`}
        </button>
      ))}
    </div>
  );
}

const isHex6 = (v: string) => /^#[0-9a-fA-F]{6}$/.test(v || '');
const isMelVar = (v: string) => /^var\(--mel-[a-z0-9-]+\)$/.test(v || '');

/** Color picker + global swatch select. Writes hex or var(--mel-name). */
function ColorField({ value, globals, fallback, onChange }: {
  value: string;
  globals: Record<string, string>;
  fallback: string;
  onChange: (v: string) => void;
}) {
  const names = Object.keys(globals || {});
  return (
    <div className="mel-row">
      <input type="color" value={isHex6(value) ? value : fallback} onChange={(e) => onChange(e.target.value)} />
      <select
        value={isMelVar(value) ? value : ''}
        onChange={(e) => { if (e.target.value) onChange(e.target.value); }}
        title="Global swatch"
      >
        <option value="">custom</option>
        {names.map((n) => <option key={n} value={`var(--mel-${n})`}>{n}</option>)}
      </select>
    </div>
  );
}

/** Font picker: curated stacks + global font tokens. */
function FontField({ value, globals, onChange }: {
  value: string;
  globals: Record<string, string>;
  onChange: (v: string) => void;
}) {
  const names = Object.keys(globals || {});
  return (
    <Field label="Font">
      <select value={value || ''} onChange={(e) => onChange(e.target.value)}>
        <option value="">default</option>
        {FONT_STACK_OPTIONS.map((o) => <option key={o.key} value={o.key}>{o.label}</option>)}
        {names.map((n) => <option key={n} value={`var(--mel-font-${n})`}>🌐 {n}</option>)}
      </select>
    </Field>
  );
}

/** Global palette: colors + fonts, add/remove. Change propagates via CSS vars. */
export const FONT_STACK_OPTIONS = [
  { key: 'system-sans', label: 'System Sans', stack: `-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif` },
  { key: 'system-serif', label: 'System Serif', stack: `Georgia, 'Times New Roman', serif` },
  { key: 'system-mono', label: 'System Mono', stack: `ui-monospace, Menlo, Consolas, monospace` },
  { key: 'display', label: 'Display', stack: `Impact, 'Arial Narrow', sans-serif` },
  { key: 'handwriting', label: 'Handwriting', stack: `'Comic Sans MS', 'Chalkboard SE', cursive` },
];

function GlobalsPanel({ colors, fonts, onChange, onNotice }: {
  colors: Record<string, string>;
  fonts: Record<string, string>;
  onChange: (p: { colors?: Record<string, string>; fonts?: Record<string, string> }) => void;
  onNotice?: (msg: string) => void;
}) {
  const [name, setName] = useState('accent');
  const [hex, setHex] = useState('#2563eb');
  const [fname, setFname] = useState('heading');
  const [fstack, setFstack] = useState('system-serif');
  const slug = (s: string) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 32);
  /** Pull the active theme's palette in as theme-* globals (never overwrites). */
  const importTheme = async () => {
    const d: any = (window as any).MelintirData;
    const base = typeof d?.restUrl === 'string' ? d.restUrl.split('/melintir/v1')[0] : null;
    if (!base) {
      onNotice?.('theme import: no REST base');
      return;
    }
    try {
      const r = await fetch(wpApiUrl(base, '/melintir/v1/theme-globals'), {
        headers: d?.nonce ? { 'X-WP-Nonce': d.nonce } : {},
      });
      const j = await r.json();
      const tc = j?.colors && typeof j.colors === 'object' ? j.colors : {};
      const tf = j?.fonts && typeof j.fonts === 'object' ? j.fonts : {};
      onChange({ colors: { ...(colors || {}), ...tc }, fonts: { ...(fonts || {}), ...tf } });
      onNotice?.(`theme import: ${Object.keys(tc).length} colors, ${Object.keys(tf).length} fonts (theme-*)`);
    } catch {
      onNotice?.('theme import failed');
    }
  };
  return (
    <>
      <h4>Globals</h4>
      <div className="mel-row">
        <button onClick={importTheme} title="Copy the active theme's palette into globals as theme-*">⬇ Theme → globals</button>
      </div>
      {Object.entries(colors || {}).map(([n, h]) => (
        <div key={n} className="mel-row">
          <span className="mel-status" title={`var(--mel-${n})`}>{n}</span>
          <input
            type="color"
            value={isHex6(h) ? h : '#000000'}
            onChange={(e) => onChange({ colors: { ...colors, [n]: e.target.value } })}
          />
          <button onClick={() => { const c = { ...colors }; delete c[n]; onChange({ colors: c }); }}>✕</button>
        </div>
      ))}
      <div className="mel-row">
        <input placeholder="name" value={name} onChange={(e) => setName(e.target.value)} />
        <input type="color" value={isHex6(hex) ? hex : '#2563eb'} onChange={(e) => setHex(e.target.value)} />
        <button onClick={() => {
          const k = slug(name);
          if (k && isHex6(hex)) onChange({ colors: { ...colors, [k]: hex } });
        }}>+</button>
      </div>
      <h4>Fonts</h4>
      {Object.entries(fonts || {}).map(([n, f]) => (
        <div key={n} className="mel-row">
          <span className="mel-status" title={`var(--mel-font-${n})`}>{n}</span>
          <span className="mel-status">{String(f).slice(0, 24)}</span>
          <button onClick={() => { const c = { ...(fonts || {}) }; delete c[n]; onChange({ fonts: c }); }}>✕</button>
        </div>
      ))}
      <div className="mel-row">
        <input placeholder="name" value={fname} onChange={(e) => setFname(e.target.value)} />
        <select value={fstack} onChange={(e) => setFstack(e.target.value)}>
          {FONT_STACK_OPTIONS.map((o) => <option key={o.key} value={o.key}>{o.label}</option>)}
        </select>
        <button onClick={() => {
          const k = slug(fname);
          if (k) onChange({ fonts: { ...(fonts || {}), [k]: fstack } });
        }}>+</button>
      </div>
    </>
  );
}
