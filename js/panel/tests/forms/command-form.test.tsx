// @vitest-environment jsdom

// The generic command form (GUARDRAILS 2.2, 8; PRD 6.1): rendered from a command's JSON Schema with
// the kit's SchemaForm and the panel's catalogue for its labels, it checks the document with the
// generated runtime validator before it submits and shows the first issue at its field and in the
// error summary; a valid document runs the command through the host's transport with one
// idempotency key per form instance, so a dry run and the commit that follows, and a rejected
// submit and its retry, share the key, and a commit gets the next run a new one. It shows the
// receipt, what a dry run would change and, for a rejection, the problem details and each error at
// its field from the page's errors prop. A schema with a keyword the form has no field for shows
// why instead of a form.

import type { CommandAnswer } from '@cboxdk/cms-panel/extend';
import { screen, waitFor, within } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, test } from 'vitest';

import { CommandForm, readForm } from '../../src/forms/CommandForm';
import type { CommandCall } from '../../src/host/commands';
import { contributions, renderHost } from '../host/harness';

const ACTIVATE = JSON.parse(
  readFileSync(
    join(
      import.meta.dirname,
      '../../../../packages/core/resources/schemas/commands/actor.activate.v1.json',
    ),
    'utf8',
  ),
) as Record<string, unknown>;

const ACTOR = '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01';

const RECEIPT = {
  changeset_id: null,
  consistency_token: null,
  outcome: 'rejected',
  position: null,
  projections: [],
  retention_class: 'standard',
  wait_level: 'commit',
} as const;

const COMMITTED: CommandAnswer = {
  receipt: {
    ...RECEIPT,
    outcome: 'committed',
    changeset_id: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a99',
    position: '12',
  },
  problem: null,
  dryRun: null,
};

const DRY_RUN: CommandAnswer = {
  receipt: { ...RECEIPT, outcome: 'dry_run' },
  problem: null,
  dryRun: {
    becomes_visible: [],
    blast_radius: { aggregates: [{ count: 1, kind: 'actor' }], mutations: 1 },
    changes: [{ after: 2, aggregate: `actor:${ACTOR}`, before: 1, mutations: 1 }],
  },
};

const REJECTED: CommandAnswer = {
  receipt: RECEIPT,
  problem: {
    code: 'validation_failed',
    detail: 'Only a pending actor is activated.',
    errors: [
      {
        code: 'validation_failed',
        detail: 'Only a pending actor is activated.',
        field: 'command.actor',
      },
    ],
    instance: null,
    retryable: false,
    status: 422,
    title: 'Validation failed',
    type: 'https://cbox.dk/cms/errors/validation_failed',
  },
  dryRun: null,
};

/** The texts of the panel's catalogue the test expects to see. */
const TEXT = {
  actor: 'Actor',
  actorDescription: 'The id of the pending actor to activate.',
  version: 'Version',
  run: 'Run command',
  tryIt: 'Try without saving',
  notSent: 'The form was not sent. Check the fields marked below.',
  refused: 'The command was refused',
  saved: 'Saved',
  dryRunTitle: 'Dry run: nothing was saved',
  unsupported: "This command's form cannot be shown",
} as const;

function render(
  answers: readonly CommandAnswer[],
  errors: unknown = {},
  schema: unknown = ACTIVATE,
) {
  const queue = [...answers];
  const calls: CommandCall[] = [];

  return {
    calls,
    ...renderHost(
      <CommandForm
        command="actor.activate"
        version={1}
        read={readForm(schema)}
        bindings={[]}
        errors={errors}
      />,
      {
        contributions: contributions([], { cms: [] }),
        registrations: {},
        answering: {
          runCommand: (call) => {
            calls.push(call);
            const answer = queue.shift();

            return answer === undefined
              ? Promise.reject(new Error('No answer left.'))
              : Promise.resolve(answer);
          },
        },
      },
    ),
  };
}

