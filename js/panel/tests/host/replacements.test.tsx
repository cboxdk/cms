// @vitest-environment jsdom

// Replacements (section 3.6 of the panel extension architecture): the winning replacement of the
// page's target renders in place of the default with the page's props; the default renders when
// none wins the target, while the replacement loads, and, with a notice that names its addon, when
// it throws or its addon's registration does not match.

import { screen, waitFor } from '@testing-library/react';
import { describe, expect, test } from 'vitest';

import { PointHost } from '../../src/host';
import { codes, contributions, fill, lazy, point, registration, renderHost } from './harness';

const TEXT = { input: 'Default input', stars: 'stars' } as const;

const DEFAULT = <input aria-label={TEXT.input} />;

function Stars({ value }: { readonly value: number }) {
  return <p>{[value, TEXT.stars].join(' ')}</p>;
}

function Broken(): never {
  throw new Error('No stars.');
}

function field(implementation: unknown) {
  return {
    contributions: contributions(
      [
        point(
          'form.field@1',
          [
            fill('stars', 'stars.input', 1000, {
              kind: 'replacement',
              replacement: { key: 'stars:rating' },
            }),
          ],
          { kind: 'replacement', region: null, multiplicity: 'exclusive' },
        ),
      ],
      { stars: ['stars.input'] },
    ),
    registrations: { stars: registration({ 'stars.input': lazy(implementation) }) },
  };
}

describe('a replacement', () => {
  test('renders in place of the default for its target, with the page s props', async () => {
    renderHost(
      <PointHost
        point="form.field@1"
        target="stars:rating"
        props={{ value: 4 }}
        fallback={DEFAULT}
      />,
      field(Stars),
    );

    expect(screen.getByLabelText('Default input')).toBeTruthy();
    expect(await screen.findByText('4 stars')).toBeTruthy();
    expect(screen.queryByLabelText('Default input')).toBeNull();
  });

  test('leaves the default for another target', async () => {
    const { source } = renderHost(
      <PointHost point="form.field@1" target="cms:text" props={{ value: 4 }} fallback={DEFAULT} />,
      field(Stars),
    );

    await waitFor(() => {
      expect(source.version()).toBeGreaterThanOrEqual(0);
    });
    expect(screen.getByLabelText('Default input')).toBeTruthy();
    expect(screen.queryByText('4 stars')).toBeNull();
  });

  test('falls back to the default with a notice naming its addon when it throws', async () => {
    const { recorded } = renderHost(
      <PointHost
        point="form.field@1"
        target="stars:rating"
        props={{ value: 4 }}
        fallback={DEFAULT}
      />,
      field(Broken),
    );

    expect(await screen.findByText('A part from stars could not be shown')).toBeTruthy();
    expect(screen.getByLabelText('Default input')).toBeTruthy();
    expect(codes(recorded)).toEqual(['panel_replacement_failed stars stars.input']);
  });
});
