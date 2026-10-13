import {
  Badge,
  Button,
  Callout,
  Dialog,
  DryRunReport,
  EmptyState,
  Field,
  Form,
  Inline,
  NodePicker,
  ProblemDetails,
  Section,
  Stack,
  Tag,
  TextInput,
  Timestamp,
} from '@cboxdk/cms-ui-kit';
import { usePage } from '@inertiajs/react';
import { useMemo, useState, type SubmitEvent } from 'react';

import { uuid7 } from '../../access/ids';
import { explanationOf } from '../../access/problems';
import { committed, mayRun } from '../../content/editor';
import {
  commandName,
  isLocale,
  isSlug,
  needsRelease,
  placeableNodes,
  PLACE,
  placementRows,
  placeStep,
  PUBLISH,
  publishSteps,
  RELEASE,
  rowPath,
  UNPUBLISH,
  unpublishStep,
  WINDOW,
  windowOf,
  type CommandOf,
  type PlacementRow,
  type Step,
  type Window,
} from '../../content/publish';
import type {
  EntryDetailV1,
  EntryHeadV1,
  Visibility,
} from '../../generated/protocol/EntryDetailV1';
import type { DryRunSummaryV1 } from '../../generated/protocol/DryRunSummaryV1';
import type { ListedNodeV1 } from '../../generated/protocol/NodeListV1';
import type { ProblemV1 } from '../../generated/protocol/ProblemV1';
import type { TypeEditorV1 } from '../../generated/protocol/TypeEditorV1';
import { dryRunReport } from '../../host/actions';
import { useCoreServices } from '../../host';
import { useTranslation } from '../../i18n/translations';
import { paletteOf, type PaletteState } from '../../shell/palette';
import { nodeTree } from '../Access/Grants';

/** The tone of each stored visibility of a placement. */
const VISIBILITY: Readonly<
  Record<Visibility, 'neutral' | 'info' | 'success' | 'warning' | 'danger'>
> = {
  hidden: 'neutral',
  scheduled: 'info',
  live: 'success',
  expired: 'warning',
  withdrawn: 'danger',
};

/** What a publish does, which names its review and its notice. */
type Action = 'publish' | 'schedule' | 'unpublish';

/** A change the person asked for, tried as a dry run and waiting for their confirmation. */
interface Review {
  readonly action: Action;
  readonly title: string;
  readonly steps: readonly Step[];
  readonly summary: DryRunSummaryV1 | null;
}

/** What the last change gave: a rejection with its problem, or no answer at all. */
type Failure =
  { readonly status: 'rejected'; readonly problem: ProblemV1 } | { readonly status: 'failed' };

/** Whether the person may run every command of the steps. */
function mayRunAll(palette: PaletteState, commands: readonly CommandOf[]): boolean {
  return commands.every((command) => mayRun(palette, command));
}

/**
 * The editor's publish panel (PRD 5.6, 5.7, 5.9, 6.4): the release state of the entry's shared
 * variant, every placement of the entry in each of its locales with its path, visibility and
 * window, a publish and a schedule per placement locale and an unpublish of the entry, and a form
 * that adds a placement under a node of a site with a slug and a language. Every publish, schedule
 * and unpublish is first run as a dry run, and its summary, the blast radius and what becomes
 * visible at which path, shown for the person to confirm before the commands run for real; the
 * home placement is published with entry.publish, which releases and opens its window in one
 * changeset, and any other with variant.release when the draft is not released yet, then
 * placement.set_window. Each command runs through the Inertia profile as the person, so the page's
 * reads come back fresh after it, and a rejection shows its problem details.
 */
