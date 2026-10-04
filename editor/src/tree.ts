import { uid, type MelNode } from './types';

/** Mirrors Security::MAX_DEPTH — deeper trees are dropped by PHP on save. */
export const MAX_TREE_DEPTH = 6;

export function findNode(root: MelNode, id: string): MelNode | null {
  if (root.id === id) return root;
  for (const c of root.elements || []) {
    const hit = findNode(c, id);
    if (hit) return hit;
  }
  return null;
}

/** Parent of id, or null when id is the root or missing. */
export function findParent(root: MelNode, id: string): MelNode | null {
  for (const c of root.elements || []) {
    if (c.id === id) return root;
    const hit = findParent(c, id);
    if (hit) return hit;
  }
  return null;
}

/** Depth of id (root = 0), or -1 when missing. */
export function depthOfId(root: MelNode, id: string, depth = 0): number {
  if (root.id === id) return depth;
  for (const c of root.elements || []) {
    const hit = depthOfId(c, id, depth + 1);
    if (hit >= 0) return hit;
  }
  return -1;
}

/** Node count of the subtree including itself (leaf = 1). */
export function subtreeHeight(node: MelNode): number {
  if (!node.elements || node.elements.length === 0) return 1;
  return 1 + Math.max(...node.elements.map(subtreeHeight));
}

export function isDescendant(ancestor: MelNode, id: string): boolean {
  return (ancestor.elements || []).some((c) => c.id === id || isDescendant(c, id));
}

/**
 * Remove id from the tree. Returns the removed node + new root,
 * or null when id is the root / missing. Never mutates the input.
 */
export function detach(root: MelNode, id: string): { removed: MelNode; root: MelNode } | null {
  if (root.id === id) return null;
  let removed: MelNode | null = null;
  const walk = (node: MelNode): MelNode => {
    if (removed) return node;
    const elements: MelNode[] = [];
    let changed = false;
    for (const c of node.elements || []) {
      if (!removed && c.id === id) {
        removed = c;
        changed = true;
        continue;
      }
      const next = walk(c);
      if (next !== c) changed = true;
      elements.push(next);
    }
    return changed ? { ...node, elements } : node;
  };
  const next = walk(root);
  return removed ? { removed, root: next } : null;
}

/**
 * Insert node into parentId at index (clamped). Returns the new root,
 * or null when the parent is missing or not a container.
 */
export function insertAt(root: MelNode, parentId: string, index: number, node: MelNode): MelNode | null {
  if (root.id === parentId) {
    if (root.elType !== 'container') return null;
    const elements = [...(root.elements || [])];
    elements.splice(Math.max(0, Math.min(index, elements.length)), 0, node);
    return { ...root, elements };
  }
  const at = (root.elements || []).findIndex((e) => e.id === parentId);
  if (at >= 0) {
    const parent = root.elements[at];
    if (parent.elType !== 'container') return null;
    const elements = [...(parent.elements || [])];
    elements.splice(Math.max(0, Math.min(index, elements.length)), 0, node);
    const next = [...root.elements];
    next[at] = { ...parent, elements };
    return { ...root, elements: next };
  }
  let changed = false;
  const elements = (root.elements || []).map((c) => {
    const r = insertAt(c, parentId, index, node);
    if (r) {
      changed = true;
      return r;
    }
    return c;
  });
  return changed ? { ...root, elements } : null;
}

/** Whether dragId may be dropped into parentId (same checks as moveNode). */
export function canDrop(root: MelNode, dragId: string, parentId: string): boolean {
  if (dragId === root.id) return false;
  const drag = findNode(root, dragId);
  if (!drag) return false;
  const parent = parentId === root.id ? root : findNode(root, parentId);
  if (!parent || parent.elType !== 'container') return false;
  if (parentId === dragId || isDescendant(drag, parentId)) return false;
  return depthOfId(root, parentId) + subtreeHeight(drag) <= MAX_TREE_DEPTH;
}

/**
 * Move dragId into parentId at index. Returns the new root, or null when
 * the move is illegal (self-drop, widget parent, depth overflow, missing).
 */
