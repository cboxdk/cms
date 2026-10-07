// @vitest-environment jsdom

// The popover a list or a menu of the kit opens in keeps hanging from its trigger while it is open
// (js/ui-kit/src/components/internal/Popover.tsx). React Aria places it when it opens, when the
// window resizes and when the popover or its trigger changes size, not when the trigger moves; a
// choice in a MultiSelect moves its button when the page around it changes, as the tags below the
// button do in a dialog centred on the screen. jsdom lays nothing out, so the test gives the
// button the rectangles a browser would.

import { screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, test } from 'vitest';

import { MultiSelect } from '../../src/components/MultiSelect';
import { renderKit } from '../keyboard/harness';

const OPTIONS = [
  { id: 'en', label: 'English' },
  { id: 'da', label: 'Danish' },
  { id: 'de', label: 'German' },
];

/** The texts the test renders, as a caller's translations would give them. */
const TEXT = { label: 'Locales', none: 'Every locale' };

/** A MultiSelect that holds its own value, as a form does. */
function Locales() {
  const [value, setValue] = useState<string[]>([]);

  return (
    <MultiSelect
      label={TEXT.label}
      options={OPTIONS}
      value={value}
      onChange={setValue}
      noneLabel={TEXT.none}
    />
  );
}

/** Lays the element out at the rectangle, as a browser would, from now on. */
function placeAt(element: Element, top: number): void {
  element.getBoundingClientRect = () => new DOMRect(100, top, 300, 40);
}

/** The popover the list opened in. */
function popover(): HTMLElement {
  const list = screen.getByRole('listbox');
  const surface = list.closest('.cms-popover');

  if (!(surface instanceof HTMLElement)) {
    throw new Error('the list is not in a popover');
  }

  return surface;
}

describe('the popover of the kit', () => {
  test('moves with its trigger when a choice moves the trigger while the list is open', async () => {
    const { user } = renderKit(<Locales />);
    const button = screen.getByRole('button', { name: /Locales/ });
    placeAt(button, 200);

    await user.click(button);
    await screen.findByRole('listbox');
    const opened = popover().style.top;

    expect(opened).not.toBe('');

    // The choice renders the tag of the option below the button; in a dialog centred on the
    // screen, the dialog shrinks or grows by it and the button moves.
    placeAt(button, 260);
    await user.keyboard(' ');

    await screen.findByText('English', { selector: '.cms-multi-select__chosen *' });
    await waitFor(() => {
      expect(Number.parseFloat(popover().style.top) - Number.parseFloat(opened)).toBe(60);
    });
  });

  test('stays where it is when a render leaves the trigger where it was', async () => {
    const { user } = renderKit(<Locales />);
    const button = screen.getByRole('button', { name: /Locales/ });
    placeAt(button, 200);

    await user.click(button);
    await screen.findByRole('listbox');
    const opened = popover().style.top;

    await user.keyboard(' ');
    await screen.findByText('English', { selector: '.cms-multi-select__chosen *' });

    expect(popover().style.top).toBe(opened);
  });
});
