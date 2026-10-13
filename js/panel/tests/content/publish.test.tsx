// @vitest-environment jsdom

// The editor's publish panel (PRD 5.6, 5.7, 5.9, 6.4): the entry's placements by path and locale;
// the revision a publish releases and whether a release is needed; the steps of a publish,
// entry.publish for the home placement and variant.release then placement.set_window for any
// other; the documents of placement.create and entry.unpublish; a window from the browser's date
// and time controls; and the panel itself, which tries a publish as a dry run, names the path that
// becomes visible, and runs it for real only once the person confirms.

import type { CommandAnswer } from '@cboxdk/cms-panel/extend';
import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { beforeEach, describe, expect, test, vi } from 'vitest';

import {
  fromLocalDateTime,
  isLocale,
  isSlug,
  needsRelease,
  placeableNodes,
  placementRows,
  placeStep,
  publishSteps,
  revisionToRelease,
  rowPath,
  unpublishStep,
  windowOf,
} from '../../src/content/publish';
import type { ActionListV1 } from '../../src/generated/protocol/ActionListV1';
import type { DryRunSummaryV1 } from '../../src/generated/protocol/DryRunSummaryV1';
import type { EntryDetailV1, EntryHeadV1 } from '../../src/generated/protocol/EntryDetailV1';
import type { ListedNodeV1 } from '../../src/generated/protocol/NodeListV1';
import type { ReceiptV1 } from '../../src/generated/protocol/ReceiptV1';
import type { TypeEditorV1 } from '../../src/generated/protocol/TypeEditorV1';
import { translator } from '../../src/i18n/translations';
import { PublishPanel } from '../../src/pages/Content/Publish';
import { contributions, renderHost } from '../host/harness';

const inertia = vi.hoisted(() => ({
  page: { props: {}, url: '/cms/content/shop:product' },
  router: { post: vi.fn(), visit: vi.fn(), reload: vi.fn(), on: vi.fn(() => () => undefined) },
}));

vi.mock('@inertiajs/react', () => ({
  Head: () => null,
  router: inertia.router,
  usePage: () => inertia.page,
}));

const t = translator('en');

const ENTRY = '0192a0c0-0000-7000-8000-0000000009e1';
const SITE = '0192a0c0-0000-7000-8000-0000000009f1';
const ROOT = '0192a0c0-0000-7000-8000-0000000009a1';
const SECTION = '0192a0c0-0000-7000-8000-0000000009a2';
const MOUNT = '0192a0c0-0000-7000-8000-0000000009a3';
const HOME_PLACEMENT = '0192a0c0-0000-7000-8000-0000000009b1';
const OTHER_PLACEMENT = '0192a0c0-0000-7000-8000-0000000009b2';
const CHANGESET = '0192a0c0-0000-7000-8000-0000000009c1';

const DESCRIPTOR = {
  capabilities: { history: 'full', localization: 'none', routable: true, stages: 'draft-release' },
} as TypeEditorV1;

const NODES: readonly ListedNodeV1[] = [
  { id: ROOT, kind: 'site', label: 'north', parent: null, site: SITE, site_handle: 'north' },
  {
    id: SECTION,
    kind: 'section',
    label: 'north/news',
    parent: ROOT,
    site: SITE,
    site_handle: 'north',
  },
  {
    id: MOUNT,
    kind: 'mount',
    label: 'north/mount',
    parent: ROOT,
    site: SITE,
    site_handle: 'north',
  },
];

function head(changes: Partial<EntryHeadV1> = {}): EntryHeadV1 {
  return {
    changed: null,
    fields: {},
    latest: 1,
    released: null,
    revision: 1,
    schema_version: 1,
    stale: false,
    state: 'unreleased',
    translated_from: null,
    variant: 'shared',
    version: 3,
    ...changes,
  };
}

const DETAIL: EntryDetailV1 = {
  heads: [head()],
  home: ROOT,
  id: ENTRY,
  lifecycle: 'active',
  placements: [
    {
      id: HOME_PLACEMENT,
      locales: [
        { canonical: true, locale: 'da', slug: 'rowing', visibility: 'hidden', window: null },
      ],
      node: ROOT,
      version: 2,
    },
    {
      id: OTHER_PLACEMENT,
      locales: [
        { canonical: false, locale: 'da', slug: 'rowing', visibility: 'hidden', window: null },
      ],
      node: SECTION,
      version: 1,
    },
  ],
  type: '0192a0c0-0000-7000-8000-0000000009d1',
  version: 1,
};

