// @vitest-environment jsdom

// The points of the generic command form (section 8 of the panel extension architecture): a
// warning check's issue is listed with its field and the server's error replaces it at the same
// path after a submit; an acknowledge check's issue holds the submit until it is ticked; an error
// check's issue, which only a check that mirrors a hook may give, blocks the submit at its field; a
// step before the submit may cancel in its addon's name or patch its path and go on to the core's
// confirmation, which alone sends the draft; a step after the receipt gets the receipt and may
// issue a follow-up the addon may issue; a dry run is explicit and runs no step; the submit
// decorator disables the run with its reason; the input of a member bound to a value class is the
// winning replacement's, with its data; the receipt is decorated; and the dry run slot renders
// below what would change.

import type {
  CommandAnswer,
  Decorator,
  FormCheck,
  JsonValue,
  SlotProps,
  StepProps,
} from '@cboxdk/cms-panel/extend';
import type {
  CommandFormReceiptV1,
  DryRunViewV1,
  FieldInputProps,
} from '@cboxdk/cms-panel/experimental';
import { screen, waitFor } from '@testing-library/react';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, test } from 'vitest';

import { CommandForm, readForm } from '../../src/forms/CommandForm';
import type { CommandCall } from '../../src/host/commands';
import {
  contributions,
  fill,
  lazy,
  point,
  registration,
  renderHost,
  type HostOptions,
} from '../host/harness';

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

const ACTOR_ID_CLASS = 'Cbox\\Cms\\Contracts\\Ids\\ActorId';

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

interface Activate {
  readonly actor?: string;
  readonly version?: number;
}

/** The texts of the panel's catalogue and the addon's the tests expect to see. */
const TEXT = {
  actor: 'Actor',
  version: 'Version',
  run: 'Run command',
  tryIt: 'Try without saving',
  held: 'The form was not sent',
  acknowledge: 'I have read this and want to go on',
  confirm: 'Run',
  confirmTitle: 'Run this now?',
  stopped: 'acme stopped this.',
  cannotRun: 'The command cannot be run now',
  saved: 'Saved',
  dryRunTitle: 'Dry run: nothing was saved',
  hint: 'Mind the actor',
  mustTick: 'Tick to go on',
  blocked: 'The actor is frozen',
  cancelled: 'No, not now',
  disabled: 'Frozen by acme',
} as const;

/** The texts the test's own contributions render, which no catalogue holds. */
const OWN = {
  reviewing: 'Reviewing',
  useVersion: 'Use version 7',
  stop: 'Stop',
  followUp: 'Follow-up for',
  request: 'Request a review',
  picked: 'Picked',
  decoration: 'Receipt decoration for',
  dryRunSection: 'Dry run section:',
  change: 'change',
} as const;

const ADDON_TEXTS = {
  'acme.hint': TEXT.hint,
  'acme.tick': TEXT.mustTick,
  'acme.frozen': TEXT.blocked,
  'acme.cancelled': TEXT.cancelled,
  'acme.disabled': TEXT.disabled,
} as const;

const warning: FormCheck<Activate> = (document) =>
  document.actor === undefined
    ? []
    : [{ path: 'actor', code: 'acme.hint', severity: 'warning', message: 'acme.hint' }];

const acknowledge: FormCheck<Activate> = () => [
  { path: 'version', code: 'acme.tick', severity: 'acknowledge', message: 'acme.tick' },
];

const blocking: FormCheck<Activate> = (document) =>
  document.actor === ACTOR
    ? [{ path: 'actor', code: 'acme.frozen', severity: 'error', message: 'acme.frozen' }]
    : [];

function Review({ draft, patch, next, cancel }: StepProps<Activate, 'version'>) {
  return (
    <div>
      <p>{[OWN.reviewing, draft.actor ?? ''].join(' ')}</p>
      <button
        type="button"
        onClick={() => {
          patch('version', 7);
          next();
        }}
      >
        {OWN.useVersion}
      </button>
      <button
        type="button"
        onClick={() => {
          cancel('acme.cancelled');
        }}
      >
        {OWN.stop}
      </button>
    </div>
  );
}

