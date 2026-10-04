import { useEffect, useMemo, useState } from 'react';
import { useEditor } from './store';
import { generateCss, initWasm, isWasm } from './wasm';
import { PreviewNode, wpApiBase, wpApiUrl, type WpMenu } from './widgets';
import type { MelNode, WidgetType } from './types';

type BP = 'desktop' | 'tablet' | 'mobile';

const PALETTE: WidgetType[] = ['heading', 'text', 'image', 'button', 'video', 'divider', 'spacer', 'icon-box', 'tabs', 'form', 'loop', 'accordion', 'gallery', 'counter', 'testimonial', 'nav'];

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
  const { doc, selectedId, setSelected, addWidget, updateNode, removeNode, undo, redo, load, dirty, setGlobals } = useEditor();
  const [wasmOk, setWasmOk] = useState(false);
  const [status, setStatus] = useState('loading…');
  const [saving, setSaving] = useState(false);
  const [bp, setBp] = useState<BP>('desktop');
  const [templates, setTemplates] = useState<{ name: string; doc: any }[]>([]);
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
    refreshHistory();
  }, []);

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

  const sel: MelNode | null = useMemo(() => {
    const find = (n: MelNode): MelNode | null =>
      n.id === selectedId ? n : (n.elements || []).map(find).find(Boolean) || null;
    return selectedId ? find(doc.root) : null;
  }, [doc, selectedId]);

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
        <h3>Melintir v0.2 {isWasm() || wasmOk ? '⚡WASM' : 'JS-fallback'}</h3>
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
        <div className="mel-grid">
          {PALETTE.map((w) => (
            <button key={w} onClick={() => addWidget(w)}>{w}</button>
          ))}
        </div>
        <GlobalsPanel colors={doc.globals?.colors || {}} onChange={setGlobals} />
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
          />
        )}
      </aside>
      <main className="mel-canvas" onClick={() => setSelected(null)}>
        <div className="mel-page" style={{ maxWidth: bp === 'mobile' ? 390 : bp === 'tablet' ? 768 : 1100 }}>
          {(doc.root.elements || []).map((n) => (
            <PreviewNode key={n.id} node={n} selected={n.id === selectedId} onSelect={setSelected} />
          ))}
        </div>
      </main>
    </div>
  );
}

function Inspector({ node, bp, scope, globals, onStyle, onSetting, onRemove }: {
  node: MelNode;
  bp: BP;
  scope: { layout: any; typo: any };
  globals: Record<string, string>;
  onStyle: (p: Record<string, any>) => void;
  onSetting: (p: Record<string, any>) => void;
  onRemove: () => void;
}) {
  const s = node.settings || {};
  const L = scope.layout;
  const T = scope.typo;
  return (
    <div className="mel-inspector">
      <h4>
        {node.widgetType || node.elType}
        {bp !== 'desktop' && <span className="mel-bpbadge">· {bp}</span>}
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

/** Global palette: name + hex rows, add/remove. Change propagates via CSS vars. */
function GlobalsPanel({ colors, onChange }: {
  colors: Record<string, string>;
  onChange: (c: Record<string, string>) => void;
}) {
  const [name, setName] = useState('accent');
  const [hex, setHex] = useState('#2563eb');
  const slug = (s: string) => s.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 32);
  return (
    <>
      <h4>Globals</h4>
      {Object.entries(colors || {}).map(([n, h]) => (
        <div key={n} className="mel-row">
          <span className="mel-status" title={`var(--mel-${n})`}>{n}</span>
          <input
            type="color"
            value={isHex6(h) ? h : '#000000'}
            onChange={(e) => onChange({ ...colors, [n]: e.target.value })}
          />
          <button onClick={() => { const c = { ...colors }; delete c[n]; onChange(c); }}>✕</button>
        </div>
      ))}
      <div className="mel-row">
        <input placeholder="name" value={name} onChange={(e) => setName(e.target.value)} />
        <input type="color" value={isHex6(hex) ? hex : '#2563eb'} onChange={(e) => setHex(e.target.value)} />
        <button onClick={() => {
          const k = slug(name);
          if (k && isHex6(hex)) onChange({ ...colors, [k]: hex });
        }}>+</button>
      </div>
    </>
  );
}
