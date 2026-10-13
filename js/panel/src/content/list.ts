// What the content list shows of an entry (PRD 5.4, 13.4): entry.list gives each entry's system
// columns and, as its fields, the title field its type's editor descriptor names with the value
// of the shared head's draft, so a row shows the title, the type's label, a state and the last
// change. The list's address is its state (cbox-ui PATTERNS: URL is state): the type is the path's
// last segment, the release state `?state=<state>` and the page `?after=<id>`.

import type { TableDensity } from '@cboxdk/cms-ui-kit';

import type { TypeEditorV1 } from '../generated/protocol/TypeEditorV1';
import type {
  EntryLifecycle,
  ListedEntryV1,
  ReleaseState,
} from '../generated/protocol/EntryListV1';
import type { TranslationKey } from '../i18n/translations';
import type { PaletteState } from '../shell/palette';

/** The command that creates an entry, which the empty list and the page's button open the form of. */
export const CREATE = 'entry.create';

/** Where this browser keeps the reader's density of the list. */
export const DENSITY_KEY = 'cbox-cms.content.density';

/** The query parameter of the release state the list is filtered by. */
export const STATE = 'state';

/** The release states the list may be filtered by, in the order the filter offers them. */
export const STATES: readonly ReleaseState[] = ['unreleased', 'released', 'withdrawn'];

/** Whether a text is a release state. */
export function isReleaseState(value: string | null): value is ReleaseState {
  return STATES.some((state) => state === value);
}

/** The address of the list of the type (null for every type) filtered by the state (null for every state), from its first page. */
export function filteredUrl(
  content: string,
  type: string | null,
  state: ReleaseState | null,
): string {
  const path = type === null ? content : `${content}/${type}`;

  return state === null ? path : `${path}?${STATE}=${encodeURIComponent(state)}`;
}

/** The tone and text of an entry's state, as a pill shows it. */
export interface EntryStatus {
  readonly tone: 'neutral' | 'info' | 'success' | 'warning' | 'danger';
  readonly key: TranslationKey;
}

/** What the lifecycle says when it is not active, which wins over the release state. */
const LIFECYCLES: Readonly<Record<Exclude<EntryLifecycle, 'active'>, EntryStatus>> = {
  archived: { tone: 'neutral', key: 'panel.content.lifecycle.archived' },
  merged: { tone: 'neutral', key: 'panel.content.lifecycle.merged' },
  tombstoned: { tone: 'danger', key: 'panel.content.lifecycle.tombstoned' },
  purged: { tone: 'danger', key: 'panel.content.lifecycle.purged' },
};

/** The text of each release state as the filter offers it and a pill shows it. */
export const STATE_STATUS: Readonly<Record<ReleaseState, EntryStatus>> = {
  unreleased: { tone: 'neutral', key: 'panel.content.state.unreleased' },
  released: { tone: 'success', key: 'panel.content.state.released' },
  withdrawn: { tone: 'warning', key: 'panel.content.state.withdrawn' },
};

/** The pill of an entry: its lifecycle when it is not active, else the shared variant's release state. */
export function statusOf(entry: ListedEntryV1): EntryStatus {
  if (entry.lifecycle !== 'active') {
    return LIFECYCLES[entry.lifecycle];
  }

  return entry.state === null
    ? { tone: 'neutral', key: 'panel.content.state.none' }
    : STATE_STATUS[entry.state];
}

/**
 * The entry's title: the value of the title field its type's descriptor names, when the fields
 * entry.list gave hold it as a text, or null for an entry without one.
 */
export function titleOf(entry: ListedEntryV1, type: TypeEditorV1 | undefined): string | null {
  const field = type?.title_field ?? null;

  if (field === null) {
    return null;
  }

  const value = entry.fields[field];

  return typeof value === 'string' && value.trim() !== '' ? value : null;
}

/**
 * The command the page's create action opens the form of, `entry.create@<version>` at the highest
 * version the person may run, or null when the palette does not list it, so the server would refuse.
 */
export function createAction(palette: PaletteState): string | null {
  if (palette.status !== 'ready') {
    return null;
  }

  const versions = palette.list.actions
    .filter((action) => action.kind === 'command' && action.name === CREATE)
    .map((action) => action.version);

  return versions.length === 0 ? null : `${CREATE}@${String(Math.max(...versions))}`;
}

/**
 * The reader's density of the list, as this browser kept it, comfortable by default and when the
 * browser keeps nothing, such as in a private window.
 */
export function densityOf(): TableDensity {
  try {
    return window.localStorage.getItem(DENSITY_KEY) === 'compact' ? 'compact' : 'comfortable';
  } catch {
    return 'comfortable';
  }
}

/** Keeps the reader's density in this browser; a browser that keeps nothing forgets it. */
export function keepDensity(density: TableDensity): void {
  try {
    window.localStorage.setItem(DENSITY_KEY, density);
  } catch {
    // A browser that refuses storage keeps the density for this page only.
  }
}
