import { Button, ConfirmDialog, type ConfirmDialogProps } from '@cboxdk/cms-ui-kit';
import { useState } from 'react';

import {
  check,
  focused,
  inDanish,
  inDark,
  inForcedColours,
  single,
  textsOf,
  waitFor,
  type Localized,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<ConfirmDialogProps> = {
  title: 'Components/Overlays/ConfirmDialog',
  component: ConfirmDialog,
};

export default meta;

const TEXTS: Localized<{
  open: string;
  title: string;
  message: string;
  confirm: string;
  cancel: string;
}> = {
  da: {
    open: 'Tilbagekald',
    title: 'Tilbagekald adgangen?',
    message: 'Ada mister rollen Redaktør på Nyheder med det samme. Du kan tildele den igen.',
    confirm: 'Tilbagekald',
    cancel: 'Annullér',
  },
  en: {
    open: 'Revoke',
    title: 'Revoke the grant?',
    message: 'Ada loses the role Editor on News at once. You can assign it again.',
    confirm: 'Revoke',
    cancel: 'Cancel',
  },
};

function Revoke({
  globals,
  initiallyOpen,
}: {
  readonly globals: Readonly<Record<string, unknown>>;
  readonly initiallyOpen: boolean;
}) {
  const texts = textsOf(TEXTS, globals);
  const [open, setOpen] = useState(initiallyOpen);

  return (
    <>
      <Button
        variant="danger"
        onClick={() => {
          setOpen(true);
        }}
      >
        {texts.open}
      </Button>
      <ConfirmDialog
        title={texts.title}
        message={texts.message}
        confirmLabel={texts.confirm}
        cancelLabel={texts.cancel}
        open={open}
        onOpenChange={setOpen}
        onConfirm={() => {
          document.body.dataset['confirmed'] = 'true';
        }}
      />
    </>
  );
}

/** Focus starts on Cancel, so Enter right away takes no action; Tab and Enter confirm. */
export const Keyboard: Story = {
  render: (_args, { globals }) => <Revoke globals={globals} initiallyOpen={false} />,
  play: async ({ userEvent }) => {
    await userEvent.tab();
    await userEvent.keyboard('{Enter}');
    await waitFor(() => document.querySelector('[role="alertdialog"]') !== null, 'it opens');
    const dialog = single(document.body, '[role="alertdialog"]', HTMLElement);
    const [cancel, confirm] = dialog.querySelectorAll('button');

    check(document.activeElement === cancel, 'focus starts on Cancel');
    await userEvent.tab();
    check(focused(HTMLButtonElement) === confirm, 'Tab moves to the confirming button');
    await userEvent.keyboard('{Enter}');
    check(document.body.dataset['confirmed'] === 'true', 'Enter confirms');
    delete document.body.dataset['confirmed'];
  },
};

/** Open: an alert dialog with its question, its message and the two buttons. */
export const Open: Story = {
  render: (_args, { globals }) => <Revoke globals={globals} initiallyOpen />,
  play: async () => {
    await waitFor(() => document.querySelector('[role="alertdialog"]') !== null, 'it is open');
  },
};

export const Dark: Story = inDark(Open);
export const ForcedColors: Story = inForcedColours(Open);
export const Danish: Story = inDanish(Open);
