// @vitest-environment jsdom

import { screen, waitFor, within } from '@testing-library/react';
import { describe, expect, test, vi } from 'vitest';

import { Combobox } from '../../src/components/Combobox';
import { renderKit } from './harness';

const OPTIONS = [
  { id: 'news', label: 'News' },
  { id: 'sport', label: 'Sport' },
  { id: 'cafe', label: 'Café' },
];

/** The texts the test renders, as a caller's translations would give them. */
const TEXT = { label: 'Section', empty: 'No section matches.' };

function renderCombobox(onChange: (value: string | null) => void = () => undefined) {
  return renderKit(
    <Combobox label={TEXT.label} options={OPTIONS} emptyLabel={TEXT.empty} onChange={onChange} />,
  );
}

describe('the keyboard contract of Combobox', () => {
  test('typing opens the list with the options that match, ignoring case and accents', async () => {
    const { user } = renderCombobox();
    const input = screen.getByRole('combobox', { name: 'Section' });

    await user.click(input);
    await user.keyboard('CAFE');

    const list = await screen.findByRole('listbox');
    expect(
      within(list)
        .getAllByRole('option')
        .map((option) => option.textContent),
    ).toEqual(['Café']);
    expect(input.getAttribute('aria-expanded')).toBe('true');
  });

  test('Down moves through the options while focus stays in the input', async () => {
    const { user } = renderCombobox();
    const input = screen.getByRole('combobox');

    await user.click(input);
    await user.keyboard('{ArrowDown}');
    const list = await screen.findByRole('listbox');
    await user.keyboard('{ArrowDown}');

    expect(document.activeElement).toBe(input);
    const activeId = input.getAttribute('aria-activedescendant');
    expect(activeId).not.toBeNull();
    expect(
      within(list)
        .getAllByRole('option')
        .some((option) => option.id === activeId),
    ).toBe(true);
  });

  test('Enter chooses the option in focus and closes the list', async () => {
    const onChange = vi.fn();
    const { user } = renderCombobox(onChange);
    const input = screen.getByRole('combobox');

    await user.click(input);
    await user.keyboard('sp');
    await screen.findByRole('listbox');
    await user.keyboard('{ArrowDown}{Enter}');

    expect(onChange).toHaveBeenLastCalledWith('sport');
    expect((input as HTMLInputElement).value).toBe('Sport');
    await waitFor(() => {
      expect(screen.queryByRole('listbox')).toBeNull();
    });
  });

  test('Escape closes the list', async () => {
    const { user } = renderCombobox();

    await user.click(screen.getByRole('combobox'));
    await user.keyboard('n');
    await screen.findByRole('listbox');
    await user.keyboard('{Escape}');

    await waitFor(() => {
      expect(screen.queryByRole('listbox')).toBeNull();
    });
  });

  test('says when no option matches', async () => {
    const { user } = renderCombobox();

    await user.click(screen.getByRole('combobox'));
    await user.keyboard('zz');

    expect(await screen.findByText('No section matches.')).not.toBeNull();
  });
});
