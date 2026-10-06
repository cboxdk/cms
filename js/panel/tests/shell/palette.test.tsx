// @vitest-environment jsdom

// The command palette (GUARDRAILS 8, keyboard first; PRD 13.4): built from the prop `palette`, the
// read of action.list as the person who signed in. Its pages are the list's navigation entries the
// shell's navigation knows, its commands the list's commands, labelled by the panel's catalogue
// when it has a text for the action and by the command's schema otherwise; a query of the list is
// no entry. Ctrl+K opens it, typing filters, Enter opens the entry in focus: a page at its address,
// a command at its form page below the Inertia profile's address. A rejected read and a prop the
// panel cannot read show why instead of entries.

import { screen, waitFor } from '@testing-library/react';
import { describe, expect, test } from 'vitest';

import type { ActionListV1 } from '../../src/generated/protocol/ActionListV1';
import type { NavEntry } from '../../src/host';
import { paletteCommands, paletteOf, palettePages } from '../../src/shell/palette';
import { PanelPalette } from '../../src/shell/PanelPalette';
import { contributions, fill, point, renderHost } from '../host/harness';

const LIST: ActionListV1 = {
  actions: [
    {
      description: 'Lists the actions.',
      kind: 'query',
      name: 'action.list',
      title: 'action.list, contract version 1',
      version: 1,
    },
    {
      description: 'Adds a probe.',
      kind: 'command',
      name: 'probe.add',
      title: 'probe.add, contract version 1',
      version: 2,
    },
    {
      description: 'Creates a role (PRD 5.10, 6.4) in one changeset.',
      kind: 'command',
      name: 'role.create',
      title: 'role.create, contract version 1',
      version: 1,
    },
  ],
  navigation: [
    { icon: null, id: 'cms.account-me', label: 'panel.nav.account_me', page: 'account.me' },
    { icon: 'inbox', id: 'tally.lost', label: 'tally.nav.lost', page: 'tally.gone' },
  ],
};

const NAV: readonly NavEntry[] = [
  {
    id: 'cms.account-me',
    addon: 'cms',
    label: 'Who am I',
    icon: null,
    page: 'account.me',
    url: '/cms/account/me',
    priority: 100,
  },
];

/** The texts of the panel's catalogue the test expects to see. */
const TEXT = {
  open: 'Search',
  search: 'Find a page or a command',
  dialog: 'Command palette',
  me: 'Who am I',
  createRole: 'Create a role',
  refused: 'The panel could not read your commands',
  unavailable: 'The commands could not be loaded',
} as const;

/** The page's contributions: the core's nav entry to the who-am-I page, which the server lists. */
function shell() {
  return contributions(
    [
      point(
        'shell.nav@1',
        [
          fill('cms', 'cms.account-me', 100, {
            kind: 'nav',
            props: {},
            nav: { icon: null, label: 'panel.nav.account_me', page: 'account.me' },
          }),
        ],
        { kind: 'nav', region: null },
      ),
    ],
    { cms: [] },
    {
      pages: [
        { page: 'account.me', url: '/cms/account/me' },
        { page: 'home', url: '/cms' },
      ],
    },
  );
}

function render(palette: unknown) {
  return renderHost(<PanelPalette palette={palette} />, {
    contributions: shell(),
    registrations: {},
    texts: { 'panel.nav.account_me': TEXT.me },
  });
}

describe('the palette entries', () => {
  test('are the commands of the list, labelled by the catalogue or the schema, each opening its form page, and never a query', () => {
    expect(paletteCommands(LIST, '/cms/commands', 'en')).toEqual([
      {
        id: 'command:probe.add@2',
        label: 'probe.add, contract version 1',
        description: 'Adds a probe.',
        keywords: ['probe.add'],
        url: '/cms/commands/probe.add/v2',
      },
      {
        id: 'command:role.create@1',
        label: TEXT.createRole,
        description:
          'Create a role with a handle, a classification ceiling and the commands it may run.',
        keywords: ['role.create'],
        url: '/cms/commands/role.create/v1',
      },
    ]);
    expect(paletteCommands(LIST, '/cms/commands', 'da')[1]?.label).toBe('Opret en rolle');
  });

  test('are the navigation entries the shell knows, with its text and address, leaving out one it does not', () => {
    expect(palettePages(LIST, NAV)).toEqual([
      {
        id: 'page:cms.account-me',
        label: TEXT.me,
        keywords: ['account.me'],
        url: '/cms/account/me',
      },
    ]);
  });

  test('come from the prop as the server shared it: the list, a rejection, or something the panel cannot read', () => {
    expect(paletteOf({ result: LIST, rejection: null })).toEqual({ status: 'ready', list: LIST });
    expect(
      paletteOf({
        result: null,
        rejection: {
          type: 'https://cbox.dk/cms/errors/unauthorized',
          title: 'Unauthorized',
          status: 403,
          code: 'unauthorized',
          detail: 'No.',
          errors: [],
          instance: null,
          retryable: false,
        },
      }).status,
    ).toBe('rejected');
    expect(paletteOf({ result: { actions: 'none' }, rejection: null })).toEqual({
      status: 'unreadable',
    });
    expect(paletteOf(undefined)).toEqual({ status: 'unreadable' });
  });
});

describe('the panel s palette', () => {
  test('opens with Ctrl+K and its button, lists the pages and the commands, filters, and opens a command s form page with Enter', async () => {
    const { user, recorded } = render({ result: LIST, rejection: null });

    expect(screen.getByRole('button', { name: new RegExp(TEXT.open) })).not.toBeNull();
    await user.keyboard('{Control>}k{/Control}');
    await screen.findByRole('dialog', { name: TEXT.dialog });

    expect(screen.getAllByRole('option').map((option) => option.textContent)).toEqual([
      TEXT.me,
      'probe.add, contract version 1Adds a probe.',
      `${TEXT.createRole}Create a role with a handle, a classification ceiling and the commands it may run.`,
    ]);

    await user.keyboard('role.cr');
    await waitFor(() => {
      expect(screen.getAllByRole('option')).toHaveLength(1);
    });
    await user.keyboard('{Enter}');

    expect(recorded.visits).toEqual(['/cms/commands/role.create/v1']);
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).toBeNull();
    });
  });

  test('opens a page entry at its address', async () => {
    const { user, recorded } = render({ result: LIST, rejection: null });

    await user.click(screen.getByRole('button', { name: new RegExp(TEXT.open) }));
    await screen.findByRole('dialog');
    await user.keyboard('who');
    await user.keyboard('{ArrowDown}{Enter}');

    expect(recorded.visits).toEqual(['/cms/account/me']);
  });

  test('says why when the read was rejected or the prop cannot be read, and still opens and closes', async () => {
    const rejected = render({
      result: null,
      rejection: {
        type: 'https://cbox.dk/cms/errors/unauthorized',
        title: 'Unauthorized',
        status: 403,
        code: 'unauthorized',
        detail: 'No.',
        errors: [],
        instance: null,
        retryable: false,
      },
    });

    await rejected.user.keyboard('{Control>}k{/Control}');
    await screen.findByRole('dialog');
    expect(screen.getByRole('alert').textContent).toContain(TEXT.refused);
    expect(screen.queryAllByRole('option')).toEqual([]);
    await rejected.user.keyboard('{Escape}');
    await waitFor(() => {
      expect(screen.queryByRole('dialog')).toBeNull();
    });
    rejected.unmount();

    const unreadable = render(undefined);

    await unreadable.user.keyboard('{Control>}k{/Control}');
    await screen.findByRole('dialog');
    expect(screen.getByRole('alert').textContent).toContain(TEXT.unavailable);
  });
});
