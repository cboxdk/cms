import {
  Badge,
  Breadcrumbs,
  Button,
  Callout,
  EmptyState,
  EntryForm,
  ErrorState,
  Form,
  Inline,
  KeyboardShortcut,
  newEntryFields,
  NodePicker,
  Page,
  PageHeader,
  ProblemDetails,
  Stack,
  TextLink,
  validateEntry,
  type EntryFormErrors,
  type JsonObject,
} from '@cboxdk/cms-ui-kit';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState, type SubmitEvent } from 'react';

import { uuid7 } from '../../access/ids';
import { explanationOf } from '../../access/problems';
import {
  committed,
  CREATE,
  createDocument,
  editorStateOf,
  editorTitleOf,
  entryUrl,
  isSaveShortcut,
  mayRun,
  REVISE,
  reviseDocument,
  saveErrorsOf,
  saveStatusOf,
  type EditorState,
} from '../../content/editor';
import { STATE_STATUS } from '../../content/list';
import { typesOf } from '../../content/types';
import type { ContentEditorPageV1 } from '../../generated/pages/ContentEditorPageV1';
import type { ProblemV1 } from '../../generated/protocol/ProblemV1';
import type { ReceiptV1 } from '../../generated/protocol/ReceiptV1';
import { useCoreServices } from '../../host';
import { useHostRuntime } from '../../host/runtime';
import { useTranslation } from '../../i18n/translations';
import { paletteOf } from '../../shell/palette';
import { nodeTree } from '../Access/Grants';

import '../../content/editor.css';
import { commandFormUrl, PanelShell } from '../../shell/PanelShell';

/**
 * The entry editor (PRD 5.4, 11.12, 13.4), after the editor of round 1 of the panel's design: the
 * page for a new entry of a type and the page of an entry, with the entry's title, its state and
 * what the last save's receipt said in the header, and the form of the type rendered by the kit's
 * EntryForm from the editor descriptor type.describe answered with. The props are
 * ContentEditorPageV1, generated from the page's JSON Schema: the reads of type.describe, of
 * entry.get for an entry and of node.list for a new entry, whose home the person chooses in the
 * tree of the nodes they reach. Save, or ⌘↵ (Ctrl+Enter), checks the fields on the client and runs
 * entry.create or entry.revise version 1 through the Inertia profile as the person, with a new
 * idempotency key per save and, for a revise, the version of the shared variant the page read, so
 * a save over a change someone else made is refused with version_conflict instead of overwriting
 * it. A rejected save shows each error at its field, and its problem details with what to do; a
 * save that committed shows that it did, from its receipt, and a new entry's page becomes the
 * page of the entry. While a save runs, the page says so; a read the server refused, or answered
 * with what the panel cannot read, shows why and the way back to the list.
 */
export default function Editor(props: ContentEditorPageV1) {
  const { t } = useTranslation();
  const page = usePage();
  const state = useMemo(() => editorStateOf(props), [props]);
  const types = useMemo(() => typesOf(page.props['types']), [page.props]);
  const content = types.content ?? props.list;
  const descriptor = 'descriptor' in state ? state.descriptor : undefined;

  function signOut(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault();
    router.post(props.logout);
  }

  return (
    <PanelShell page="content.editor" contentType={props.type}>
      {state.status === 'ready' ? (
        <EntryEditor
          key={state.descriptor.name}
          props={props}
          state={state}
          content={content}
          onSignOut={signOut}
        />
      ) : (
        <>
          <Head title={descriptor?.label ?? t('panel.editor.title')} />
          <Page
            width="narrow"
            header={
              <PageHeader
                title={descriptor?.label ?? t('panel.editor.title')}
                breadcrumbs={
                  <Breadcrumbs
                    label={t('panel.editor.breadcrumbs')}
                    items={[
                      { id: 'content', label: t('panel.content.title'), href: content },
                      ...(descriptor === undefined
                        ? []
                        : [{ id: 'type', label: descriptor.label, href: props.list }]),
                    ]}
                  />
                }
              />
            }
          >
            <Unavailable state={state} list={props.list} />
            <Form method="post" action={props.logout} onSubmit={signOut}>
              <Button type="submit">{t('panel.home.sign_out')}</Button>
            </Form>
          </Page>
        </>
      )}
    </PanelShell>
  );
}

