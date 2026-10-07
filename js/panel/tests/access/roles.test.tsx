// @vitest-environment jsdom

// The roles page (PRD 5.10, 13.4): the roles of the installation as role.list answered them, with
// what the viewer may do decided by the prop `palette`; creating a role and replacing a role's
// permissions through the host's command transport as the viewer, with the receipt shown on the
// page and a refusal explained where the change was made; and the empty, loading, rejected and
// unreadable states of the list.

import { committedReceipt, rejectedProblem } from '@cboxdk/cms-panel/testing';
import { screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, test, vi } from 'vitest';

import { UUID7 } from '../../src/access/ids';
import type { AccessRolesPageV1 } from '../../src/generated/pages/AccessRolesPageV1';
import type { ActionListV1 } from '../../src/generated/protocol/ActionListV1';
import type { RoleListV1 } from '../../src/generated/protocol/RoleListV1';
import { translator } from '../../src/i18n/translations';
import Roles from '../../src/pages/Access/Roles';
import { contributions, renderHost, type Answering } from '../host/harness';

const inertia = vi.hoisted(() => ({
  page: { props: {}, url: '/cms/access/roles' },
  router: {
    post: vi.fn(),
    visit: vi.fn(),
    reload: vi.fn(),
    on: vi.fn(() => () => undefined),
  },
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  router: inertia.router,
  usePage: () => inertia.page,
}));

const t = translator('en');

const ADMIN = '0192a0c0-0000-7000-8000-000000000331';
const DESK = '0192a0c0-0000-7000-8000-000000000332';

const ROLES: RoleListV1 = {
  roles: [
    {
      ceiling: 'personal',
      handle: 'admin',
      id: ADMIN,
      permissions: ['grant.list', 'role.list'],
      version: 1,
    },
    { ceiling: 'internal', handle: 'desk', id: DESK, permissions: ['entry.revise'], version: 3 },
  ],
  next: null,
};

/** The actions the person may run, as action.list lists them. */
function palette(...names: string[]): ActionListV1 {
  return {
    actions: names.map((name) => ({
      description: `${name}.`,
      kind: name.endsWith('.list') ? 'query' : 'command',
      name,
      title: name,
      version: 1,
    })),
    navigation: [],
  };
}

const EVERYTHING = palette('role.list', 'role.create', 'role.set_permissions', 'entry.revise');

/** Renders the page with the props and the shared props every page behind the login has. */
function renderRoles(
  props: Partial<AccessRolesPageV1> = {},
  actions: ActionListV1 = EVERYTHING,
  answering: Answering = {},
) {
  const page: AccessRolesPageV1 = {
    logout: '/cms/logout',
    rejection: null,
    result: { ...ROLES },
    ...props,
  };
  inertia.page.props = {
    ...page,
    brand: { login: null, logo: null, name: 'Cbox CMS' },
    palette: { rejection: null, result: actions },
  };

  return renderHost(<Roles {...page} />, {
    contributions: contributions(
      [],
      { cms: [] },
      { pages: [{ page: 'access.roles', url: '/cms/access/roles' }] },
    ),
    registrations: {},
    answering,
  });
}

beforeEach(() => {
  inertia.page.url = '/cms/access/roles';
  inertia.router.post.mockReset();
  inertia.router.visit.mockReset();
  inertia.router.reload.mockReset();
});

