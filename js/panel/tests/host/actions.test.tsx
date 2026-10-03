// @vitest-environment jsdom

// Actions (section 3.3 of the panel extension architecture): data, no code. The host renders a kit
// button per action in render order, its text in its addon's catalogue, overflowing into a menu
// past the point's maximum, and hands the page the action taken with the command document
// prefilled from the point's props by JSON pointer.

import { screen } from '@testing-library/react';
import { describe, expect, test } from 'vitest';

import { PointHost, type HostAction } from '../../src/host';
import { atPointer } from '../../src/host/PointHost';
import { contributions, fill, point, renderHost } from './harness';

/** The text of the page's actions, as the page's catalogue would give it. */
const TEXT = { actions: 'Actions for you' } as const;

function action(addon: string, id: string, priority: number, label: string) {
  return fill(addon, id, priority, {
    kind: 'action',
    props: { actor: '0192a0c0-0000-7000-8000-000000000b01', grants: [{ node: 'n-1' }] },
    action: {
      command: `${addon}.request@1`,
      confirm: 'dry_run',
      icon: 'plus',
      label,
      prefill: [
        { property: 'actor', pointer: '/actor' },
        { property: 'node', pointer: '/grants/0/node' },
        { property: 'missing', pointer: '/nowhere' },
      ],
      tone: 'neutral',
    },
  });
}

describe('an action point', () => {
  test('renders the actions in order, overflowing past its maximum, and hands the page the prefilled command', async () => {
    const taken: HostAction[] = [];
    const { user } = renderHost(
      <PointHost
        point="me.actions@1"
        label={TEXT.actions}
        onAction={(action_) => {
          taken.push(action_);
        }}
      />,
      {
        contributions: contributions(
          [
            point(
              'me.actions@1',
              [
                action('beta', 'beta.second', 200, 'beta.label'),
                action('alpha', 'alpha.first', 100, 'alpha.label'),
              ],
              { kind: 'action', region: null, multiplicity: 'max', max: 1 },
            ),
          ],
          {},
        ),
        registrations: {},
        texts: { 'alpha.label': 'Request access', 'beta.label': 'Ask again' },
      },
    );

    expect(screen.getByRole('toolbar', { name: TEXT.actions })).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Request access' }));
    await user.click(screen.getByRole('button', { name: 'More actions' }));
    await user.click(await screen.findByRole('menuitem', { name: 'Ask again' }));

    expect(
      taken.map((entry) => [entry.contribution, entry.command, entry.label, entry.confirm]),
    ).toEqual([
      ['alpha.first', 'alpha.request@1', 'Request access', 'dry_run'],
      ['beta.second', 'beta.request@1', 'Ask again', 'dry_run'],
    ]);
    expect(taken[0]?.document).toEqual({
      actor: '0192a0c0-0000-7000-8000-000000000b01',
      node: 'n-1',
    });
  });

  test('reads a JSON pointer as RFC 6901 does', () => {
    const document = { 'a/b': { '~c': [1, { d: true }] } };

    expect(atPointer(document, '/a~1b/~0c/1/d')).toBe(true);
    expect(atPointer(document, '/a~1b/~0c/2')).toBeUndefined();
    expect(atPointer(document, '/a~1b/~0c/01')).toBeUndefined();
    expect(atPointer(document, '')).toEqual(document);
  });
});
