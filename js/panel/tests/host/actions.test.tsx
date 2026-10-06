// @vitest-environment jsdom

// Actions (section 3.3 of the panel extension architecture): data, no code. The host renders a kit
// button per action in render order, its text in its addon's catalogue, overflowing into a menu
// past the point's maximum, and a press runs the command through the contribution's own host, with
// the document prefilled from the point's props by JSON pointer and the provenance of the
// contribution, asking first as the action says: at once, after the viewer confirms, or after a dry
// run whose summary the viewer reviews. The receipt is shown where the actions are, and a form
// action is handed to the page, which opens the command's form.

import type { CommandAnswer } from '@cboxdk/cms-panel/extend';
import { screen, waitFor } from '@testing-library/react';
import { describe, expect, test } from 'vitest';

import { PointHost, type ActionOutcome, type HostAction } from '../../src/host';
import { atPointer } from '../../src/host/PointHost';
import type { CommandCall } from '../../src/host/commands';
import { codes, contributions, fill, point, renderHost } from './harness';

/** The text of the page's actions, as the page's catalogue would give it. */
const TEXT = { actions: 'Actions for you' } as const;

const RECEIPT: CommandAnswer['receipt'] = {
  changeset_id: '0192a0c0-0000-7000-8000-000000000c01',
  consistency_token: null,
  outcome: 'committed',
  position: '12',
  projections: [],
  retention_class: 'standard',
  wait_level: 'commit',
};

const DRY_RUN: NonNullable<CommandAnswer['dryRun']> = {
  becomes_visible: [],
  blast_radius: { aggregates: [{ count: 1, kind: 'request' }], mutations: 1 },
  changes: [
    {
      after: 1,
      aggregate: 'request:0192a0c0-0000-7000-8000-000000000b01',
      before: null,
      mutations: 1,
    },
  ],
};

/** Answers a dry run with its summary and a real run with a committed receipt. */
function answering(call: CommandCall): Promise<CommandAnswer> {
  return Promise.resolve(
    call.options.dryRun === true
      ? {
          receipt: { ...RECEIPT, outcome: 'dry_run', changeset_id: null, position: null },
          problem: null,
          dryRun: DRY_RUN,
        }
      : { receipt: RECEIPT, problem: null, dryRun: null },
  );
}

