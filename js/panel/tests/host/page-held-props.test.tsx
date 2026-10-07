// @vitest-environment jsdom

// Points whose props the page holds (RenderedPoint::heldByPage() in PHP): the server sends their
// fills with no props, and the page hands the host the props it built, which the host gives each
// contribution of a slot or a decorator in place of the server's; a fill of such a point renders
// nothing until the page gives them. A replacement that reads data gets its data query's result
// beside the page's props.

import type { Decorator, DataState, SlotProps } from '@cboxdk/cms-panel/extend';
import { screen } from '@testing-library/react';
import { describe, expect, test } from 'vitest';

import { PointHost } from '../../src/host';
import { contributions, fill, lazy, point, registration, renderHost } from './harness';

interface Receipt {
  readonly outcome: string;
}

/** The texts the test's contributions render, which no catalogue holds. */
const OWN = {
  sees: 'Section sees',
  decorated: 'Decorated',
  picked: 'Picked',
  withData: 'with data',
  receipt: 'The receipt',
  fallback: 'Default',
} as const;

function Section({ props }: SlotProps<Receipt>) {
  return <p>{[OWN.sees, props.outcome].join(' ')}</p>;
}

const decorate: Decorator<Receipt> = (props) => ({
  after: <p>{[OWN.decorated, props.outcome].join(' ')}</p>,
});

function Picked({ value, data }: { readonly value: string; readonly data: DataState<unknown> }) {
  return <p>{[OWN.picked, value, OWN.withData, data.status].join(' ')}</p>;
}

describe('a point whose props the page holds', () => {
  test("hands a slot's contributions the page's props, and renders nothing until the page gives them", async () => {
    const options = {
      contributions: contributions(
        [point('form.dryrun@1', [fill('acme', 'acme.section', 1000, { props: null })])],
        { acme: ['acme.section'] },
      ),
      registrations: { acme: registration({ 'acme.section': lazy(Section) }) },
    };
    const { unmount } = renderHost(
      <PointHost point="form.dryrun@1" props={{ outcome: 'committed' }} />,
      options,
    );

    expect(await screen.findByText(`${OWN.sees} committed`)).toBeTruthy();
    unmount();

    renderHost(<PointHost point="form.dryrun@1" />, options);
    await new Promise((resolve) => {
      setTimeout(resolve, 20);
    });
    expect(screen.queryByText(new RegExp(OWN.sees))).toBeNull();
  });

  test("hands a decorator the page's props, and renders the default once", async () => {
    renderHost(
      <PointHost
        point="form.receipt@1"
        props={{ outcome: 'dry_run' }}
        render={() => <p>{OWN.receipt}</p>}
      />,
      {
        contributions: contributions(
          [
            point(
              'form.receipt@1',
              [
                fill('acme', 'acme.after', 1000, {
                  kind: 'decorator',
                  props: null,
                  decorator: { tightens: [] },
                }),
              ],
              { kind: 'decorator', region: null },
            ),
          ],
          { acme: ['acme.after'] },
        ),
        registrations: { acme: registration({ 'acme.after': decorate }) },
      },
    );

    expect(await screen.findByText(`${OWN.decorated} dry_run`)).toBeTruthy();
    expect(screen.getAllByText(OWN.receipt)).toHaveLength(1);
  });

  test("hands a replacement that reads data its query's result beside the page's props", async () => {
    renderHost(
      <PointHost
        point="form.field@1"
        target="acme:stars"
        props={{ value: '4' }}
        fallback={<p>{OWN.fallback}</p>}
      />,
      {
        contributions: contributions(
          [
            point(
              'form.field@1',
              [
                fill('acme', 'acme.stars', 1000, {
                  kind: 'replacement',
                  props: null,
                  data: true,
                  replacement: { key: 'acme:stars' },
                }),
              ],
              { kind: 'replacement', region: null, multiplicity: 'exclusive' },
            ),
          ],
          { acme: ['acme.stars'] },
        ),
        registrations: { acme: registration({ 'acme.stars': lazy(Picked) }) },
        ext: { acme: { 'acme.stars': [1, 2, 3] } },
      },
    );

    expect(await screen.findByText(`${OWN.picked} 4 ${OWN.withData} ready`)).toBeTruthy();
  });
});
