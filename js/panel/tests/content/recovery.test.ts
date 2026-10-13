// @vitest-environment jsdom

// The entry editor's local recovery (PRD 5.4, 13.4): edits kept per person, type, entry and
// version, found only for the same entry at the same version, the entry's records at other
// versions removed as they are found, expired and foreign records pruned, everything removed on
// sign-out; and a browser that refuses storage at any step, reaching it, listing, reading,
// writing or removing, which keeps nothing and finds nothing, and never throws.

import { afterEach, describe, expect, test, vi } from 'vitest';

import {
  browserStorage,
  RECOVERY_MAX_AGE,
  RECOVERY_PREFIX,
  recoveryKey,
  RecoveryStore,
  type RecoveryKey,
  type RecoveryStorage,
} from '../../src/content/recovery';

const ENTRY = '0192a0c0-0000-7000-8000-0000000009e1';

const OTHER = '0192a0c0-0000-7000-8000-0000000009e2';

const VIEWER = '0192a0c0-0000-7000-8000-0000000009a1';

const KEY: RecoveryKey = { viewer: VIEWER, type: 'shop:product', entry: ENTRY, version: 4 };

/** A Storage in memory, as the browser's localStorage behaves. */
class MemoryStorage implements RecoveryStorage {
  readonly items = new Map<string, string>();

  get length(): number {
    return this.items.size;
  }

  key(index: number): string | null {
    return [...this.items.keys()][index] ?? null;
  }

  getItem(key: string): string | null {
    return this.items.get(key) ?? null;
  }

  setItem(key: string, value: string): void {
    this.items.set(key, value);
  }

  removeItem(key: string): void {
    this.items.delete(key);
  }
}

/** A storage that refuses every step, as a browser with blocked site data does. */
class RefusingStorage implements RecoveryStorage {
  get length(): number {
    throw new DOMException('The operation is insecure.', 'SecurityError');
  }

  key(): string | null {
    throw new DOMException('The operation is insecure.', 'SecurityError');
  }

  getItem(): string | null {
    throw new DOMException('The operation is insecure.', 'SecurityError');
  }

  setItem(): void {
    throw new DOMException('The quota has been exceeded.', 'QuotaExceededError');
  }

  removeItem(): void {
    throw new DOMException('The operation is insecure.', 'SecurityError');
  }
}

afterEach(() => {
  vi.restoreAllMocks();
  window.localStorage.clear();
});

describe('a storage that keeps', () => {
  test('finds the edits of the same person, type, entry and version, and nothing else', () => {
    const storage = new MemoryStorage();
    const store = new RecoveryStore(storage, () => 1_000);

    expect(store.keep(KEY, { name: 'Oak table, long' })).toBe(true);
    expect(store.find(KEY)).toEqual({ fields: { name: 'Oak table, long' }, keptAt: 1_000 });
    expect(store.find({ ...KEY, entry: OTHER })).toBeNull();
    expect(store.find({ ...KEY, viewer: null })).toBeNull();
    expect(store.find({ ...KEY, type: 'shop:order' })).toBeNull();
    expect(store.find(KEY)).not.toBeNull();
  });

  test('removes the edits of the entry at another version instead of offering them', () => {
    const storage = new MemoryStorage();
    const store = new RecoveryStore(storage, () => 1_000);
    store.keep(KEY, { name: 'Made on version 4' });
    store.keep({ ...KEY, entry: OTHER }, { name: 'Another entry' });

    expect(store.find({ ...KEY, version: 5 })).toBeNull();
    expect(storage.items.has(recoveryKey(KEY))).toBe(false);
    expect(storage.items.has(recoveryKey({ ...KEY, entry: OTHER }))).toBe(true);
  });

  test('keeps a new entry under its type, apart from the entries of the type', () => {
    const store = new RecoveryStore(new MemoryStorage(), () => 1_000);
    const fresh = { ...KEY, entry: null, version: 0 };
    store.keep(fresh, { name: 'Not saved yet' });

    expect(recoveryKey(fresh)).toBe(`${RECOVERY_PREFIX}${VIEWER}:shop:product:new:0`);
    expect(store.find(fresh)?.fields).toEqual({ name: 'Not saved yet' });
    expect(store.find(KEY)).toBeNull();
  });

  test('forgets the entry at every version, and clears every record of the editor and only those', () => {
    const storage = new MemoryStorage();
    const store = new RecoveryStore(storage, () => 1_000);
    storage.setItem('cms.panel.content.density', 'compact');
    store.keep(KEY, { name: 'A' });
    store.keep({ ...KEY, version: 5 }, { name: 'B' });
    store.keep({ ...KEY, entry: OTHER }, { name: 'C' });

    store.forget(KEY);

    expect([...storage.items.keys()].sort()).toEqual(
      ['cms.panel.content.density', recoveryKey({ ...KEY, entry: OTHER })].sort(),
    );

    store.clear();

    expect([...storage.items.keys()]).toEqual(['cms.panel.content.density']);
  });

  test('prunes records older than the maximum age and values that are not records', () => {
    let now = 1_000;
    const storage = new MemoryStorage();
    const store = new RecoveryStore(storage, () => now);
    store.keep({ ...KEY, entry: OTHER }, { name: 'Old' });
    now += RECOVERY_MAX_AGE;
    store.keep(KEY, { name: 'Recent' });
    storage.setItem(`${RECOVERY_PREFIX}garbage`, '{not json');
    storage.setItem(`${RECOVERY_PREFIX}wrong`, JSON.stringify({ fields: [], kept_at: 'today' }));
    now += 1;

    store.prune();

    expect([...storage.items.keys()]).toEqual([recoveryKey(KEY)]);
  });

  test('finds nothing in a record of the entry whose value is no record', () => {
    const storage = new MemoryStorage();
    storage.setItem(recoveryKey(KEY), JSON.stringify({ fields: 'Oak', kept_at: 1 }));

    expect(new RecoveryStore(storage, () => 1).find(KEY)).toBeNull();
  });
});

