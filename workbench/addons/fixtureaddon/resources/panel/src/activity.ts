// The fixture addon's observer of every command that completed (section 3.9 of the panel
// extension architecture) and what it records: the last command the viewer ran, kept in the
// browser's session storage under KEY, so the who-am-I page's section RecentActivity can show it.
// An observer is read-only and gets no host, so it writes nothing but this record; a record the
// storage holds that is not of this form is read as none.

import type { Observer } from '@cboxdk/cms-panel/extend';
import type { CommandCompletedV1 } from '@cboxdk/cms-panel/experimental';

/** The key of the record in the session storage. */
export const KEY = 'fixtureaddon.activity';

/** What the observer records: the event, as the host gave it. */
export interface ActivityRecord {
  readonly command: string;
  readonly version: number;
  readonly outcome: CommandCompletedV1['outcome'];
  readonly changeset: string | null;
}

const OUTCOMES: readonly string[] = ['rejected', 'committed', 'committed_wait_timeout', 'dry_run'];

export const activity: Observer<CommandCompletedV1> = (event) => {
  const record: ActivityRecord = {
    command: event.command,
    version: event.version,
    outcome: event.outcome,
    changeset: event.changeset,
  };

  window.sessionStorage.setItem(KEY, JSON.stringify(record));
};

/** The record the storage holds, or null when it holds none or one that is not of this form. */
export function readActivity(): ActivityRecord | null {
  const text = window.sessionStorage.getItem(KEY);

  if (text === null) {
    return null;
  }

  let parsed: unknown;

  try {
    parsed = JSON.parse(text);
  } catch {
    return null;
  }

  if (typeof parsed !== 'object' || parsed === null) {
    return null;
  }

  const record = parsed as Readonly<Record<string, unknown>>;

  if (
    typeof record.command !== 'string' ||
    typeof record.version !== 'number' ||
    typeof record.outcome !== 'string' ||
    !OUTCOMES.includes(record.outcome) ||
    (record.changeset !== null && typeof record.changeset !== 'string')
  ) {
    return null;
  }

  return {
    command: record.command,
    version: record.version,
    outcome: record.outcome as CommandCompletedV1['outcome'],
    changeset: record.changeset,
  };
}