/** Why the page shows no form, and the way on. */
function Unavailable({
  state,
  list,
}: {
  readonly state: Exclude<EditorState, { readonly status: 'ready' }>;
  readonly list: string;
}) {
  const { t, locale } = useTranslation();
  const { contributions } = useHostRuntime();
  const back = <TextLink href={list}>{t('panel.editor.back')}</TextLink>;

  switch (state.status) {
    case 'missing_type':
      return (
        <ErrorState
          title={t('panel.editor.missing_title')}
          description={t('panel.editor.missing_body')}
          action={back}
          headingLevel={2}
        />
      );
    case 'localized':
      return (
        <EmptyState
          title={t('panel.editor.localized_title')}
          description={t('panel.editor.localized_body')}
          action={
            <Inline gap="sm" wrap>
              <Button
                variant="primary"
                onClick={() => {
                  router.visit(commandFormUrl(contributions.commands, 'entry.create@2'));
                }}
              >
                {t('panel.editor.localized_action')}
              </Button>
              {back}
            </Inline>
          }
          headingLevel={2}
        />
      );
    case 'rejected':
      return (
        <Stack gap="md">
          <ProblemDetails
            problem={state.problem}
            explanation={explanationOf(locale, t, state.problem, 'panel.editor.refused')}
          />
          {back}
        </Stack>
      );
    case 'unreadable':
      return (
        <ErrorState
          title={t('panel.editor.unavailable_title')}
          description={t('panel.editor.unavailable_body')}
          action={back}
          headingLevel={2}
        />
      );
  }
}

/** What the last save gave: the kernel's answer, or no answer the panel could read. */
type Outcome =
  | { readonly status: 'answered'; readonly receipt: ReceiptV1; readonly problem: ProblemV1 | null }
  | { readonly status: 'failed' };

/** The form of a type the page can show, with its save. */
function EntryEditor({
  props,
  state,
  content,
  onSignOut,
}: {
  readonly props: ContentEditorPageV1;
  readonly state: Extract<EditorState, { readonly status: 'ready' }>;
  readonly content: string;
  readonly onSignOut: (event: SubmitEvent<HTMLFormElement>) => void;
}) {
  const { t, locale } = useTranslation();
  const page = usePage();
  const { runCommand } = useCoreServices();
  const palette = useMemo(() => paletteOf(page.props.palette), [page.props.palette]);
  const { descriptor, entry, head } = state;
  const [created] = useState(() => uuid7());
  const [fields, setFields] = useState<JsonObject>(() =>
    head === null ? newEntryFields(descriptor, new Date()) : head.fields,
  );
  const [home, setHome] = useState<string | null>(null);
  const [showErrors, setShowErrors] = useState<EntryFormErrors>('touched');
  const [saving, setSaving] = useState(false);
  const [outcome, setOutcome] = useState<Outcome | null>(null);
  const [edited, setEdited] = useState(false);
  const [homeMissing, setHomeMissing] = useState(false);
  const command = entry === null ? CREATE : REVISE;
  const allowed = mayRun(palette, command);
  const problem = outcome?.status === 'answered' ? outcome.problem : null;
  const errors = useMemo(() => saveErrorsOf(problem, locale), [problem, locale]);
  const shownErrors = edited ? {} : errors.fields;
  // A rejection whose every error is at a field of the form is shown there and in the form's
  // summary of errors; one with an error about the entry as a whole, such as a version conflict,
  // shows its problem details too.
  const problemShown =
    errors.other > 0 || (Object.keys(errors.fields).length === 0 && errors.home === undefined);
  const title = editorTitleOf(descriptor, fields);
  const heading =
    entry === null
      ? t('panel.editor.new_title', { type: descriptor.label })
      : (title ?? t('panel.content.untitled'));
  const nodes = state.nodes;
  const tree = useMemo(
    () => (nodes?.status === 'ready' ? nodeTree(nodes.value.nodes) : []),
    [nodes],
  );
  const homeUnavailable = entry === null && (nodes?.status !== 'ready' || tree.length === 0);
  const disabled = saving || !allowed || homeUnavailable;

  function save(): void {
    if (disabled) {
      return;
    }

    const missingHome = entry === null && home === null;
    setHomeMissing(missingHome);

    if (validateEntry(descriptor, fields, 'write').length > 0 || missingHome) {
      setShowErrors('all');

      return;
    }

    const document =
      entry === null || head === null
        ? createDocument(created, descriptor, home ?? '', fields)
        : reviseDocument(entry, head, fields);

    if (document === undefined) {
      setOutcome({ status: 'failed' });

      return;
    }

    setSaving(true);
    runCommand(
      `${command.name}@${String(command.version)}`,
      document,
      { waitLevel: 'commit' },
      crypto.randomUUID(),
    )
      .then((answer) => {
        setOutcome({ status: 'answered', receipt: answer.receipt, problem: answer.problem });
        setEdited(false);
        setShowErrors(committed(answer.receipt) ? 'touched' : 'all');

        if (entry === null && committed(answer.receipt)) {
          router.visit(entryUrl(props.list, created), { replace: true, preserveState: true });
        }
      })
      .catch(() => {
        setOutcome({ status: 'failed' });
      })
      .finally(() => {
        setSaving(false);
      });
  }

  const latest = useRef(save);

  useEffect(() => {
    latest.current = save;
  });

  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (isSaveShortcut(event)) {
        event.preventDefault();
        latest.current();
      }
    };

    window.addEventListener('keydown', onKey);

    return () => {
      window.removeEventListener('keydown', onKey);
    };
  }, []);

  const status = saving
    ? null
    : outcome?.status === 'answered'
      ? saveStatusOf(outcome.receipt)
      : null;

  return (
    <>
      <Head title={heading} />
      <Page
        header={
          <PageHeader
            title={heading}
            breadcrumbs={
              <Breadcrumbs
                label={t('panel.editor.breadcrumbs')}
                items={[
                  { id: 'content', label: t('panel.content.title'), href: content },
                  { id: 'type', label: descriptor.label, href: props.list },
                  { id: 'entry', label: heading },
                ]}
              />
            }
            meta={
              <Inline gap="sm" wrap>
                {head === null ? (
                  <Badge tone="neutral">{t('panel.editor.state.new')}</Badge>
                ) : (
                  <Badge tone={STATE_STATUS[head.state].tone}>
                    {t(STATE_STATUS[head.state].key)}
                  </Badge>
                )}
                <span className="cms-panel-editor__status" role="status" aria-live="polite">
                  {saving ? t('panel.editor.status.saving') : status === null ? '' : t(status.key)}
                </span>
              </Inline>
            }
            actions={
              <Button
                variant="primary"
                icon="check"
                onClick={save}
                disabled={disabled}
                aria-busy={saving}
                aria-keyshortcuts="Meta+Enter Control+Enter"
              >
                {saving ? t('panel.editor.saving') : t('panel.editor.save')}{' '}
                <span className="cms-panel-editor__shortcut" aria-hidden="true">
                  <KeyboardShortcut keys={['Mod', 'Enter']} />
                </span>
              </Button>
            }
          />
        }
      >
        <Stack gap="lg">
          {allowed ? null : (
            <Callout tone="info" title={t('panel.editor.read_only_title')}>
              {t(entry === null ? 'panel.editor.read_only_create' : 'panel.editor.read_only_body')}
            </Callout>
          )}
          {outcome?.status === 'failed' ? (
            <Callout tone="danger" title={t('panel.editor.failed_title')}>
              {t('panel.editor.failed_body')}
            </Callout>
          ) : null}
          {problem === null || !problemShown ? null : (
            <ProblemDetails
              problem={problem}
              explanation={explanationOf(locale, t, problem, 'panel.editor.rejected')}
            />
          )}
          {entry === null ? (
            <HomeField
              state={state}
              tree={tree}
              home={home}
              error={
                errors.home ??
                (homeMissing && showErrors === 'all' ? t('panel.editor.home_required') : undefined)
              }
              disabled={saving || !allowed}
              onChange={(next) => {
                setHome(next);
                setHomeMissing(false);
              }}
            />
          ) : null}
          <EntryForm
            descriptor={descriptor}
            value={fields}
            onChange={(next) => {
              setFields(next);
              setEdited(true);
            }}
            locale={locale}
            showErrors={showErrors}
            errors={shownErrors}
            idPrefix="entry"
            disabled={saving || !allowed}
          />
          <Inline gap="sm" justify="between" wrap>
            <TextLink href={props.list}>{t('panel.editor.back')}</TextLink>
            <Form method="post" action={props.logout} onSubmit={onSignOut}>
              <Button type="submit">{t('panel.home.sign_out')}</Button>
            </Form>
          </Inline>
        </Stack>
      </Page>
    </>
  );
}

