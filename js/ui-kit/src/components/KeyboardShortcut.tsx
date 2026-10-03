import { useMemo } from 'react';

import './keyboard-shortcut.css';

/**
 * A key of a shortcut: Mod is Command on Apple's systems and Ctrl elsewhere; any other key is
 * written as given, such as K or Enter.
 *
 * @experimental
 */
export type ShortcutKey = 'Mod' | 'Shift' | 'Alt' | (string & {});

/**
 * The props of KeyboardShortcut.
 *
 * @experimental
 */
export interface KeyboardShortcutProps {
  /** The keys pressed together, the modifiers first, such as ['Mod', 'K']. */
  readonly keys: readonly ShortcutKey[];
}

/** Whether the page runs on one of Apple's systems, whose modifier is Command. */
function onApple(): boolean {
  return typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform);
}

const APPLE: Readonly<Record<string, string>> = { Mod: '⌘', Shift: '⇧', Alt: '⌥' };
const OTHER: Readonly<Record<string, string>> = { Mod: 'Ctrl', Shift: 'Shift', Alt: 'Alt' };

/**
 * The keys of a keyboard shortcut, such as the one that opens the command palette, each in a kbd
 * element, written for the reader's system: ⌘ K on a Mac and Ctrl K elsewhere.
 *
 * @experimental
 */
export function KeyboardShortcut({ keys }: KeyboardShortcutProps) {
  const names = useMemo(() => (onApple() ? APPLE : OTHER), []);

  return (
    <kbd className="cms-keyboard-shortcut">
      {keys.map((key, index) => (
        <kbd key={`${String(index)}-${key}`} className="cms-keyboard-shortcut__key">
          {names[key] ?? key}
        </kbd>
      ))}
    </kbd>
  );
}
