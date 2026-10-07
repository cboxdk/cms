// The generic command form (GUARDRAILS 2.2, 8; PRD 6.1, 8.4): a form rendered from a command's
// JSON Schema by the kit's SchemaForm, for any command the Inertia profile exposes, so every
// command the palette offers can be run with the keyboard. It names no command: the command, its
// version, its schema and the value classes it binds its members to are the page's props.
//
// The form hosts the points of its page (section 8 of the panel extension architecture): the
// checks of command.form.checks@1 run on every edit and show their issues, an issue of severity
// error at its field, which blocks the submit, the others listed with their field, one of severity
// acknowledge with the tick that lets the submit go on; the steps of command.form.steps@1 run
// before the submit, each in turn, and may patch the paths their manifest declares or cancel, then
// the core's own confirmation runs last, and after the receipt the steps of that position run
// with it; the decorator command.form.submit@1 tightens the form's actions; the input of a member
// bound to a value class is the replacement at command.form.field@1 for that class, the core's
// pickers of NodeId, ActorId and RoleId among them; the receipt is decorated by
// command.form.receipt@1; and what a dry run would change is followed by command.form.dryrun@1.
//
// Before it submits, it checks the document with the generated runtime validator, through the
// rules read from the schema (rules.ts), and shows the first issue at its field; then it runs the
// command through the host's transport, as the viewer, with one idempotency key per form instance,
// so the same form submitted twice gives one changeset, and the wait level chosen. A dry run is
// explicit: its own button, which commits nothing, runs no step and needs no acknowledgement, and
// shows what the kernel would answer. The form shows the receipt's outcome, what a dry run would
// change, and, for a rejection, the problem details and each error at its field, read from the
// page's errors prop, which replace the checks' issues at the same paths. A commit ends the
// instance: the next run gets a new key.

import {
  Badge,
  Button,
  Callout,
  Checkbox,
  DryRunReport,
  ErrorState,
  ErrorSummary,
  Fieldset,
  Form,
  FormActions,
  Inline,
  ProblemDetails,
  ReceiptStatus,
  SchemaForm,
  Section,
  Select,
  Stack,
  TextLink,
  initialDocument,
  isSchemaUnsupported,
  readCommandSchema,
  type FormModel,
  type JsonObject,
  type SchemaFormField,
  type UnsupportedSchema,
} from '@cboxdk/cms-ui-kit';
import type { CommandAnswer } from '@cboxdk/cms-panel/extend';
import {
  useCallback,
  useEffect,
  useId,
  useMemo,
  useState,
  useSyncExternalStore,
  type ReactNode,
  type SubmitEvent,
} from 'react';

import type { CommandFormPageV1 } from '../generated/pages/CommandFormPageV1';
import type { ReceiptV1 } from '../generated/protocol/ReceiptV1';
import { validate } from '../generated/validation';
import { FlowHost, PointHost, useCoreServices, usePointHost, type Tightened } from '../host';
import { dryRunReport } from '../host/actions';
import { useAddonName } from '../host/boundary';
import {
  issueKey,
  maySubmit,
  type CheckRun,
  type CheckRuns,
  type FoundIssue,
} from '../host/checks';
import type { FlowRun } from '../host/flow';
import { useHostRuntime } from '../host/runtime';
import { useTranslation, type TranslationKey } from '../i18n/translations';
import { fieldInputProps } from './field-input';
import { atControls, fieldErrors, labelAt, withoutServerPaths } from './issues';
import { FORM_PATH, fieldValuesIssue, rulesOf, serverErrors } from './rules';
import { fieldTexts } from './texts';

/** What the page read from the command's schema: the form, or why there is none, with the texts. */
export type ReadForm =
  | {
      readonly model: FormModel;
      readonly title: string | undefined;
      readonly description: string | undefined;
    }
  | {
      readonly failure: UnsupportedSchema;
      readonly title: string | undefined;
      readonly description: string | undefined;
    };

/**
 * Reads the command's schema into the form's model, or into the reason the form cannot be shown,
 * with the schema's title and description either way.
 */
