import {
  Badge,
  Breadcrumbs,
  Button,
  Callout,
  ConfirmDialog,
  DescriptionList,
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
  Section,
  Stack,
  TextLink,
  validateEntry,
  type EntryFormErrors,
  type JsonObject,
  type JsonValue,
} from '@cboxdk/cms-ui-kit';
import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState, type SubmitEvent } from 'react';

import { uuid7 } from '../../access/ids';
import {
  changedAtText,
  fieldDifferences,
  fieldValue,
  isNewerHead,
  isVersionConflict,
  sameFields,
  valueText,
  withFieldValue,
} from '../../content/conflict';
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
import { browserStorage, RecoveryStore, type Recovered } from '../../content/recovery';
import { typesOf } from '../../content/types';
import type { ContentEditorPageV1 } from '../../generated/pages/ContentEditorPageV1';
import type { EntryHeadV1 } from '../../generated/protocol/EntryDetailV1';
import type { ProblemV1 } from '../../generated/protocol/ProblemV1';
import type { ReceiptV1 } from '../../generated/protocol/ReceiptV1';
import type { TypeEditorV1 } from '../../generated/protocol/TypeEditorV1';
import { useCoreServices } from '../../host';
import { useHostRuntime } from '../../host/runtime';
import { useTranslation, type Translate } from '../../i18n/translations';
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

/**
 * The comparison after a version conflict, once the person took the saved version: their own
 * edits as they were, to bring back per field, and who saved the version the form now shows.
 */
