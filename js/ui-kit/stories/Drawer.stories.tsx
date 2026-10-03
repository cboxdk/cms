import { Button, DescriptionList, Drawer, type DrawerProps } from '@cboxdk/cms-ui-kit';
import { useState } from 'react';

import {
  check,
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

const meta: StoryMeta<DrawerProps> = {
  title: 'Components/Overlays/Drawer',
  component: Drawer,
};

export default meta;

const TEXTS: Localized<{
  open: string;
  title: string;
  role: string;
  node: string;
  effect: string;
  allow: string;
  close: string;
}> = {
  da: {
    open: 'Vis adgang',
    title: 'Adgang',
    role: 'Rolle',
    node: 'Node',
    effect: 'Virkning',
    allow: 'Tillad',
    close: 'Luk',
  },
  en: {
    open: 'Show the grant',
    title: 'Grant',
    role: 'Role',
    node: 'Node',
    effect: 'Effect',
    allow: 'Allow',
    close: 'Close',
  },
};

function GrantDrawer({
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
      <Drawer
        title={texts.title}
        open={open}
        onOpenChange={setOpen}
        footer={
          <Button
            onClick={() => {
              setOpen(false);
            }}
          >
            {texts.close}
          </Button>
        }
      >
        <DescriptionList
          layout="stacked"
          items={[
            { id: 'role', term: texts.role, description: 'editor' },
            { id: 'node', term: texts.node, description: 'News' },
            { id: 'effect', term: texts.effect, description: texts.allow },
          ]}
        />
      </Drawer>
    </>
  );
}

/** Open at the end of the screen; Escape closes it. */
export const Open: Story = {
  render: (_args, { globals }) => <GrantDrawer globals={globals} initiallyOpen />,
  play: async () => {
    await waitFor(() => document.querySelector('[role="dialog"]') !== null, 'it is open');
    const dialog = single(document.body, '[role="dialog"]', HTMLElement);

    check(dialog.contains(document.activeElement), 'focus is in the drawer');
  },
};

/** Enter on the button opens it with focus inside; Escape closes it and focus returns. */
export const Keyboard: Story = {
  render: (_args, { globals }) => <GrantDrawer globals={globals} initiallyOpen={false} />,
  play: async ({ canvasElement, userEvent }) => {
    const opener = single(canvasElement, 'button', HTMLButtonElement);

    await userEvent.tab();
    await userEvent.keyboard('{Enter}');
    await waitFor(() => document.querySelector('[role="dialog"]') !== null, 'Enter opens it');
    await userEvent.keyboard('{Escape}');
    await waitFor(() => document.querySelector('[role="dialog"]') === null, 'Escape closes it');
    await waitFor(() => document.activeElement === opener, 'focus returns to the button');
  },
};

export const Dark: Story = inDark(Open);
export const ForcedColors: Story = inForcedColours(Open);
export const Danish: Story = inDanish(Open);
