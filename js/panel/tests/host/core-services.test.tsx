// @vitest-environment jsdom

// The core's services (PRD 13.4): a page of the core runs a command through the host's transport
// without a provenance, keeping the idempotency key it gives, and once the command answered the
// observers of panel.observe.command@1 are told what completed (section 3.9 of the panel
// extension architecture): the command's name and version, the receipt's outcome and the
// changeset it committed, on a frozen event, in render order, an observer that throws reported
// and the others still called; a rejected command is observed too, with no changeset. The event
// is built as the point's generated contract names it.

import type { CommandAnswer } from '@cboxdk/cms-panel/extend';
import { waitFor } from '@testing-library/react';
import { describe, expect, test } from 'vitest';

import { commandCompleted, useCoreServices, type CoreServices } from '../../src/host';
import { contributions, fill, point, registration, renderHost } from './harness';

function answer(
  outcome: 'committed' | 'rejected' | 'dry_run',
  changeset: string | null,
): CommandAnswer {
  return {
    receipt: {
      changeset_id: changeset,
      consistency_token: null,
      outcome,
      position: null,
      projections: [],
      retention_class: 'standard',
      wait_level: 'commit',
    },
    problem: null,
    dryRun: null,
  };
}

function Probe({ onServices }: { readonly onServices: (services: CoreServices) => void }) {
  onServices(useCoreServices());

  return null;
}

describe('commandCompleted', () => {
  test('reads the name and version from the command and the outcome and changeset from the receipt', () => {
    expect(
      commandCompleted(
        'entry.create@2',
        answer('committed', '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01'),
      ),
    ).toEqual({
      changeset: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01',
      command: 'entry.create',
      outcome: 'committed',
      version: 2,
    });
    expect(commandCompleted('grant.assign', answer('rejected', null))).toEqual({
      changeset: null,
      command: 'grant.assign',
      outcome: 'rejected',
      version: 1,
    });
  });
});

describe('the core services', () => {
  test('run a command with the key given and tell the observers in order what completed, past one that throws', async () => {
    const seen: string[] = [];
    let services: CoreServices | undefined;
    const page = contributions(
      [
        point(
          'panel.observe.command@1',
          [
            fill('beta', 'beta.log', 200, { kind: 'observer', props: {} }),
            fill('alpha', 'alpha.bad', 100, { kind: 'observer', props: {} }),
          ],
          { kind: 'observer', region: null },
        ),
      ],
      { alpha: ['alpha.bad'], beta: ['beta.log'] },
    );
    const { recorded, source } = renderHost(
      <Probe
        onServices={(given) => {
          services = given;
        }}
      />,
      {
        contributions: page,
        registrations: {
          alpha: registration({
            'alpha.bad': () => {
              throw new Error('alpha fails');
            },
          }),
          beta: registration({
            'beta.log': (event: unknown) => {
              seen.push(`${JSON.stringify(event)} ${String(Object.isFrozen(event))}`);
            },
          }),
        },
        answering: {
          runCommand: (call) =>
            Promise.resolve(
              answer(
                call.options.dryRun === true ? 'dry_run' : 'committed',
                call.options.dryRun === true ? null : '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01',
              ),
            ),
        },
      },
    );

    if (services === undefined) {
      throw new Error('No services.');
    }

    // The addons' code arrives after the first render; the services tell the observers that are
    // registered when the command answers.
    await waitFor(() => {
      expect(source.settled(page, 'alpha')?.status).toBe('registered');
      expect(source.settled(page, 'beta')?.status).toBe('registered');
    });

    const committed = await services.runCommand('entry.create@1', { entry: 'x' }, {}, 'key-1');
    const dryRun = await services.runCommand('entry.create@1', { entry: 'x' }, { dryRun: true });

    expect(committed.receipt.outcome).toBe('committed');
    expect(dryRun.receipt.outcome).toBe('dry_run');
    expect(recorded.commands.map((call) => [call.command, call.key, call.provenance])).toEqual([
      ['entry.create@1', 'key-1', undefined],
      ['entry.create@1', undefined, undefined],
    ]);
    expect(seen).toEqual([
      '{"changeset":"0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01","command":"entry.create","outcome":"committed","version":1} true',
      '{"changeset":null,"command":"entry.create","outcome":"dry_run","version":1} true',
    ]);
    expect(recorded.reports).toEqual([
      {
        code: 'panel_observer_failed',
        addon: 'alpha',
        point: 'panel.observe.command@1',
        contribution: 'alpha.bad',
      },
    ]);
  });
});
