// @vitest-environment jsdom

import { screen, waitFor } from '@testing-library/react';
import { describe, expect, test, vi } from 'vitest';

import { Menu } from '../../src/components/Menu';
import { active, renderKit } from './harness';

const ITEMS = [
  { id: 'edit', label: 'Edit' },
  { id: 'duplicate', label: 'Duplicate', disabled: true },
  { id: 'revoke', label: 'Revoke', tone: 'danger' as const },
];

function renderMenu(onAction: (id: string) => void = () => undefined) {
  return renderKit(
    <Menu
      trigger={{ label: 'Actions for the grant', icon: 'more' }}
      items={ITEMS}
      onAction={onAction}
    />,
  );
}

describe('the keyboard contract of Menu', () => {
  test('the trigger is named by its label and says that it opens a menu', () => {
    renderMenu();
    const trigger = screen.getByRole('button', { name: 'Actions for the grant' });

    expect(trigger.getAttribute('aria-haspopup')).toBe('true');
    expect(trigger.getAttribute('aria-expanded')).toBe('false');
  });

  test('Enter opens it on the first item', async () => {
    const { user } = renderMenu();

    await user.tab();
    await user.keyboard('{Enter}');

    await screen.findByRole('menu');
    await waitFor(() => {
      expect(active().textContent).toBe('Edit');
    });
  });

  test('Up opens it on the last item', async () => {
    const { user } = renderMenu();

    await user.tab();
    await user.keyboard('{ArrowUp}');

    await screen.findByRole('menu');
    await waitFor(() => {
      expect(active().textContent).toBe('Revoke');
    });
  });

  test('Down skips a disabled item, and Enter takes the action and closes the menu', async () => {
    const onAction = vi.fn();
    const { user } = renderMenu(onAction);

    await user.tab();
    await user.keyboard('{Enter}');
    await screen.findByRole('menu');
    await user.keyboard('{ArrowDown}');
    expect(active().textContent).toBe('Revoke');
    await user.keyboard('{Enter}');

    expect(onAction).toHaveBeenCalledExactlyOnceWith('revoke');
    await waitFor(() => {
      expect(screen.queryByRole('menu')).toBeNull();
    });
  });

  test('typing moves to the item that starts with what is typed', async () => {
    const { user } = renderMenu();

    await user.tab();
    await user.keyboard('{Enter}');
    await screen.findByRole('menu');
    await user.keyboard('r');

    expect(active().textContent).toBe('Revoke');
  });

  test('Escape closes it and returns focus to the trigger', async () => {
    const { user } = renderMenu();
    const trigger = screen.getByRole('button', { name: 'Actions for the grant' });

    await user.tab();
    await user.keyboard('{Enter}');
    await screen.findByRole('menu');
    await user.keyboard('{Escape}');

    await waitFor(() => {
      expect(screen.queryByRole('menu')).toBeNull();
    });
    await waitFor(() => {
      expect(document.activeElement).toBe(trigger);
    });
  });
});
