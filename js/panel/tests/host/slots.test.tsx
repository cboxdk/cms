// @vitest-environment jsdom

// The host's slots (sections 3.1, 3.2, 5.1 and 5.4 of the panel extension architecture): each
// contribution renders in its own boundary and scope, in render order by priority, then namespace,
// then id; one that throws leaves the others rendered and is reported with its addon; an addon
// whose registration does not match cms:build's renders none of its contributions and is reported
// as panel_addon_mismatch; and the structured regions take descriptors.

import { usePanelHost, type DataState, type SlotProps } from '@cboxdk/cms-panel/extend';
import { screen, waitFor, within } from '@testing-library/react';
import { describe, expect, test } from 'vitest';

import { PointHost } from '../../src/host';
import { renderOrder } from '../../src/host/model';
import { codes, contributions, fill, lazy, point, registration, renderHost } from './harness';

/** The texts the pages and contributions of the tests render, as a catalogue would give them. */
const TEXT = { desk: 'Desk', own: 'Own', ownPanel: 'Own panel', panelOf: 'Panel of' } as const;

function Card({ label }: { readonly label: string }) {
  return function Contributed({ props }: SlotProps<{ readonly note: string }>) {
    return (
      <p data-testid="card">
        {label}: {props.note}
      </p>
    );
  };
}

function Broken(): never {
  throw new Error('The card broke.');
}

describe('the order of contributions', () => {
  test('is priority with the lowest first, then the namespace, then the id, by code unit', () => {
    const fills = [
      fill('zeta', 'zeta.a', 100),
      fill('alpha', 'alpha.z', 100),
      fill('alpha', 'alpha.b', 100),
      fill('omega', 'omega.first', 10),
      fill('Alpha', 'Alpha.case', 100),
    ];

    expect(renderOrder(fills).map((entry) => entry.id)).toEqual([
      'omega.first',
      'Alpha.case',
      'alpha.b',
      'alpha.z',
      'zeta.a',
    ]);
  });

  test('holds on the page whatever order the document lists them in', async () => {
    renderHost(<PointHost point="desk.cards@1" />, {
      contributions: contributions(
        [
          point('desk.cards@1', [
            fill('beta', 'beta.one', 1000),
            fill('alpha', 'alpha.two', 1000),
            fill('cms', 'cms.first', 100),
          ]),
        ],
        { alpha: ['alpha.two'], beta: ['beta.one'], cms: ['cms.first'] },
      ),
      registrations: {
        alpha: registration({ 'alpha.two': lazy(Card({ label: 'Alpha' })) }),
        beta: registration({ 'beta.one': lazy(Card({ label: 'Beta' })) }),
        cms: registration({ 'cms.first': lazy(Card({ label: 'Core' })) }),
      },
    });

    await waitFor(() => {
      expect(screen.getAllByTestId('card').map((card) => card.textContent)).toEqual([
        'Core: Weekly desk',
        'Alpha: Weekly desk',
        'Beta: Weekly desk',
      ]);
    });
  });
});