const ROWS = placementRows(DETAIL, NODES);
const NOW = new Date('2026-10-13T10:00:00Z');

function receipt(outcome: ReceiptV1['outcome']): ReceiptV1 {
  const done = outcome === 'committed';

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

const SUMMARY: DryRunSummaryV1 = {
  becomes_visible: [
    { from: '2026-10-13T10:00:00.000000Z', locale: 'da', placement: HOME_PLACEMENT },
  ],
  blast_radius: { aggregates: [{ count: 1, kind: 'placement' }], mutations: 2 },
  changes: [],
};

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

beforeEach(() => {
  inertia.router.post.mockReset();
});

describe('what the panel reads and sends', () => {
  test('every locale of every placement by its path, and whether it is the home placement', () => {
    expect(ROWS.map((row) => [row.path, row.locale.locale, row.home])).toEqual([
      ['north/rowing', 'da', true],
      ['north/news/rowing', 'da', false],
    ]);
    expect(rowPath(ROWS, OTHER_PLACEMENT, 'da')).toBe('north/news/rowing');
    expect(rowPath(ROWS, OTHER_PLACEMENT, 'en')).toBeUndefined();
    expect(placeableNodes(NODES).map((node) => node.id)).toEqual([ROOT, SECTION]);
  });

  test('releases the draft, or the released revision when nothing was written after it, and nothing without stages', () => {
    expect(revisionToRelease(DESCRIPTOR, head())).toBe(1);
    expect(revisionToRelease(DESCRIPTOR, head({ latest: 2, released: 2, state: 'released' }))).toBe(
      2,
    );
    expect(
      revisionToRelease(
        DESCRIPTOR,
        head({ revision: 3, latest: 3, released: 2, state: 'released' }),
      ),
    ).toBe(3);
    expect(
      revisionToRelease(
        { ...DESCRIPTOR, capabilities: { ...DESCRIPTOR.capabilities, stages: 'none' } },
        head(),
      ),
    ).toBeNull();
    expect(needsRelease(DESCRIPTOR, head())).toBe(true);
    expect(needsRelease(DESCRIPTOR, head({ latest: 2, released: 2, state: 'released' }))).toBe(
      false,
    );
    expect(
      needsRelease(DESCRIPTOR, head({ revision: 3, latest: 3, released: 2, state: 'released' })),
    ).toBe(true);
  });

  test('publishes the home placement with entry.publish, and another with variant.release then placement.set_window', () => {
    const [home, other] = ROWS;
    const window = { live_from: '2026-11-01T08:00:00Z', live_until: null };

    expect(
      home === undefined ? null : publishSteps(DESCRIPTOR, DETAIL, head(), home, window, NOW),
    ).toEqual([
      {
        command: { name: 'entry.publish', version: 1 },
        document: {
          entry: ENTRY,
          locale: 'da',
          placement: HOME_PLACEMENT,
          placement_version: 2,
          revision: 1,
          version: 3,
          window,
        },
      },
    ]);
    expect(
      other === undefined ? null : publishSteps(DESCRIPTOR, DETAIL, head(), other, null, NOW),
    ).toEqual([
      {
        command: { name: 'variant.release', version: 1 },
        document: { entry: ENTRY, revision: 1, version: 3 },
      },
      {
        command: { name: 'placement.set_window', version: 1 },
        document: {
          locale: 'da',
          placement: OTHER_PLACEMENT,
          version: 1,
          window: { live_from: '2026-10-13T10:00:00.000Z', live_until: null },
        },
      },
    ]);
    expect(
      other === undefined
        ? null
        : publishSteps(
            DESCRIPTOR,
            DETAIL,
            head({ latest: 2, released: 2, state: 'released' }),
            other,
            window,
            NOW,
          )?.map((step) => step.command.name),
    ).toEqual(['placement.set_window']);
  });

  test('the documents of placement.create and entry.unpublish', () => {
    const [root] = NODES;

    expect(
      root === undefined ? null : placeStep(OTHER_PLACEMENT, DETAIL, root, ' rowing ', 'da'),
    ).toEqual({
      command: { name: 'placement.create', version: 1 },
      document: {
        entry: ENTRY,
        node: ROOT,
        placement: OTHER_PLACEMENT,
        site: SITE,
        slugs: [{ locale: 'da', slug: 'rowing' }],
      },
    });
    expect(
      root === undefined
        ? null
        : placeStep(OTHER_PLACEMENT, DETAIL, { ...root, site: null }, 'rowing', 'da'),
    ).toBeUndefined();
    expect(unpublishStep(DETAIL, head())).toEqual({
      command: { name: 'entry.unpublish', version: 1 },
      document: { entry: ENTRY, version: 3 },
    });
  });

  test('a window from the local date and time controls, and the slugs and locales the kernel takes', () => {
    expect(fromLocalDateTime('')).toBeNull();
    expect(fromLocalDateTime('2026-11-01T08:00')).toMatch(
      /^2026-11-01T08:00:00(Z|[+-]\d{2}:\d{2})$/,
    );
    expect(windowOf('', '')).toEqual({ window: null, problem: 'missing' });
    expect(windowOf('2026-11-02T08:00', '2026-11-01T08:00')).toEqual({
      window: null,
      problem: 'order',
    });
    expect(windowOf('', '2026-11-01T08:00').window?.live_from).toBeNull();
    expect([isSlug('rowing-at-dawn'), isSlug('a/b'), isSlug('..'), isSlug(' ')]).toEqual([
      true,
      false,
      false,
      false,
    ]);
    expect([isLocale('da'), isLocale('en-GB'), isLocale('english')]).toEqual([true, true, false]);
  });
});

describe('the panel', () => {
  function renderPanel(actions: ActionListV1, answer: (dryRun: boolean) => CommandAnswer) {
    inertia.page.props = { palette: { rejection: null, result: actions } };

    return renderHost(
      <PublishPanel descriptor={DESCRIPTOR} entry={DETAIL} head={head()} nodes={NODES} />,
      {
        contributions: contributions([], { cms: [] }, { pages: [] }),
        registrations: {},
        answering: { runCommand: (call) => Promise.resolve(answer(call.options.dryRun === true)) },
      },
    );
  }

  test('tries a publish as a dry run, names the path that becomes visible, and publishes once confirmed', async () => {
    const { recorded } = renderPanel(
      palette(
        'entry.publish@1',
        'placement.set_window@1',
        'variant.release@1',
        'placement.create@1',
      ),
      (dryRun) =>
        dryRun
          ? { receipt: receipt('dry_run'), problem: null, dryRun: SUMMARY }
          : { receipt: receipt('committed'), problem: null, dryRun: null },
    );

    fireEvent.click(
      screen.getByRole('button', {
        name: t('panel.publish.publish_label', { path: 'north/rowing', locale: 'da' }),
      }),
    );

    const dialog = await screen.findByRole('dialog');

    expect(
      within(dialog).getByText(
        t('panel.publish.becomes_visible_item', { path: 'north/rowing', locale: 'da' }),
      ),
    ).toBeDefined();
    expect(recorded.commands.map((call) => [call.command, call.options.dryRun ?? false])).toEqual([
      ['entry.publish@1', true],
    ]);

    fireEvent.click(within(dialog).getByRole('button', { name: t('panel.publish.confirm') }));

    await waitFor(() => {
      expect(recorded.commands.map((call) => [call.command, call.options.dryRun ?? false])).toEqual(
        [
          ['entry.publish@1', true],
          ['entry.publish@1', false],
        ],
      );
    });
  });

  test('shows a rejected dry run with its problem and runs nothing for real', async () => {
    const { recorded } = renderPanel(
      palette(
        'entry.publish@1',
        'placement.set_window@1',
        'variant.release@1',
        'placement.create@1',
      ),
      () => ({
        receipt: receipt('rejected'),
        problem: {
          code: 'agent_visibility_forbidden',
          detail: 'No.',
          errors: [{ code: 'agent_visibility_forbidden', detail: 'No.', field: null }],
          instance: null,
          retryable: false,
          status: 403,
          title: 'Forbidden',
          type: 'https://cms.cbox.dk/errors/agent_visibility_forbidden',
        },
        dryRun: null,
      }),
    );

    fireEvent.click(
      screen.getByRole('button', {
        name: t('panel.publish.publish_label', { path: 'north/news/rowing', locale: 'da' }),
      }),
    );

    await waitFor(() => {
      expect(screen.getByText('agent_visibility_forbidden')).toBeDefined();
    });
    expect(recorded.commands.map((call) => call.command)).toEqual(['variant.release@1']);
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  test('lets a person who may not publish see the placements and says what it takes', () => {
    renderPanel(palette(), () => ({ receipt: receipt('committed'), problem: null, dryRun: null }));

    expect(screen.getByText(t('panel.publish.read_only'))).toBeDefined();
    expect(
      screen
        .getByRole('button', {
          name: t('panel.publish.publish_label', { path: 'north/rowing', locale: 'da' }),
        })
        .hasAttribute('disabled'),
    ).toBe(true);
  });
});
