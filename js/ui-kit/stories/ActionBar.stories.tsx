import { ActionBar, type ActionBarProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<ActionBarProps> = {
  title: 'Components/Actions/ActionBar',
  component: ActionBar,
};

export default meta;

const TEXTS: Localized<{
  label: string;
  edit: string;
  grant: string;
  history: string;
  deactivate: string;
}> = {
  da: {
    label: 'Handlinger for medarbejderen',
    edit: 'Redigér',
    grant: 'Tildel adgang',
    history: 'Historik',
    deactivate: 'Deaktivér',
  },
  en: {
    label: 'Actions for the member of staff',
    edit: 'Edit',
    grant: 'Assign a grant',
    history: 'History',
    deactivate: 'Deactivate',
  },
};

function bar(globals: Readonly<Record<string, unknown>>) {
  const texts = textsOf(TEXTS, globals);

  return (
    <ActionBar
      label={texts.label}
      visible={2}
      actions={[
        { id: 'edit', label: texts.edit, variant: 'primary' },
        { id: 'grant', label: texts.grant, icon: 'plus' },
        { id: 'history', label: texts.history },
        { id: 'deactivate', label: texts.deactivate, variant: 'danger' },
      ]}
      onAction={(id) => {
        document.body.dataset['action'] = id;
      }}
    />
  );
}

/**
 * Two actions as buttons and the rest in the overflow menu. The bar is one stop of the Tab order;
 * Right moves between its actions, and the menu's items take theirs.
 */
export const Default: Story = {
  render: (_args, { globals }) => bar(globals),
  play: async ({ canvasElement, userEvent }) => {
    single(canvasElement, '[role="toolbar"]', HTMLDivElement);
    const buttons = canvasElement.querySelectorAll('button');

    await userEvent.tab();
    check(document.activeElement === buttons[0], 'Tab reaches the first action');
    await userEvent.keyboard('{ArrowRight}');
    check(document.activeElement === buttons[1], 'Right moves to the next action');
    await userEvent.keyboard('{ArrowRight}');
    const more = focused(HTMLButtonElement);
    check(more.getAttribute('aria-haspopup') === 'true', 'the last stop is the overflow menu');

    await userEvent.keyboard('{Enter}');
    await waitFor(() => document.querySelector('[role="menu"]') !== null, 'Enter opens the menu');
    await userEvent.keyboard('{ArrowDown}');
    await userEvent.keyboard('{Enter}');
    check(document.body.dataset['action'] === 'deactivate', 'the menu takes the action');
    delete document.body.dataset['action'];
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
