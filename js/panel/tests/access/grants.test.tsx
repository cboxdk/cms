// @vitest-environment jsdom

// The grants page (PRD 5.10, 13.4): the grants as grant.list answered them, each with its actor,
// role, node, effect and languages; assigning a grant with the pickers, whose reads the page asks
// for as the optional prop `pickers` when the form opens and shows as loading until they arrive;
// revoking a grant after a confirmation; the receipt shown on the page and a refusal by the guard
// explained where the change was made; and the rejected state of the list.

import { committedReceipt, rejectedProblem } from '@cboxdk/cms-panel/testing';
import { screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, test, vi } from 'vitest';

import { UUID7 } from '../../src/access/ids';
import type { AccessGrantsPageV1 } from '../../src/generated/pages/AccessGrantsPageV1';
import type { GrantPickersV1 } from '../../src/generated/pages/GrantPickersV1';
import type { ActionListV1 } from '../../src/generated/protocol/ActionListV1';
import type { GrantListV1 } from '../../src/generated/protocol/GrantListV1';
import { translator } from '../../src/i18n/translations';
import Grants from '../../src/pages/Access/Grants';
import { contributions, renderHost, type Answering } from '../host/harness';

const inertia = vi.hoisted(() => ({
  page: { props: {}, url: '/cms/access/grants' },
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

const ROOT = '0192a0c0-0000-7000-8000-000000000301';
const NEWS = '0192a0c0-0000-7000-8000-000000000302';
const ADA = '0192a0c0-0000-7000-8000-000000000321';
const EVE = '0192a0c0-0000-7000-8000-000000000322';
const ADMIN_ROLE = '0192a0c0-0000-7000-8000-000000000331';
const DESK = '0192a0c0-0000-7000-8000-000000000332';
const GRANT = '0192a0c0-0000-7000-8000-000000000341';

const GRANTS: GrantListV1 = {
  grants: [
    {
      actor: ADA,
      effect: 'allow',
      id: GRANT,
      locales: null,
      node: ROOT,
      node_label: 'north',
      profile: { display_name: 'Ada Admin', email: 'ada@example.com' },
      role: ADMIN_ROLE,
      role_handle: 'admin',
      version: 1,
    },
    {
      actor: EVE,
      effect: 'deny',
      id: '0192a0c0-0000-7000-8000-000000000344',
      locales: ['da', 'en'],
      node: NEWS,
      node_label: 'north/nyheder',
      profile: null,
      role: DESK,
      role_handle: 'desk',
      version: 2,
    },
  ],
  next: null,
};

const PICKERS: GrantPickersV1 = {
  actors: {
    rejection: null,
    result: {
      actors: [
        {
          id: ADA,
          profile: { display_name: 'Ada Admin', email: 'ada@example.com' },
          state: 'active',
          version: 1,
        },
        {
          id: EVE,
          profile: { display_name: 'Eve Editor', email: 'eve@example.com' },
          state: 'active',
          version: 1,
        },
      ],
      next: null,
    },
  },
  nodes: {
    rejection: null,
    result: {
      nodes: [
        { id: ROOT, kind: 'site', label: 'north', parent: null, site: ROOT, site_handle: 'north' },
        {
          id: NEWS,
          kind: 'section',
          label: 'north/nyheder',
          parent: ROOT,
          site: ROOT,
          site_handle: 'north',
        },
      ],
      next: null,
    },
  },
  roles: {
    rejection: null,
    result: {
      roles: [
        {
          ceiling: 'personal',
          handle: 'admin',
          id: ADMIN_ROLE,
          permissions: ['grant.assign'],
          version: 1,
        },
        {
          ceiling: 'internal',
          handle: 'desk',
          id: DESK,
          permissions: ['entry.revise'],
          version: 1,
        },
      ],
      next: null,
    },
  },
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

const EVERYTHING = palette('grant.list', 'grant.assign', 'grant.revoke');

/** Renders the page with the props and the shared props every page behind the login has. */
function renderGrants(
  props: Partial<AccessGrantsPageV1> = {},
  options: {
    readonly actions?: ActionListV1;
    readonly pickers?: GrantPickersV1;
    readonly answering?: Answering;
  } = {},
) {
  const page: AccessGrantsPageV1 = {
    locales: ['da', 'en'],
    logout: '/cms/logout',
    rejection: null,
    result: { ...GRANTS },
    ...props,
  };
  inertia.page.props = {
    ...page,
    brand: { login: null, logo: null, name: 'Cbox CMS' },
    palette: { rejection: null, result: options.actions ?? EVERYTHING },
    ...(options.pickers === undefined ? {} : { pickers: options.pickers }),
  };

  return renderHost(<Grants {...page} />, {
    contributions: contributions(
      [],
      { cms: [] },
      { pages: [{ page: 'access.grants', url: '/cms/access/grants' }] },
    ),
    registrations: {},
    answering: options.answering ?? {},
  });
}

beforeEach(() => {
  inertia.page.url = '/cms/access/grants';
  inertia.router.post.mockReset();
  inertia.router.visit.mockReset();
  inertia.router.reload.mockReset();
});

describe('the list', () => {
  test('shows every grant with its actor, role, node, effect and languages', () => {
    renderGrants();

    const table = screen.getByRole('grid', { name: t('panel.grants.title') });

    expect(within(table).getByText('Ada Admin')).toBeDefined();
    expect(within(table).getByText(/ada@example\.com/)).toBeDefined();
    expect(within(table).getByText(t('panel.grants.name_withheld'))).toBeDefined();
    expect(within(table).getByText(EVE)).toBeDefined();
    expect(within(table).getByText('admin')).toBeDefined();
    expect(within(table).getByText('nyheder')).toBeDefined();
    expect(within(table).getByText(t('panel.access.effect.allow'))).toBeDefined();
    expect(within(table).getByText(t('panel.access.effect.deny'))).toBeDefined();
    expect(within(table).getByText(t('panel.access.every_locale'))).toBeDefined();
    expect(within(table).getByText('da, en')).toBeDefined();
    expect(screen.getByRole('button', { name: t('panel.grants.assign') })).toBeDefined();
  });

  test('offers no assignment or revocation to a person who may not, and says who can', () => {
    renderGrants({}, { actions: palette('grant.list') });

    expect(screen.queryByRole('button', { name: t('panel.grants.assign') })).toBeNull();
    expect(screen.getByText(t('panel.grants.cannot_assign'))).toBeDefined();
    expect(screen.queryByRole('button', { name: /Actions for the grant/ })).toBeNull();
  });

  test('shows the empty state when there is no grant', () => {
    renderGrants({ result: { grants: [], next: null } });

    expect(screen.getByRole('heading', { name: t('panel.grants.empty_title') })).toBeDefined();
  });

  test('explains a rejected read with the text of its code', () => {
    renderGrants({ result: null, rejection: { ...rejectedProblem('unauthorized').problem } });

    expect(screen.getByRole('alert').textContent).toContain(t('panel.problem.unauthorized'));
  });
});

describe('assigning a grant', () => {
  test('asks for the pickers when the form opens and shows them loading until they arrive', async () => {
    const { user } = renderGrants();

    await user.click(screen.getByRole('button', { name: t('panel.grants.assign') }));

    expect(inertia.router.reload).toHaveBeenCalledWith({ only: ['pickers'] });

    const dialog = screen.getByRole('dialog', { name: t('panel.grants.assign_title') });

    expect(within(dialog).getByText(t('panel.grants.pickers_loading'))).toBeDefined();
  });

  test('runs grant.assign as the viewer with the actor, role and node chosen with the keyboard', async () => {
    const { user, recorded } = renderGrants(
      {},
      {
        pickers: PICKERS,
        answering: { runCommand: () => Promise.resolve(committedReceipt()) },
      },
    );

    await user.click(screen.getByRole('button', { name: t('panel.grants.assign') }));

    const dialog = screen.getByRole('dialog', { name: t('panel.grants.assign_title') });

    await user.type(within(dialog).getByRole('combobox', { name: /Member of staff/ }), 'eve');
    await user.keyboard('{ArrowDown}{Enter}');
    await user.type(within(dialog).getByRole('combobox', { name: /^Role/ }), 'desk');
    await user.keyboard('{ArrowDown}{Enter}');
    await user.click(within(dialog).getByRole('button', { name: 'Choose' }));

    const tree = await screen.findByRole('dialog', { name: t('panel.grants.node') });

    await user.click(within(tree).getByRole('row', { name: 'north' }));
    await user.keyboard('{ArrowRight}{ArrowDown}{Enter}');
    await user.click(within(tree).getByRole('button', { name: 'Choose' }));
    await user.click(within(dialog).getByRole('button', { name: t('panel.grants.submit_assign') }));

    await waitFor(() => {
      expect(recorded.commands).toHaveLength(1);
    });

    expect(recorded.commands[0]?.command).toBe('grant.assign@1');
    expect(recorded.commands[0]?.document).toEqual({
      grant: expect.stringMatching(UUID7) as string,
      actor: EVE,
      role: DESK,
      node: NEWS,
      effect: 'allow',
      locales: null,
    });
    await waitFor(() => {
      expect(screen.queryByRole('dialog', { name: t('panel.grants.assign_title') })).toBeNull();
    });
    expect(recorded.notices).toEqual([t('panel.grants.assigned')]);
    expect(screen.getByRole('status').textContent).toContain('Saved');
  });

  test('asks for every choice before anything runs', async () => {
    const { user, recorded } = renderGrants({}, { pickers: PICKERS });

    await user.click(screen.getByRole('button', { name: t('panel.grants.assign') }));

    const dialog = screen.getByRole('dialog', { name: t('panel.grants.assign_title') });

    await user.click(within(dialog).getByRole('button', { name: t('panel.grants.submit_assign') }));

    expect(recorded.commands).toHaveLength(0);
    expect(within(dialog).getByText(t('panel.grants.actor_required'))).toBeDefined();
    expect(within(dialog).getByText(t('panel.grants.role_required'))).toBeDefined();
    expect(within(dialog).getByText(t('panel.grants.node_required'))).toBeDefined();
  });

  test('explains a refusal by the guard where the change was made, and keeps the form open', async () => {
    const { user } = renderGrants(
      {},
      {
        pickers: PICKERS,
        answering: { runCommand: () => Promise.resolve(rejectedProblem('step_up_required')) },
      },
    );

    await user.click(screen.getByRole('button', { name: t('panel.grants.assign') }));

    const dialog = screen.getByRole('dialog', { name: t('panel.grants.assign_title') });

    await user.type(within(dialog).getByRole('combobox', { name: /Member of staff/ }), 'ada');
    await user.keyboard('{ArrowDown}{Enter}');
    await user.type(within(dialog).getByRole('combobox', { name: /^Role/ }), 'admin');
    await user.keyboard('{ArrowDown}{Enter}');
    await user.click(within(dialog).getByRole('button', { name: 'Choose' }));

    const tree = await screen.findByRole('dialog', { name: t('panel.grants.node') });

    await user.click(within(tree).getByRole('row', { name: 'north' }));
    await user.click(within(tree).getByRole('button', { name: 'Choose' }));
    await user.click(within(dialog).getByRole('button', { name: t('panel.grants.submit_assign') }));

    await waitFor(() => {
      expect(within(dialog).getByRole('alert').textContent).toContain(
        t('panel.problem.step_up_required'),
      );
    });
    expect(screen.getByRole('dialog', { name: t('panel.grants.assign_title') })).toBeDefined();
  });

  test('says why a picker has no options when its read was refused', async () => {
    const refused: GrantPickersV1 = {
      ...PICKERS,
      actors: { rejection: { ...rejectedProblem('unauthorized').problem }, result: null },
    };
    const { user } = renderGrants({}, { pickers: refused });

    await user.click(screen.getByRole('button', { name: t('panel.grants.assign') }));

    const dialog = screen.getByRole('dialog', { name: t('panel.grants.assign_title') });

    await user.click(within(dialog).getByRole('combobox', { name: /Member of staff/ }));
    await user.keyboard('{ArrowDown}');

    expect(await screen.findByText(t('panel.problem.unauthorized'))).toBeDefined();
  });
});

describe('revoking a grant', () => {
  test("asks first, then runs grant.revoke at the grant's version and tells the person", async () => {
    const { user, recorded } = renderGrants(
      {},
      {
        answering: { runCommand: () => Promise.resolve(committedReceipt()) },
      },
    );

    await user.click(
      screen.getByRole('button', {
        name: t('panel.grants.row_actions', { role: 'admin', actor: 'Ada Admin' }),
      }),
    );
    await user.click(await screen.findByRole('menuitem', { name: t('panel.grants.revoke') }));

    const confirm = await screen.findByRole('alertdialog', {
      name: t('panel.grants.revoke_title'),
    });

    expect(confirm.textContent).toContain('Ada Admin');
    expect(recorded.commands).toHaveLength(0);

    await user.click(
      within(confirm).getByRole('button', { name: t('panel.grants.revoke_confirm') }),
    );

    await waitFor(() => {
      expect(recorded.commands).toHaveLength(1);
    });
    expect(recorded.commands[0]?.command).toBe('grant.revoke@1');
    expect(recorded.commands[0]?.document).toEqual({ grant: GRANT, version: 1 });
    expect(recorded.notices).toEqual([t('panel.grants.revoked')]);
    await waitFor(() => {
      expect(screen.getByRole('status').textContent).toContain('Saved');
    });
  });

  test('shows the problem of a refused revocation on the page', async () => {
    const { user } = renderGrants(
      {},
      {
        answering: {
          runCommand: () => Promise.resolve(rejectedProblem('grant_escalation_refused')),
        },
      },
    );

    await user.click(
      screen.getByRole('button', {
        name: t('panel.grants.row_actions', { role: 'admin', actor: 'Ada Admin' }),
      }),
    );
    await user.click(await screen.findByRole('menuitem', { name: t('panel.grants.revoke') }));
    await user.click(
      within(await screen.findByRole('alertdialog')).getByRole('button', {
        name: t('panel.grants.revoke_confirm'),
      }),
    );

    await waitFor(() => {
      expect(screen.getByRole('alert').textContent).toContain(
        t('panel.problem.grant_escalation_refused'),
      );
    });
  });
});
