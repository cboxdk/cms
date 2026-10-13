// The entry editor's local recovery (PRD 5.4, 13.4): the edits a person has not saved, kept in this
// browser per person, type, entry and version, so a reload, a crash or a closed tab does not lose
// them, and offered back only on the editor of the same entry at the same version, where they
// still apply to what the person edited. A record of the entry at another version is of content
// that has changed since, and is removed instead of offered. Records expire after RECOVERY_MAX_AGE
// and are removed when the person signs out, so edits do not outlive the session in the browser
// longer than that.
//
// The browser may refuse storage at every step: reaching it (a private window, blocked site
// data), reading, writing (the quota) and removing. Each step is wrapped, and a refusal means the
// editor works as it does without recovery: nothing is kept, nothing is offered, and nothing
// throws. A record that is not one of ours, or whose content is no longer readable, is ignored.

import type { JsonObject } from '@cboxdk/cms-ui-kit';

/** The prefix of every key of the editor's recovery records. */
export const RECOVERY_PREFIX = 'cms.panel.editor.recovery.v1:';

/** How long a record is kept, in milliseconds: seven days. */
export const RECOVERY_MAX_AGE = 7 * 24 * 60 * 60 * 1000;

/** The part of the browser's Storage the store uses. */
export interface RecoveryStorage {
  readonly length: number;
  key(index: number): string | null;
  getItem(key: string): string | null;
  setItem(key: string, value: string): void;
  removeItem(key: string): void;
}

/** Whose edits of which entry a record holds. */
export interface RecoveryKey {
  /** The person, their actor id, or null when the page does not know them. */
  readonly viewer: string | null;
  /** The type, `<owner>:<handle>`. */
  readonly type: string;
  /** The entry, or null for a new entry of the type. */
  readonly entry: string | null;
  /** The version of the entry's head the edits were made on, 0 for a new entry. */
  readonly version: number;
}

/** The unsaved edits a record holds. */
export interface Recovered {
  /** The fields as the person left them. */
  readonly fields: JsonObject;
  /** When they were kept, in milliseconds since the epoch. */
  readonly keptAt: number;
}

/** The storage of this browser for recovery, or null when the browser refuses it. */
export function browserStorage(): RecoveryStorage | null {
  try {
    const storage = window.localStorage;
    // Some browsers hand out the storage and refuse the first use of it.
    storage.getItem(RECOVERY_PREFIX);

    return storage;
  } catch {
    return null;
  }
}

/** The key of the person's, type's and entry's records, without the version. */
function entryPrefix(key: Omit<RecoveryKey, 'version'>): string {
  return `${RECOVERY_PREFIX}${key.viewer ?? '-'}:${key.type}:${key.entry ?? 'new'}:`;
}

/** The storage key of a record. */
export function recoveryKey(key: RecoveryKey): string {
  return `${entryPrefix(key)}${String(key.version)}`;
}

function isObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** The record a stored value is, or null when it is not one of ours. */
function parse(value: string | null): Recovered | null {
  if (value === null) {
    return null;
  }

  try {
    const record: unknown = JSON.parse(value);

    if (!isObject(record) || !isObject(record['fields']) || typeof record['kept_at'] !== 'number') {
      return null;
    }

    return { fields: record['fields'] as JsonObject, keptAt: record['kept_at'] };
  } catch {
    return null;
  }
}

/** Every key of the editor's records in the storage; none when the storage refuses to list them. */
function keys(storage: RecoveryStorage): string[] {
  const found: string[] = [];

  try {
    for (let index = 0; index < storage.length; index += 1) {
      const key = storage.key(index);

      if (key?.startsWith(RECOVERY_PREFIX) === true) {
        found.push(key);
      }
    }
  } catch {
    return found;
  }

  return found;
}

function remove(storage: RecoveryStorage, key: string): void {
  try {
    storage.removeItem(key);
  } catch {
    // A storage that refuses to remove a record keeps it until it expires.
  }
}

/**
 * The recovery records of the editor in one storage, or in none: with a null storage, as a browser
 * that refuses storage gives, it keeps nothing and finds nothing.
 */
export class RecoveryStore {
  constructor(
    private readonly storage: RecoveryStorage | null,
    private readonly now: () => number = Date.now,
  ) {}

  /** Keeps the edits of the entry at the version; false when the browser refused to keep them. */
  keep(key: RecoveryKey, fields: JsonObject): boolean {
    if (this.storage === null) {
      return false;
    }

    try {
      this.storage.setItem(recoveryKey(key), JSON.stringify({ fields, kept_at: this.now() }));

      return true;
    } catch {
      return false;
    }
  }

  /**
   * The edits kept for the entry at exactly the version, or null; the entry's records at other
   * versions, and every expired record, are removed as they are found.
   */
  find(key: RecoveryKey): Recovered | null {
    if (this.storage === null) {
      return null;
    }

    this.prune();
    const wanted = recoveryKey(key);
    const prefix = entryPrefix(key);
    let found: Recovered | null = null;

    for (const stored of keys(this.storage)) {
      if (stored === wanted) {
        found = this.read(stored);
      } else if (stored.startsWith(prefix)) {
        remove(this.storage, stored);
      }
    }

    return found;
  }

  /** Removes the edits of the entry at every version, as a save or a discard does. */
  forget(key: Omit<RecoveryKey, 'version'>): void {
    if (this.storage === null) {
      return;
    }

    const prefix = entryPrefix(key);

    for (const stored of keys(this.storage)) {
      if (stored.startsWith(prefix)) {
        remove(this.storage, stored);
      }
    }
  }

  /** Removes every record of the editor, as signing out does. */
  clear(): void {
    if (this.storage === null) {
      return;
    }

    for (const stored of keys(this.storage)) {
      remove(this.storage, stored);
    }
  }

  /** Removes every record that has expired or is not one of ours. */
  prune(): void {
    if (this.storage === null) {
      return;
    }

    const oldest = this.now() - RECOVERY_MAX_AGE;

    for (const stored of keys(this.storage)) {
      const record = this.read(stored);

      if (record === null || record.keptAt < oldest) {
        remove(this.storage, stored);
      }
    }
  }

  private read(key: string): Recovered | null {
    if (this.storage === null) {
      return null;
    }

    try {
      return parse(this.storage.getItem(key));
    } catch {
      return null;
    }
  }
}