export function PublishPanel({
  descriptor,
  entry,
  head,
  nodes,
}: {
  readonly descriptor: TypeEditorV1;
  readonly entry: EntryDetailV1;
  readonly head: EntryHeadV1;
  readonly nodes: readonly ListedNodeV1[];
}) {
  const { t, locale } = useTranslation();
  const page = usePage();
  const palette = useMemo(() => paletteOf(page.props.palette), [page.props.palette]);
  const { runCommand, notify } = useCoreServices();
  const rows = useMemo(() => placementRows(entry, nodes), [entry, nodes]);
  const [busy, setBusy] = useState<'trying' | 'running' | null>(null);
  const [review, setReview] = useState<Review | null>(null);
  const [failure, setFailure] = useState<Failure | null>(null);
  const [schedule, setSchedule] = useState<PlacementRow | null>(null);
  const changed = needsRelease(descriptor, head) && head.state === 'released';

  function stepsOf(row: PlacementRow, window: Window | null): readonly Step[] | undefined {
    return publishSteps(descriptor, entry, head, row, window, new Date());
  }

  function commandsOf(row: PlacementRow): readonly CommandOf[] {
    if (row.home) {
      return [PUBLISH];
    }

    return needsRelease(descriptor, head) ? [RELEASE, WINDOW] : [WINDOW];
  }

  function answered(answer: { readonly problem: ProblemV1 | null }): void {
    setFailure(
      answer.problem === null
        ? { status: 'failed' }
        : { status: 'rejected', problem: answer.problem },
    );
  }

  function trial(action: Action, title: string, steps: readonly Step[] | undefined): void {
    const first = steps?.[0];

    if (steps === undefined || first === undefined) {
      setFailure({ status: 'failed' });

      return;
    }

    setBusy('trying');
    setFailure(null);
    runCommand(commandName(first.command), first.document, { dryRun: true })
      .then((answer) => {
        if (answer.receipt.outcome === 'dry_run') {
          setReview({ action, title, steps, summary: answer.dryRun });
        } else {
          answered(answer);
        }
      })
      .catch(() => {
        setFailure({ status: 'failed' });
      })
      .finally(() => {
        setBusy(null);
      });
  }

  async function confirm(confirmed: Review): Promise<void> {
    setReview(null);
    setBusy('running');

    try {
      for (const step of confirmed.steps) {
        const answer = await runCommand(commandName(step.command), step.document, {
          waitLevel: 'commit',
        });

        if (!committed(answer.receipt)) {
          answered(answer);

          return;
        }
      }

      notify('success', t(`panel.publish.done.${confirmed.action}`));
    } catch {
      setFailure({ status: 'failed' });
    } finally {
      setBusy(null);
    }
  }

  const unpublish = unpublishStep(entry, head);
  const disabled = busy !== null;

  return (
    <Section
      title={t('panel.publish.title')}
      description={t('panel.publish.description')}
      headingLevel={2}
      actions={
        head.state === 'released' && mayRun(palette, UNPUBLISH) ? (
          <Button
            variant="danger"
            disabled={disabled}
            onClick={() => {
              trial(
                'unpublish',
                t('panel.publish.review_unpublish'),
                unpublish === undefined ? undefined : [unpublish],
              );
            }}
          >
            {t('panel.publish.unpublish')}
          </Button>
        ) : null
      }
    >
      <Stack gap="lg">
        <Inline gap="sm" wrap>
          {head.state === 'released' && head.released !== null ? (
            <Badge tone="success">{t('panel.publish.released', { revision: head.released })}</Badge>
          ) : (
            <Badge tone="neutral">{t('panel.publish.unreleased')}</Badge>
          )}
          {changed ? <span>{t('panel.publish.draft_changed')}</span> : null}
          <span className="cms-panel-publish__status" role="status" aria-live="polite">
            {busy === null
              ? ''
              : t(busy === 'trying' ? 'panel.publish.trying' : 'panel.publish.running')}
          </span>
        </Inline>
        {failure === null ? null : failure.status === 'failed' ? (
          <Callout tone="danger" title={t('panel.publish.failed_title')}>
            {t('panel.publish.failed_body')}
          </Callout>
        ) : (
          <ProblemDetails
            problem={failure.problem}
            explanation={explanationOf(locale, t, failure.problem, 'panel.publish.rejected')}
          />
        )}
        {mayRunAll(palette, [PUBLISH, WINDOW, PLACE]) ? null : (
          <Callout tone="info" title={t('panel.editor.read_only_title')}>
            {t('panel.publish.read_only')}
          </Callout>
        )}
        <Stack gap="sm">
          <h3 className="cms-panel-publish__heading">{t('panel.publish.placements')}</h3>
          {rows.length === 0 ? (
            <p>{t('panel.publish.no_placements')}</p>
          ) : (
            <ul className="cms-panel-publish__rows">
              {rows.map((row) => {
                const allowed = mayRunAll(palette, commandsOf(row));
                const where = { path: row.path, locale: row.locale.locale };

                return (
                  <li
                    key={`${row.placement.id} ${row.locale.locale}`}
                    className="cms-panel-publish__row"
                  >
                    <Stack gap="xs">
                      <Inline gap="sm" wrap>
                        <code className="cms-panel-publish__path">{row.path}</code>
                        <Tag label={row.locale.locale} />
                        <Badge tone={VISIBILITY[row.locale.visibility]}>
                          {t(`panel.publish.visibility.${row.locale.visibility}`)}
                        </Badge>
                        {row.home ? <Tag label={t('panel.publish.home')} /> : null}
                        {row.locale.canonical ? <Tag label={t('panel.publish.canonical')} /> : null}
                      </Inline>
                      <WindowText row={row} />
                    </Stack>
                    <Inline gap="sm" wrap>
                      <Button
                        variant="primary"
                        disabled={disabled || !allowed}
                        aria-label={t('panel.publish.publish_label', where)}
                        onClick={() => {
                          trial(
                            'publish',
                            t('panel.publish.review_publish', { path: row.path }),
                            stepsOf(row, null),
                          );
                        }}
                      >
                        {t('panel.publish.publish')}
                      </Button>
                      <Button
                        disabled={disabled || !allowed}
                        aria-label={t('panel.publish.schedule_label', where)}
                        onClick={() => {
                          setSchedule(row);
                        }}
                      >
                        {t('panel.publish.schedule')}
                      </Button>
                    </Inline>
                  </li>
                );
              })}
            </ul>
          )}
        </Stack>
        <PlaceForm
          entry={entry}
          nodes={nodes}
          disabled={disabled || !mayRun(palette, PLACE)}
          onPlace={(step) => {
            setBusy('running');
            setFailure(null);
            runCommand(commandName(step.command), step.document, { waitLevel: 'commit' })
              .then((answer) => {
                if (committed(answer.receipt)) {
                  notify('success', t('panel.publish.done.place'));
                } else {
                  answered(answer);
                }

                return committed(answer.receipt);
              })
              .catch(() => {
                setFailure({ status: 'failed' });

                return false;
              })
              .finally(() => {
                setBusy(null);
              });
          }}
        />
      </Stack>
      {schedule === null ? null : (
        <ScheduleDialog
          row={schedule}
          onClose={() => {
            setSchedule(null);
          }}
          onReview={(window) => {
            setSchedule(null);
            trial(
              'schedule',
              t('panel.publish.review_schedule', { path: schedule.path }),
              stepsOf(schedule, window),
            );
          }}
        />
      )}
      {review === null ? null : (
        <Dialog
          title={review.title}
          open
          onOpenChange={(open) => {
            if (!open) {
              setReview(null);
            }
          }}
          footer={
            <Inline gap="sm" justify="end" wrap>
              <Button
                onClick={() => {
                  setReview(null);
                }}
              >
                {t('panel.publish.cancel')}
              </Button>
              <Button
                variant="primary"
                onClick={() => {
                  void confirm(review);
                }}
              >
                {t('panel.publish.confirm')}
              </Button>
            </Inline>
          }
        >
          <Stack gap="md">
            <p>{t('panel.publish.review_intro')}</p>
            {review.steps.length > 1 ? <p>{t('panel.publish.review_then_window')}</p> : null}
            <BecomesVisible rows={rows} summary={review.summary} />
            {review.summary === null ? null : (
              <DryRunReport report={dryRunReport(review.summary)} />
            )}
          </Stack>
        </Dialog>
      )}
    </Section>
  );
}