describe('failure isolation', () => {
  test('a contribution that throws leaves the others rendered, and is reported with its addon and shown as its notice', async () => {
    const { recorded } = renderHost(<PointHost point="desk.cards@1" />, {
      contributions: contributions(
        [
          point('desk.cards@1', [
            fill('alpha', 'alpha.card', 100),
            fill('broken', 'broken.card', 200),
            fill('gamma', 'gamma.card', 300),
          ]),
        ],
        { alpha: ['alpha.card'], broken: ['broken.card'], gamma: ['gamma.card'] },
      ),
      registrations: {
        alpha: registration({ 'alpha.card': lazy(Card({ label: 'Alpha' })) }),
        broken: registration({ 'broken.card': lazy(Broken) }),
        gamma: registration({ 'gamma.card': lazy(Card({ label: 'Gamma' })) }),
      },
    });

    await waitFor(() => {
      expect(screen.getAllByTestId('card').map((card) => card.textContent)).toEqual([
        'Alpha: Weekly desk',
        'Gamma: Weekly desk',
      ]);
    });
    expect(screen.getByText('A part from broken could not be shown')).toBeTruthy();
    expect(screen.queryByText(/The card broke/)).toBeNull();
    expect(recorded.reports).toEqual([
      {
        code: 'panel_contribution_failed',
        addon: 'broken',
        point: 'desk.cards@1',
        contribution: 'broken.card',
      },
    ]);
  });

  test('a viewer with internal access sees what was thrown', async () => {
    renderHost(<PointHost point="desk.cards@1" />, {
      contributions: contributions(
        [point('desk.cards@1', [fill('broken', 'broken.card', 200)])],
        { broken: ['broken.card'] },
        { details: true },
      ),
      registrations: { broken: registration({ 'broken.card': lazy(Broken) }) },
    });

    expect(await screen.findByText('Error: The card broke.')).toBeTruthy();
  });

  test('a module that fails to import is a failed contribution', async () => {
    const { recorded } = renderHost(<PointHost point="desk.cards@1" />, {
      contributions: contributions([point('desk.cards@1', [fill('alpha', 'alpha.card', 100)])], {
        alpha: ['alpha.card'],
      }),
      registrations: {
        alpha: registration({ 'alpha.card': () => Promise.reject(new Error('Gone.')) }),
      },
    });

    expect(await screen.findByText('A part from alpha could not be shown')).toBeTruthy();
    expect(codes(recorded)).toEqual(['panel_contribution_failed alpha alpha.card']);
  });
});

describe('the runtime registration check', () => {
  test('a registration that does not match renders none of that addon s contributions and reports panel_addon_mismatch', async () => {
    const { recorded } = renderHost(
      <>
        <PointHost point="desk.cards@1" />
        <PointHost point="desk.aside@1" />
      </>,
      {
        contributions: contributions(
          [
            point('desk.cards@1', [
              fill('alpha', 'alpha.card', 100),
              fill('rogue', 'rogue.card', 200),
            ]),
            point('desk.aside@1', [fill('rogue', 'rogue.aside', 100)], { region: 'aside' }),
          ],
          // cms:build compiled rogue.card and rogue.aside; the bundle registers rogue.card and rogue.extra.
          { alpha: ['alpha.card'], rogue: ['rogue.aside', 'rogue.card'] },
        ),
        registrations: {
          alpha: registration({ 'alpha.card': lazy(Card({ label: 'Alpha' })) }),
          rogue: registration({
            'rogue.card': lazy(Card({ label: 'Rogue' })),
            'rogue.extra': lazy(Card({ label: 'Rogue extra' })),
          }),
        },
      },
    );

    await waitFor(() => {
      expect(screen.getAllByTestId('card').map((card) => card.textContent)).toEqual([
        'Alpha: Weekly desk',
      ]);
    });
    expect(screen.queryByText(/Rogue/)).toBeNull();
    expect(screen.queryByText(/could not/)).toBeNull();
    expect(codes(recorded)).toEqual(['panel_addon_mismatch rogue']);
  });

  test('so does a module that exports no registration, or one of another major of the panel API', async () => {
    const { recorded } = renderHost(<PointHost point="desk.cards@1" />, {
      contributions: contributions(
        [
          point('desk.cards@1', [
            fill('plain', 'plain.card', 100),
            fill('future', 'future.card', 200),
          ]),
        ],
        {
          future: ['future.card'],
          plain: ['plain.card'],
        },
      ),
      registrations: {
        plain: { 'plain.card': lazy(Card({ label: 'Plain' })) },
        future: {
          contributions: { 'future.card': lazy(Card({ label: 'Future' })) },
          ids: ['future.card'],
          sdk: { major: 2, minor: 0 },
        },
      },
    });

    await waitFor(() => {
      expect(codes(recorded).sort()).toEqual([
        'panel_addon_mismatch future',
        'panel_addon_mismatch plain',
      ]);
    });
    expect(screen.queryByTestId('card')).toBeNull();
  });

  test('an addon whose bundle cannot be loaded shows one notice and is reported', async () => {
    const { recorded } = renderHost(<PointHost point="desk.cards@1" />, {
      contributions: contributions(
        [point('desk.cards@1', [fill('away', 'away.one', 100), fill('away', 'away.two', 200)])],
        {
          away: ['away.one', 'away.two'],
        },
      ),
      registrations: {},
    });

    expect(await screen.findByText('away could not be loaded')).toBeTruthy();
    expect(screen.getAllByText('away could not be loaded')).toHaveLength(1);
    expect(codes(recorded)).toEqual(['panel_addon_unavailable away']);
  });
});

