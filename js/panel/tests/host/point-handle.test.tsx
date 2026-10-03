// @vitest-environment jsdom

// What a page asks a point for rather than renders it as (usePointHost): the form checks of a
// command, run with the addons' registered code; the flow of its steps, rendered by FlowHost, which
// ends only through the core's own confirmation after every step; the observers; and the columns.

import type { FormCheck, StepProps } from '@cboxdk/cms-panel/extend';
import { screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, test } from 'vitest';

import { FlowHost, usePointHost, type PointHandle } from '../../src/host';
import type { FlowRun } from '../../src/host/flow';
import { codes, contributions, fill, lazy, point, registration, renderHost } from './harness';

/** The texts the steps and pages of the tests render, as a catalogue would give them. */
const TEXT = { start: 'Start', approve: 'Approve', confirm: 'Core confirm', cell: 'Cell' } as const;

/** Hands the test the point's handle on every render. */
function Probe({
  point: id,
  onHandle,
}: {
  readonly point: string;
  readonly onHandle: (handle: PointHandle) => void;
}) {
  onHandle(usePointHost(id));

  return null;
}

const titleCheck: FormCheck<{ readonly fields: { readonly title: string } }> = (document) =>
  document.fields.title === ''
    ? [{ path: 'fields.title', code: 'alpha.title', severity: 'error', message: 'alpha.title' }]
    : [];

describe('a point s checks', () => {
  test('run the addons registered checks for the form s command once their code is checked', async () => {
    let handle: PointHandle | undefined;
    renderHost(<Probe point="form.checks@1" onHandle={(received) => (handle = received)} />, {
      contributions: contributions(
        [
          point(
            'form.checks@1',
            [
              fill('alpha', 'alpha.title', 100, {
                kind: 'form_check',
                check: { command: 'note.draft@1', severity: 'error' },
              }),
              fill('alpha', 'alpha.other', 200, {
                kind: 'form_check',
                check: { command: 'note.other@1', severity: 'warning' },
              }),
            ],
            { kind: 'form_check', region: null },
          ),
        ],
        { alpha: ['alpha.other', 'alpha.title'] },
      ),
      registrations: {
        alpha: registration({ 'alpha.title': titleCheck, 'alpha.other': () => [] }),
      },
    });

    await waitFor(() => {
      expect(
        handle
          ?.checks('note.draft@1', { fields: { title: '' } })
          .now.blocking.map((issue) => issue.code),
      ).toEqual(['alpha.title']);
    });
    expect(handle?.checks('note.draft@1', { fields: { title: 'Ready' } }).now.issues).toEqual([]);
    expect(handle?.kind).toBe('form_check');
  });
});