describe('the command form', () => {
  test('labels its fields from the catalogue, checks the document before it submits and shows the issue at its field', async () => {
    const { user, calls } = render([]);

    expect(screen.getByLabelText(new RegExp(`^${TEXT.actor}`))).not.toBeNull();
    expect(screen.getByText(TEXT.actorDescription)).not.toBeNull();

    await user.click(screen.getByRole('button', { name: TEXT.run }));

    const summary = await screen.findByRole('group', { name: TEXT.notSent });

    expect(within(summary).getByRole('link').getAttribute('href')).toMatch(/#.*-actor$/);
    expect(screen.getByLabelText(new RegExp(`^${TEXT.actor}`)).getAttribute('aria-invalid')).toBe(
      'true',
    );
    expect(calls).toEqual([]);

    await user.type(screen.getByLabelText(new RegExp(`^${TEXT.actor}`)), 'not an id');
    await user.type(screen.getByLabelText(new RegExp(`^${TEXT.version}`)), '1');
    await user.click(screen.getByRole('button', { name: TEXT.run }));

    expect(calls).toEqual([]);
    expect(screen.getByLabelText(new RegExp(`^${TEXT.actor}`)).getAttribute('aria-invalid')).toBe(
      'true',
    );
    expect(
      screen.getByLabelText(new RegExp(`^${TEXT.version}`)).getAttribute('aria-invalid'),
    ).toBeNull();
  });

  test('runs a dry run and then the commit with one idempotency key, shows what each came to, and starts a new instance after the commit', async () => {
    const { user, calls } = render([DRY_RUN, COMMITTED, COMMITTED]);

    await user.type(screen.getByLabelText(new RegExp(`^${TEXT.actor}`)), ACTOR);
    await user.type(screen.getByLabelText(new RegExp(`^${TEXT.version}`)), '1');
    await user.click(screen.getByRole('button', { name: TEXT.tryIt }));

    await screen.findByText(TEXT.dryRunTitle);
    expect(calls).toHaveLength(1);
    expect(calls[0]).toMatchObject({
      command: 'actor.activate@1',
      document: { actor: ACTOR, version: 1 },
      options: { dryRun: true, waitLevel: 'commit' },
    });
    expect(calls[0]?.key).toMatch(/^[0-9a-f-]{36}$/);

    await user.click(screen.getByRole('button', { name: TEXT.run }));

    await screen.findByText(TEXT.saved, { selector: '.cms-callout__title' });
    expect(calls).toHaveLength(2);
    expect(calls[1]?.options).toEqual({ dryRun: false, waitLevel: 'commit' });
    expect(calls[1]?.key).toBe(calls[0]?.key);

    await user.click(screen.getByRole('button', { name: TEXT.run }));
    await waitFor(() => {
      expect(calls).toHaveLength(3);
    });
    expect(calls[2]?.key).not.toBe(calls[0]?.key);
  });

  test('shows a rejection with its problem and each error at its field from the errors prop, and keeps the key for the retry', async () => {
    const { user, calls } = render([REJECTED, COMMITTED], {
      'command.actor': 'Only a pending actor is activated.',
    });

    await user.type(screen.getByLabelText(new RegExp(`^${TEXT.actor}`)), ACTOR);
    await user.type(screen.getByLabelText(new RegExp(`^${TEXT.version}`)), '1');
    await user.click(screen.getByRole('button', { name: TEXT.run }));

    const summary = await screen.findByRole('group', { name: TEXT.refused });

    expect(within(summary).getByRole('link').textContent).toBe(
      'Only a pending actor is activated.',
    );
    expect(screen.getByRole('alert').textContent).toContain('validation_failed');
    expect(screen.getByLabelText(new RegExp(`^${TEXT.actor}`)).getAttribute('aria-invalid')).toBe(
      'true',
    );

    await user.click(screen.getByRole('button', { name: TEXT.run }));
    await waitFor(() => {
      expect(calls).toHaveLength(2);
    });
    expect(calls[1]?.key).toBe(calls[0]?.key);
  });

  test('says why when the schema has a keyword the form has no field for', () => {
    const properties = ACTIVATE['properties'] as Record<string, Record<string, unknown>>;

    render(
      [],
      {},
      {
        ...ACTIVATE,
        properties: { ...properties, version: { ...properties['version'], multipleOf: 2 } },
      },
    );

    expect(screen.getByText(TEXT.unsupported)).not.toBeNull();
    expect(screen.getByText(/"multipleOf" at #\/properties\/version/)).not.toBeNull();
    expect(screen.queryByRole('button', { name: TEXT.run })).toBeNull();
  });
});