function FollowUp({
  receipt,
  issue,
  next,
}: StepProps<Activate, never, { 'acme.request@1': object }>) {
  return (
    <div>
      <p>{[OWN.followUp, receipt?.receipt.outcome ?? ''].join(' ')}</p>
      <button
        type="button"
        onClick={() => {
          void issue('acme.request@1', { actor: ACTOR }).then(() => {
            next();
          });
        }}
      >
        {OWN.request}
      </button>
    </div>
  );
}

const guard: Decorator<object, 'disabled_reason'> = () => ({
  tighten: { disabled_reason: 'acme.disabled' },
});

function Picked(
  props: FieldInputProps & {
    readonly data: { readonly status: string; readonly value?: JsonValue };
  },
) {
  return (
    <label>
      {[OWN.picked, props.label, `(${props.data.status})`].join(' ')}
      <input
        id={props.id}
        name={props.path}
        value={props.value ?? ''}
        onChange={(event) => {
          props.onChange(event.target.value);
        }}
      />
    </label>
  );
}

function AfterReceipt({ props }: SlotProps<CommandFormReceiptV1>) {
  return <p>{[OWN.decoration, props.command, String(props.version)].join(' ')}</p>;
}

const receiptDecorator: Decorator<CommandFormReceiptV1> = (props) => ({
  after: <AfterReceipt props={props} />,
});

function DryRunSection({ props }: SlotProps<DryRunViewV1>) {
  const changes = props.summary.changes;

  return (
    <p>
      {[OWN.dryRunSection, String(Array.isArray(changes) ? changes.length : 0), OWN.change].join(
        ' ',
      )}
    </p>
  );
}

/** The points of the form, each with the fills given, and the addon's registration. */
function form(options: {
  readonly checks?: readonly {
    readonly id: string;
    readonly severity: 'info' | 'warning' | 'acknowledge' | 'error';
  }[];
  readonly steps?: readonly {
    readonly id: string;
    readonly position: 'before_submit' | 'after_receipt';
    readonly patches: readonly string[];
  }[];
  readonly submit?: boolean;
  readonly receipt?: boolean;
  readonly dryRun?: boolean;
  readonly field?: boolean;
  readonly implementations: Readonly<Record<string, unknown>>;
}): Pick<HostOptions, 'contributions' | 'registrations' | 'ext'> {
  const points = [];
  const ids: string[] = [];

  if (options.checks !== undefined) {
    points.push(
      point(
        'command.form.checks@1',
        options.checks.map((check) =>
          fill('acme', check.id, 1000, {
            kind: 'form_check',
            props: {},
            check: { command: 'actor.activate@1', severity: check.severity },
          }),
        ),
        { kind: 'form_check', region: null },
      ),
    );
    ids.push(...options.checks.map((check) => check.id));
  }

  if (options.steps !== undefined) {
    points.push(
      point(
        'command.form.steps@1',
        options.steps.map((step) =>
          fill('acme', step.id, 1000, {
            kind: 'flow_step',
            props: {},
            step: {
              command: 'actor.activate@1',
              position: step.position,
              patches: step.patches,
              timeout_seconds: 30,
            },
          }),
        ),
        { kind: 'flow_step', region: null },
      ),
    );
    ids.push(...options.steps.map((step) => step.id));
  }

  if (options.submit === true) {
    points.push(
      point(
        'command.form.submit@1',
        [
          fill('acme', 'acme.guard', 1000, {
            kind: 'decorator',
            props: { command: 'actor.activate', version: 1, title: 'Activate' },
            decorator: { tightens: ['disabled_reason'] },
          }),
        ],
        { kind: 'decorator', region: null },
      ),
    );
    ids.push('acme.guard');
  }

  if (options.receipt === true) {
    points.push(
      point(
        'command.form.receipt@1',
        [
          fill('acme', 'acme.receipt', 1000, {
            kind: 'decorator',
            props: null,
            decorator: { tightens: [] },
          }),
        ],
        { kind: 'decorator', region: null },
      ),
    );
    ids.push('acme.receipt');
  }

  if (options.dryRun === true) {
    points.push(
      point('command.form.dryrun@1', [fill('acme', 'acme.dryrun', 1000, { props: null })], {
        kind: 'slot',
        region: 'sections',
      }),
    );
    ids.push('acme.dryrun');
  }

  if (options.field === true) {
    points.push(
      point(
        'command.form.field@1',
        [
          fill('acme', 'acme.actor-input', 1000, {
            kind: 'replacement',
            props: null,
            data: true,
            replacement: { key: ACTOR_ID_CLASS },
          }),
        ],
        { kind: 'replacement', region: null, multiplicity: 'exclusive' },
      ),
    );
    ids.push('acme.actor-input');
  }

  return {
    contributions: contributions(points, { acme: ids }),
    registrations: { acme: registration(options.implementations as never) },
    ext: { acme: { 'acme.actor-input': { actors: [{ id: ACTOR }] } } },
  };
}