describe('a point s flow', () => {
  test('renders each step, then the core s confirmation, which alone hands the page the draft', async () => {
    function Reason({
      draft,
      patch,
      next,
    }: StepProps<{ readonly fields: object }, 'fields.ext.approvals.reason'>) {
      return (
        <button
          type="button"
          onClick={() => {
            patch('fields.ext.approvals.reason', 'Asked Ada');
            next();
          }}
        >
          {[TEXT.approve, JSON.stringify(draft.fields)].join(' ')}
        </button>
      );
    }

    const confirmed: object[] = [];
    let started: FlowRun | undefined;

    function Form() {
      const handle = usePointHost('form.steps@1');
      const [run, setRun] = useState<FlowRun | undefined>(undefined);

      return run === undefined ? (
        <button
          type="button"
          onClick={() => {
            started = handle.flow('grant.assign@1', 'before_submit', {
              fields: { ext: { approvals: {} } },
            });
            setRun(started);
          }}
        >
          {TEXT.start}
        </button>
      ) : (
        <FlowHost
          point="form.steps@1"
          run={run}
          dryRun={() => Promise.reject(new Error('No dry run in this test.'))}
          confirmation={(_draft, confirm) => (
            <button type="button" onClick={confirm}>
              {TEXT.confirm}
            </button>
          )}
          onConfirmed={(draft) => confirmed.push(draft)}
        />
      );
    }

    const page = contributions(
      [
        point(
          'form.steps@1',
          [
            fill('approvals', 'approvals.reason', 100, {
              kind: 'flow_step',
              step: {
                command: 'grant.assign@1',
                patches: ['fields.ext.approvals.reason'],
                position: 'before_submit',
                timeout_seconds: 30,
              },
            }),
            fill('approvals', 'approvals.second', 200, {
              kind: 'flow_step',
              step: {
                command: 'grant.assign@1',
                patches: ['fields.ext.approvals.reason'],
                position: 'before_submit',
                timeout_seconds: 30,
              },
            }),
          ],
          { kind: 'flow_step', region: null },
        ),
      ],
      { approvals: ['approvals.reason', 'approvals.second'] },
    );
    const { user, source } = renderHost(<Form />, {
      contributions: page,
      registrations: {
        approvals: registration({
          'approvals.reason': lazy(Reason),
          'approvals.second': lazy(Reason),
        }),
      },
    });

    await waitFor(() => {
      expect(source.settled(page, 'approvals')?.status).toBe('registered');
    });
    await user.click(screen.getByRole('button', { name: 'Start' }));
    expect(started?.state.phase).toBe('step');
    await user.click(
      await screen.findByRole('button', { name: 'Approve {"ext":{"approvals":{}}}' }),
    );
    await user.click(
      await screen.findByRole('button', {
        name: 'Approve {"ext":{"approvals":{"reason":"Asked Ada"}}}',
      }),
    );
    expect(confirmed).toEqual([]);
    await user.click(await screen.findByRole('button', { name: 'Core confirm' }));

    expect(confirmed).toEqual([{ fields: { ext: { approvals: { reason: 'Asked Ada' } } } }]);
  });

  test('a step that throws cancels the flow in its addon s name', async () => {
    function Broken(): never {
      throw new Error('No step.');
    }

    let started: FlowRun | undefined;

    function Form() {
      const handle = usePointHost('form.steps@1');
      const [run, setRun] = useState<FlowRun | undefined>(undefined);

      return run === undefined ? (
        <button
          type="button"
          onClick={() => {
            started = handle.flow('grant.assign@1', 'before_submit', {});
            setRun(started);
          }}
        >
          {TEXT.start}
        </button>
      ) : (
        <FlowHost
          point="form.steps@1"
          run={run}
          dryRun={() => Promise.reject(new Error('none'))}
          confirmation={() => null}
          onConfirmed={() => undefined}
        />
      );
    }

    const page = contributions(
      [
        point(
          'form.steps@1',
          [
            fill('approvals', 'approvals.broken', 100, {
              kind: 'flow_step',
              step: {
                command: 'grant.assign@1',
                patches: [],
                position: 'before_submit',
                timeout_seconds: 30,
              },
            }),
          ],
          { kind: 'flow_step', region: null },
        ),
      ],
      { approvals: ['approvals.broken'] },
    );
    const { recorded, user, source } = renderHost(<Form />, {
      contributions: page,
      registrations: { approvals: registration({ 'approvals.broken': lazy(Broken) }) },
    });

    await waitFor(() => {
      expect(source.settled(page, 'approvals')?.status).toBe('registered');
    });
    await user.click(screen.getByRole('button', { name: 'Start' }));

    await waitFor(() => {
      expect(started?.state).toMatchObject({
        phase: 'cancelled',
        addon: 'approvals',
        cause: 'failed',
      });
    });
    expect(
      await screen.findByText('approvals stopped this because one of its steps failed.'),
    ).toBeTruthy();
    expect(codes(recorded)).toEqual(['panel_step_failed approvals approvals.broken']);
  });
});

describe('a point s observers and columns', () => {
  test('observers are called in order and a throw is reported; columns render cells in boundaries', async () => {
    let handle: PointHandle | undefined;
    const seen: string[] = [];

    function Cell({ props }: { readonly props: { readonly handle: string } }) {
      return <span>{[TEXT.cell, props.handle].join(' ')}</span>;
    }

    const { recorded } = renderHost(
      <>
        <Probe point="panel.observe@1" onHandle={(received) => (handle = received)} />
        <Columns />
      </>,
      {
        contributions: contributions(
          [
            point(
              'panel.observe@1',
              [
                fill('alpha', 'alpha.watch', 100, { kind: 'observer' }),
                fill('beta', 'beta.watch', 200, { kind: 'observer' }),
              ],
              { kind: 'observer', region: null },
            ),
            point('roles.columns@1', [fill('alpha', 'alpha.column', 100)], { region: 'columns' }),
          ],
          { alpha: ['alpha.column', 'alpha.watch'], beta: ['beta.watch'] },
        ),
        registrations: {
          alpha: registration({
            'alpha.watch': (event: { readonly name: string }) => seen.push(event.name),
            'alpha.column': lazy({ header: 'alpha.header', width: 'narrow', cell: Cell }),
          }),
          beta: registration({
            'beta.watch': () => {
              throw new Error('Not watching.');
            },
          }),
        },
        texts: { 'alpha.header': 'Approvals' },
      },
    );

    await waitFor(() => {
      handle?.observe({ name: 'grant.assign' });
      expect(seen.length).toBeGreaterThan(0);
      expect(codes(recorded)).toContain('panel_observer_failed beta beta.watch');
    });
    expect(await screen.findByText('Approvals')).toBeTruthy();
    expect(screen.getByText('Cell editor')).toBeTruthy();
  });
});

function Columns() {
  const handle = usePointHost('roles.columns@1');

  return (
    <table>
      <thead>
        <tr>
          {handle.columns.map((column) => (
            <th key={column.id}>{column.header}</th>
          ))}
        </tr>
      </thead>
      <tbody>
        <tr>
          {handle.columns.map((column) => (
            <td key={column.id}>{column.cell({ handle: 'editor' })}</td>
          ))}
        </tr>
      </tbody>
    </table>
  );
}
