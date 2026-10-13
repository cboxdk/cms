// @vitest-environment jsdom

// The entry editor (PRD 5.4, 11.12, 13.4): what the page's reads give it, checked by their
// generated validators, from the form of a new entry or of an entry to why it shows none; the
// documents a save sends, entry.create and entry.revise version 1; the errors of a rejected save at
// the fields they are about; what a receipt says; the shortcut that saves; and the page itself,
// which saves an entry with Ctrl+Enter at the version it read with a new idempotency key per save,
// shows a rejection's error at its field, asks for the home of a new entry before it saves, and
// lets a person who may not save read the form.

import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { rejectedProblem } from '@cboxdk/cms-panel/testing';
import type { CommandAnswer } from '@cboxdk/cms-panel/extend';
import { act, fireEvent, screen, waitFor, within } from '@testing-library/react';
import { useState } from 'react';
import { beforeEach, describe, expect, test, vi } from 'vitest';

import {
  committed,
  createDocument,
  editorStateOf,
  editorTitleOf,
  entryUrl,
  isSaveShortcut,
  mayRun,
  newEntryUrl,
  reviseDocument,
  saveErrorsOf,
  saveStatusOf,
} from '../../src/content/editor';
import type { ContentEditorPageV1 } from '../../src/generated/pages/ContentEditorPageV1';
import type { ActionListV1 } from '../../src/generated/protocol/ActionListV1';
import { recoveryKey, RecoveryStore } from '../../src/content/recovery';
import type { EntryDetailV1, EntryHeadV1 } from '../../src/generated/protocol/EntryDetailV1';
import { validateProblemV1, type ProblemV1 } from '../../src/generated/protocol/ProblemV1';
import type { ReceiptV1 } from '../../src/generated/protocol/ReceiptV1';
import { validateTypeEditorV1, type TypeEditorV1 } from '../../src/generated/protocol/TypeEditorV1';
import { translator } from '../../src/i18n/translations';
import Editor from '../../src/pages/Content/Editor';
import { contributions, renderHost } from '../host/harness';