describe('a slot', () => {
  test('hands each contribution frozen props, its data and its own host', async () => {
    const seen: {
      readonly frozen: boolean;
      readonly data: DataState<unknown>;
      readonly text: string;
    }[] = [];

    function Reader({
      props,
      data,
    }: SlotProps<{ readonly note: string }, { readonly count: number }>) {
      const host = usePanelHost();
      seen.push({
        frozen: Object.isFrozen(props),
        data,
        text: host.t('alpha.heading', { count: 3 }),
      });

      return <p>{props.note}</p>;
    }

    renderHost(<PointHost point="desk.cards@1" />, {
      contributions: contributions(
        [point('desk.cards@1', [fill('alpha', 'alpha.card', 100, { data: true })])],
        { alpha: ['alpha.card'] },
      ),
      registrations: { alpha: registration({ 'alpha.card': lazy(Reader) }) },
      ext: { alpha: { 'alpha.card': { count: 3 } } },
      texts: { 'alpha.heading': 'Count {count}' },
    });

    expect(await screen.findByText('Weekly desk')).toBeTruthy();
    expect(seen.at(-1)).toEqual({
      frozen: true,
      data: { status: 'ready', value: { count: 3 } },
      text: 'Count 3',
    });
  });

  test('renders at most its maximum, and an exclusive slot one', async () => {
    const cards = registration({
      'alpha.one': lazy(Card({ label: 'One' })),
      'alpha.two': lazy(Card({ label: 'Two' })),
      'alpha.three': lazy(Card({ label: 'Three' })),
    });
    const fills = [
      fill('alpha', 'alpha.one', 1),
      fill('alpha', 'alpha.two', 2),
      fill('alpha', 'alpha.three', 3),
    ];

    renderHost(
      <>
        <section data-testid="max">
          <PointHost point="desk.cards@1" />
        </section>
        <section data-testid="exclusive">
          <PointHost point="desk.banner@1" />
        </section>
      </>,
      {
        contributions: contributions(
          [
            point('desk.cards@1', fills, { multiplicity: 'max', max: 2 }),
            point('desk.banner@1', fills, { multiplicity: 'exclusive' }),
          ],
          { alpha: ['alpha.one', 'alpha.three', 'alpha.two'] },
        ),
        registrations: { alpha: cards },
      },
    );

    await waitFor(() => {
      expect(
        within(screen.getByTestId('max'))
          .getAllByTestId('card')
          .map((card) => card.textContent),
      ).toEqual(['One: Weekly desk', 'Two: Weekly desk']);
    });
    expect(
      within(screen.getByTestId('exclusive'))
        .getAllByTestId('card')
        .map((card) => card.textContent),
    ).toEqual(['One: Weekly desk']);
  });

  test('wraps each contribution in its scope, which names its addon, point and contribution', async () => {
    const { container } = renderHost(<PointHost point="desk.cards@1" />, {
      contributions: contributions([point('desk.cards@1', [fill('alpha', 'alpha.card', 100)])], {
        alpha: ['alpha.card'],
      }),
      registrations: { alpha: registration({ 'alpha.card': lazy(Card({ label: 'Alpha' })) }) },
    });

    await screen.findByTestId('card');
    const scope = container.querySelector('[data-cms-contribution="alpha.card"]');
    expect(scope?.getAttribute('data-cms-addon')).toBe('alpha');
    expect(scope?.getAttribute('data-cms-point')).toBe('desk.cards@1');
    expect(scope?.contains(screen.getByTestId('card'))).toBe(true);
  });

  test('renders nothing for a point the server sent no contribution for, and reports a point of another kind', async () => {
    const { container, recorded } = renderHost(
      <>
        <PointHost point="desk.none@1" />
        <PointHost point="desk.actions@1" />
      </>,
      {
        contributions: contributions(
          [
            point('desk.actions@1', [fill('alpha', 'alpha.act', 100)], {
              kind: 'action',
              region: null,
            }),
          ],
          { alpha: [] },
        ),
        registrations: {},
      },
    );

    await waitFor(() => {
      expect(codes(recorded)).toEqual(['panel_point_kind_mismatch alpha alpha.act']);
    });
    expect(container.textContent).toBe('');
  });
});

