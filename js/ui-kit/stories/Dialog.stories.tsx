import { Button, Dialog, Form, TextInput, type DialogProps } from '@cboxdk/cms-ui-kit';
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

const meta: StoryMeta<DialogProps> = {
  title: 'Components/Overlays/Dialog',
  component: Dialog,
};

export default meta;

const TEXTS: Localized<{
  open: string;
  title: string;
  handle: string;
  cancel: string;
  save: string;
}> = {
  da: {
    open: 'Opret rolle',
    title: 'Opret en rolle',
    handle: 'Håndtag',
    cancel: 'Annullér',
    save: 'Opret',
  },
  en: {
    open: 'Create a role',
    title: 'Create a role',
    handle: 'Handle',
    cancel: 'Cancel',
    save: 'Create',
  },
};

function RoleDialog({
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
        onClick={() => {
          setOpen(true);
        }}
      >
        {texts.open}
      </Button>
      <Dialog
        title={texts.title}
        open={open}
        onOpenChange={setOpen}
        footer={
          <>
            <Button
              onClick={() => {
                setOpen(false);
              }}
            >
              {texts.cancel}
            </Button>
            <Button variant="primary">{texts.save}</Button>
          </>
        }
      >
        <Form>
          <TextInput label={texts.handle} name="handle" />
        </Form>
      </Dialog>
    </>
  );
}

/**
 * Enter on the button opens the dialog with focus in it; Tab stays inside, and Escape closes it
 * and returns focus to the button.
 */
export const Keyboard: Story = {
  render: (_args, { globals }) => <RoleDialog globals={globals} initiallyOpen={false} />,
  play: async ({ canvasElement, userEvent }) => {
    const opener = single(canvasElement, 'button', HTMLButtonElement);

    await userEvent.tab();
    await userEvent.keyboard('{Enter}');
    await waitFor(() => document.querySelector('[role="dialog"]') !== null, 'the dialog opens');
    const dialog = single(document.body, '[role="dialog"]', HTMLElement);
    check(dialog.contains(document.activeElement), 'focus is in the dialog');

    for (let step = 0; step < 6; step++) {
      await userEvent.tab();
      check(dialog.contains(document.activeElement), 'Tab stays in the dialog');
    }

    await userEvent.keyboard('{Escape}');
    await waitFor(() => document.querySelector('[role="dialog"]') === null, 'Escape closes it');
    await waitFor(() => document.activeElement === opener, 'focus returns to the button');
  },
};

/** Open, as the page shows it: the page behind is dimmed and out of reach. */
export const Open: Story = {
  render: (_args, { globals }) => <RoleDialog globals={globals} initiallyOpen />,
  play: async () => {
    await waitFor(() => document.querySelector('[role="dialog"]') !== null, 'the dialog is open');
    const dialog = single(document.body, '[role="dialog"]', HTMLElement);
    const heading = single(dialog, 'h2', HTMLHeadingElement);

    check(dialog.getAttribute('aria-labelledby') === heading.id, 'the heading names the dialog');
    focused(HTMLElement);
  },
};

export const Dark: Story = inDark(Open);
export const ForcedColors: Story = inForcedColours(Open);
export const Danish: Story = inDanish(Open);