function action(
  addon: string,
  id: string,
  priority: number,
  label: string,
  confirm: 'none' | 'confirm' | 'dry_run' | 'form' = 'dry_run',
) {
  return fill(addon, id, priority, {
    kind: 'action',
    props: { actor: '0192a0c0-0000-7000-8000-000000000b01', grants: [{ node: 'n-1' }] },
    action: {
      command: `${addon}.request@1`,
      confirm,
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

/** The page with one action point of the actions given, at most `max` shown as buttons. */
function page(
  actions: ReturnType<typeof action>[],
  options: {
    readonly max?: number;
    readonly onAction?: (action: HostAction) => void;
    readonly onOutcome?: (action: HostAction, outcome: ActionOutcome) => void;
    readonly answer?: (call: CommandCall) => Promise<CommandAnswer>;
    readonly confirm?: () => Promise<boolean>;
  } = {},
) {
  return renderHost(
    <PointHost
      point="me.actions@1"
      label={TEXT.actions}
      onAction={options.onAction}
      onOutcome={options.onOutcome}
    />,
    {
      contributions: contributions(
        [
          point(
            'me.actions@1',
            actions,
            options.max === undefined
              ? { kind: 'action', region: null }
              : { kind: 'action', region: null, multiplicity: 'max', max: options.max },
          ),
        ],
        { alpha: [], beta: [] },
      ),
      registrations: {},
      texts: { 'alpha.label': 'Request access', 'beta.label': 'Ask again' },
      answering: { runCommand: options.answer, confirm: options.confirm },
    },
  );
}

describe('an action point', () => {
  test('renders the actions in order, overflowing past its maximum, and hands the page a form action with the prefilled command', async () => {
    const taken: HostAction[] = [];
    const { user, recorded } = page(
      [
        action('beta', 'beta.second', 200, 'beta.label', 'form'),
        action('alpha', 'alpha.first', 100, 'alpha.label', 'form'),
      ],
      { max: 1, onAction: (entry) => taken.push(entry) },
    );

    const toolbar = screen.getByRole('toolbar', { name: TEXT.actions });
    const marked = toolbar.closest('[data-cms-point]');

    // The toolbar's element names the point and the actions in render order, for a test that reads
    // what a point renders, such as the testkit's PanelVisit.
    expect(marked?.getAttribute('data-cms-point')).toBe('me.actions@1');
    expect(marked?.getAttribute('data-cms-actions')).toBe('alpha.first beta.second');
    await user.click(screen.getByRole('button', { name: 'Request access' }));
    await user.click(screen.getByRole('button', { name: 'More actions' }));
    await user.click(await screen.findByRole('menuitem', { name: 'Ask again' }));

    expect(
      taken.map((entry) => [entry.contribution, entry.command, entry.label, entry.confirm]),
    ).toEqual([
      ['alpha.first', 'alpha.request@1', 'Request access', 'form'],
      ['beta.second', 'beta.request@1', 'Ask again', 'form'],
    ]);
    expect(taken[0]?.document).toEqual({
      actor: '0192a0c0-0000-7000-8000-000000000b01',
      node: 'n-1',
    });
    expect(recorded.commands).toEqual([]);
  });

  test('runs an action at once through the contribution s host, with its provenance, and shows the receipt', async () => {
    const outcomes: ActionOutcome[] = [];
    const { user, recorded } = page([action('alpha', 'alpha.first', 100, 'alpha.label', 'none')], {
      answer: answering,
      onOutcome: (_action, outcome) => outcomes.push(outcome),
    });

    await user.click(screen.getByRole('button', { name: 'Request access' }));

    await waitFor(() => {
      expect(screen.getByRole('status')).toBeTruthy();
    });
    expect(screen.getByText('Saved')).toBeTruthy();
    expect(recorded.commands).toEqual([
      {
        command: 'alpha.request@1',
        document: { actor: '0192a0c0-0000-7000-8000-000000000b01', node: 'n-1' },
        options: {},
        provenance: 'addon:alpha:alpha.first',
      },
    ]);
    expect(outcomes).toEqual([
      {
        status: 'answered',
        answer: { receipt: RECEIPT, problem: null, dryRun: null },
        dryRun: false,
      },
    ]);
  });

  test('asks the viewer first for an action that confirms, and runs nothing when they decline', async () => {
    let confirmations = 0;
    const declined = page([action('alpha', 'alpha.first', 100, 'alpha.label', 'confirm')], {
      answer: answering,
      confirm: () => {
        confirmations += 1;

        return Promise.resolve(false);
      },
    });

    await declined.user.click(screen.getByRole('button', { name: 'Request access' }));

    await waitFor(() => {
      expect(confirmations).toBe(1);
    });
    expect(declined.recorded.commands).toEqual([]);
    expect(screen.queryByRole('status')).toBeNull();
    declined.unmount();

    const accepted = page([action('alpha', 'alpha.first', 100, 'alpha.label', 'confirm')], {
      answer: answering,
      confirm: () => Promise.resolve(true),
    });

    await accepted.user.click(screen.getByRole('button', { name: 'Request access' }));

    await waitFor(() => {
      expect(accepted.recorded.commands).toHaveLength(1);
    });
    expect(accepted.recorded.commands[0]?.options).toEqual({});
  });

  test('runs a dry run first, shows what would change, and runs the command for real once the viewer confirms', async () => {
    const { user, recorded } = page(
      [action('alpha', 'alpha.first', 100, 'alpha.label', 'dry_run')],
      { answer: answering },
    );

    await user.click(screen.getByRole('button', { name: 'Request access' }));

    expect(
      await screen.findByRole('dialog', { name: 'Request access: what would change' }),
    ).toBeTruthy();
    expect(screen.getByText('Changes in the plan: 1')).toBeTruthy();
    expect(screen.getByText('request:0192a0c0-0000-7000-8000-000000000b01')).toBeTruthy();
    expect(recorded.commands.map((call) => call.options)).toEqual([{ dryRun: true }]);

    await user.click(screen.getByRole('button', { name: 'Run it' }));

    await waitFor(() => {
      expect(screen.getByText('Saved')).toBeTruthy();
    });
    expect(recorded.commands.map((call) => call.options)).toEqual([{ dryRun: true }, {}]);
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  test('runs nothing for real when the viewer cancels the review of the dry run', async () => {
    const { user, recorded } = page(
      [action('alpha', 'alpha.first', 100, 'alpha.label', 'dry_run')],
      { answer: answering },
    );

    await user.click(screen.getByRole('button', { name: 'Request access' }));
    await user.click(await screen.findByRole('button', { name: 'Cancel' }));

    await waitFor(() => {
      expect(screen.queryByRole('dialog')).toBeNull();
    });
    expect(recorded.commands.map((call) => call.options)).toEqual([{ dryRun: true }]);
    expect(screen.queryByRole('status')).toBeNull();
  });

  test('shows the problem of a dry run that was rejected instead of a review', async () => {
    const { user } = page([action('alpha', 'alpha.first', 100, 'alpha.label', 'dry_run')], {
      answer: () =>
        Promise.resolve({
          receipt: { ...RECEIPT, outcome: 'rejected', changeset_id: null, position: null },
          problem: {
            code: 'unauthorized',
            detail: 'The actor may not run the command.',
            errors: [],
            instance: null,
            retryable: false,
            status: 403,
            title: 'Unauthorized',
            type: 'https://cbox.dk/cms/errors/unauthorized',
          },
          dryRun: null,
        }),
    });

    await user.click(screen.getByRole('button', { name: 'Request access' }));

    await waitFor(() => {
      expect(screen.getByText('Refused')).toBeTruthy();
    });
    expect(screen.getByText('The actor may not run the command.')).toBeTruthy();
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  test('reports an action whose call failed, and a form action the page does not handle', async () => {
    const { user, recorded } = page([
      action('alpha', 'alpha.first', 100, 'alpha.label', 'none'),
      action('beta', 'beta.second', 200, 'beta.label', 'form'),
    ]);

    await user.click(screen.getByRole('button', { name: 'Request access' }));

    await waitFor(() => {
      expect(screen.getByText('alpha: the action could not be run')).toBeTruthy();
    });

    await user.click(screen.getByRole('button', { name: 'Ask again' }));

    expect(codes(recorded)).toEqual([
      'panel_action_failed alpha alpha.first',
      'panel_action_unhandled beta beta.second',
    ]);
  });

  test('reads a JSON pointer as RFC 6901 does', () => {
    const document = { 'a/b': { '~c': [1, { d: true }] } };

    expect(atPointer(document, '/a~1b/~0c/1/d')).toBe(true);
    expect(atPointer(document, '/a~1b/~0c/2')).toBeUndefined();
    expect(atPointer(document, '/a~1b/~0c/01')).toBeUndefined();
    expect(atPointer(document, '')).toEqual(document);
  });
});
