import { create } from 'zustand';
import { blankDoc, uid, type MelDoc, type MelNode, type WidgetType } from './types';
import { insertAt, moveNode as moveTreeNode, siblingSlot } from './tree';

interface EditorState {
  doc: MelDoc;
  selectedId: string | null;
  past: string[];
  future: string[];
  dirty: boolean;
  setSelected: (id: string | null) => void;
  addWidget: (type: WidgetType) => void;
  addWidgetAt: (type: WidgetType, parentId: string, index: number) => boolean;
  insertNode: (node: MelNode) => void;
  moveNode: (dragId: string, parentId: string, index: number) => boolean;
  nudgeSelected: (dir: -1 | 1) => void;
  updateNode: (id: string, patch: Partial<MelNode>) => void;
  removeNode: (id: string) => void;
  undo: () => void;
  redo: () => void;
  load: (doc: MelDoc, markDirty?: boolean) => void;
  setGlobals: (patch: { colors?: Record<string, string>; fonts?: Record<string, string> }) => void;
}

const snap = (doc: MelDoc) => JSON.stringify(doc);

function mapNode(n: MelNode, id: string, fn: (n: MelNode) => MelNode | null): MelNode | null {
  if (n.id === id) return fn(n);
  return { ...n, elements: n.elements.map((c) => mapNode(c, id, fn)).filter(Boolean) as MelNode[] };
}