export function readForm(schema: unknown): ReadForm {
  const record =
    typeof schema === 'object' && schema !== null && !Array.isArray(schema)
      ? (schema as Readonly<Record<string, unknown>>)
      : {};
  const text = (key: string): string | undefined => {
    const value = record[key];

    return typeof value === 'string' && value.trim() !== '' ? value : undefined;
  };
  const title = text('title');
  const description = text('description');

  try {
    return { model: readCommandSchema(schema), title, description };
  } catch (failure: unknown) {
    if (isSchemaUnsupported(failure)) {
      return { failure, title, description };
    }

    throw failure;
  }
}

/** The wait levels a form offers, in the order of the envelope's schema, each with its text. */
const WAIT_LEVELS: readonly {
  readonly id: ReceiptV1['wait_level'];
  readonly key: TranslationKey;
}[] = [
  { id: 'commit', key: 'panel.command_form.wait.commit' },
  { id: 'origin', key: 'panel.command_form.wait.origin' },
  { id: 'edge', key: 'panel.command_form.wait.edge' },
  { id: 'verified', key: 'panel.command_form.wait.verified' },
  { id: 'propagated', key: 'panel.command_form.wait.propagated' },
];

/** Whether the wait level is one the form offers. */
function isWaitLevel(value: string): value is ReceiptV1['wait_level'] {
  return WAIT_LEVELS.some((level) => level.id === value);
}

/** The point of the form's checks, as the page declares it. */
const CHECKS = 'command.form.checks@1';

/** The point of the form's steps, as the page declares it. */
const STEPS = 'command.form.steps@1';

/** The props of CommandForm. */
export interface CommandFormProps {
  /** The command's name, as the page's props give it. */
  readonly command: string;
  readonly version: number;
  /** What the page read from the command's schema. */
  readonly read: ReadForm;
  /** The value classes the command binds its members to, by path, as the page's props give them. */
  readonly bindings: CommandFormPageV1['bindings'];
  /** The page's errors prop, which a rejected submit leaves the field errors in. */
  readonly errors: unknown;
}

/** The form of the command, or why there is none. */
export function CommandForm({ command, version, read, bindings, errors }: CommandFormProps) {
  const { t } = useTranslation();

  if ('failure' in read) {
    return (
      <ErrorState
        title={t('panel.command_form.unsupported_title')}
        description={t('panel.command_form.unsupported_body', {
          keyword: read.failure.keyword,
          pointer: read.failure.pointer,
        })}
      />
    );
  }

  return (
    <FormBody
      command={command}
      version={version}
      model={read.model}
      bindings={bindings}
      errors={errors}
    />
  );
}

/** How a submit ended, as the form shows it. */
type Outcome =
  | { readonly status: 'answered'; readonly answer: CommandAnswer; readonly dryRun: boolean }
  | { readonly status: 'failed' };

function isCommitted(receipt: ReceiptV1): boolean {
  return receipt.outcome === 'committed' || receipt.outcome === 'committed_wait_timeout';
}

