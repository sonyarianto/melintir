import { useEffect, useMemo, useState } from 'react';
import { useEditor } from './store';
import { generateCss, initWasm, isWasm } from './wasm';
import { PreviewNode } from './widgets';
import type { WidgetType } from './types';

const PALETTE: WidgetType[] = ['heading', 'text', 'image', 'button', 'video', 'divider', 'spacer', 'icon-box', 'tabs'];

export default function App() {
  const { doc, selectedId, setSelected, addWidget, updateNode, removeNode, undo, redo, load, dirty } = useEditor();
  const [wasmOk, setWasmOk] = useState(false);
  const [status, setStatus] = useState('loading…');
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    initWasm().then(setWasmOk);
    // Load existing doc for this post.
    const d: any = (window as any).MelintirData;
    if (d?.restUrl) {
      fetch(d.restUrl + '/load', { headers: { 'X-WP-Nonce': d.nonce } })
        .then((r) => r.json())
        .then((j) => {
          if (j?.doc?.root) {
            load(typeof j.doc === 'string' ? JSON.parse(j.doc) : j.doc);
            setStatus('loaded');
          } else setStatus('new document');
        })
        .catch(() => setStatus('new document (offline)'));
    }
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

  const sel = useMemo(() => {
    const find = (n: any): any => (n.id === selectedId ? n : (n.elements || []).map(find).find(Boolean));
    return selectedId ? find(doc.root) : null;
  }, [doc, selectedId]);

  return (
    <div className="mel-app">
      <style>{css}</style>
      <aside className="mel-panel">
        <h3>Melintir v0.1 {isWasm() || wasmOk ? '⚡WASM' : 'JS-fallback'}</h3>
        <div className="mel-row">
          <button onClick={undo}>↩</button>
          <button onClick={redo}>↪</button>
          <button onClick={save} disabled={saving || !dirty}>{saving ? '…' : 'Save'}</button>
        </div>
        <p className="mel-status">{status} · css {Math.round(ms * 100) / 100}ms · {JSON.stringify(doc.root).length / 1024 < 1 ? '<1' : (JSON.stringify(doc.root).length / 1024).toFixed(1)}KB</p>
        <h4>Add</h4>
        <div className="mel-grid">
          {PALETTE.map((w) => (
            <button key={w} onClick={() => addWidget(w)}>{w}</button>
          ))}
        </div>
        {sel && (
          <div className="mel-inspector">
            <h4>Selected: {sel.widgetType || sel.elType} <button onClick={() => removeNode(sel.id)}>✕</button></h4>
            {sel.settings?.text != null && (
              <label>Text<input value={sel.settings.text} onChange={(e) => updateNode(sel.id, { settings: { text: e.target.value } })} /></label>
            )}
            {sel.settings?.html != null && (
              <label>HTML<textarea value={sel.settings.html} onChange={(e) => updateNode(sel.id, { settings: { html: e.target.value } })} /></label>
            )}
            {sel.settings?.url != null && (
              <label>URL<input value={sel.settings.url} onChange={(e) => updateNode(sel.id, { settings: { url: e.target.value } })} /></label>
            )}
            <label>Font size<input type="number" value={sel.style?.typo?.size || ''} onChange={(e) => updateNode(sel.id, { style: { typo: { ...sel.style?.typo, size: +e.target.value } } as any })} /></label>
            <label>Gap/padding<input type="number" value={sel.style?.layout?.gap ?? sel.style?.layout?.padding ?? ''} onChange={(e) => updateNode(sel.id, { style: { layout: { ...sel.style?.layout, gap: +e.target.value, padding: +e.target.value } } as any })} /></label>
          </div>
        )}
      </aside>
      <main className="mel-canvas" onClick={() => setSelected(null)}>
        <div className="mel-page">
          {(doc.root.elements || []).map((n) => (
            <PreviewNode key={n.id} node={n} selected={n.id === selectedId} onSelect={setSelected} />
          ))}
        </div>
      </main>
    </div>
  );
}