function defaults(type: WidgetType): Partial<MelNode> {
  switch (type) {
    case 'heading': return { settings: { text: 'New heading', tag: 'h2' }, style: { typo: { size: 32, weight: 700 } } };
    case 'text': return { settings: { html: '<p>New text</p>' }, style: {} };
    case 'image': return { settings: { url: 'https://picsum.photos/800/450', alt: '' }, style: {} };
    case 'button': return { settings: { text: 'Click me', url: '#' }, style: {} };
    case 'video': return { settings: { url: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' }, style: {} };
    case 'divider': return { settings: {}, style: {} };
    case 'spacer': return { settings: {}, style: { layout: { padding: 24 } as any } };
    case 'icon-box': return { settings: { title: 'Feature', desc: 'Description', icon: 'star' }, style: {} };
    case 'tabs': return { settings: { tabs: [{ title: 'Tab 1', content: 'Content 1' }, { title: 'Tab 2', content: 'Content 2' }] }, style: {} };
    case 'form': return {
      settings: {
        fields: [
          { label: 'Name', name: 'name', type: 'text', required: true, options: [] },
          { label: 'Email', name: 'email', type: 'email', required: true, options: [] },
          { label: 'Message', name: 'message', type: 'textarea', required: false, options: [] },
        ],
        buttonText: 'Send',
        successMsg: 'Thanks! We got your message.',
      },
      style: {},
    };
    case 'loop': return {
      settings: { postType: 'post', postsPerPage: 6, columns: 3, order: 'DESC', orderBy: 'date', showImage: true, showTitle: true, showExcerpt: true },
      style: {},
    };
    case 'accordion': return {
      settings: { items: [{ title: 'Item 1', content: 'Content 1' }, { title: 'Item 2', content: 'Content 2' }] },
      style: {},
    };
    case 'gallery': return {
      settings: { images: [{ url: 'https://picsum.photos/seed/a/600/400', alt: '', id: 0 }, { url: 'https://picsum.photos/seed/b/600/400', alt: '', id: 0 }], columns: 3 },
      style: {},
    };
    case 'counter': return { settings: { number: 1234, prefix: '', suffix: '+' }, style: {} };
    case 'testimonial': return {
      settings: { quote: 'Melintir is blazing fast.', name: 'Jane Doe', role: 'Founder', avatar: '' },
      style: {},
    };
    case 'nav': return {
      settings: { menu: 0, layout: 'horizontal', showToggle: true },
      style: {},
    };
    case 'products': return {
      settings: { count: 8, columns: 4, order: 'DESC', orderBy: 'date', category: 0, showImage: true, showTitle: true, showPrice: true, showRating: true, showBadge: true, showCart: true },
      style: {},
    };
    case 'product-title': return { settings: { tag: 'h1' }, style: {} };
    case 'product-image': return { settings: { showThumbs: true }, style: {} };
    case 'product-price':
    case 'product-cart':
    case 'product-rating':
    case 'product-excerpt': return { settings: {}, style: {} };
    case 'menu-cart': return { settings: { showCount: true, showTotal: false }, style: {} };
    case 'woo-cart':
    case 'woo-checkout': return { settings: {}, style: {} };
  }
}

export const useEditor = create<EditorState>((set, get) => ({
  doc: blankDoc(),
  selectedId: null,
  past: [],
  future: [],
  dirty: false,
  setSelected: (selectedId) => set({ selectedId }),
  addWidget: (type) =>
    set((s) => {
      const d = defaults(type);
      const node: MelNode = { id: uid(), elType: 'widget', widgetType: type, settings: d.settings || {}, style: (d.style as any) || {}, elements: [] };
      return { past: [...s.past.slice(-49), snap(s.doc)], future: [], dirty: true, selectedId: node.id, doc: { ...s.doc, root: { ...s.doc.root, elements: [...s.doc.root.elements, node] } } };
    }),
  addWidgetAt: (type, parentId, index) => {
    const d = defaults(type);
    const node: MelNode = { id: uid(), elType: 'widget', widgetType: type, settings: d.settings || {}, style: (d.style as any) || {}, elements: [] };
    let ok = false;
    set((s) => {
      const root = insertAt(s.doc.root, parentId, index, node);
      if (!root) return s;
      ok = true;
      return { past: [...s.past.slice(-49), snap(s.doc)], future: [], dirty: true, selectedId: node.id, doc: { ...s.doc, root } };
    });
    return ok;
  },
  insertNode: (node) =>
    set((s) => {
      const remap = (n: MelNode): MelNode => ({ ...n, id: uid(), elements: (n.elements || []).map(remap) });
      const copy = remap(node);
      return { past: [...s.past.slice(-49), snap(s.doc)], future: [], dirty: true, selectedId: copy.id, doc: { ...s.doc, root: { ...s.doc.root, elements: [...s.doc.root.elements, copy] } } };
    }),
  moveNode: (dragId, parentId, index) => {
    let ok = false;
    set((s) => {
      const root = moveTreeNode(s.doc.root, dragId, parentId, index);
      if (!root) return s;
      ok = true;
      return { past: [...s.past.slice(-49), snap(s.doc)], future: [], dirty: true, selectedId: dragId, doc: { ...s.doc, root } };
    });
    return ok;
  },
  nudgeSelected: (dir) => {
    const { doc, selectedId } = get();
    if (!selectedId) return;
    const slot = siblingSlot(doc.root, selectedId);
    if (!slot) return;
    const next = slot.index + dir;
    if (next < 0 || next >= slot.parent.elements.length) return;
    // Detach-then-insert: after removal the list is shorter by one, so the
    // precomputed sibling index is exactly the right insertion point.
    const root = moveTreeNode(doc.root, selectedId, slot.parent.id, next);
    if (!root) return;
    set((s) => ({ past: [...s.past.slice(-49), snap(s.doc)], future: [], dirty: true, doc: { ...s.doc, root } }));
  },
  updateNode: (id, patch) =>
    set((s) => {
      const root = mapNode(s.doc.root, id, (n) => ({ ...n, ...patch, style: { ...n.style, ...(patch.style || {}) }, settings: { ...n.settings, ...(patch.settings || {}) } }));
      if (!root) return s;
      return { past: [...s.past.slice(-49), snap(s.doc)], future: [], dirty: true, doc: { ...s.doc, root } };
    }),
  removeNode: (id) =>
    set((s) => {
      const prune = (n: MelNode): MelNode => ({ ...n, elements: n.elements.filter((c) => c.id !== id).map(prune) });
      return { past: [...s.past.slice(-49), snap(s.doc)], future: [], dirty: true, selectedId: null, doc: { ...s.doc, root: prune(s.doc.root) } };
    }),
  undo: () => set((s) => {
    if (!s.past.length) return s;
    const prev = s.past[s.past.length - 1];
    return { doc: JSON.parse(prev), past: s.past.slice(0, -1), future: [snap(s.doc), ...s.future], dirty: true };
  }),
  redo: () => set((s) => {
    if (!s.future.length) return s;
    const [next, ...rest] = s.future;
    return { doc: JSON.parse(next), past: [...s.past, snap(s.doc)], future: rest, dirty: true };
  }),
  load: (doc, markDirty = true) => set((s) => {
    // Preserve undo across loads so restoring a revision/autosave/template
    // never destroys the pre-restore state (undo returns to it).
    const prev = snap(s.doc);
    const past = JSON.stringify(doc) === prev ? s.past : [...s.past.slice(-49), prev];
    return { doc, past, future: [], dirty: markDirty, selectedId: null };
  }),
  setGlobals: (patch) => set((s) => ({
    past: [...s.past.slice(-49), snap(s.doc)], future: [], dirty: true,
    doc: { ...s.doc, globals: { ...s.doc.globals, ...patch } },
  })),
}));
