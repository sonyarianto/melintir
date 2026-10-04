import type { MelNode } from './types';

export function PreviewNode({ node, selected, onSelect }: { node: MelNode; selected: boolean; onSelect: (id: string) => void }) {
  const cls = `mel-${node.id}${selected ? ' mel-selected' : ''}`;
  if (node.elType === 'container') {
    return (
      <div className={`mel-container ${cls}`} onClick={(e) => { e.stopPropagation(); onSelect(node.id); }}>
        {node.elements.map((c) => (
          <PreviewNode key={c.id} node={c} selected={false} onSelect={onSelect} />
        ))}
        {node.elements.length === 0 && <div className="mel-empty">Empty container</div>}
      </div>
    );
  }
  const s = node.settings || {};
  const wrap = (inner: React.ReactNode) => (
    <div className={cls} onClick={(e) => { e.stopPropagation(); onSelect(node.id); }}>{inner}</div>
  );
  switch (node.widgetType) {
    case 'heading': {
      const Tag = (s.tag || 'h2') as any;
      return wrap(<Tag className="mel-heading">{s.text}</Tag>);
    }
    case 'text': return wrap(<div className="mel-text" dangerouslySetInnerHTML={{ __html: s.html || '' }} />);
    case 'image': return wrap(<figure className="mel-image">{s.url ? <img src={s.url} alt={s.alt || ''} loading="lazy" /> : 'No image'}</figure>);
    case 'button': return wrap(<div className="mel-btn-wrap"><span className="mel-btn">{s.text}</span></div>);
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
    default: return wrap(<div>?</div>);
  }
}
