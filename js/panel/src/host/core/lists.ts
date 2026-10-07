// The kernel's lists as the core's pickers read them (PRD 13.4): the results of node.list,
// actor.list and role.list, which the pickers get as their data, read into what the kit's pickers
// take. The result codecs write the documents; a member above the viewer's access is absent, so
// each reader takes what is there and nothing else.

import type { JsonValue } from '@cboxdk/cms-panel/extend';
import type { PickerActor, PickerRole, TreeNode } from '@cboxdk/cms-ui-kit';

function isRecord(value: unknown): value is Readonly<Record<string, unknown>> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function text(record: Readonly<Record<string, unknown>>, key: string): string | undefined {
  const value = record[key];

  return typeof value === 'string' && value !== '' ? value : undefined;
}

/** The items of a list's result under the key, each an object with an id. */
function items(
  result: JsonValue,
  key: string,
): readonly (Readonly<Record<string, unknown>> & { readonly id: string })[] {
  const list = isRecord(result) ? result[key] : undefined;

  if (!Array.isArray(list)) {
    return [];
  }

  return list.flatMap((item) => {
    const id = isRecord(item) ? text(item, 'id') : undefined;

    return isRecord(item) && id !== undefined ? [{ ...item, id }] : [];
  });
}

/**
 * The nodes of node.list as a tree, in the list's order: each node under its parent when the list
 * has it, at the root otherwise, labelled by the last segment of its path label.
 */
export function nodeTree(result: JsonValue): TreeNode[] {
  const nodes = items(result, 'nodes');
  const children = new Map<string, TreeNode[]>();
  const roots: TreeNode[] = [];
  const known = new Set(nodes.map((node) => node.id));

  for (const node of nodes) {
    const label = text(node, 'label') ?? node.id;
    const entry: TreeNode[] = [];
    children.set(node.id, entry);
    const tree: TreeNode = {
      id: node.id,
      label: label.split('/').at(-1) ?? label,
      children: entry,
    };
    const parent = text(node, 'parent');
    const siblings = parent !== undefined && known.has(parent) ? children.get(parent) : undefined;
    (siblings ?? roots).push(tree);
  }

  return roots.map(leafless);
}

/** The node with no children member when it has none, so the tree shows it as a leaf. */
function leafless(node: TreeNode): TreeNode {
  const below = node.children ?? [];

  return below.length === 0
    ? { id: node.id, label: node.label }
    : { id: node.id, label: node.label, children: below.map(leafless) };
}

/**
 * The actors of actor.list as the picker offers them: named by their profile's display name when
 * the viewer may read it, by their id otherwise, with the email that tells actors of one name
 * apart.
 */
export function pickerActors(result: JsonValue): PickerActor[] {
  return items(result, 'actors').map((actor) => {
    const profile = isRecord(actor.profile) ? actor.profile : {};
    const email = text(profile, 'email');

    return {
      id: actor.id,
      name: text(profile, 'display_name') ?? actor.id,
      ...(email === undefined ? {} : { email }),
    };
  });
}

/** The roles of role.list as the picker offers them: by handle, with the ceiling as the second line. */
export function pickerRoles(result: JsonValue): PickerRole[] {
  return items(result, 'roles').map((role) => {
    const ceiling = text(role, 'ceiling');

    return {
      id: role.id,
      handle: text(role, 'handle') ?? role.id,
      ...(ceiling === undefined ? {} : { description: ceiling }),
    };
  });
}