export function moveNode(root: MelNode, dragId: string, parentId: string, index: number): MelNode | null {
  if (!canDrop(root, dragId, parentId)) return null;
  const detached = detach(root, dragId);
  if (!detached) return null;
  return insertAt(detached.root, parentId, index, detached.removed);
}

/** Deep copy with fresh ids, so pastes/duplicates never collide. */
export function freshCopy(node: MelNode): MelNode {
  return { ...node, id: uid(), elements: (node.elements || []).map(freshCopy) };
}

/** Replace the node with id by `replacement` (ids kept as given). */
export function replaceNode(root: MelNode, id: string, replacement: MelNode): MelNode | null {
  if (root.id === id) return null; // root itself is never replaced
  let changed = false;
  const elements = (root.elements || []).map((c) => {
    if (!changed && c.id === id) {
      changed = true;
      return replacement;
    }
    const r = replaceNode(c, id, replacement);
    if (r) {
      changed = true;
      return r;
    }
    return c;
  });
  return changed ? { ...root, elements } : null;
}

export interface PatternMap {
  [pid: string]: MelNode;
}

/**
 * Client-side mirror of Patterns::expand (PHP): refs resolve to pattern
 * content with deterministic -2/-3 suffixes for repeat embeds. Used for
 * preview + preview CSS only; the saved doc keeps its refs.
 *
 * Also returns refs: ref-instance-id -> fully expanded node, so the canvas
 * can render locked content with the exact ids the preview CSS targets.
 */
export function expandPreview(doc: { root: MelNode }, map: PatternMap): { root: MelNode; refs: Record<string, MelNode> } {
  const counts: Record<string, number> = {};
  const refs: Record<string, MelNode> = {};
  const used = new Set<string>();
  const collect = (n: MelNode): void => {
    used.add(n.id);
    (n.elements || []).forEach(collect);
  };
  collect(doc.root);
  let budget = 0;
  const suffixIds = (n: MelNode, suffix: string): MelNode => {
    if (!suffix) return n;
    return { ...n, id: n.id + suffix, elements: (n.elements || []).map((c) => suffixIds(c, suffix)) };
  };
  const dedupe = (n: MelNode): MelNode => {
    let id = n.id;
    let i = 2;
    while (used.has(id)) {
      id = `${n.id}-${i}`;
      i++;
    }
    used.add(id);
    return { ...n, id, elements: (n.elements || []).map(dedupe) };
  };
  const countNodes = (n: MelNode): number =>
    1 + (n.elements || []).reduce((a, c) => a + countNodes(c), 0);
  const expandNode = (n: MelNode, stack: string[]): MelNode | null => {
    const pid = n.elType === 'widget' && n.widgetType === 'pattern-ref'
      ? (n.settings as any)?.patternId
      : null;
    if (typeof pid === 'string' && pid) {
      const src = map[pid];
      if (!src) return null;
      if (stack.includes(pid)) return null;
      counts[pid] = (counts[pid] || 0) + 1;
      const copy = dedupe(suffixIds(src, counts[pid] > 1 ? `-${counts[pid]}` : ''));
      budget += countNodes(copy);
      if (budget > 2000) return null;
      // Re-enter expandNode (not just kids): the replacement itself may be
      // another ref, which is exactly how cycles form.
      const expanded = expandNode(copy, [...stack, pid]);
      if (expanded) refs[n.id] = expanded;
      return expanded;
    }
    return expandKids(n, stack);
  };
  const expandKids = (n: MelNode, stack: string[]): MelNode => ({
    ...n,
    elements: (n.elements || [])
      .map((c) => expandNode(c, stack))
      .filter((c): c is MelNode => c !== null),
  });
  return { root: expandKids(doc.root, []), refs };
}

/** { parent, index } of id among its siblings, or null for root/missing. */
export function siblingSlot(root: MelNode, id: string): { parent: MelNode; index: number } | null {
  const parent = findParent(root, id);
  if (!parent) return null;
  const index = (parent.elements || []).findIndex((e) => e.id === id);
  return index < 0 ? null : { parent, index };
}
