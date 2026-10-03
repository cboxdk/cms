// What the keyboard tests of the kit share (GUARDRAILS 8: every component has a keyboard
// contract). Each test file renders kit components in jsdom with Testing Library and drives them
// with user-event as a reader at a keyboard would: it asks for jsdom with a `@vitest-environment
// jsdom` comment, renders through renderKit, and reads the page through Testing Library's queries.
// The story tests of gate 7 hold the same contracts in Chromium.

import { cleanup, render, type RenderResult } from '@testing-library/react';
import userEvent, { type UserEvent } from '@testing-library/user-event';
import type { ReactNode } from 'react';
import { afterEach } from 'vitest';

import { KitI18nProvider } from '../../src/i18n/KitI18nProvider';

// React asserts its test environment for act(); Testing Library sets the flag for React 18 and 19.
Reflect.set(globalThis, 'IS_REACT_ACT_ENVIRONMENT', true);

afterEach(() => {
  cleanup();
});

/** Renders the node in the kit's English texts and returns the screen and a keyboard user. */
export function renderKit(node: ReactNode): RenderResult & { readonly user: UserEvent } {
  const user = userEvent.setup();

  return { ...render(<KitI18nProvider locale="en">{node}</KitI18nProvider>), user };
}

/** The element that has focus; fails the test when nothing has. */
export function active(): Element {
  const element = document.activeElement;

  if (element === null || element === document.body) {
    throw new Error('nothing has focus');
  }

  return element;
}