/** The home of a new entry: the node of the tree the person reaches that owns its content. */
function HomeField({
  state,
  tree,
  home,
  error,
  disabled,
  onChange,
}: {
  readonly state: Extract<EditorState, { readonly status: 'ready' }>;
  readonly tree: ReturnType<typeof nodeTree>;
  readonly home: string | null;
  readonly error: string | undefined;
  readonly disabled: boolean;
  readonly onChange: (home: string | null) => void;
}) {
  const { t, locale } = useTranslation();
  const nodes = state.nodes;

  if (nodes?.status === 'rejected') {
    return (
      <ProblemDetails
        problem={nodes.problem}
        explanation={explanationOf(locale, t, nodes.problem, 'panel.editor.nodes_refused')}
      />
    );
  }

  if (nodes?.status !== 'ready') {
    return (
      <ErrorState
        title={t('panel.editor.nodes_unavailable_title')}
        description={t('panel.editor.nodes_unavailable_body')}
        headingLevel={2}
      />
    );
  }

  if (tree.length === 0) {
    return (
      <EmptyState
        title={t('panel.editor.nodes_empty_title')}
        description={t('panel.editor.nodes_empty_body')}
        headingLevel={2}
      />
    );
  }

  return (
    <fieldset className="cms-panel-editor__home" disabled={disabled}>
      <NodePicker
        label={t('panel.editor.home')}
        description={t('panel.editor.home_description')}
        error={error}
        nodes={tree}
        value={home}
        onChange={onChange}
        empty={<EmptyState title={t('panel.editor.nodes_empty_title')} headingLevel={3} />}
        required
      />
    </fieldset>
  );
}