describe('the list', () => {
  test('shows every role with its handle, ceiling and permissions, and the button to create one', () => {
    renderRoles();

    const table = screen.getByRole('grid', { name: t('panel.roles.title') });

    expect(within(table).getByText('admin')).toBeDefined();
    expect(within(table).getByText('desk')).toBeDefined();
    expect(within(table).getByText('Personal')).toBeDefined();
    expect(within(table).getByText('entry.revise')).toBeDefined();
    expect(screen.getByRole('button', { name: t('panel.roles.create') })).toBeDefined();
    expect(screen.queryByText(t('panel.roles.cannot_create'))).toBeNull();
  });

  test('offers no creation to a person who may not create roles, and says who can', () => {
    renderRoles({}, palette('role.list'));

    expect(screen.queryByRole('button', { name: t('panel.roles.create') })).toBeNull();
    expect(screen.getByText(t('panel.roles.cannot_create'))).toBeDefined();
    expect(
      screen.queryByRole('button', { name: t('panel.roles.row_actions', { handle: 'admin' }) }),
    ).toBeNull();
  });

  test('shows the empty state with the first action when there is no role', () => {
    renderRoles({ result: { roles: [], next: null } });

    expect(screen.getByRole('heading', { name: t('panel.roles.empty_title') })).toBeDefined();
    expect(screen.getByText(t('panel.roles.empty_body'))).toBeDefined();
  });

  test('explains a rejected read with the text of its code', () => {
    const { problem } = rejectedProblem('unauthorized');

    renderRoles({ result: null, rejection: { ...problem } });

    expect(screen.getByRole('alert').textContent).toContain(t('panel.problem.unauthorized'));
    expect(screen.queryByRole('grid')).toBeNull();
  });

  test('says when the page got no answer it could read', () => {
    renderRoles({ result: { roles: 'none' } });

    expect(
      screen.getByRole('heading', { name: t('panel.access.unavailable_title') }),
    ).toBeDefined();
  });

  test('visits the next page after the last role, and goes back to the first', async () => {
    const { user } = renderRoles({ result: { ...ROLES, next: DESK } });

    await user.click(screen.getByRole('button', { name: 'Next' }));

    expect(inertia.router.visit).toHaveBeenCalledWith(
      `/cms/access/roles?after=${DESK}`,
      expect.objectContaining({ preserveState: true, preserveScroll: true }),
    );
  });
});

