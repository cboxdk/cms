// @vitest-environment jsdom

// Decorators (section 3.5 of the panel extension architecture): a decorator is a function of its
// target's props and never receives the default, so it cannot remove it; the host renders the
// default exactly once, whatever the decorators answer, throw or tighten, and combines their
// tightening most restrictively: a disabled reason only disables, a description is appended, and a
// tone only moves towards danger. Undeclared tightening is passed over and reported.

import type { Decorator } from '@cboxdk/cms-panel/extend';
import { screen, waitFor } from '@testing-library/react';
import { describe, expect, test } from 'vitest';

import { PointHost, type Tightened } from '../../src/host';
import { compose } from '../../src/host/decorators';
import { codes, contributions, fill, point, registration, renderHost } from './harness';

/** The texts the decorators render, as an addon's catalogue would give them. */
const TEXT = {
  before: 'Before alpha',
  after: 'After alpha',
  outerBefore: '[a',
  outerAfter: 'a]',
  innerBefore: '[b',
  innerAfter: 'b]',
} as const;

/** The default of the tests: the submit button, as the page renders it with the tightened props. */
function submit(tightened: Tightened) {
  return (
    <button
      type="submit"
      data-testid="default"
      data-tone={tightened.tone}
      disabled={tightened.disabled}
    >
      {['Save', ...tightened.descriptions.map((description) => description.text)].join(' | ')}
    </button>
  );
}

function decorated(
  decorators: Readonly<
    Record<string, Decorator<object, 'disabled_reason' | 'description' | 'tone_towards_danger'>>
  >,
  tightens: Readonly<
    Record<string, readonly ('disabled_reason' | 'description' | 'tone_towards_danger')[]>
  >,
) {
  const ids = Object.keys(decorators);

  return {
    contributions: contributions(
      [
        point(
          'form.submit@1',
          ids.map((id, index) =>
            fill(id.split('.')[0] ?? '', id, (index + 1) * 100, {
              kind: 'decorator',
              decorator: { tightens: tightens[id] ?? [] },
            }),
          ),
          { kind: 'decorator', region: null },
        ),
      ],
      Object.fromEntries(ids.map((id) => [id.split('.')[0] ?? '', [id]])),
    ),
    registrations: Object.fromEntries(
      ids.map((id) => [id.split('.')[0] ?? '', registration({ [id]: decorators[id] as never })]),
    ),
  };
}

describe('a decorator', () => {
  test('cannot remove the default: it gets only the props, and the default renders once whatever it answers', async () => {
    const received: unknown[] = [];
    const { recorded } = renderHost(<PointHost point="form.submit@1" render={submit} />, {
      ...decorated(
        {
          'alpha.wrap': (props) => {
            received.push(props);

            return { before: <p>{TEXT.before}</p>, after: <p>{TEXT.after}</p> };
          },
          'beta.drop': () => ({ before: null, after: null }),
          'gamma.throw': () => {
            throw new Error('No.');
          },
          'delta.junk': () => 'not a decoration' as never,
        },
        {},
      ),
    });

    expect(await screen.findByText(TEXT.before)).toBeTruthy();
    await waitFor(() => {
      expect(codes(recorded).sort()).toEqual([
        'panel_decorator_failed delta delta.junk',
        'panel_decorator_failed gamma gamma.throw',
      ]);
    });
    expect(screen.getAllByTestId('default')).toHaveLength(1);
    // Called on each render, with the props alone, frozen: never the default.
    expect(received.length).toBeGreaterThan(0);
    expect(
      received.every(
        (props) => JSON.stringify(props) === '{"note":"Weekly desk"}' && Object.isFrozen(props),
      ),
    ).toBe(true);
  });

  test('renders the default while the decorators load, and before and after it, the first decorator outermost', async () => {
    const { container } = renderHost(<PointHost point="form.submit@1" render={submit} />, {
      ...decorated(
        {
          'alpha.outer': () => ({
            before: <span>{TEXT.outerBefore}</span>,
            after: <span>{TEXT.outerAfter}</span>,
          }),
          'beta.inner': () => ({
            before: <span>{TEXT.innerBefore}</span>,
            after: <span>{TEXT.innerAfter}</span>,
          }),
        },
        {},
      ),
    });

    expect(screen.getAllByTestId('default')).toHaveLength(1);
    await screen.findByText(TEXT.outerBefore);
    expect(container.textContent).toBe('[a[bSaveb]a]');
  });

  test('tightening combines most restrictively, and undeclared tightening is passed over and reported', async () => {
    const { recorded } = renderHost(
      <PointHost point="form.submit@1" tone="info" render={submit} />,
      {
        ...decorated(
          {
            'alpha.guard': () => ({
              tighten: { disabled_reason: 'alpha.self', tone_towards_danger: 'warning' },
            }),
            'beta.note': () => ({
              tighten: { description: 'beta.note', tone_towards_danger: 'danger' },
            }),
            'gamma.loose': () => ({
              tighten: { description: 'gamma.extra', disabled_reason: 'gamma.no' },
            }),
            'delta.calm': () => ({
              tighten: { tone_towards_danger: 'warning' },
              badge: { tone: 'info', label: 'delta.badge' },
            }),
          },
          {
            'alpha.guard': ['disabled_reason', 'tone_towards_danger'],
            'beta.note': ['description', 'tone_towards_danger'],
            'gamma.loose': ['description'],
            'delta.calm': ['tone_towards_danger'],
          },
        ),
        texts: {
          'beta.note': 'Needs a second pair of eyes',
          'gamma.extra': 'Logged',
          'delta.badge': 'Watched',
        },
      },
    );

    await waitFor(() => {
      expect(screen.getByTestId('default').textContent).toBe(
        'Save | Needs a second pair of eyes | Logged',
      );
    });
    expect(screen.getByText('Watched')).toBeTruthy();
    const button = screen.getByTestId('default');
    expect(button.getAttribute('data-tone')).toBe('danger');
    expect(button.hasAttribute('disabled')).toBe(true);
    expect(button.textContent).toBe('Save | Needs a second pair of eyes | Logged');
    expect(codes(recorded)).toEqual(['panel_decorator_tightening_refused gamma gamma.loose']);
  });
});

describe('compose()', () => {
  test('never loosens: a tone only moves up, and no decorator enables what another disabled', () => {
    const composition = compose(
      'danger',
      [
        {
          addon: 'a',
          contribution: 'a.one',
          tightens: ['disabled_reason'],
          decoration: { tighten: { disabled_reason: 'a.why' } },
        },
        {
          addon: 'b',
          contribution: 'b.two',
          tightens: ['tone_towards_danger', 'disabled_reason'],
          decoration: { tighten: { tone_towards_danger: 'warning' } },
        },
      ],
      (_addon, key) => `text of ${key}`,
      () => undefined,
    );

    expect(composition.tightened).toEqual({
      disabled: true,
      disabledReasons: [{ addon: 'a', text: 'text of a.why' }],
      descriptions: [],
      tone: 'danger',
    });
  });
});