describe('the structured regions', () => {
  test('a toolbar renders descriptors with the kit, overflowing buttons past its maximum into a menu', async () => {
    const pressed: string[] = [];
    const { user } = renderHost(<PointHost point="desk.header@1" />, {
      contributions: contributions(
        [
          point(
            'desk.header@1',
            [
              fill('alpha', 'alpha.env', 100),
              fill('alpha', 'alpha.one', 200),
              fill('alpha', 'alpha.two', 300),
              fill('alpha', 'alpha.none', 400),
            ],
            { region: 'toolbar', multiplicity: 'max', max: 1 },
          ),
        ],
        { alpha: ['alpha.env', 'alpha.none', 'alpha.one', 'alpha.two'] },
      ),
      registrations: {
        alpha: registration({
          'alpha.env': lazy(() => ({ kind: 'badge', label: 'alpha.staging', tone: 'warning' })),
          'alpha.one': lazy(() => ({
            kind: 'button',
            label: 'alpha.one',
            onPress: () => pressed.push('one'),
          })),
          'alpha.two': lazy(() => ({
            kind: 'button',
            label: 'alpha.two',
            onPress: () => pressed.push('two'),
          })),
          'alpha.none': lazy(() => null),
        }),
      },
      texts: { 'alpha.staging': 'Staging', 'alpha.one': 'First', 'alpha.two': 'Second' },
    });

    expect(await screen.findByText('Staging')).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'First' }));
    expect(screen.queryByRole('button', { name: 'Second' })).toBeNull();
    await user.click(screen.getByRole('button', { name: 'More actions' }));
    await user.click(await screen.findByRole('menuitem', { name: 'Second' }));
    expect(pressed).toEqual(['one', 'two']);
  });

  test('tabs come after the page s own, each contribution s panel in its boundary', async () => {
    function Panel({ props }: SlotProps<{ readonly note: string }>) {
      return <p>{[TEXT.panelOf, props.note].join(' ')}</p>;
    }

    const { user } = renderHost(
      <PointHost
        point="desk.tabs@1"
        label={TEXT.desk}
        tabs={[{ id: 'own', label: TEXT.own, content: <p>{TEXT.ownPanel}</p> }]}
      />,
      {
        contributions: contributions(
          [point('desk.tabs@1', [fill('alpha', 'alpha.tab', 100)], { region: 'tabs' })],
          { alpha: ['alpha.tab'] },
        ),
        registrations: {
          alpha: registration({ 'alpha.tab': lazy({ label: 'alpha.tab', component: Panel }) }),
        },
        texts: { 'alpha.tab': 'Approvals' },
      },
    );

    expect(screen.getByText('Own panel')).toBeTruthy();
    await user.click(await screen.findByRole('tab', { name: 'Approvals' }));
    expect(await screen.findByText('Panel of Weekly desk')).toBeTruthy();
    expect(screen.getAllByRole('tab').map((tab) => tab.textContent)).toEqual(['Own', 'Approvals']);
  });
});
