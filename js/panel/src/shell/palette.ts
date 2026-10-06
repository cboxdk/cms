// The command palette's entries (GUARDRAILS 8, keyboard first; PRD 13.2, 13.4): built from the prop
// `palette` every page behind the login shares, the read of action.list as the person who signed
// in, which the server decided, so the palette offers no command the server would refuse and no
// page the person may not open. A command entry opens the command's form page at the Inertia
// profile's address; a page entry opens its page at the address the shell's navigation knows.
// Nothing here names an action, a type or a field: the entries are what the server listed.

import type { PalettePropV1 } from '../generated/pages/PalettePropV1';
import { validatePalettePropV1 } from '../generated/pages/PalettePropV1';
import { validateActionListV1, type ActionListV1 } from '../generated/protocol/ActionListV1';
import { validateProblemV1, type ProblemV1 } from '../generated/protocol/ProblemV1';
import type { NavEntry } from '../host';
import { lookup, type Locale } from '../i18n/translations';

/** What the prop `palette` gave: the list, the problem details of a rejected read, or a document the panel cannot read. */
export type PaletteState =
  | { readonly status: 'ready'; readonly list: ActionListV1 }
  | { readonly status: 'rejected'; readonly problem: ProblemV1 }
  | { readonly status: 'unreadable' };

/** An entry of the palette: a page to open or a command to run, with the address it opens. */
export interface PaletteItem {
  readonly id: string;
  readonly label: string;
  readonly description?: string | undefined;
  readonly keywords: readonly string[];
  readonly url: string;
}

/** The prefix of a command entry's id, before `<name>@<version>`. */
export const COMMAND_ENTRY = 'command:';

/** The prefix of a page entry's id, before the nav entry's id. */
export const PAGE_ENTRY = 'page:';

/** Reads the prop `palette` of a page, as the server shared it. */
export function paletteOf(prop: unknown): PaletteState {
  const checked = validatePalettePropV1(prop);

  if (!checked.valid) {
    return { status: 'unreadable' };
  }

  return stateOf(checked.value);
}

function stateOf(prop: PalettePropV1): PaletteState {
  if (prop.result !== null) {
    const list = validateActionListV1(prop.result);

    return list.valid ? { status: 'ready', list: list.value } : { status: 'unreadable' };
  }

  if (prop.rejection !== null) {
    const problem = validateProblemV1(prop.rejection);

    return problem.valid
      ? { status: 'rejected', problem: problem.value }
      : { status: 'unreadable' };
  }

  return { status: 'unreadable' };
}

/**
 * The command entries: every command of the list, labelled by the panel's catalogue when it has a
 * text for the action (`panel.action.<name>.title` and `.description`) and by the title and
 * description of the command's JSON Schema otherwise, found by its name too, each opening the
 * command's form page below the Inertia profile's address, `<commands>/<name>/v<version>`.
 * A query of the list is not an entry: the palette runs commands and opens pages.
 */
export function paletteCommands(
  list: ActionListV1,
  commands: string,
  locale: Locale,
): PaletteItem[] {
  return list.actions
    .filter((action) => action.kind === 'command')
    .map((action) => ({
      id: `${COMMAND_ENTRY}${action.name}@${String(action.version)}`,
      label: lookup(locale, `panel.action.${action.name}.title`) ?? action.title,
      description: lookup(locale, `panel.action.${action.name}.description`) ?? action.description,
      keywords: [action.name],
      url: `${commands}/${action.name}/v${String(action.version)}`,
    }));
}

/**
 * The page entries: every navigation entry of the list the shell's navigation knows, with the
 * text and the address the navigation gives it, in the list's order; an entry the navigation does
 * not have is left out, because there is nothing to open.
 */
export function palettePages(list: ActionListV1, nav: readonly NavEntry[]): PaletteItem[] {
  const pages: PaletteItem[] = [];

  for (const entry of list.navigation) {
    const known = nav.find((candidate) => candidate.id === entry.id);

    if (known === undefined) {
      continue;
    }

    pages.push({
      id: `${PAGE_ENTRY}${entry.id}`,
      label: known.label,
      keywords: [entry.page],
      url: known.url,
    });
  }

  return pages;
}

/** The address an entry's id opens, or undefined for an id the palette did not give. */
export function urlOf(items: readonly PaletteItem[], id: string): string | undefined {
  return items.find((item) => item.id === id)?.url;
}
