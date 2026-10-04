import type { MelNode } from './types';

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

/** { parent, index } of id among its siblings, or null for root/missing. */
export function siblingSlot(root: MelNode, id: string): { parent: MelNode; index: number } | null {
  const parent = findParent(root, id);
  if (!parent) return null;
  const index = (parent.elements || []).findIndex((e) => e.id === id);
  return index < 0 ? null : { parent, index };
}