describe('creating a role', () => {
  test('runs role.create as the viewer with a new id, shows the receipt and tells the person', async () => {
    const { user, recorded } = renderRoles({}, EVERYTHING, {
      runCommand: () => Promise.resolve(committedReceipt()),
    });

    await user.click(screen.getByRole('button', { name: t('panel.roles.create') }));

    const dialog = screen.getByRole('dialog', { name: t('panel.roles.create_title') });

    await user.type(within(dialog).getByRole('textbox', { name: /Handle/ }), 'reviewers');
    await user.click(within(dialog).getByRole('button', { name: t('panel.roles.submit_create') }));

    await waitFor(() => {
      expect(recorded.commands).toHaveLength(1);
    });

    const call = recorded.commands[0];

    expect(call?.command).toBe('role.create@1');
    expect(call?.provenance).toBeUndefined();
    expect(call?.document).toEqual({
      role: expect.stringMatching(UUID7) as string,
      handle: 'reviewers',
      ceiling: 'internal',
      permissions: [],
    });

    await waitFor(() => {
      expect(screen.queryByRole('dialog', { name: t('panel.roles.create_title') })).toBeNull();
    });
    expect(recorded.notices).toEqual([t('panel.roles.created', { handle: 'reviewers' })]);
    expect(screen.getByRole('status').textContent).toContain('Saved');
  });

  test('refuses a handle that is not of the form before anything runs', async () => {
    const { user, recorded } = renderRoles();

    await user.click(screen.getByRole('button', { name: t('panel.roles.create') }));

    const dialog = screen.getByRole('dialog', { name: t('panel.roles.create_title') });

    await user.type(within(dialog).getByRole('textbox', { name: /Handle/ }), 'Not A Handle');
    await user.click(within(dialog).getByRole('button', { name: t('panel.roles.submit_create') }));

    expect(recorded.commands).toHaveLength(0);
    expect(within(dialog).getByText(t('panel.roles.handle_invalid'))).toBeDefined();
  });

  test('keeps the form open on a refusal, explains the code and marks the field it is about', async () => {
    const answer = rejectedProblem('validation_failed', [
      { code: 'validation_failed', field: 'handle', detail: 'Another role has the handle desk.' },
    ]);
    const { user } = renderRoles({}, EVERYTHING, { runCommand: () => Promise.resolve(answer) });

    await user.click(screen.getByRole('button', { name: t('panel.roles.create') }));

    const dialog = screen.getByRole('dialog', { name: t('panel.roles.create_title') });

    await user.type(within(dialog).getByRole('textbox', { name: /Handle/ }), 'desk');
    await user.click(within(dialog).getByRole('button', { name: t('panel.roles.submit_create') }));

    await waitFor(() => {
      expect(within(dialog).getByRole('alert').textContent).toContain(
        t('panel.problem.validation_failed'),
      );
    });
    expect(within(dialog).getByText('Another role has the handle desk.')).toBeDefined();
    expect(screen.getByRole('dialog', { name: t('panel.roles.create_title') })).toBeDefined();
  });

  test("explains a refusal by the guard in the person's language", async () => {
    const { user } = renderRoles({}, EVERYTHING, {
      runCommand: () => Promise.resolve(rejectedProblem('grant_escalation_refused')),
    });

    await user.click(screen.getByRole('button', { name: t('panel.roles.create') }));

    const dialog = screen.getByRole('dialog', { name: t('panel.roles.create_title') });

    await user.type(within(dialog).getByRole('textbox', { name: /Handle/ }), 'wide');
    await user.click(within(dialog).getByRole('button', { name: t('panel.roles.submit_create') }));

    await waitFor(() => {
      expect(within(dialog).getByRole('alert').textContent).toContain(
        t('panel.problem.grant_escalation_refused'),
      );
    });
  });

  test('says when the panel got no answer', async () => {
    const { user } = renderRoles({}, EVERYTHING, {
      runCommand: () => Promise.reject(new Error('no answer')),
    });

    await user.click(screen.getByRole('button', { name: t('panel.roles.create') }));

    const dialog = screen.getByRole('dialog', { name: t('panel.roles.create_title') });

    await user.type(within(dialog).getByRole('textbox', { name: /Handle/ }), 'reviewers');
    await user.click(within(dialog).getByRole('button', { name: t('panel.roles.submit_create') }));

    await waitFor(() => {
      expect(within(dialog).getByText(t('panel.access.failed_title'))).toBeDefined();
    });
  });
});

describe('editing permissions', () => {
  test("opens from the row's menu with the role's permissions, and saves only a change", async () => {
    const { user, recorded } = renderRoles({}, EVERYTHING, {
      runCommand: () => Promise.resolve(committedReceipt()),
    });

    await user.click(
      screen.getByRole('button', { name: t('panel.roles.row_actions', { handle: 'desk' }) }),
    );
    await user.click(
      await screen.findByRole('menuitem', { name: t('panel.roles.edit_permissions') }),
    );

    const dialog = await screen.findByRole('dialog', {
      name: t('panel.roles.edit_title', { handle: 'desk' }),
    });
    const save = within(dialog).getByRole('button', { name: t('panel.roles.submit_permissions') });

    expect(save.hasAttribute('disabled')).toBe(true);
    expect(within(dialog).getByText(t('panel.roles.unchanged'))).toBeDefined();

    // The role's permission is chosen already; its tag's remove button takes it away.
    await user.click(within(dialog).getByRole('button', { name: 'Remove entry.revise' }));

    expect(save.hasAttribute('disabled')).toBe(false);

    await user.click(save);

    await waitFor(() => {
      expect(recorded.commands).toHaveLength(1);
    });
    expect(recorded.commands[0]?.command).toBe('role.set_permissions@1');
    expect(recorded.commands[0]?.document).toEqual({ role: DESK, version: 3, permissions: [] });
    expect(recorded.notices).toEqual([t('panel.roles.permissions_saved', { handle: 'desk' })]);
  });
});