interface Comparison {
  readonly mine: JsonObject;
  readonly by: string;
}

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
  const { contributions } = useHostRuntime();
  const palette = useMemo(() => paletteOf(page.props.palette), [page.props.palette]);
  const { descriptor, entry, head } = state;
  const [created] = useState(() => uuid7());
  // The head the person's edits are made on: the head the page read when it opened, then the head
  // each of their own saves left, or the head they chose to continue from after a conflict. A head
  // the page reads again because someone else saved does not become it on its own, so a save never
  // overwrites a change the person has not seen.
  const [base, setBase] = useState<EntryHeadV1 | null>(head);
  const [initial] = useState<JsonObject>(() =>
    head === null ? newEntryFields(descriptor, new Date()) : head.fields,
  );
  const [fields, setFields] = useState<JsonObject>(initial);
  const [home, setHome] = useState<string | null>(null);
  const [showErrors, setShowErrors] = useState<EntryFormErrors>('touched');
  const [saving, setSaving] = useState(false);
  const [outcome, setOutcome] = useState<Outcome | null>(null);
  const [adopt, setAdopt] = useState(false);
  const [edited, setEdited] = useState(false);
  const [homeMissing, setHomeMissing] = useState(false);
  const [comparison, setComparison] = useState<Comparison | null>(null);
  const [reloading, setReloading] = useState(false);
  // The visit the person started away from changes that are not saved, which the page asks about.
  const [leaving, setLeaving] = useState<{ readonly go: () => void } | null>(null);
  const store = useMemo(() => new RecoveryStore(browserStorage()), []);
  const recoveryKey = useMemo(
    () => ({
      viewer: contributions.viewer,
      type: descriptor.name,
      entry: entry?.id ?? null,
      version: base?.version ?? 0,
    }),
    [contributions.viewer, descriptor.name, entry?.id, base?.version],
  );
  const [offer, setOffer] = useState<Recovered | null>(() => {
    const found = store.find(recoveryKey);

    return found === null || sameFields(found.fields, initial) ? null : found;
  });
  const command = entry === null ? CREATE : REVISE;
  const allowed = mayRun(palette, command);
  const problem = outcome?.status === 'answered' ? outcome.problem : null;
  const conflict = isVersionConflict(problem);
  const theirs = conflict && isNewerHead(head, base) ? head : null;
  const errors = useMemo(() => saveErrorsOf(problem, locale), [problem, locale]);
  const shownErrors = edited ? {} : errors.fields;
  const dirty = !sameFields(fields, base?.fields ?? initial);
  // A rejection whose every error is at a field of the form is shown there and in the form's
  // summary of errors; one with an error about the entry as a whole shows its problem details
  // too, but a version conflict, which the conflict's own notice explains with who saved.
  const problemShown =
    !conflict &&
    (errors.other > 0 || (Object.keys(errors.fields).length === 0 && errors.home === undefined));
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
  const dirtyRef = useRef(dirty);
  const bypass = useRef(false);

  useEffect(() => {
    dirtyRef.current = dirty;
  }, [dirty]);

  // The person's own save committed: the head the page reads with the answer is the one it left,
  // and for a new entry the first head, once the page is the page of the entry.
  if (adopt && head !== null && (base === null || isNewerHead(head, base))) {
    setBase(head);
    setAdopt(false);
  }

  // The edits the person has not saved are kept in this browser while they differ from the head
  // they are made on, and forgotten once they do not; not while the page offers edits it kept.
  useEffect(() => {
    if (offer !== null) {
      return;
    }

    if (dirty) {
      store.keep(recoveryKey, fields);
    } else {
      store.forget(recoveryKey);
    }
  }, [offer, dirty, fields, recoveryKey, store]);

  // Leaving with changes that are not saved: closing or reloading the tab asks the browser's own
  // question, and a visit inside the panel asks the page's, unless it is the page's own reload.
  useEffect(() => {
    const onBeforeUnload = (event: BeforeUnloadEvent) => {
      if (dirtyRef.current && !bypass.current) {
        event.preventDefault();
      }
    };
    const stop = router.on('before', (event) => {
      const visit = event.detail.visit;

      if (
        !dirtyRef.current ||
        bypass.current ||
        visit.method !== 'get' ||
        visit.prefetch ||
        visit.only.length > 0
      ) {
        return;
      }

      event.preventDefault();
      setLeaving({
        go: () => {
          router.visit(visit.url, {
            method: visit.method,
            data: visit.data,
            replace: visit.replace,
            preserveScroll: visit.preserveScroll,
            preserveState: visit.preserveState,
          });
        },
      });
    });

    window.addEventListener('beforeunload', onBeforeUnload);

    return () => {
      stop();
      window.removeEventListener('beforeunload', onBeforeUnload);
    };
  }, []);

  function leave(): void {
    if (leaving === null) {
      return;
    }

    store.forget(recoveryKey);
    bypass.current = true;
    leaving.go();
  }

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
      entry === null || base === null
        ? createDocument(created, descriptor, home ?? '', fields)
        : reviseDocument(entry, base, fields);

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
        const saved = committed(answer.receipt);
        setOutcome({ status: 'answered', receipt: answer.receipt, problem: answer.problem });
        setEdited(false);
        setShowErrors(saved ? 'touched' : 'all');

        if (saved) {
          setComparison(null);
          setAdopt(true);
        }

        if (entry === null && saved) {
          store.forget(recoveryKey);
          bypass.current = true;
          router.visit(entryUrl(props.list, created), {
            replace: true,
            preserveState: true,
            onFinish: () => {
              bypass.current = false;
            },
          });
        }
      })
      .catch(() => {
        setOutcome({ status: 'failed' });
      })
      .finally(() => {
        setSaving(false);
      });
  }

  /** Reads the entry again, keeping the person's edits, so the conflict can compare them. */
  function reloadEntry(): void {
    setReloading(true);
    router.reload({
      only: ['entry_read'],
      onFinish: () => {
        setReloading(false);
      },
    });
  }

  /** Continues from the version someone else saved, with the person's edits to bring back. */
  function takeTheirs(saved: EntryHeadV1): void {
    setComparison({ mine: fields, by: changedByName(saved, t) });
    setFields(saved.fields);
    setBase(saved);
    setOutcome(null);
    setEdited(true);
    setShowErrors('touched');
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
  const shownHead = base ?? head;

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
                {shownHead === null ? (
                  <Badge tone="neutral">{t('panel.editor.state.new')}</Badge>
                ) : (
                  <Badge tone={STATE_STATUS[shownHead.state].tone}>
                    {t(STATE_STATUS[shownHead.state].key)}
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
          {offer === null ? null : (
            <RecoveryNotice
              recovered={offer}
              onRestore={() => {
                setFields(offer.fields);
                setEdited(true);
                setOffer(null);
              }}
              onDiscard={() => {
                store.forget(recoveryKey);
                setOffer(null);
              }}
            />
          )}
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
          {conflict ? (
            <ConflictNotice
              descriptor={descriptor}
              theirs={theirs}
              mine={fields}
              reloading={reloading}
              onReload={reloadEntry}
              onTakeTheirs={takeTheirs}
            />
          ) : null}
          {problem === null || !problemShown ? null : (
            <ProblemDetails
              problem={problem}
              explanation={explanationOf(locale, t, problem, 'panel.editor.rejected')}
            />
          )}
          {comparison === null ? null : (
            <ComparePanel
              descriptor={descriptor}
              comparison={comparison}
              fields={fields}
              onUse={(path) => {
                setFields(withFieldValue(fields, path, fieldValue(comparison.mine, path)));
              }}
              onUseAll={() => {
                setFields(comparison.mine);
                setComparison(null);
              }}
              onDone={() => {
                setComparison(null);
              }}
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
            <Form
              method="post"
              action={props.logout}
              onSubmit={(event) => {
                store.clear();
                bypass.current = true;
                onSignOut(event);
              }}
            >
              <Button type="submit">{t('panel.home.sign_out')}</Button>
            </Form>
          </Inline>
        </Stack>
      </Page>
      <ConfirmDialog
        title={t('panel.editor.leave.title')}
        message={t('panel.editor.leave.body')}
        confirmLabel={t('panel.editor.leave.confirm')}
        cancelLabel={t('panel.editor.leave.cancel')}
        open={leaving !== null}
        onOpenChange={(open) => {
          if (!open) {
            setLeaving(null);
          }
        }}
        onConfirm={leave}
      />
    </>
  );
}

/** The name of whoever saved the head, or the panel's word for someone the reader may not name. */
function changedByName(head: EntryHeadV1, t: Translate): string {
  return head.changed?.name ?? t('panel.editor.conflict.someone');
}

/** A value of a field as the comparison shows it. */
function ComparedValue({ value }: { readonly value: JsonValue | undefined }) {
  const { t } = useTranslation();
  const text = valueText(value);

  return text === null ? (
    <span className="cms-panel-editor__empty">{t('panel.editor.conflict.empty')}</span>
  ) : (
    <span className="cms-panel-editor__value">{text}</span>
  );
}

/**
 * A save refused because someone else saved the entry after the person opened it: who saved and
 * when, what differs per field between what they saved and the person's edits, and the way on,
 * which takes the saved version into the form and keeps the person's edits beside it to bring
 * back. Until the page has read the saved version, it offers to read it.
 */
function ConflictNotice({
  descriptor,
  theirs,
  mine,
  reloading,
  onReload,
  onTakeTheirs,
}: {
  readonly descriptor: TypeEditorV1;
  readonly theirs: EntryHeadV1 | null;
  readonly mine: JsonObject;
  readonly reloading: boolean;
  readonly onReload: () => void;
  readonly onTakeTheirs: (theirs: EntryHeadV1) => void;
}) {
  const { t, locale } = useTranslation();

  if (theirs === null) {
    return (
      <Callout
        tone="warning"
        title={t('panel.editor.conflict.title')}
        action={
          <Button onClick={onReload} disabled={reloading} aria-busy={reloading}>
            {reloading ? t('panel.editor.conflict.reloading') : t('panel.editor.conflict.reload')}
          </Button>
        }
      >
        {t('panel.editor.conflict.unread')}
      </Callout>
    );
  }

  const name = changedByName(theirs, t);
  const differences = fieldDifferences(descriptor, theirs.fields, mine, locale);

  return (
    <Callout
      tone="warning"
      title={t('panel.editor.conflict.title')}
      action={
        <Button
          variant="primary"
          onClick={() => {
            onTakeTheirs(theirs);
          }}
        >
          {t('panel.editor.conflict.reload')}
        </Button>
      }
    >
      <Stack gap="md">
        <p>
          {t('panel.editor.conflict.body', {
            name,
            time: theirs.changed === null ? '' : changedAtText(theirs.changed.at, locale),
          })}
        </p>
        {differences.length === 0 ? (
          <p>{t('panel.editor.conflict.same')}</p>
        ) : (
          <div className="cms-panel-editor__compare">
            <h2 className="cms-panel-editor__compare-title">
              {t('panel.editor.conflict.compare')}
            </h2>
            <ul className="cms-panel-editor__differences">
              {differences.map((difference) => (
                <li key={difference.path} className="cms-panel-editor__difference">
                  <h3 className="cms-panel-editor__field">{difference.label}</h3>
                  <DescriptionList
                    layout="stacked"
                    items={[
                      {
                        id: 'theirs',
                        term: t('panel.editor.conflict.theirs', { name }),
                        description: <ComparedValue value={difference.theirs} />,
                      },
                      {
                        id: 'mine',
                        term: t('panel.editor.conflict.mine'),
                        description: <ComparedValue value={difference.mine} />,
                      },
                    ]}
                  />
                </li>
              ))}
            </ul>
          </div>
        )}
      </Stack>
    </Callout>
  );
}

/**
 * After a conflict, with the saved version in the form: each field where the person's own edit
 * differs from what the form holds now, with the edit and a button that brings it back.
 */
function ComparePanel({
  descriptor,
  comparison,
  fields,
  onUse,
  onUseAll,
  onDone,
}: {
  readonly descriptor: TypeEditorV1;
  readonly comparison: Comparison;
  readonly fields: JsonObject;
  readonly onUse: (path: string) => void;
  readonly onUseAll: () => void;
  readonly onDone: () => void;
}) {
  const { t, locale } = useTranslation();
  const differences = fieldDifferences(descriptor, fields, comparison.mine, locale);

  return (
    <Section
      title={t('panel.editor.compare.title')}
      description={t('panel.editor.compare.body', { name: comparison.by })}
      headingLevel={2}
      actions={
        <Inline gap="sm" wrap>
          {differences.length === 0 ? null : (
            <Button onClick={onUseAll}>{t('panel.editor.compare.use_all')}</Button>
          )}
          <Button onClick={onDone}>{t('panel.editor.compare.done')}</Button>
        </Inline>
      }
    >
      <ul className="cms-panel-editor__differences">
        {differences.map((difference) => (
          <li key={difference.path} className="cms-panel-editor__difference">
            <h3 className="cms-panel-editor__field">{difference.label}</h3>
            <Inline gap="sm" justify="between" wrap>
              <ComparedValue value={difference.mine} />
              <Button
                onClick={() => {
                  onUse(difference.path);
                }}
                aria-label={`${t('panel.editor.compare.use_mine')}: ${difference.label}`}
              >
                {t('panel.editor.compare.use_mine')}
              </Button>
            </Inline>
          </li>
        ))}
      </ul>
    </Section>
  );
}

/** Edits of this entry the browser kept and the person had not saved, with Restore and Discard. */
function RecoveryNotice({
  recovered,
  onRestore,
  onDiscard,
}: {
  readonly recovered: Recovered;
  readonly onRestore: () => void;
  readonly onDiscard: () => void;
}) {
  const { t, locale } = useTranslation();

  return (
    <Callout
      tone="info"
      title={t('panel.editor.recovery.title')}
      action={
        <Inline gap="sm" wrap>
          <Button variant="primary" onClick={onRestore}>
            {t('panel.editor.recovery.restore')}
          </Button>
          <Button onClick={onDiscard}>{t('panel.editor.recovery.discard')}</Button>
        </Inline>
      }
    >
      {t('panel.editor.recovery.body', {
        time: changedAtText(new Date(recovered.keptAt).toISOString(), locale),
      })}
    </Callout>
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
