import { Menu, type MenuProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<MenuProps> = {
  title: 'Components/Actions/Menu',
  component: Menu,
};

export default meta;

const TEXTS: Localized<{
  actions: string;
  more: string;
  edit: string;
  duplicate: string;
  revoke: string;
}> = {
  da: {
    actions: 'Handlinger',
    more: 'Handlinger for adgangen',
    edit: 'Redigér',
    duplicate: 'Kopiér',
    revoke: 'Tilbagekald',
  },
  en: {
    actions: 'Actions',
    more: 'Actions for the grant',
    edit: 'Edit',
    duplicate: 'Duplicate',
    revoke: 'Revoke',
  },
};

function items(globals: Readonly<Record<string, unknown>>) {
  const texts = textsOf(TEXTS, globals);

  return [
    { id: 'edit', label: texts.edit },
    { id: 'duplicate', label: texts.duplicate, disabled: true },
    { id: 'revoke', label: texts.revoke, tone: 'danger' as const },
  ];
}

/** Closed: a button with its text. */
export const Closed: Story = {
  render: (_args, { globals }) => (
    <Menu
      trigger={{ label: textsOf(TEXTS, globals).actions }}
      items={items(globals)}
      onAction={() => undefined}
    />
  ),
  play: async ({ canvasElement, userEvent }) => {
    const trigger = single(canvasElement, 'button', HTMLButtonElement);

    await userEvent.tab();
    await userEvent.keyboard('{Enter}');
    await waitFor(() => document.querySelector('[role="menu"]') !== null, 'Enter opens the menu');
    check(focused(HTMLElement).getAttribute('role') === 'menuitem', 'focus is on the first item');
    await userEvent.keyboard('{Escape}');
    await waitFor(() => document.querySelector('[role="menu"]') === null, 'Escape closes it');
    await waitFor(() => document.activeElement === trigger, 'focus returns to the button');
  },
};

/** Open, from an icon button: Down opens it, and Down again skips the disabled item. */
export const Open: Story = {
  render: (_args, { globals }) => (
    <div style={{ display: 'flex', justifyContent: 'center', minBlockSize: '12rem' }}>
      <Menu
        trigger={{ label: textsOf(TEXTS, globals).more, icon: 'more' }}
        items={items(globals)}
        onAction={(id) => {
          document.body.dataset['action'] = id;
        }}
      />
    </div>
  ),
  play: async ({ canvasElement, userEvent }) => {
    single(canvasElement, 'button[aria-label]', HTMLButtonElement);
    await userEvent.tab();
    await userEvent.keyboard('{ArrowDown}');
    await waitFor(() => document.querySelector('[role="menu"]') !== null, 'Down opens the menu');
    await userEvent.keyboard('{ArrowDown}');
    check(focused(HTMLElement).dataset['tone'] === 'danger', 'Down skips the disabled item');
  },
};

/** Enter on an item takes its action and closes the menu. */
export const TakesAction: Story = {
  render: Open.render,
  play: async ({ userEvent }) => {
    await userEvent.tab();
    await userEvent.keyboard('{ArrowDown}');
    await waitFor(() => document.querySelector('[role="menu"]') !== null, 'Down opens the menu');
    await userEvent.keyboard('{ArrowDown}{Enter}');
    check(document.body.dataset['action'] === 'revoke', 'Enter takes the action');
    await waitFor(() => document.querySelector('[role="menu"]') === null, 'the menu closes');
    delete document.body.dataset['action'];
  },
};

export const Dark: Story = inDark(Open);
export const ForcedColors: Story = inForcedColours(Open);
export const Danish: Story = inDanish(Open);
