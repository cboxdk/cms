// @vitest-environment jsdom

import { screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, test } from 'vitest';

import { Button } from '../../src/components/Button';
import { Dialog } from '../../src/components/Dialog';
import { TextInput } from '../../src/components/TextInput';
import { active, renderKit } from './harness';

/** The texts the test renders, as a caller's translations would give them. */
const TEXT = { create: 'Create a role', save: 'Create', handle: 'Handle' };

function Harness() {
  const [open, setOpen] = useState(false);

  return (
    <>
      <Button
        onClick={() => {
          setOpen(true);
        }}
      >
        {TEXT.create}
      </Button>
      <Dialog
        title={TEXT.create}
        open={open}
        onOpenChange={setOpen}
        footer={<Button variant="primary">{TEXT.save}</Button>}
      >
        <TextInput label={TEXT.handle} />
      </Dialog>
    </>
  );
}

describe('the keyboard contract of Dialog', () => {
  test('Enter on the opener opens it with focus inside, named by its heading', async () => {
    const { user } = renderKit(<Harness />);

    await user.tab();
    await user.keyboard('{Enter}');

    const dialog = await screen.findByRole('dialog', { name: 'Create a role' });
    await waitFor(() => {
      expect(dialog.contains(active())).toBe(true);
    });
  });

  test('Tab and Shift+Tab stay inside it', async () => {
    const { user } = renderKit(<Harness />);

    await user.tab();
    await user.keyboard('{Enter}');
    const dialog = await screen.findByRole('dialog');
    await waitFor(() => {
      expect(dialog.contains(active())).toBe(true);
    });

    for (let step = 0; step < 5; step++) {
      await user.tab();
      expect(dialog.contains(active())).toBe(true);
    }

    for (let step = 0; step < 5; step++) {
      await user.tab({ shift: true });
      expect(dialog.contains(active())).toBe(true);
    }
  });

  test('Escape closes it and returns focus to the opener', async () => {
    const { user } = renderKit(<Harness />);
    const opener = screen.getByRole('button', { name: 'Create a role' });

    await user.tab();
    await user.keyboard('{Enter}');
    const dialog = await screen.findByRole('dialog');
    // Escape reaches the dialog only from inside it, and focus moves in after the dialog mounts.
    await waitFor(() => {
      expect(dialog.contains(active())).toBe(true);
    });
    await user.keyboard('{Escape}');

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).toBeNull();
    });
    await waitFor(() => {
      expect(document.activeElement).toBe(opener);
    });
  });

  test('its close button, named in the kit’s text, closes it', async () => {
    const { user } = renderKit(<Harness />);

    await user.click(screen.getByRole('button', { name: 'Create a role' }));
    await user.click(await screen.findByRole('button', { name: 'Close' }));

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).toBeNull();
    });
  });
});
