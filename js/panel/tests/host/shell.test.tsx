// @vitest-environment jsdom

// Nav entries and pages (section 3.4 of the panel extension architecture): a nav entry is data, an
// entry of the shell's navigation that opens a page of its addon, which the server lists among
// the pages only when the viewer may open it; the entries are the command palette's pages too. A
// page point renders the addon's page component of the page shown, with its data from the
// deferred prop of its addon, and nothing for a page the server did not list.

import type { PageProps } from '@cboxdk/cms-panel/extend';
import { screen, waitFor } from '@testing-library/react';
import { describe, expect, test } from 'vitest';

import {
  navEntries,
  paletteEntries,
  PointHost,
  usePointHost,
  type PointHandle,
} from '../../src/host';
import { contributions, fill, lazy, point, registration, renderHost } from './harness';

function nav(addon: string, id: string, priority: number, page: string, label: string) {
  return fill(addon, id, priority, {
    kind: 'nav',
    props: {},
    nav: { icon: 'inbox', label, page },
  });
}

/** The page's contributions: two nav entries, one to a page the server lists and one to none. */
function shell() {
  return contributions(
    [
      point(
        'shell.nav@1',
        [
          nav('beta', 'beta.link', 200, 'beta.queue', 'beta.nav'),
          nav('alpha', 'alpha.link', 100, 'alpha.board', 'alpha.nav'),
          nav('alpha', 'alpha.lost', 50, 'alpha.gone', 'alpha.lost'),
        ],
        { kind: 'nav', region: null },
      ),
      point(
        'shell.page@1',
        [
          fill('alpha', 'alpha.board', 1000, { kind: 'page', props: {}, data: true }),
          fill('beta', 'beta.queue', 1000, { kind: 'page', props: {} }),
        ],
        { kind: 'page', region: null },
      ),
    ],
    { alpha: ['alpha.board'], beta: ['beta.queue'] },
    {
      pages: [
        { page: 'alpha.board', url: '/cms/x/alpha/board' },
        { page: 'beta.queue', url: '/cms/x/beta/queue' },
        { page: 'home', url: '/cms' },
      ],
    },
  );
}

const TEXTS = { 'alpha.nav': 'Board', 'beta.nav': 'Queue', 'alpha.lost': 'Lost' };

/** The text the page of the test renders, as its catalogue would give it. */
const TEXT = { board: 'Board of' } as const;

function Probe({ onHandle }: { readonly onHandle: (handle: PointHandle) => void }) {
  onHandle(usePointHost('shell.nav@1'));

  return null;
}

function Board({ data }: PageProps<{ readonly count: number }>) {
  return (
    <p data-testid="board">
      {data.status === 'ready' ? [TEXT.board, String(data.value.count)].join(' ') : data.status}
    </p>
  );
}

describe('nav entries', () => {
  test('are the nav point s entries in render order, each with its text and the address of its page, leaving out one whose page the server did not list', () => {
    const text = (_addon: string, key: string) => TEXTS[key as keyof typeof TEXTS];
    const entries = navEntries(shell(), 'shell.nav@1', text);

    expect(entries.map((entry) => [entry.id, entry.label, entry.page, entry.url])).toEqual([
      ['alpha.link', 'Board', 'alpha.board', '/cms/x/alpha/board'],
      ['beta.link', 'Queue', 'beta.queue', '/cms/x/beta/queue'],
    ]);
    expect(paletteEntries(shell(), 'shell.nav@1', text)).toEqual([
      {
        id: 'alpha.link',
        kind: 'page',
        label: 'Board',
        page: 'alpha.board',
        url: '/cms/x/alpha/board',
      },
      {
        id: 'beta.link',
        kind: 'page',
        label: 'Queue',
        page: 'beta.queue',
        url: '/cms/x/beta/queue',
      },
    ]);
    expect(navEntries(shell(), 'shell.page@1', text)).toEqual([]);
  });

  test('come from the point s handle, with their texts from the addons catalogues', () => {
    let handle: PointHandle | undefined;
    renderHost(<Probe onHandle={(received) => (handle = received)} />, {
      contributions: shell(),
      registrations: {},
      texts: TEXTS,
    });

    expect(handle?.kind).toBe('nav');
    expect(handle?.nav.map((entry) => entry.label)).toEqual(['Board', 'Queue']);
  });
});

describe('a page point', () => {
  test('renders the addon s page component of the page shown with its data, in its scope', async () => {
    renderHost(<PointHost point="shell.page@1" page="alpha.board" />, {
      contributions: shell(),
      registrations: { alpha: registration({ 'alpha.board': lazy(Board) }) },
      ext: { alpha: { 'alpha.board': { count: 3 } } },
    });

    await waitFor(() => {
      expect(screen.getByTestId('board').textContent).toBe('Board of 3');
    });
    expect(
      screen.getByTestId('board').closest('[data-cms-contribution="alpha.board"]'),
    ).not.toBeNull();
    expect(screen.queryByText(/Queue/)).toBeNull();
  });

  test('renders nothing for a page the server did not list, or for the wrong kind of point', () => {
    const { container } = renderHost(
      <>
        <PointHost point="shell.page@1" page="alpha.gone" />
        <PointHost point="shell.nav@1" page="alpha.board" />
      </>,
      {
        contributions: shell(),
        registrations: { alpha: registration({ 'alpha.board': lazy(Board) }) },
      },
    );

    expect(container.textContent).toBe('');
  });
});

describe('the shell s form action', () => {
  test('opens the form of the action s command below the commands address', async () => {
    const { commandFormUrl } = await import('../../src/shell/PanelShell');

    expect(commandFormUrl('/cms/commands', 'entry.create@1')).toBe('/cms/commands/entry.create/v1');
    expect(commandFormUrl('/cms/commands', 'grant.assign@3')).toBe('/cms/commands/grant.assign/v3');
    expect(commandFormUrl('/cms/commands', 'entry.create')).toBe('/cms/commands/entry.create/v1');
  });
});