function render(
  answers: readonly CommandAnswer[],
  options: Pick<HostOptions, 'contributions' | 'registrations' | 'ext'>,
  errors: unknown = {},
  bindings: readonly { readonly path: string; readonly class: string }[] = [],
) {
  const queue = [...answers];
  const calls: CommandCall[] = [];

  return {
    calls,
    ...renderHost(
      <CommandForm
        command="actor.activate"
        version={1}
        read={readForm(ACTIVATE)}
        bindings={bindings}
        errors={errors}
      />,
      {
        ...options,
        texts: ADDON_TEXTS,
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

async function fillIn(user: ReturnType<typeof render>['user'], actor = ACTOR): Promise<void> {
  await user.type(screen.getByLabelText(new RegExp(`^${TEXT.actor}`)), actor);
  await user.type(screen.getByLabelText(new RegExp(`^${TEXT.version}`)), '1');
}

describe('the checks of the command form', () => {
  test("lists a warning with its field, and the server's error replaces it at the same path after the submit", async () => {
    // The page's errors prop holds the field errors of the rejection the redirect lands with; the
    // form reads them once the command answered.
    const { user, calls } = render(
      [REJECTED],
      form({
        checks: [{ id: 'acme.hint', severity: 'warning' }],
        implementations: { 'acme.hint': warning },
      }),
      { 'command.actor': 'Only a pending actor is activated.' },
    );

    expect(screen.queryByText(TEXT.hint)).toBeNull();
    await fillIn(user);
    expect(await screen.findByText(TEXT.hint)).toBeTruthy();
    expect(screen.getByText(`acme: ${TEXT.actor}`)).toBeTruthy();
    expect(screen.queryByText('Only a pending actor is activated.')).toBeNull();
    // A warning does not block: the submit goes through, and the server answers.
    await user.click(screen.getByRole('button', { name: TEXT.run }));
    await waitFor(() => {
      expect(calls).toHaveLength(1);
    });

    expect(
      await screen.findByText('Only a pending actor is activated.', {
        selector: '.cms-error-summary__link',
      }),
    ).toBeTruthy();
    expect(screen.queryByText(TEXT.hint)).toBeNull();
    expect(screen.getByLabelText(new RegExp(`^${TEXT.actor}`)).getAttribute('aria-invalid')).toBe(
      'true',
    );
  });

  test('holds the submit until an acknowledge issue is ticked, and sends nothing meanwhile', async () => {
    const { user, calls } = render(
      [COMMITTED],
      form({
        checks: [{ id: 'acme.tick', severity: 'acknowledge' }],
        implementations: { 'acme.tick': acknowledge },
      }),
    );

    await fillIn(user);
    expect(await screen.findByText(TEXT.mustTick)).toBeTruthy();
    await user.click(screen.getByRole('button', { name: TEXT.run }));

    expect(await screen.findByText(TEXT.held)).toBeTruthy();
    expect(calls).toEqual([]);

    await user.click(screen.getByRole('checkbox', { name: TEXT.acknowledge }));
    await user.click(screen.getByRole('button', { name: TEXT.run }));

    await screen.findByText(TEXT.saved, { selector: '.cms-callout__title' });
    expect(calls).toHaveLength(1);
    expect(screen.queryByText(TEXT.held)).toBeNull();
  });

  test('blocks the submit on an error at its field, which only a mirrored check gives', async () => {
    const { user, calls } = render(
      [COMMITTED],
      form({
        checks: [{ id: 'acme.frozen', severity: 'error' }],
        implementations: { 'acme.frozen': blocking },
      }),
    );

    await fillIn(user);
    await waitFor(() => {
      expect(screen.getByLabelText(new RegExp(`^${TEXT.actor}`)).getAttribute('aria-invalid')).toBe(
        'true',
      );
    });
    expect(screen.getAllByText(TEXT.blocked).length).toBeGreaterThan(0);

    await user.click(screen.getByRole('button', { name: TEXT.run }));

    expect(await screen.findByText(TEXT.held)).toBeTruthy();
    expect(calls).toEqual([]);
    // A dry run is explicit and never held: it commits nothing and shows what the kernel answers.
    await user.click(screen.getByRole('button', { name: TEXT.tryIt }));
    await waitFor(() => {
      expect(calls).toHaveLength(1);
    });
    expect(calls[0]?.options.dryRun).toBe(true);
  });

  test("weighs an error down to the check's declared severity, so an unmirrored check cannot block", async () => {
    const { user, calls } = render(
      [COMMITTED],
      form({
        checks: [{ id: 'acme.frozen', severity: 'warning' }],
        implementations: { 'acme.frozen': blocking },
      }),
    );

    await fillIn(user);
    expect(await screen.findByText(TEXT.blocked)).toBeTruthy();
    expect(
      screen.getByLabelText(new RegExp(`^${TEXT.actor}`)).getAttribute('aria-invalid'),
    ).toBeNull();

    await user.click(screen.getByRole('button', { name: TEXT.run }));
    await waitFor(() => {
      expect(calls).toHaveLength(1);
    });
  });
});

describe('the steps of the command form', () => {
  test("runs a step before the submit, which cancels in its addon's name, or patches its path and goes on to the core's confirmation", async () => {
    const { user, calls } = render(
      [COMMITTED],
      form({
        steps: [{ id: 'acme.review', position: 'before_submit', patches: ['version'] }],
        implementations: { 'acme.review': lazy(Review) },
      }),
    );

    await fillIn(user);
    await user.click(screen.getByRole('button', { name: TEXT.run }));

    expect(await screen.findByText(`${OWN.reviewing} ${ACTOR}`)).toBeTruthy();
    expect(screen.getByText('Step 1, from acme')).toBeTruthy();
    expect(calls).toEqual([]);

    await user.click(screen.getByRole('button', { name: OWN.stop }));

    expect(await screen.findByText(TEXT.stopped)).toBeTruthy();
    expect(screen.getByText(TEXT.cancelled)).toBeTruthy();
    expect(calls).toEqual([]);

    await user.click(screen.getByRole('button', { name: 'Back to the form' }));
    await user.click(screen.getByRole('button', { name: TEXT.run }));
    await user.click(await screen.findByRole('button', { name: OWN.useVersion }));

    expect(await screen.findByText(TEXT.confirmTitle)).toBeTruthy();
    expect(calls).toEqual([]);

    await user.click(screen.getByRole('button', { name: TEXT.confirm }));

    await screen.findByText(TEXT.saved, { selector: '.cms-callout__title' });
    expect(calls).toHaveLength(1);
    expect(calls[0]?.document).toEqual({ actor: ACTOR, version: 7 });
    expect(screen.getByLabelText(new RegExp(`^${TEXT.version}`))).toHaveProperty('value', '7');
  });

  test('runs no step for a dry run, and hands a step after the receipt the receipt and the commands its addon may issue', async () => {
    const { user, calls } = render(
      [DRY_RUN, COMMITTED, COMMITTED],
      form({
        steps: [
          { id: 'acme.review', position: 'before_submit', patches: ['version'] },
          { id: 'acme.follow-up', position: 'after_receipt', patches: [] },
        ],
        implementations: { 'acme.review': lazy(Review), 'acme.follow-up': lazy(FollowUp) },
      }),
    );

    await fillIn(user);
    await user.click(screen.getByRole('button', { name: TEXT.tryIt }));

    await screen.findByText(TEXT.dryRunTitle);
    expect(screen.queryByText(`${OWN.reviewing} ${ACTOR}`)).toBeNull();
    expect(calls).toHaveLength(1);

    await user.click(screen.getByRole('button', { name: TEXT.run }));
    await user.click(await screen.findByRole('button', { name: OWN.useVersion }));
    await user.click(await screen.findByRole('button', { name: TEXT.confirm }));

    expect(await screen.findByText(`${OWN.followUp} committed`)).toBeTruthy();
    await user.click(screen.getByRole('button', { name: OWN.request }));

    await waitFor(() => {
      expect(calls).toHaveLength(3);
    });
    expect(calls[2]).toMatchObject({
      command: 'acme.request@1',
      document: { actor: ACTOR },
      provenance: 'addon:acme:acme.follow-up',
    });
    await waitFor(() => {
      expect(screen.queryByText(`${OWN.followUp} committed`)).toBeNull();
    });
  });
});

describe('the decorators, the field replacement and the dry run slot of the command form', () => {
  test("disables the run with the submit decorator's reason", async () => {
    const { user, calls } = render(
      [COMMITTED],
      form({ submit: true, implementations: { 'acme.guard': guard } }),
    );

    await fillIn(user);
    expect(await screen.findByText(TEXT.cannotRun)).toBeTruthy();
    expect(screen.getByText(`acme: ${TEXT.disabled}`)).toBeTruthy();
    expect(screen.getByRole('button', { name: TEXT.run })).toHaveProperty('disabled', true);
    await user.click(screen.getByRole('button', { name: TEXT.run }));
    expect(calls).toEqual([]);
  });

  test('renders the input of a member bound to a value class with the winning replacement, with its data, and the receipt and dry run points once the command answered', async () => {
    const { user, calls } = render(
      [DRY_RUN],
      form({
        field: true,
        receipt: true,
        dryRun: true,
        implementations: {
          'acme.actor-input': lazy(Picked),
          'acme.receipt': receiptDecorator,
          'acme.dryrun': lazy(DryRunSection),
        },
      }),
      {},
      [{ path: 'actor', class: ACTOR_ID_CLASS }],
    );

    const picked = await screen.findByLabelText(`${OWN.picked} ${TEXT.actor} (ready)`);
    await user.type(picked, ACTOR);
    await user.type(screen.getByLabelText(new RegExp(`^${TEXT.version}`)), '1');
    await user.click(screen.getByRole('button', { name: TEXT.tryIt }));

    await screen.findByText(TEXT.dryRunTitle);
    expect(calls[0]?.document).toEqual({ actor: ACTOR, version: 1 });
    expect(await screen.findByText(`${OWN.decoration} actor.activate 1`)).toBeTruthy();
    expect(await screen.findByText(`${OWN.dryRunSection} 1 ${OWN.change}`)).toBeTruthy();
  });
});
