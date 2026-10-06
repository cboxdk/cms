// @vitest-environment jsdom

import { screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, test, vi } from 'vitest';

import { Button } from '../../src/components/Button';
import { CommandPalette } from '../../src/components/CommandPalette';
import { renderKit } from './harness';

const SECTIONS = [
  {
    id: 'pages',
    title: 'Pages',
    items: [
      { id: 'page:me', label: 'Who am I' },
      { id: 'page:roles', label: 'Roles' },
    ],
  },
  {
    id: 'commands',
    title: 'Commands',
    items: [{ id: 'command:grant.assign', label: 'Assign a grant', keywords: ['grant.assign'] }],
  },
];

/** The texts the test renders, as a caller's translations would give them. */
const TEXT = {
  elsewhere: 'Elsewhere',
  label: 'Command palette',
  search: 'Find a page or a command',
  empty: 'Nothing matches.',
};

function Harness({ onAction }: { readonly onAction: (id: string) => void }) {
  const [open, setOpen] = useState(false);

  return (
    <>
      <Button>{TEXT.elsewhere}</Button>
      <CommandPalette
        label={TEXT.label}
        searchLabel={TEXT.search}
        sections={SECTIONS}
        open={open}
        onOpenChange={setOpen}
        onAction={onAction}
        emptyLabel={TEXT.empty}
      />
    </>
  );
}

describe('the keyboard contract of CommandPalette', () => {
  test('Ctrl+K opens it with focus in its search field, and Command+K too', async () => {
    const { user } = renderKit(<Harness onAction={() => undefined} />);

    await user.keyboard('{Control>}k{/Control}');
    await screen.findByRole('dialog', { name: 'Command palette' });
    await waitFor(() => {
      expect(document.activeElement).toBe(
        screen.getByRole('searchbox', { name: 'Find a page or a command' }),
      );
    });

    await user.keyboard('{Escape}');
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).toBeNull();
    });
    await user.keyboard('{Meta>}k{/Meta}');
    expect(await screen.findByRole('dialog')).not.toBeNull();
  });

  test('is a combobox: the search field controls a listbox of options and names the one in focus', async () => {
    const { user } = renderKit(<Harness onAction={() => undefined} />);

    await user.keyboard('{Control>}k{/Control}');
    await screen.findByRole('dialog');
    const field = screen.getByRole('searchbox', { name: 'Find a page or a command' });
    const list = screen.getByRole('listbox', { name: 'Command palette' });

    expect(field.getAttribute('aria-controls')).toBe(list.id);
    expect(field.getAttribute('aria-autocomplete')).toBe('list');
    expect(screen.getAllByRole('option')).toHaveLength(3);

    await user.keyboard('{ArrowDown}');
    await waitFor(() => {
      expect(field.getAttribute('aria-activedescendant')).toBe(
        screen.getByRole('option', { name: 'Who am I' }).id,
      );
    });
  });

  test('typing filters the entries, by their keywords too', async () => {
    const { user } = renderKit(<Harness onAction={() => undefined} />);

    await user.keyboard('{Control>}k{/Control}');
    await screen.findByRole('dialog');
    await user.keyboard('grant.as');

    await waitFor(() => {
      expect(screen.getAllByRole('option').map((item) => item.textContent)).toEqual([
        'Assign a grant',
      ]);
    });
  });

  test('Down and Enter run the entry in focus and close the palette', async () => {
    const onAction = vi.fn();
    const { user } = renderKit(<Harness onAction={onAction} />);

    await user.keyboard('{Control>}k{/Control}');
    await screen.findByRole('dialog');
    await user.keyboard('ro');
    await user.keyboard('{ArrowDown}{Enter}');

    expect(onAction).toHaveBeenCalledExactlyOnceWith('page:roles');
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).toBeNull();
    });
  });

  test('Escape closes it and returns focus to where it was', async () => {
    const { user } = renderKit(<Harness onAction={() => undefined} />);
    const elsewhere = screen.getByRole('button', { name: 'Elsewhere' });

    await user.tab();
    expect(document.activeElement).toBe(elsewhere);
    await user.keyboard('{Control>}k{/Control}');
    await screen.findByRole('dialog');
    await user.keyboard('{Escape}');

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).toBeNull();
    });
    await waitFor(() => {
      expect(document.activeElement).toBe(elsewhere);
    });
  });

  test('says when nothing matches', async () => {
    const { user } = renderKit(<Harness onAction={() => undefined} />);

    await user.keyboard('{Control>}k{/Control}');
    await screen.findByRole('dialog');
    await user.keyboard('zzzz');

    expect(await screen.findByText('Nothing matches.')).not.toBeNull();
  });
});