/** The placement's window in its locale: its start and its end, each when it has one. */
function WindowText({ row }: { readonly row: PlacementRow }) {
  const { t } = useTranslation();
  const window = row.locale.window;
  const from = window?.from ?? null;
  const until = window?.until ?? null;

  if (window === null) {
    return <span className="cms-panel-publish__window">{t('panel.publish.no_window')}</span>;
  }

  if (from === null && until === null) {
    return <span className="cms-panel-publish__window">{t('panel.publish.window_always')}</span>;
  }

  return (
    <span className="cms-panel-publish__window">
      {from === null ? null : (
        <span>
          {t('panel.publish.live_from')} <Timestamp value={from} />
        </span>
      )}
      {until === null ? null : (
        <span>
          {t('panel.publish.live_until')} <Timestamp value={until} />
        </span>
      )}
    </span>
  );
}

/** What the dry run makes visible, by the path and locale of each placement. */
function BecomesVisible({
  rows,
  summary,
}: {
  readonly rows: readonly PlacementRow[];
  readonly summary: DryRunSummaryV1 | null;
}) {
  const { t } = useTranslation();
  const visible = summary?.becomes_visible ?? [];

  return (
    <Stack gap="xs">
      <h3 className="cms-panel-publish__heading">{t('panel.publish.becomes_visible')}</h3>
      {visible.length === 0 ? (
        <p>{t('panel.publish.nothing_visible')}</p>
      ) : (
        <ul className="cms-panel-publish__visible">
          {visible.map((item) => (
            <li key={`${item.placement} ${item.locale}`}>
              {t('panel.publish.becomes_visible_item', {
                path: rowPath(rows, item.placement, item.locale) ?? item.placement,
                locale: item.locale,
              })}
            </li>
          ))}
        </ul>
      )}
    </Stack>
  );
}