function FormBody({
  command,
  version,
  model,
  bindings,
  errors,
}: {
  readonly command: string;
  readonly version: number;
  readonly model: FormModel;
  readonly bindings: CommandFormPageV1['bindings'];
  readonly errors: unknown;
}) {
  const { t, locale } = useTranslation();
  const runtime = useHostRuntime();
  const { runCommand: runAsViewer } = useCoreServices();
  const checks = usePointHost('command.form.checks@1');
  const steps = usePointHost('command.form.steps@1');
  const idPrefix = useId();
  const ref = `${command}@${String(version)}`;
  const rules = useMemo(() => rulesOf(model), [model]);
  const texts = useMemo(() => fieldTexts(locale, command), [locale, command]);
  const [document, setDocument] = useState<JsonObject>(() => initialDocument(model.root));
  const [key, setKey] = useState(() => crypto.randomUUID());
  const [waitLevel, setWaitLevel] = useState<ReceiptV1['wait_level']>('commit');
  const [submitting, setSubmitting] = useState(false);
  const [clientErrors, setClientErrors] = useState<Readonly<Record<string, string>>>({});
  const [outcome, setOutcome] = useState<Outcome | null>(null);
  const [acknowledged, setAcknowledged] = useState<ReadonlySet<string>>(() => new Set());
  const [held, setHeld] = useState(false);
  const [flow, setFlow] = useState<FlowRun | null>(null);
  const [followUp, setFollowUp] = useState<FlowRun | null>(null);
  const loadedChecks = checks.loaded.join('\n');
  const runChecks = checks.checks;
  // The checks run on every edit, and again when an addon's code arrives: the synchronous ones
  // give their issues at once, the asynchronous ones as they settle, cancelled by the next edit.
  const checked = useMemo<{ readonly edits: AbortController; readonly runs: CheckRuns }>(() => {
    const edits = new AbortController();

    return { edits, runs: runChecks(ref, document, edits.signal) };
    // The checks are those of the contributions loaded, which loadedChecks names; the handle's
    // function is rebuilt on every render and would rerun them on every render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ref, document, loadedChecks]);
  const [settled, setSettled] = useState<{ readonly of: CheckRuns; readonly run: CheckRun } | null>(
    null,
  );

  useEffect(() => {
    const { edits, runs } = checked;
    void runs.settled.then((run) => {
      if (!edits.signal.aborted) {
        setSettled({ of: runs, run });
      }
    });

    return () => {
      edits.abort();
    };
  }, [checked]);

  const issues = settled?.of === checked.runs ? settled.run : checked.runs.now;

  const issueText = useCallback(
    (issue: FoundIssue): string => runtime.text(issue.addon, issue.message, issue.parameters),
    [runtime],
  );
  const server = useMemo(() => serverErrors(errors), [errors]);
  const answered = outcome?.status === 'answered' && !outcome.dryRun;
  const shownIssues = answered ? withoutServerPaths(issues.issues, server) : issues.issues;
  const blocking = shownIssues.filter((issue) => issue.severity === 'error');
  const acknowledgements = shownIssues.filter((issue) => issue.severity === 'acknowledge');
  const notes = shownIssues.filter(
    (issue) => issue.severity === 'info' || issue.severity === 'warning',
  );
  const shown = atControls(
    answered ? server : fieldErrors(clientErrors, blocking, issueText),
    model,
  );
  const summary = Object.entries(shown)
    .filter(([path]) => path !== FORM_PATH)
    .map(([path, message]) => ({ target: `${idPrefix}-${path}`, message }));
  const formError = shown[FORM_PATH];
  const answer = outcome?.status === 'answered' ? outcome.answer : null;
  const busy = submitting || flow !== null;

  const runCommand = useCallback(
    (draft: JsonObject, dryRun: boolean): Promise<CommandAnswer> =>
      runAsViewer(ref, draft, { dryRun, waitLevel }, key),
    [runAsViewer, ref, waitLevel, key],
  );

  function submit(draft: JsonObject, dryRun: boolean): void {
    setSubmitting(true);
    setFollowUp(null);
    runCommand(draft, dryRun)
      .then((received) => {
        setOutcome({ status: 'answered', answer: received, dryRun });

        if (isCommitted(received.receipt)) {
          setKey(crypto.randomUUID());
        }

        if (!dryRun) {
          const after = steps.flow(ref, 'after_receipt', draft);
          setFollowUp(after.state.phase === 'done' ? null : after);
        }
      })
      .catch(() => {
        setOutcome({ status: 'failed' });
      })
      .finally(() => {
        setSubmitting(false);
      });
  }

  /** Validates the document, and gives it when it may be sent. */
  function checkedDocument(): JsonObject | undefined {
    const checked = validate<JsonObject>(document, rules);

    if (!checked.valid) {
      setOutcome(null);
      setHeld(false);
      setClientErrors({ [checked.issue.path ?? FORM_PATH]: checked.issue.reason });

      return undefined;
    }

    setClientErrors({});

    return checked.value;
  }

  function run(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();

    const draft = checkedDocument();

    if (draft === undefined || busy) {
      return;
    }

    setSubmitting(true);
    void checked.runs.settled.then((run) => {
      setSubmitting(false);

      if (!maySubmit(run, acknowledged)) {
        setOutcome(null);
        setHeld(true);

        return;
      }

      setHeld(false);
      const started = steps.flow(ref, 'before_submit', draft);

      if (started.state.phase === 'confirm') {
        // No step: the viewer's press is the confirmation.
        const confirmed = started.confirm();

        if (confirmed !== undefined) {
          submit(confirmed, false);
        }

        return;
      }

      setOutcome(null);
      setFlow(started);
    });
  }

  function tryIt(): void {
    const draft = checkedDocument();

    if (draft !== undefined && !busy) {
      setHeld(false);
      submit(draft, true);
    }
  }

  const renderInput = useCallback(
    (field: SchemaFormField, input: ReactNode): ReactNode => {
      const binding = bindings.find((candidate) => candidate.path === field.path);

      if (binding === undefined) {
        return input;
      }

      return (
        <PointHost
          point="command.form.field@1"
          target={binding.class}
          props={fieldInputProps(field, { command, version, locale })}
          fallback={input}
        />
      );
    },
    [bindings, command, version, locale],
  );

  return (
    <Stack gap="lg">
      {summary.length > 0 ? (
        <ErrorSummary
          title={t(
            answered ? 'panel.command_form.refused_title' : 'panel.command_form.invalid_title',
          )}
          errors={summary}
        />
      ) : null}
      {formError === undefined ? null : (
        <Callout tone="danger" title={t('panel.command_form.refused_title')}>
          {formError}
        </Callout>
      )}
      {held ? (
        <Callout tone="warning" title={t('panel.command_form.held_title')}>
          {t('panel.command_form.held_body')}
        </Callout>
      ) : null}
      <Form noValidate onSubmit={run} data-cms-form-key={key}>
        <SchemaForm
          model={model}
          value={document}
          onChange={(next) => {
            setDocument(next);
            setHeld(false);
          }}
          errors={shown}
          texts={texts}
          idPrefix={idPrefix}
          disabled={busy}
          renderInput={renderInput}
          checkJson={(value) => {
            const issue = fieldValuesIssue(value);

            return issue === undefined
              ? []
              : [t('panel.command_form.fields_invalid', { reason: issue })];
          }}
        />
        {notes.length === 0 && acknowledgements.length === 0 ? null : (
          <Issues
            model={model}
            texts={texts}
            idPrefix={idPrefix}
            notes={notes}
            acknowledgements={acknowledgements}
            acknowledged={acknowledged}
            text={issueText}
            onAcknowledge={(issue, on) => {
              setAcknowledged((current) => {
                const next = new Set(current);

                if (on) {
                  next.add(issueKey(issue));
                } else {
                  next.delete(issueKey(issue));
                }

                return next;
              });
            }}
          />
        )}
        <Fieldset
          legend={t('panel.command_form.options')}
          description={t('panel.command_form.options_description')}
        >
          <Select
            id={`${idPrefix}-wait-level`}
            name="wait_level"
            label={t('panel.command_form.wait_level')}
            options={WAIT_LEVELS.map((level) => ({ id: level.id, label: t(level.key) }))}
            value={waitLevel}
            onChange={(value) => {
              if (isWaitLevel(value)) {
                setWaitLevel(value);
              }
            }}
            disabled={busy}
          />
        </Fieldset>
        <PointHost
          point="command.form.submit@1"
          render={(tightened: Tightened) => (
            <Actions tightened={tightened} busy={busy} onTry={tryIt} />
          )}
        />
      </Form>
      {flow === null ? null : (
        <Flow
          run={flow}
          dryRun={(draft) => runCommand(draft, true)}
          onConfirmed={(draft) => {
            setFlow(null);
            setDocument(draft);
            submit(draft, false);
          }}
          onCancelled={() => {
            setFlow(null);
          }}
        />
      )}
      {outcome?.status === 'failed' ? (
        <Callout tone="warning" title={t('panel.command_form.failed_title')}>
          {t('panel.command_form.failed_body')}
        </Callout>
      ) : null}
      {answer === null ? null : <Answer command={command} version={version} answer={answer} />}
      {followUp === null || answer === null ? null : (
        <FlowHost
          point={STEPS}
          run={followUp}
          dryRun={() => runCommand(document, true)}
          receipt={answer}
          confirmation={() => null}
          onConfirmed={() => undefined}
        />
      )}
    </Stack>
  );
}

/** The form's actions, as the decorators tightened them: the run, the dry run, and what they said. */
function Actions({
  tightened,
  busy,
  onTry,
}: {
  readonly tightened: Tightened;
  readonly busy: boolean;
  readonly onTry: () => void;
}) {
  const { t } = useTranslation();

  return (
    <Stack gap="sm">
      {tightened.disabledReasons.length === 0 ? null : (
        <Callout tone="warning" title={t('panel.command_form.disabled_title')}>
          <ul>
            {tightened.disabledReasons.map((reason) => (
              <li key={`${reason.addon} ${reason.text}`}>
                <AttributedText addon={reason.addon} text={reason.text} />
              </li>
            ))}
          </ul>
        </Callout>
      )}
      {tightened.descriptions.length === 0 ? null : (
        <ul className="cms-command-form__descriptions">
          {tightened.descriptions.map((description) => (
            <li key={`${description.addon} ${description.text}`}>
              <AttributedText addon={description.addon} text={description.text} />
            </li>
          ))}
        </ul>
      )}
      <FormActions>
        <Button
          type="submit"
          variant={tightened.tone === 'danger' ? 'danger' : 'primary'}
          disabled={busy || tightened.disabled}
        >
          {t('panel.command_form.run')}
        </Button>
        <Button type="button" variant="secondary" disabled={busy} onClick={onTry}>
          {t('panel.command_form.try')}
        </Button>
      </FormActions>
    </Stack>
  );
}

/** A text with the addon that said it. */
function AttributedText({ addon, text }: { readonly addon: string; readonly text: string }) {
  const { t } = useTranslation();
  const name = useAddonName(addon);

  return <>{t('panel.host.disabled_by', { addon: name, reason: text })}</>;
}

/** The issues the checks found that do not block: listed with their field, acknowledged where asked. */
function Issues({
  model,
  texts,
  idPrefix,
  notes,
  acknowledgements,
  acknowledged,
  text,
  onAcknowledge,
}: {
  readonly model: FormModel;
  readonly texts: ReturnType<typeof fieldTexts>;
  readonly idPrefix: string;
  readonly notes: readonly FoundIssue[];
  readonly acknowledgements: readonly FoundIssue[];
  readonly acknowledged: ReadonlySet<string>;
  readonly text: (issue: FoundIssue) => string;
  readonly onAcknowledge: (issue: FoundIssue, on: boolean) => void;
}) {
  const { t } = useTranslation();

  return (
    <Section title={t('panel.command_form.issues')}>
      <Stack gap="sm">
        {[...notes, ...acknowledgements].map((issue) => (
          <Issue
            key={issueKey(issue)}
            issue={issue}
            label={labelAt(model, issue.path, texts)}
            target={`${idPrefix}-${issue.path}`}
            text={text(issue)}
            acknowledged={acknowledged.has(issueKey(issue))}
            onAcknowledge={(on) => {
              onAcknowledge(issue, on);
            }}
          />
        ))}
      </Stack>
    </Section>
  );
}

function Issue({
  issue,
  label,
  target,
  text,
  acknowledged,
  onAcknowledge,
}: {
  readonly issue: FoundIssue;
  readonly label: string;
  readonly target: string;
  readonly text: string;
  readonly acknowledged: boolean;
  readonly onAcknowledge: (on: boolean) => void;
}) {
  const { t } = useTranslation();
  const name = useAddonName(issue.addon);

  return (
    <div
      className="cms-contribution"
      data-cms-addon={issue.addon}
      data-cms-point={CHECKS}
      data-cms-contribution={issue.contribution}
      data-cms-issue={issue.code}
    >
      <Callout
        tone={issue.severity === 'info' ? 'info' : 'warning'}
        title={t('panel.command_form.issue_title', { addon: name, field: label })}
        action={<TextLink href={`#${target}`}>{t('panel.command_form.go_to_field')}</TextLink>}
      >
        <Stack gap="sm">
          <p>{text}</p>
          {issue.severity === 'acknowledge' ? (
            <Checkbox
              label={t('panel.command_form.acknowledge')}
              checked={acknowledged}
              onChange={onAcknowledge}
            />
          ) : null}
        </Stack>
      </Callout>
    </div>
  );
}

/**
 * The flow before the submit: each step in turn, with where it is, then the core's own
 * confirmation, which alone ends the flow with the draft to submit.
 */
function Flow({
  run,
  dryRun,
  onConfirmed,
  onCancelled,
}: {
  readonly run: FlowRun;
  readonly dryRun: (draft: JsonObject) => Promise<CommandAnswer>;
  readonly onConfirmed: (draft: JsonObject) => void;
  readonly onCancelled: () => void;
}) {
  const { t } = useTranslation();
  const state = useSyncExternalStore(
    (listener) => run.subscribe(listener),
    () => run.state,
  );
  const stepAddon = useAddonName(state.phase === 'step' ? state.step.addon : '');

  return (
    <Section
      title={t('panel.command_form.flow')}
      description={
        state.phase === 'step'
          ? t('panel.command_form.step_of', { step: state.index + 1, addon: stepAddon })
          : undefined
      }
    >
      <Stack gap="md">
        <FlowHost
          point={STEPS}
          run={run}
          dryRun={() => dryRun(state.draft)}
          confirmation={(_draft, confirm) => (
            <Callout tone="info" title={t('panel.host.confirm_title')}>
              <Stack gap="sm">
                <p>{t('panel.host.confirm_body')}</p>
                <Inline gap="sm">
                  <Button type="button" variant="primary" onClick={confirm}>
                    {t('panel.host.confirm')}
                  </Button>
                  <Button type="button" variant="quiet" onClick={onCancelled}>
                    {t('panel.host.cancel')}
                  </Button>
                </Inline>
              </Stack>
            </Callout>
          )}
          onConfirmed={onConfirmed}
        />
        {state.phase === 'cancelled' || state.phase === 'step' ? (
          <Inline gap="sm">
            <Button type="button" variant="quiet" onClick={onCancelled}>
              {t(state.phase === 'cancelled' ? 'panel.command_form.back' : 'panel.host.cancel')}
            </Button>
          </Inline>
        ) : null}
      </Stack>
    </Section>
  );
}

/**
 * What the command answered: the receipt, decorated by command.form.receipt@1, what a dry run
 * would change, followed by command.form.dryrun@1, and a rejection's problem.
 */
function Answer({
  command,
  version,
  answer,
}: {
  readonly command: string;
  readonly version: number;
  readonly answer: CommandAnswer;
}) {
  const { t } = useTranslation();
  const committed = isCommitted(answer.receipt);

  return (
    <Section title={t('panel.command_form.result')}>
      <Stack gap="md">
        <PointHost
          point="command.form.receipt@1"
          props={{ command, version, receipt: answer.receipt, problem: answer.problem }}
          render={() => <ReceiptStatus receipt={answer.receipt} />}
        />
        {committed ? (
          <Callout tone="success" title={t('panel.command_form.committed_title')}>
            {t('panel.command_form.committed_body')}
          </Callout>
        ) : null}
        {answer.dryRun === null ? null : (
          <Section title={t('panel.command_form.dry_run_report')} headingLevel={3}>
            <Stack gap="md">
              <DryRunReport report={dryRunReport(answer.dryRun)} />
              <PointHost
                point="command.form.dryrun@1"
                props={{ command, version, summary: answer.dryRun, receipt: answer.receipt }}
              />
            </Stack>
          </Section>
        )}
        {answer.problem === null ? null : (
          <ProblemDetails problem={answer.problem} explanation={t('panel.host.refused')} />
        )}
      </Stack>
    </Section>
  );
}

/** The command and version as the page's header shows them. */
export function CommandBadge({
  command,
  version,
}: {
  readonly command: string;
  readonly version: number;
}) {
  return <Badge tone="neutral">{`${command}@${String(version)}`}</Badge>;
}