describe('a storage that refuses', () => {
  test('keeps nothing and finds nothing when every step throws, and never throws itself', () => {
    const store = new RecoveryStore(new RefusingStorage());

    expect(store.keep(KEY, { name: 'Oak' })).toBe(false);
    expect(store.find(KEY)).toBeNull();
    expect(() => {
      store.forget(KEY);
      store.clear();
      store.prune();
    }).not.toThrow();
  });

  test('keeps nothing when writing throws, as a full quota does, and still reads what is there', () => {
    const storage = new MemoryStorage();
    new RecoveryStore(storage, () => 1).keep(KEY, { name: 'Kept before' });
    vi.spyOn(storage, 'setItem').mockImplementation(() => {
      throw new DOMException('The quota has been exceeded.', 'QuotaExceededError');
    });
    const store = new RecoveryStore(storage, () => 2);

    expect(store.keep(KEY, { name: 'Not kept' })).toBe(false);
    expect(store.find(KEY)?.fields).toEqual({ name: 'Kept before' });
  });

  test('finds nothing when reading throws, and leaves a record it cannot remove', () => {
    const storage = new MemoryStorage();
    new RecoveryStore(storage, () => 1).keep(KEY, { name: 'Oak' });
    vi.spyOn(storage, 'getItem').mockImplementation(() => {
      throw new DOMException('The operation is insecure.', 'SecurityError');
    });
    vi.spyOn(storage, 'removeItem').mockImplementation(() => {
      throw new DOMException('The operation is insecure.', 'SecurityError');
    });

    expect(new RecoveryStore(storage, () => 2).find(KEY)).toBeNull();
    expect(storage.items.size).toBe(1);
  });

  test('has no storage when the browser refuses to hand it out or to use it', () => {
    const descriptor = Object.getOwnPropertyDescriptor(window, 'localStorage');
    Object.defineProperty(window, 'localStorage', {
      configurable: true,
      get: () => {
        throw new DOMException('The operation is insecure.', 'SecurityError');
      },
    });

    try {
      expect(browserStorage()).toBeNull();
      expect(new RecoveryStore(browserStorage()).keep(KEY, { name: 'Oak' })).toBe(false);
      expect(new RecoveryStore(null).find(KEY)).toBeNull();
    } finally {
      if (descriptor !== undefined) {
        Object.defineProperty(window, 'localStorage', descriptor);
      }
    }

    expect(browserStorage()).toBe(window.localStorage);
  });
});