/** The dialog that asks for a placement's window in its locale, from and until in the person's time zone. */
function ScheduleDialog({
  row,
  onClose,
  onReview,
}: {
  readonly row: PlacementRow;
  readonly onClose: () => void;
  readonly onReview: (window: Window) => void;
}) {
  const { t } = useTranslation();
  const [from, setFrom] = useState('');
  const [until, setUntil] = useState('');
  const [problem, setProblem] = useState<'missing' | 'order' | null>(null);
  const formId = `schedule-${row.placement.id}-${row.locale.locale}`;

  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    const checked = windowOf(from, until);

    if (checked.window === null) {
      setProblem(checked.problem);

      return;
    }

    onReview(checked.window);
  }

  const error =
    problem === null
      ? undefined
      : t(problem === 'order' ? 'panel.publish.schedule_order' : 'panel.publish.schedule_missing');

  return (
    <Dialog
      title={t('panel.publish.schedule_title', { path: row.path })}
      open
      onOpenChange={(open) => {
        if (!open) {
          onClose();
        }
      }}
      footer={
        <Inline gap="sm" justify="end" wrap>
          <Button onClick={onClose}>{t('panel.publish.cancel')}</Button>
          <Button variant="primary" type="submit" form={formId}>
            {t('panel.publish.schedule_review')}
          </Button>
        </Inline>
      }
    >
      <Form id={formId} onSubmit={submit}>
        <Stack gap="md">
          <p>{t('panel.publish.schedule_description')}</p>
          <Field id={`${formId}-from`} label={t('panel.publish.schedule_from')} error={error}>
            {(control) => (
              <input
                {...control}
                className="cms-input"
                type="datetime-local"
                value={from}
                onChange={(event) => {
                  setFrom(event.target.value);
                  setProblem(null);
                }}
              />
            )}
          </Field>
          <Field id={`${formId}-until`} label={t('panel.publish.schedule_until')}>
            {(control) => (
              <input
                {...control}
                className="cms-input"
                type="datetime-local"
                value={until}
                onChange={(event) => {
                  setUntil(event.target.value);
                  setProblem(null);
                }}
              />
            )}
          </Field>
        </Stack>
      </Form>
    </Dialog>
  );
}

/** The form that places the entry under a node of a site, with a slug in a language. */
function PlaceForm({
  entry,
  nodes,
  disabled,
  onPlace,
}: {
  readonly entry: EntryDetailV1;
  readonly nodes: readonly ListedNodeV1[];
  readonly disabled: boolean;
  readonly onPlace: (step: Step) => void;
}) {
  const { t, locale: panelLocale } = useTranslation();
  const placeable = useMemo(() => placeableNodes(nodes), [nodes]);
  const tree = useMemo(() => nodeTree(placeable), [placeable]);
  const [node, setNode] = useState<string | null>(null);
  const [slug, setSlug] = useState('');
  const [locale, setLocale] = useState<string>(panelLocale);
  const [shown, setShown] = useState(false);

  const chosen = placeable.find((candidate) => candidate.id === node);
  const errors = {
    node: chosen === undefined ? t('panel.publish.place_node_required') : undefined,
    slug: isSlug(slug) ? undefined : t('panel.publish.place_slug_invalid'),
    locale: isLocale(locale) ? undefined : t('panel.publish.place_locale_invalid'),
  };

  function submit(event: SubmitEvent<HTMLFormElement>): void {
    event.preventDefault();
    setShown(true);

    if (chosen === undefined || errors.slug !== undefined || errors.locale !== undefined) {
      return;
    }

    const step = placeStep(uuid7(), entry, chosen, slug, locale);

    if (step !== undefined) {
      onPlace(step);
      setSlug('');
      setNode(null);
      setShown(false);
    }
  }

  return (
    <Form onSubmit={submit} aria-labelledby="cms-panel-publish-place">
      <fieldset className="cms-panel-publish__place" disabled={disabled}>
        <Stack gap="md">
          <h3 id="cms-panel-publish-place" className="cms-panel-publish__heading">
            {t('panel.publish.place_title')}
          </h3>
          {tree.length === 0 ? (
            <EmptyState title={t('panel.publish.nodes_empty')} headingLevel={4} />
          ) : (
            <NodePicker
              label={t('panel.publish.place_node')}
              description={t('panel.publish.place_node_description')}
              error={shown ? errors.node : undefined}
              nodes={tree}
              value={node}
              onChange={setNode}
              empty={<EmptyState title={t('panel.publish.nodes_empty')} headingLevel={4} />}
              required
            />
          )}
          <TextInput
            id="cms-panel-publish-slug"
            label={t('panel.publish.place_slug')}
            description={t('panel.publish.place_slug_description')}
            error={shown ? errors.slug : undefined}
            value={slug}
            onChange={(event) => {
              setSlug(event.target.value);
            }}
            required
          />
          <TextInput
            id="cms-panel-publish-locale"
            label={t('panel.publish.place_locale')}
            description={t('panel.publish.place_locale_description')}
            error={shown ? errors.locale : undefined}
            value={locale}
            onChange={(event) => {
              setLocale(event.target.value);
            }}
            required
          />
          <Inline gap="sm">
            <Button type="submit" disabled={disabled || tree.length === 0}>
              {t('panel.publish.place')}
            </Button>
          </Inline>
        </Stack>
      </fieldset>
    </Form>
  );
}
