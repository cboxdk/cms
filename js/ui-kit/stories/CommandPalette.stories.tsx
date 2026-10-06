import {
  Button,
  CommandPalette,
  ErrorState,
  KeyboardShortcut,
  type CommandPaletteProps,
  type CommandPaletteSection,
} from '@cboxdk/cms-ui-kit';
import { useState, type ReactNode } from 'react';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  textsOf,
  waitFor,
  type Localized,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<CommandPaletteProps> = {
  title: 'Components/Overlays/CommandPalette',
  component: CommandPalette,
};

export default meta;

const TEXTS: Localized<{
  label: string;
  search: string;
  open: string;
  empty: string;
  loading: string;
  failed: string;
  failedDescription: string;
  pages: string;
  commands: string;
  me: string;
  roles: string;
  createRole: string;
  createRoleDescription: string;
  assign: string;
  assignDescription: string;
}> = {
  da: {
    label: 'Kommandopalet',
    search: 'Find en side eller en kommando',
    open: 'Kommandoer',
    empty: 'Ingen side eller kommando passer.',
    loading: 'Henter kommandoerne',
    failed: 'Kommandoerne kunne ikke hentes',
    failedDescription: 'Prøv igen om lidt.',
    pages: 'Sider',
    commands: 'Kommandoer',
    me: 'Hvem er jeg',
    roles: 'Roller',
    createRole: 'Opret rolle',
    createRoleDescription: 'Opret en rolle med et håndtag.',
    assign: 'Tildel adgang',
    assignDescription: 'Giv en medarbejder en rolle på en node.',
  },
  en: {
    label: 'Command palette',
    search: 'Find a page or a command',
    open: 'Commands',
    empty: 'No page or command matches.',
    loading: 'Loading the commands',
    failed: 'The commands could not be loaded',
    failedDescription: 'Try again in a moment.',
    pages: 'Pages',
    commands: 'Commands',
    me: 'Who am I',
    roles: 'Roles',
    createRole: 'Create a role',
    createRoleDescription: 'Create a role with a handle.',
    assign: 'Assign a grant',
    assignDescription: 'Give a member of staff a role on a node.',
  },
};

function sections(globals: Readonly<Record<string, unknown>>): CommandPaletteSection[] {
  const texts = textsOf(TEXTS, globals);

  return [
    {
      id: 'pages',
      title: texts.pages,
      items: [
        { id: 'page:me', label: texts.me },
        { id: 'page:roles', label: texts.roles },
      ],
    },
    {
      id: 'commands',
      title: texts.commands,
      items: [
        {
          id: 'command:role.create',
          label: texts.createRole,
          description: texts.createRoleDescription,
          keywords: ['role.create'],
        },
        {
          id: 'command:grant.assign',
          label: texts.assign,
          description: texts.assignDescription,
          keywords: ['grant.assign'],
        },
      ],
    },
  ];
}

function Palette({
  globals,
  initiallyOpen,
  loading,
  error,
}: {
  readonly globals: Readonly<Record<string, unknown>>;
  readonly initiallyOpen: boolean;
  readonly loading?: boolean;
  readonly error?: ReactNode;
}) {
  const texts = textsOf(TEXTS, globals);
  const [open, setOpen] = useState(initiallyOpen);

  return (
    <>
      <Button
        icon="search"
        onClick={() => {
          setOpen(true);
        }}
      >
        {texts.open} <KeyboardShortcut keys={['Mod', 'K']} />
      </Button>
      <CommandPalette
        label={texts.label}
        searchLabel={texts.search}
        sections={sections(globals)}
        open={open}
        onOpenChange={setOpen}
        onAction={(id) => {
          document.body.dataset['action'] = id;
        }}
        emptyLabel={texts.empty}
        {...(loading === true ? { loading: texts.loading } : {})}
        error={error}
      />
    </>
  );
}

const dialog = () => document.querySelector('[role="dialog"]');

/**
 * Ctrl+K opens the palette with focus in the search field; typing filters, Down moves through the
 * entries, Enter runs one and closes the palette, and focus returns to where it was.
 */
export const Keyboard: Story = {
  render: (_args, { globals }) => <Palette globals={globals} initiallyOpen={false} />,
  play: async ({ canvasElement, globals, userEvent }) => {
    const texts = textsOf(TEXTS, globals);
    const opener = canvasElement.querySelector('button');

    await userEvent.tab();
    await userEvent.keyboard('{Control>}k{/Control}');
    await waitFor(() => dialog() !== null, 'Ctrl+K opens the palette');
    check(dialog()?.contains(document.activeElement) === true, 'focus is in the palette');
    check(document.activeElement?.tagName === 'INPUT', 'focus is in the search field');
    await userEvent.keyboard(texts.assign.slice(0, 6));
    await waitFor(
      () => document.querySelectorAll('[role="option"]').length === 1,
      'typing filters the entries',
    );
    await userEvent.keyboard('{Enter}');
    await waitFor(() => dialog() === null, 'Enter closes the palette');
    check(document.body.dataset['action'] === 'command:grant.assign', 'Enter runs the entry');
    await waitFor(() => document.activeElement === opener, 'focus returns to where it was');
    delete document.body.dataset['action'];
  },
};

/** Open on every entry, in their sections. */
export const Open: Story = {
  render: (_args, { globals }) => <Palette globals={globals} initiallyOpen />,
  play: async () => {
    await waitFor(() => dialog() !== null, 'the palette is open');
    check(document.querySelectorAll('[role="option"]').length === 4, 'every entry is shown');
  },
};

/** Nothing matches what was typed. */
export const NoMatch: Story = {
  render: Open.render,
  play: async ({ globals, userEvent }) => {
    await waitFor(() => dialog() !== null, 'the palette is open');
    await userEvent.keyboard('zzzz');
    await waitFor(
      () => dialog()?.textContent.includes(textsOf(TEXTS, globals).empty) === true,
      'the palette says that nothing matches',
    );
  },
};

/** While the entries load, the palette says what it waits for. */
export const Loading: Story = {
  render: (_args, { globals }) => <Palette globals={globals} initiallyOpen loading />,
  play: async () => {
    await waitFor(() => dialog()?.querySelector('[role="status"]') != null, 'it says it waits');
  },
};

/** When the entries could not be loaded, the palette says why and what to do. */
export const LoadFailed: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <Palette
        globals={globals}
        initiallyOpen
        error={
          <ErrorState title={texts.failed} description={texts.failedDescription} headingLevel={3} />
        }
      />
    );
  },
  play: async () => {
    await waitFor(() => dialog()?.querySelector('[role="alert"]') != null, 'it says it failed');
  },
};

export const Dark: Story = inDark(Open);
export const ForcedColors: Story = inForcedColours(Open);
export const Danish: Story = inDanish(Open);
