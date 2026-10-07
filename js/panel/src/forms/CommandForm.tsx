// The generic command form (GUARDRAILS 2.2, 8; PRD 6.1, 8.4): a form rendered from a command's
// JSON Schema by the kit's SchemaForm, for any command the Inertia profile exposes, so every
// command the palette offers can be run with the keyboard. It names no command: the command, its
// version and its schema are the page's props. Before it submits, it checks the document with the
// generated runtime validator, through the rules read from the schema (rules.ts), and shows the
// first issue at its field; then it runs the command through the host's transport, as the viewer,
// with one idempotency key per form instance, so the same form submitted twice gives one
// changeset, with a dry run when asked and the wait level chosen. It shows the receipt's outcome,
// what a dry run would change, and, for a rejection, the problem details and each error at its
// field, read from the page's errors prop. A commit ends the instance: the next run gets a new key.

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
  ProblemDetails,
  ReceiptStatus,
  SchemaForm,
  Section,
  Select,
  Stack,
  initialDocument,
  isSchemaUnsupported,
  readCommandSchema,
  type FormModel,
  type JsonObject,
  type UnsupportedSchema,
} from '@cboxdk/cms-ui-kit';
import type { CommandAnswer } from '@cboxdk/cms-panel/extend';
import { useId, useMemo, useState, type SubmitEvent } from 'react';

import type { ReceiptV1 } from '../generated/protocol/ReceiptV1';
import { validate } from '../generated/validation';
import { dryRunReport } from '../host/actions';
import { useHostRuntime } from '../host/runtime';
import { useTranslation, type TranslationKey } from '../i18n/translations';
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

/** The props of CommandForm. */
export interface CommandFormProps {
  /** The command's name, as the page's props give it. */
  readonly command: string;
  readonly version: number;
  /** What the page read from the command's schema. */
  readonly read: ReadForm;
  /** The page's errors prop, which a rejected submit leaves the field errors in. */
  readonly errors: unknown;
}

/** The form of the command, or why there is none. */
export function CommandForm({ command, version, read, errors }: CommandFormProps) {
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

  return <FormBody command={command} version={version} model={read.model} errors={errors} />;
}

/** How a submit ended, as the form shows it. */
type Outcome =
  { readonly status: 'answered'; readonly answer: CommandAnswer } | { readonly status: 'failed' };

function FormBody({
  command,
  version,
  model,
  errors,
}: {
  readonly command: string;
  readonly version: number;
  readonly model: FormModel;
  readonly errors: unknown;
}) {
  const { t, locale } = useTranslation();
  const { services } = useHostRuntime();
  const idPrefix = useId();
  const rules = useMemo(() => rulesOf(model), [model]);
  const texts = useMemo(() => fieldTexts(locale, command), [locale, command]);
  const [document, setDocument] = useState<JsonObject>(() => initialDocument(model.root));
  const [key, setKey] = useState(() => crypto.randomUUID());
  const [dryRun, setDryRun] = useState(false);
  const [waitLevel, setWaitLevel] = useState<ReceiptV1['wait_level']>('commit');
  const [submitting, setSubmitting] = useState(false);
  const [clientErrors, setClientErrors] = useState<Readonly<Record<string, string>>>({});
  const [outcome, setOutcome] = useState<Outcome | null>(null);
  const shown = outcome === null ? clientErrors : serverErrors(errors);
  const summary = Object.entries(shown)
    .filter(([path]) => path !== FORM_PATH)
    .map(([path, message]) => ({ target: `${idPrefix}-${path}`, message }));
  const formError = shown[FORM_PATH];
  const answer = outcome?.status === 'answered' ? outcome.answer : null;

  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();

    const checked = validate<JsonObject>(document, rules);

    if (!checked.valid) {
      setOutcome(null);
      setClientErrors({ [checked.issue.path ?? FORM_PATH]: checked.issue.reason });

      return;
    }

    setClientErrors({});
    setSubmitting(true);
    services
      .runCommand({
        command: `${command}@${String(version)}`,
        document,
        options: { dryRun, waitLevel },
        key,
      })
      .then((received) => {
        setOutcome({ status: 'answered', answer: received });

        if (
          received.receipt.outcome === 'committed' ||
          received.receipt.outcome === 'committed_wait_timeout'
        ) {
          setKey(crypto.randomUUID());
        }
      })
      .catch(() => {
        setOutcome({ status: 'failed' });
      })
      .finally(() => {
        setSubmitting(false);
      });
  }

  return (
    <Stack gap="lg">
      {summary.length > 0 ? (
        <ErrorSummary
          title={t(
            outcome === null
              ? 'panel.command_form.invalid_title'
              : 'panel.command_form.refused_title',
          )}
          errors={summary}
        />
      ) : null}
      {formError === undefined ? null : (
        <Callout tone="danger" title={t('panel.command_form.refused_title')}>
          {formError}
        </Callout>
      )}
      <Form noValidate onSubmit={submit} data-cms-form-key={key}>
        <SchemaForm
          model={model}
          value={document}
          onChange={setDocument}
          errors={shown}
          texts={texts}
          idPrefix={idPrefix}
          disabled={submitting}
          checkJson={(value) => {
            const issue = fieldValuesIssue(value);

            return issue === undefined
              ? []
              : [t('panel.command_form.fields_invalid', { reason: issue })];
          }}
        />
        <Fieldset
          legend={t('panel.command_form.options')}
          description={t('panel.command_form.options_description')}
        >
          <Checkbox
            name="dry_run"
            label={t('panel.command_form.dry_run')}
            description={t('panel.command_form.dry_run_description')}
            checked={dryRun}
            onChange={setDryRun}
            disabled={submitting}
          />
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
            disabled={submitting}
          />
        </Fieldset>
        <FormActions>
          <Button type="submit" variant="primary" disabled={submitting}>
            {t(dryRun ? 'panel.command_form.try' : 'panel.command_form.run')}
          </Button>
        </FormActions>
      </Form>
      {outcome?.status === 'failed' ? (
        <Callout tone="warning" title={t('panel.command_form.failed_title')}>
          {t('panel.command_form.failed_body')}
        </Callout>
      ) : null}
      {answer === null ? null : <Answer answer={answer} />}
    </Stack>
  );
}

/** What the command answered: the receipt, what a dry run would change, and a rejection's problem. */
function Answer({ answer }: { readonly answer: CommandAnswer }) {
  const { t } = useTranslation();
  const committed =
    answer.receipt.outcome === 'committed' || answer.receipt.outcome === 'committed_wait_timeout';

  return (
    <Section title={t('panel.command_form.result')}>
      <Stack gap="md">
        <ReceiptStatus receipt={answer.receipt} />
        {committed ? (
          <Callout tone="success" title={t('panel.command_form.committed_title')}>
            {t('panel.command_form.committed_body')}
          </Callout>
        ) : null}
        {answer.dryRun === null ? null : (
          <Section title={t('panel.command_form.dry_run_report')} headingLevel={3}>
            <DryRunReport report={dryRunReport(answer.dryRun)} />
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
