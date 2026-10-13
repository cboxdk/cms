// @vitest-environment jsdom

// The content list (PRD 5.4, 13.4): the entries as entry.list answered them, each by the title
// field its type's descriptor names, with the type's label from the shared prop `types`, a pill of
// its state and its last change; the Content section of the navigation, one entry per type; the
// filter chips, which open the address of the filtered list; the density, kept in the browser;
// the create action of an empty list when the palette lists entry.create; and the rejected and
// unreadable states of the list.

import { rejectedProblem } from '@cboxdk/cms-panel/testing';
import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, test, vi } from 'vitest';

import { createAction, DENSITY_KEY, filteredUrl, statusOf, titleOf } from '../../src/content/list';
import { typeListUrl, typesOf } from '../../src/content/types';
import type { ContentListPageV1 } from '../../src/generated/pages/ContentListPageV1';
import type { ActionListV1 } from '../../src/generated/protocol/ActionListV1';
import type { EntryListV1, ListedEntryV1 } from '../../src/generated/protocol/EntryListV1';
import type { TypeEditorV1 } from '../../src/generated/protocol/TypeEditorV1';
import { translator } from '../../src/i18n/translations';
import List from '../../src/pages/Content/List';
import { contributions, renderHost } from '../host/harness';

const inertia = vi.hoisted(() => ({
  page: { props: {}, url: '/cms/content' },
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

const STORY = '0192a0c0-0000-7000-8000-0000000007a1';
const NOTE = '0192a0c0-0000-7000-8000-0000000007a2';

function editor(id: string, name: string, label: string, title: string | null): TypeEditorV1 {
  return {
    capabilities: {
      history: 'full',
      localization: 'none',
      routable: true,
      stages: 'draft-release',
    },
    description: null,
    extensions: [],
    fields: [],
    id,
    label,
    name,
    title_field: title,
    version: 1,
  };
}

const TYPES = [
  editor(STORY, 'app:story', 'Story', 'headline'),
  editor(NOTE, 'app:note', 'Note', null),
];

function entry(id: string, type: string, changes: Partial<ListedEntryV1> = {}): ListedEntryV1 {
  return {
    changed_at: '2026-03-10T12:00:00.000000Z',
    fields: {},
    home: '0192a0c0-0000-7000-8000-0000000007c1',
    id,
    lifecycle: 'active',
    state: 'unreleased',
    type,
    version: 1,
    ...changes,
  };
}

const HARBOUR = entry('0192a0c0-0000-7000-8000-0000000007e2', STORY, {
  fields: { headline: 'Harbour opens' },
  state: 'released',
});

const UNTITLED = entry('0192a0c0-0000-7000-8000-0000000007e1', NOTE);

const ENTRIES: EntryListV1 = {
  entries: [HARBOUR, UNTITLED],
  next: '0192a0c0-0000-7000-8000-0000000007e1',
};

/** The actions the person may run, as action.list lists them. */
function palette(...names: string[]): ActionListV1 {
  return {
    actions: names.map((name) => ({
      description: `${name}.`,
      kind: name.endsWith('.list') ? 'query' : 'command',
      name,
      title: name,
      version: name === 'entry.create' ? 2 : 1,
    })),
    navigation: [],
  };
}

function renderList(
  props: Partial<ContentListPageV1> = {},
  actions: ActionListV1 = palette('entry.list', 'entry.create'),
  types: unknown = { content: '/cms/content', rejection: null, result: { types: TYPES } },
) {
  const page: ContentListPageV1 = {
    logout: '/cms/logout',
    rejection: null,
    result: { ...ENTRIES },
    state: null,
    type: null,
    ...props,
  };
  inertia.page.props = {
    ...page,
    brand: { login: null, logo: null, name: 'Cbox CMS' },
    palette: { rejection: null, result: actions },
    types,
  };

  return renderHost(<List {...page} />, {
    contributions: contributions([], { cms: [] }, { pages: [] }),
    registrations: {},
  });
}

beforeEach(() => {
  inertia.page.url = '/cms/content';
  inertia.router.visit.mockReset();
  inertia.router.post.mockReset();
  window.localStorage.clear();
});

describe('what a row shows', () => {
  test('the title of the field the type names, and none for a type without a title field', () => {
    expect(titleOf(HARBOUR, TYPES[0])).toBe('Harbour opens');
    expect(titleOf(UNTITLED, TYPES[1])).toBeNull();
    expect(titleOf(entry(NOTE, STORY, { fields: { headline: '  ' } }), TYPES[0])).toBeNull();
    expect(titleOf(HARBOUR, undefined)).toBeNull();
  });

  test('the lifecycle when it is not active, and the release state otherwise', () => {
    expect(statusOf(entry(NOTE, NOTE, { state: 'released' })).key).toBe(
      'panel.content.state.released',
    );
    expect(statusOf(entry(NOTE, NOTE, { state: null })).key).toBe('panel.content.state.none');
    expect(statusOf(entry(NOTE, NOTE, { lifecycle: 'archived' })).key).toBe(
      'panel.content.lifecycle.archived',
    );
  });

  test('the addresses of the filtered lists and of a type', () => {
    expect(filteredUrl('/cms/content', null, null)).toBe('/cms/content');
    expect(filteredUrl('/cms/content', 'app:story', 'released')).toBe(
      '/cms/content/app:story?state=released',
    );
    expect(typeListUrl('/cms/content', 'app:note')).toBe('/cms/content/app:note');
  });

  test('the create action at the highest version the palette lists, or none', () => {
    expect(createAction({ status: 'ready', list: palette('entry.create') })).toBe('entry.create@2');
    expect(createAction({ status: 'ready', list: palette('entry.list') })).toBeNull();
    expect(createAction({ status: 'unreadable' })).toBeNull();
  });

  test('the types the prop gives, and unreadable for a descriptor the panel cannot read', () => {
    expect(typesOf({ content: '/cms/content', rejection: null, result: { types: TYPES } })).toEqual(
      {
        status: 'ready',
        content: '/cms/content',
        types: TYPES,
      },
    );
    expect(
      typesOf({ content: '/cms/content', rejection: null, result: { types: [{ id: 'x' }] } })
        .status,
    ).toBe('unreadable');
    expect(typesOf(null)).toEqual({ status: 'unreadable', content: null });
  });
});

describe('the list', () => {
  test('shows each entry by its title, its type and its state, and the Content section of the navigation', () => {
    renderList();

    const table = screen.getByRole('grid', { name: t('panel.content.title') });

    expect(within(table).getByText('Harbour opens')).toBeDefined();
    expect(within(table).getByText(t('panel.content.untitled'))).toBeDefined();
    expect(within(table).getByText('Story')).toBeDefined();
    expect(within(table).getByText(t('panel.content.state.released'))).toBeDefined();

    const navigation = screen.getByRole('navigation', { name: t('panel.shell.nav') });
    const links = within(navigation)
      .getAllByRole('link')
      .map((link) => [link.textContent, link.getAttribute('href')]);

    expect(links).toEqual([
      [t('panel.nav.content_all'), '/cms/content'],
      ['Story', '/cms/content/app:story'],
      ['Note', '/cms/content/app:note'],
    ]);
    expect(within(navigation).getByRole('link', { current: 'page' }).textContent).toBe(
      t('panel.nav.content_all'),
    );
  });

  test('opens the next page after the last entry shown', () => {
    renderList();

    fireEvent.click(screen.getByRole('button', { name: /Next/ }));

    expect(inertia.router.visit).toHaveBeenCalledWith(
      '/cms/content?after=0192a0c0-0000-7000-8000-0000000007e1',
      expect.anything(),
    );
  });

  test('shows the compact rows with the type in a column of its own, and keeps the choice', async () => {
    renderList();

    fireEvent.click(screen.getByRole('radio', { name: t('panel.content.density.compact') }));

    await waitFor(() => {
      expect(
        screen.getByRole('columnheader', { name: t('panel.content.column.type') }),
      ).toBeDefined();
    });
    expect(window.localStorage.getItem(DENSITY_KEY)).toBe('compact');
  });

  test('shows the empty list with the create action, and without it to a person who may not create', () => {
    renderList({ result: { entries: [], next: null } });

    expect(screen.getByRole('heading', { name: t('panel.content.empty_title') })).toBeDefined();
    expect(screen.getByText(t('panel.content.empty_body'))).toBeDefined();
    expect(screen.getAllByRole('button', { name: t('panel.content.create') })).toHaveLength(2);

    const [, empty] = screen.getAllByRole('button', { name: t('panel.content.create') });

    expect(empty).toBeDefined();
    fireEvent.click(empty as HTMLElement);

    expect(inertia.router.visit).toHaveBeenCalledWith('/cms/commands/entry.create/v2');
  });

  test('says so to a person who may not create entries', () => {
    renderList({ result: { entries: [], next: null }, state: 'withdrawn' }, palette('entry.list'));

    expect(screen.getByRole('heading', { name: t('panel.content.empty_filtered') })).toBeDefined();
    expect(screen.getByText(t('panel.content.empty_readonly'))).toBeDefined();
    expect(screen.queryByRole('button', { name: t('panel.content.create') })).toBeNull();
  });

  test('explains a rejected read with its code, and an answer it cannot read', () => {
    const { problem } = rejectedProblem('query_over_budget');
    const { unmount } = renderList({ result: null, rejection: { ...problem } });

    expect(screen.getByText('query_over_budget')).toBeDefined();
    expect(screen.queryByRole('grid')).toBeNull();
    unmount();

    renderList({ result: { entries: 'none' } });

    expect(screen.getByText(t('panel.access.unavailable_title'))).toBeDefined();
  });

  test('lists without the Content section when the types cannot be read, and names the type it cannot see', () => {
    renderList({}, palette('entry.list'), {
      content: '/cms/content',
      rejection: null,
      result: { types: [{}] },
    });

    expect(screen.queryByRole('link', { name: t('panel.nav.content_all') })).toBeNull();
    expect(screen.getAllByText(t('panel.content.unknown_type'))).toHaveLength(2);
  });
});
