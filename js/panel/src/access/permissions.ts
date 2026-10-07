// What the viewer may do on the access pages (PRD 5.10, GUARDRAILS 8): the prop `palette` every
// page behind the login shares is the read of action.list as the person, the actions the server
// decided they may run, so a page offers a command, such as creating a role or assigning a grant,
// only when the server would not refuse it, and says who can when it would. The names of the
// actions the list holds are also the permissions a person may give a role: the escalation guard
// lets an actor give only what it holds itself (invariant 31), which is exactly what the list says
// it may run, so the permissions a role form offers are these, beside those the role already has.

import type { ActionListV1 } from '../generated/protocol/ActionListV1';
import type { PaletteState } from '../shell/palette';

/** A permission a role form offers, with the text of its action when the catalogue or the schema has one. */
export interface PermissionOption {
  readonly name: string;
  readonly title: string;
}

/** Whether the list names the action, so the server lets the viewer run it. */
export function mayRun(palette: PaletteState, action: string): boolean {
  return palette.status === 'ready' && palette.list.actions.some((entry) => entry.name === action);
}

/**
 * The permissions a role form offers: every action of the list and the names given, each once,
 * sorted by name, with the title of the action the list has for it, or the name alone.
 */
export function permissionOptions(
  palette: PaletteState,
  held: readonly string[] = [],
  title: (action: ActionListV1['actions'][number]) => string = (action) => action.title,
): PermissionOption[] {
  const options = new Map<string, PermissionOption>();

  if (palette.status === 'ready') {
    for (const action of palette.list.actions) {
      options.set(action.name, { name: action.name, title: title(action) });
    }
  }

  for (const name of held) {
    if (!options.has(name)) {
      options.set(name, { name, title: name });
    }
  }

  return [...options.values()].sort((a, b) => (a.name < b.name ? -1 : a.name > b.name ? 1 : 0));
}