const inertia = vi.hoisted(() => ({
  page: { props: {}, url: '/cms/content/shop:product' },
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

/**
 * The comprehensive example's descriptor, shop:product, with its title field `name` alone in its
 * first section, so the form holds one required text field.
 */
function product(): TypeEditorV1 {
  const checked = validateTypeEditorV1(
    JSON.parse(
      readFileSync(
        join(
          import.meta.dirname,
          '../../../../packages/generators/tests/Descriptor/Fixtures/Comprehensive/editor.json',
        ),
        'utf8',
      ),
    ) as unknown,
  );

  if (!checked.valid) {
    throw new Error('The comprehensive example has no editor descriptor.');
  }

  const descriptor = checked.value;
  const tab = descriptor.layout.tabs[0];
  const section = tab?.sections[0];

  if (tab === undefined || section === undefined) {
    throw new Error('The comprehensive example has no first section.');
  }

  return {
    ...descriptor,
    fields: descriptor.fields.filter((field) => field.path === 'name'),
    layout: {
      sidebar: [],
      tabs: [{ ...tab, sections: [{ ...section, fields: [{ path: 'name', width: 'full' }] }] }],
    },
  };
}

const PRODUCT = product();

const ENTRY = '0192a0c0-0000-7000-8000-0000000008e1';

const ROOT = '0192a0c0-0000-7000-8000-0000000008a1';

const CHANGESET = '0192a0c0-0000-7000-8000-0000000008c1';

const DETAIL: EntryDetailV1 = {
  heads: [
    {
      changed: null,
      fields: { name: 'Oak table' },
      latest: 2,
      released: null,
      revision: 2,
      schema_version: 1,
      stale: false,
      state: 'unreleased',
      translated_from: null,
      variant: 'shared',
      version: 4,
    },
  ],
  home: ROOT,
  id: ENTRY,
  lifecycle: 'active',
  placements: [],
  type: PRODUCT.id,
  version: 1,
};

function read(result: unknown) {
  return { rejection: null, result: result as Record<string, never> };
}

function props(changes: Partial<ContentEditorPageV1> = {}): ContentEditorPageV1 {
  return {
    entry: ENTRY,
    entry_read: read(DETAIL),
    list: '/cms/content/shop:product',
    logout: '/cms/logout',
    nodes_read: null,
    type: 'shop:product',
    type_read: read({ type: PRODUCT }),
    ...changes,
  };
}

function receipt(outcome: ReceiptV1['outcome']): ReceiptV1 {
  const done = outcome === 'committed' || outcome === 'committed_wait_timeout';

  return {
    changeset_id: done ? CHANGESET : null,
    consistency_token: null,
    outcome,
    position: done ? '1000' : null,
    projections: [],
    retention_class: 'standard',
    wait_level: 'commit',
  };
}

/** The actions the person may run, as action.list lists them. */
function palette(...commands: string[]): ActionListV1 {
  return {
    actions: commands.map((command) => {
      const [name = '', version = '1'] = command.split('@');

      return {
        description: `${name}.`,
        kind: 'command',
        name,
        title: name,
        version: Number(version),
      };
    }),
    navigation: [],
  };
}

function renderEditor(
  page: ContentEditorPageV1,
  actions: ActionListV1,
  answer?: () => Promise<CommandAnswer>,
) {
  inertia.page.props = {
    ...page,
    brand: { login: null, logo: null, name: 'Cbox CMS' },
    palette: { rejection: null, result: actions },
    types: { content: '/cms/content', rejection: null, result: { types: [PRODUCT] } },
  };

  return renderHost(<Editor {...page} />, {
    contributions: contributions([], { cms: [] }, { pages: [] }),
    registrations: {},
    answering: answer === undefined ? {} : { runCommand: answer },
  });
}

/** A problem of validation_failed with the errors, checked by its generated validator. */
function problemWith(errors: readonly unknown[]): ProblemV1 {
  const { problem } = rejectedProblem('validation_failed');
  const checked = validateProblemV1({ ...problem, errors });

  if (!checked.valid) {
    throw new Error(`The problem is not one: ${checked.issue.reason}`);
  }

  return checked.value;
}

/** A problem of validation_failed with an error of the code at the path of the document. */
function rejectedAt(field: string, code: string): ProblemV1 {
  return problemWith([{ code, detail: 'The server says no.', field }]);
}

beforeEach(() => {
  inertia.router.visit.mockReset();
  inertia.router.post.mockReset();
  inertia.router.reload.mockReset();
  inertia.router.on.mockReset();
  inertia.router.on.mockImplementation(() => () => undefined);
  window.localStorage.clear();
});

describe('what the page reads', () => {
  test('the form of an entry: the descriptor, the entry and its shared head', () => {
    const state = editorStateOf(props());

    expect(state.status).toBe('ready');
    expect(state.status === 'ready' ? state.head?.version : null).toBe(4);
    expect(state.status === 'ready' ? state.nodes : 'no').toBeNull();
  });

  test('the form of a new entry with the nodes its home may be', () => {
    const state = editorStateOf(
      props({ entry: null, entry_read: null, nodes_read: read({ next: null, nodes: [] }) }),
    );

    expect(state.status === 'ready' ? [state.entry, state.nodes?.status] : null).toEqual([
      null,
      'ready',
    ]);
  });

  test('no form for a type it has not, an entry of another type, a type with languages, a refusal or an answer it cannot read', () => {
    expect(editorStateOf(props({ type_read: read({ type: null }) })).status).toBe('missing_type');
    expect(editorStateOf(props({ type: 'shop:other' })).status).toBe('missing_type');
    expect(editorStateOf(props({ entry_read: read({ ...DETAIL, type: ENTRY }) })).status).toBe(
      'missing_type',
    );
    expect(
      editorStateOf(
        props({
          type_read: read({
            type: {
              ...PRODUCT,
              capabilities: { ...PRODUCT.capabilities, localization: 'variants' },
            },
          }),
        }),
      ).status,
    ).toBe('localized');

    const { problem } = rejectedProblem('entry_not_found');

    expect(
      editorStateOf(props({ entry_read: { rejection: { ...problem }, result: null } })),
    ).toEqual({
      status: 'rejected',
      problem,
    });
    expect(editorStateOf(props({ entry_read: read({ heads: 'none' }) })).status).toBe('unreadable');
    expect(editorStateOf(props({ entry_read: read({ ...DETAIL, heads: [] }) })).status).toBe(
      'unreadable',
    );
  });
});

describe('what a save sends and what it says', () => {
  test('the documents of entry.create and entry.revise version 1', () => {
    expect(createDocument(ENTRY, PRODUCT, ROOT, { name: 'Chair' })).toEqual({
      entry: ENTRY,
      fields: { name: 'Chair' },
      home: ROOT,
      type: PRODUCT.id,
    });
    expect(createDocument('not an id', PRODUCT, ROOT, {})).toBeUndefined();

    const head = DETAIL.heads[0];

    expect(head === undefined ? null : reviseDocument(DETAIL, head, { name: 'Chair' })).toEqual({
      entry: ENTRY,
      fields: { name: 'Chair' },
      version: 4,
    });
  });

  test('the errors of a rejection at the fields, the home and the rest', () => {
    const errors = saveErrorsOf(
      problemWith([
        { code: 'validation_required', detail: 'Required.', field: 'command.fields.name' },
        { code: 'validation_hook_failed', detail: 'Not on a Sunday.', field: 'fields.colour' },
        { code: 'validation_too_long', detail: 'Too long.', field: 'fields[0]' },
        { code: 'validation_failed', detail: 'No such node.', field: 'command.home' },
        { code: 'version_conflict', detail: 'Changed.', field: null },
      ]),
      'en',
    );

    expect(errors).toEqual({
      fields: {
        name: t('panel.editor.issue.validation_required'),
        colour: 'Not on a Sunday.',
        '[0]': t('panel.editor.issue.validation_too_long'),
      },
      home: 'No such node.',
      other: 1,
    });
    expect(saveErrorsOf(null, 'en')).toEqual({ fields: {}, home: undefined, other: 0 });
  });

  test('what each outcome says', () => {
    expect(saveStatusOf(receipt('committed')).key).toBe('panel.editor.status.saved');
    expect(saveStatusOf(receipt('committed_wait_timeout')).key).toBe(
      'panel.editor.status.saved_pending',
    );
    expect(saveStatusOf(receipt('dry_run')).key).toBe('panel.editor.status.dry_run');
    expect(saveStatusOf(receipt('rejected')).tone).toBe('danger');
    expect([committed(receipt('committed_wait_timeout')), committed(receipt('rejected'))]).toEqual([
      true,
      false,
    ]);
  });

  test('the shortcut, the permission, the addresses and the title', () => {
    const key = { key: 'Enter', metaKey: false, ctrlKey: false, altKey: false, shiftKey: false };

    expect(isSaveShortcut({ ...key, metaKey: true })).toBe(true);
    expect(isSaveShortcut({ ...key, ctrlKey: true })).toBe(true);
    expect(isSaveShortcut(key)).toBe(false);
    expect(isSaveShortcut({ ...key, ctrlKey: true, shiftKey: true })).toBe(false);
    expect(
      mayRun(
        { status: 'ready', list: palette('entry.revise@1') },
        { name: 'entry.revise', version: 1 },
      ),
    ).toBe(true);
    expect(
      mayRun(
        { status: 'ready', list: palette('entry.revise@2') },
        { name: 'entry.revise', version: 1 },
      ),
    ).toBe(false);
    expect(mayRun({ status: 'unreadable' }, { name: 'entry.revise', version: 1 })).toBe(false);
    expect(entryUrl('/cms/content/shop:product/', ENTRY)).toBe(
      `/cms/content/shop:product/${ENTRY}`,
    );
    expect(newEntryUrl('/cms/content/shop:product')).toBe('/cms/content/shop:product/new');
    expect(editorTitleOf(PRODUCT, { name: ' ' })).toBeNull();
    expect(editorTitleOf(PRODUCT, { name: 'Chair' })).toBe('Chair');
  });
});

describe('the page', () => {
  test('saves an entry with Ctrl+Enter at the version it read, with a new key per save, and says it saved', async () => {
    const { recorded } = renderEditor(props(), palette('entry.revise@1'), () =>
      Promise.resolve({ receipt: receipt('committed'), problem: null, dryRun: null }),
    );

    expect(screen.getByRole('heading', { level: 1, name: 'Oak table' })).toBeDefined();
    fireEvent.change(screen.getByLabelText('Name', { exact: false }), {
      target: { value: 'Oak table, long' },
    });
    fireEvent.keyDown(window, { key: 'Enter', ctrlKey: true });

    await waitFor(() => {
      expect(screen.getByRole('status').textContent).toBe(t('panel.editor.status.saved'));
    });
    fireEvent.keyDown(window, { key: 'Enter', metaKey: true });
    await waitFor(() => {
      expect(recorded.commands).toHaveLength(2);
    });

    const [first, second] = recorded.commands;

    expect(first?.command).toBe('entry.revise@1');
    expect(first?.document).toEqual({
      entry: ENTRY,
      fields: { name: 'Oak table, long' },
      version: 4,
    });
    expect(first?.options).toEqual({ waitLevel: 'commit' });
    expect(first?.key).toMatch(/^[0-9a-f-]{36}$/);
    expect(second?.key).not.toBe(first?.key);
  });

  test('shows the error of a rejected save at its field', async () => {
    renderEditor(props(), palette('entry.revise@1'), () =>
      Promise.resolve({
        receipt: receipt('rejected'),
        problem: rejectedAt('command.fields.name', 'validation_too_long'),
        dryRun: null,
      }),
    );

    fireEvent.click(screen.getByRole('button', { name: new RegExp(t('panel.editor.save')) }));

    await waitFor(() => {
      expect(screen.getByRole('status').textContent).toBe(t('panel.editor.status.rejected'));
    });

    const input = screen.getByLabelText('Name', { exact: false });

    expect(input.getAttribute('aria-invalid')).toBe('true');
    expect(
      screen.getAllByText(t('panel.editor.issue.validation_too_long'), { exact: false }).length,
    ).toBeGreaterThan(0);
  });

  test('asks for the home of a new entry before it saves, and saves nothing without one', async () => {
    const { recorded } = renderEditor(
      props({
        entry: null,
        entry_read: null,
        nodes_read: read({
          next: null,
          nodes: [
            { id: ROOT, kind: 'site', label: 'shop', parent: null, site: null, site_handle: null },
          ],
        }),
      }),
      palette('entry.create@1'),
    );

    expect(
      screen.getByRole('heading', {
        level: 1,
        name: t('panel.editor.new_title', { type: PRODUCT.label }),
      }),
    ).toBeDefined();
    fireEvent.change(screen.getByLabelText('Name', { exact: false }), {
      target: { value: 'Chair' },
    });
    fireEvent.keyDown(window, { key: 'Enter', ctrlKey: true });

    await waitFor(() => {
      expect(screen.getByText(t('panel.editor.home_required'))).toBeDefined();
    });
    expect(recorded.commands).toHaveLength(0);
  });

  test('lets a person who may not save read the form, and says who can give the permission', () => {
    renderEditor(props(), palette());

    expect(screen.getByText(t('panel.editor.read_only_body'))).toBeDefined();
    expect(
      screen
        .getByRole('button', { name: new RegExp(t('panel.editor.save')) })
        .hasAttribute('disabled'),
    ).toBe(true);
  });

  test('shows a refused read with its code and the way back, and a type with languages with the way on', () => {
    const { problem } = rejectedProblem('entry_not_found');
    const { unmount } = renderEditor(
      props({ entry_read: { rejection: { ...problem }, result: null } }),
      palette('entry.revise@1'),
    );

    expect(screen.getByText('entry_not_found')).toBeDefined();
    expect(screen.getByRole('link', { name: t('panel.editor.back') }).getAttribute('href')).toBe(
      '/cms/content/shop:product',
    );
    unmount();

    renderEditor(
      props({
        type_read: read({
          type: { ...PRODUCT, capabilities: { ...PRODUCT.capabilities, localization: 'variants' } },
        }),
      }),
      palette(),
    );

    fireEvent.click(screen.getByRole('button', { name: t('panel.editor.localized_action') }));

    expect(inertia.router.visit).toHaveBeenCalledWith(expect.stringMatching(/entry\.create\/v2$/));
  });
});

/** The shared head of DETAIL as someone else's save left it: the next version, with their name. */
const SAVED_BY_OLE: EntryHeadV1 = {
  ...(DETAIL.heads[0] as EntryHeadV1),
  changed: {
    actor: '0192a0c0-0000-7000-8000-0000000008a7',
    at: '2026-10-13T12:05:00.000000Z',
    name: 'Ole Frost',
  },
  fields: { name: 'Oak table, by Ole' },
  revision: 3,
  latest: 3,
  version: 5,
};

/**
 * Renders the editor as Inertia keeps it: the props of the page, which the redirect after a save
 * replaces with the page read again, while the editor keeps its state.
 */
function renderLiveEditor(
  page: ContentEditorPageV1,
  actions: ActionListV1,
  answer: (show: (page: ContentEditorPageV1) => void) => Promise<CommandAnswer>,
) {
  let show: (page: ContentEditorPageV1) => void = () => undefined;

  function Live() {
    const [current, setCurrent] = useState(page);
    show = (next) => {
      act(() => {
        setCurrent(next);
      });
    };

    return <Editor {...current} />;
  }

  inertia.page.props = {
    ...page,
    brand: { login: null, logo: null, name: 'Cbox CMS' },
    palette: { rejection: null, result: actions },
    types: { content: '/cms/content', rejection: null, result: { types: [PRODUCT] } },
  };

  return renderHost(<Live />, {
    contributions: contributions([], { cms: [] }, { pages: [] }),
    registrations: {},
    answering: { runCommand: () => answer(show) },
  });
}

/** The editor's handler of Inertia's `before` event, which the leave guard subscribes. */
function beforeVisit(): (event: unknown) => void {
  const call = inertia.router.on.mock.calls.find((args: unknown[]) => args[0] === 'before') as
    [string, (event: unknown) => void] | undefined;

  if (call === undefined) {
    throw new Error('The editor subscribes to no before event.');
  }

  return call[1];
}

/** A visit inside the panel to the address, as Inertia's `before` event carries it. */
function visitEvent(url: string, changes: Record<string, unknown> = {}) {
  return {
    detail: {
      visit: {
        url: new URL(url, 'http://panel.test'),
        method: 'get',
        data: {},
        replace: false,
        preserveScroll: false,
        preserveState: false,
        only: [],
        prefetch: false,
        ...changes,
      },
    },
    preventDefault: vi.fn(),
  };
}

describe('a save someone else saved before', () => {
  test('says who saved, compares per field, and continues from their version with the edits to bring back', async () => {
    const { recorded } = renderLiveEditor(props(), palette('entry.revise@1'), (show) => {
      if (recorded.commands.length > 1) {
        return Promise.resolve({ receipt: receipt('committed'), problem: null, dryRun: null });
      }

      show(props({ entry_read: read({ ...DETAIL, heads: [SAVED_BY_OLE] }) }));

      return Promise.resolve(rejectedProblem('version_conflict', [{ code: 'version_conflict' }]));
    });
    const input = () => document.getElementById('entry-name') as HTMLInputElement;

    fireEvent.change(input(), { target: { value: 'Oak table, long' } });
    fireEvent.keyDown(window, { key: 'Enter', ctrlKey: true });

    const notice = await screen.findByText(t('panel.editor.conflict.title'));
    const conflict = notice.closest('.cms-callout') as HTMLElement;

    expect(within(conflict).getByText(/Ole Frost saved it at/)).toBeDefined();
    expect(
      within(conflict).getByText(t('panel.editor.conflict.theirs', { name: 'Ole Frost' })),
    ).toBeDefined();
    expect(within(conflict).getByText('Oak table, by Ole')).toBeDefined();
    expect(within(conflict).getByText('Oak table, long')).toBeDefined();
    expect(input().value).toBe('Oak table, long');
    expect(screen.queryByText('version_conflict')).toBeNull();

    fireEvent.click(
      within(conflict).getByRole('button', { name: t('panel.editor.conflict.reload') }),
    );

    expect(input().value).toBe('Oak table, by Ole');
    expect(screen.queryByText(t('panel.editor.conflict.title'))).toBeNull();
    expect(screen.getByText(t('panel.editor.compare.title'))).toBeDefined();

    fireEvent.click(
      screen.getByRole('button', { name: `${t('panel.editor.compare.use_mine')}: Name` }),
    );

    expect(input().value).toBe('Oak table, long');

    fireEvent.keyDown(window, { key: 'Enter', ctrlKey: true });
    await waitFor(() => {
      expect(recorded.commands).toHaveLength(2);
    });

    expect(recorded.commands[1]?.document).toEqual({
      entry: ENTRY,
      fields: { name: 'Oak table, long' },
      version: 5,
    });
  });

  test('offers to read the entry again when the saved version is not there yet, and names no one it may not', async () => {
    renderLiveEditor(props(), palette('entry.revise@1'), () =>
      Promise.resolve(rejectedProblem('version_conflict', [{ code: 'version_conflict' }])),
    );

    fireEvent.change(screen.getByLabelText('Name', { exact: false }), {
      target: { value: 'Oak table, long' },
    });
    fireEvent.keyDown(window, { key: 'Enter', ctrlKey: true });

    await screen.findByText(t('panel.editor.conflict.unread'));
    fireEvent.click(screen.getByRole('button', { name: t('panel.editor.conflict.reload') }));

    expect(inertia.router.reload).toHaveBeenCalledWith(
      expect.objectContaining({ only: ['entry_read'] }),
    );
  });
});

describe('the leave guard', () => {
  test('asks before a visit away from changes that are not saved, stays, and leaves on request', () => {
    renderEditor(props(), palette('entry.revise@1'));
    const before = beforeVisit();
    const untouched = visitEvent('/cms');

    before(untouched);

    expect(untouched.preventDefault).not.toHaveBeenCalled();

    fireEvent.change(screen.getByLabelText('Name', { exact: false }), {
      target: { value: 'Oak table, long' },
    });

    const away = visitEvent('/cms');
    act(() => {
      before(away);
    });

    expect(away.preventDefault).toHaveBeenCalled();
    expect(screen.getByRole('alertdialog', { name: t('panel.editor.leave.title') })).toBeDefined();

    fireEvent.click(screen.getByRole('button', { name: t('panel.editor.leave.cancel') }));

    expect(screen.queryByRole('alertdialog')).toBeNull();
    expect(inertia.router.visit).not.toHaveBeenCalled();

    const own = [
      visitEvent('/cms/commands/entry.revise/v1', { method: 'post' }),
      visitEvent('/cms/content/shop:product/x', { only: ['entry_read'] }),
      visitEvent('/cms', { prefetch: true }),
    ];

    for (const event of own) {
      before(event);
      expect(event.preventDefault).not.toHaveBeenCalled();
    }

    const again = visitEvent('/cms');
    act(() => {
      before(again);
    });
    fireEvent.click(screen.getByRole('button', { name: t('panel.editor.leave.confirm') }));

    expect(inertia.router.visit).toHaveBeenCalledWith(
      new URL('/cms', 'http://panel.test'),
      expect.objectContaining({ method: 'get' }),
    );
    expect(window.localStorage.length).toBe(0);
  });

  test('asks the browser before the tab closes or reloads with changes that are not saved', () => {
    renderEditor(props(), palette('entry.revise@1'));
    const unload = () => {
      const event = new Event('beforeunload', { cancelable: true });
      window.dispatchEvent(event);

      return event.defaultPrevented;
    };

    expect(unload()).toBe(false);

    fireEvent.change(screen.getByLabelText('Name', { exact: false }), {
      target: { value: 'Oak table, long' },
    });

    expect(unload()).toBe(true);

    fireEvent.change(screen.getByLabelText('Name', { exact: false }), {
      target: { value: 'Oak table' },
    });

    expect(unload()).toBe(false);
  });
});

describe('local recovery', () => {
  const kept = { viewer: null, type: 'shop:product', entry: ENTRY, version: 4 };

  test('keeps the edits of the entry at its version while they are not saved', () => {
    renderEditor(props(), palette('entry.revise@1'));

    fireEvent.change(screen.getByLabelText('Name', { exact: false }), {
      target: { value: 'Oak table, long' },
    });

    expect(new RecoveryStore(window.localStorage).find(kept)?.fields).toEqual({
      name: 'Oak table, long',
    });

    fireEvent.change(screen.getByLabelText('Name', { exact: false }), {
      target: { value: 'Oak table' },
    });

    expect(window.localStorage.getItem(recoveryKey(kept))).toBeNull();
  });

  test('offers the edits kept for the entry at its version, and restores them', () => {
    new RecoveryStore(window.localStorage).keep(kept, { name: 'Oak table, recovered' });
    renderEditor(props(), palette('entry.revise@1'));

    expect(screen.getByText(t('panel.editor.recovery.title'))).toBeDefined();
    expect(screen.getByLabelText<HTMLInputElement>('Name', { exact: false }).value).toBe(
      'Oak table',
    );

    fireEvent.click(screen.getByRole('button', { name: t('panel.editor.recovery.restore') }));

    expect(screen.getByLabelText<HTMLInputElement>('Name', { exact: false }).value).toBe(
      'Oak table, recovered',
    );
    expect(screen.queryByText(t('panel.editor.recovery.title'))).toBeNull();
    expect(new RecoveryStore(window.localStorage).find(kept)?.fields).toEqual({
      name: 'Oak table, recovered',
    });
  });

  test('discards the edits on request, and offers none of another version or another entry', () => {
    const store = new RecoveryStore(window.localStorage);
    store.keep(kept, { name: 'Oak table, recovered' });
    const { unmount } = renderEditor(props(), palette('entry.revise@1'));

    fireEvent.click(screen.getByRole('button', { name: t('panel.editor.recovery.discard') }));

    expect(screen.queryByText(t('panel.editor.recovery.title'))).toBeNull();
    expect(window.localStorage.getItem(recoveryKey(kept))).toBeNull();
    unmount();

    store.keep({ ...kept, version: 3 }, { name: 'Made on an older version' });
    store.keep({ ...kept, entry: '0192a0c0-0000-7000-8000-0000000008e2' }, { name: 'Another' });
    renderEditor(props(), palette('entry.revise@1'));

    expect(screen.queryByText(t('panel.editor.recovery.title'))).toBeNull();
    expect(window.localStorage.getItem(recoveryKey({ ...kept, version: 3 }))).toBeNull();
  });

  test('works without recovery when the browser refuses storage', () => {
    const descriptor = Object.getOwnPropertyDescriptor(window, 'localStorage');
    Object.defineProperty(window, 'localStorage', {
      configurable: true,
      get: () => {
        throw new DOMException('The operation is insecure.', 'SecurityError');
      },
    });

    try {
      renderEditor(props(), palette('entry.revise@1'));
      fireEvent.change(screen.getByLabelText('Name', { exact: false }), {
        target: { value: 'Oak table, long' },
      });

      expect(screen.getByLabelText<HTMLInputElement>('Name', { exact: false }).value).toBe(
        'Oak table, long',
      );
      expect(screen.queryByText(t('panel.editor.recovery.title'))).toBeNull();
    } finally {
      if (descriptor !== undefined) {
        Object.defineProperty(window, 'localStorage', descriptor);
      }
    }
  });
});
