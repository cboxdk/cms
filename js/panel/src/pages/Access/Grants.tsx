import {
  ActorChip,
  ActorPicker,
  Badge,
  Button,
  Callout,
  ConfirmDialog,
  DataTable,
  Dialog,
  EmptyState,
  ErrorState,
  Form,
  FormActions,
  MultiSelect,
  NodePath,
  NodePicker,
  Page,
  PageHeader,
  Pagination,
  ProblemDetails,
  ProgressLabel,
  RadioGroup,
  ReceiptStatus,
  RolePicker,
  Stack,
  type PickerActor,
  type PickerRole,
  type TreeNode,
} from '@cboxdk/cms-ui-kit';
import type { CommandAnswer } from '@cboxdk/cms-panel/extend';
import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState, type SubmitEvent } from 'react';

import { uuid7 } from '../../access/ids';
import { afterOf, back, forward, visitPage, type Cursors } from '../../access/list-pages';
import { mayRun } from '../../access/permissions';
import { explanationOf, fieldErrorOf } from '../../access/problems';
import { readOf, type ReadState } from '../../access/reads';
import type { AccessGrantsPageV1 } from '../../generated/pages/AccessGrantsPageV1';
import { validateGrantPickersV1, type PickerReadV1 } from '../../generated/pages/GrantPickersV1';
import { validateActorListV1, type ListedActorV1 } from '../../generated/protocol/ActorListV1';
import {
  validateGrantListV1,
  type GrantEffect,
  type ListedGrantV1,
} from '../../generated/protocol/GrantListV1';
import { validateNodeListV1, type ListedNodeV1 } from '../../generated/protocol/NodeListV1';
import { validateRoleListV1, type ListedRoleV1 } from '../../generated/protocol/RoleListV1';
import type { ProblemV1 } from '../../generated/protocol/ProblemV1';
import type { Validation } from '../../generated/validation';
import { PointHost, useCoreServices } from '../../host';
import { useTranslation, type Translate, type TranslationKey } from '../../i18n/translations';
import { paletteOf } from '../../shell/palette';
import { PanelShell } from '../../shell/PanelShell';

/** The command the page assigns a grant with, and the one it revokes a grant with. */
const ASSIGN = 'grant.assign@1';
const REVOKE = 'grant.revoke@1';

/** The optional prop of the pickers' reads, which the page asks for when its form opens. */
const PICKERS = 'pickers';

/** The text of each grant effect, in the panel's catalogue. */
const EFFECTS: Readonly<Record<GrantEffect, TranslationKey>> = {
  allow: 'panel.access.effect.allow',
  deny: 'panel.access.effect.deny',
};

/** What the pickers' reads are in: not asked for yet, on their way, or as the prop gave them. */
type Pickers =
  | { readonly status: 'loading' }
  | { readonly status: 'unreadable' }
  | {
      readonly status: 'ready';
      readonly actors: ReadState<{ readonly actors: readonly ListedActorV1[] }>;
      readonly roles: ReadState<{ readonly roles: readonly ListedRoleV1[] }>;
      readonly nodes: ReadState<{ readonly nodes: readonly ListedNodeV1[] }>;
    };

/** The pickers' reads from the prop, or loading while the prop has not arrived. */
function pickersOf(prop: unknown): Pickers {
  if (prop === undefined || prop === null) {
    return { status: 'loading' };
  }

  const checked = validateGrantPickersV1(prop);

  if (!checked.valid) {
    return { status: 'unreadable' };
  }

  const read = <T,>(picker: PickerReadV1, validate: (value: unknown) => Validation<T>) =>
    readOf(picker.result, picker.rejection, validate);

  return {
    status: 'ready',
    actors: read(checked.value.actors, validateActorListV1),
    roles: read(checked.value.roles, validateRoleListV1),
    nodes: read(checked.value.nodes, validateNodeListV1),
  };
}

/** The name of a grant's or a picker's actor: its profile's name, or that the name is withheld. */
function actorName(t: Translate, profile: { readonly display_name?: string } | null): string {
  return profile?.display_name ?? t('panel.grants.name_withheld');
}

/** The actors a picker offers, each by its name and email, those without a readable profile by their id. */
function pickerActors(t: Translate, actors: readonly ListedActorV1[]): PickerActor[] {
  return actors
    .filter((actor) => actor.state === 'active')
    .map((actor) => ({
      id: actor.id,
      name: actor.profile?.display_name ?? t('panel.grants.actor_by_id', { id: actor.id }),
      email: actor.profile?.email,
    }));
}

/** The roles a picker offers, each by its handle, with its permissions below. */
function pickerRoles(t: Translate, roles: readonly ListedRoleV1[]): PickerRole[] {
  return roles.map((role) => ({
    id: role.id,
    handle: role.handle,
    description:
      role.permissions.length === 0 ? t('panel.roles.no_permissions') : role.permissions.join(', '),
  }));
}

/**
 * The content tree a picker offers, built from the nodes' parents: a node whose parent the list
 * does not hold is a root, and each node is named by the last segment of its path label.
 */
export function nodeTree(nodes: readonly ListedNodeV1[]): TreeNode[] {
  const known = new Set(nodes.map((node) => node.id));
  const children = new Map<string | null, ListedNodeV1[]>();

  for (const node of nodes) {
    const parent = node.parent !== null && known.has(node.parent) ? node.parent : null;
    const siblings = children.get(parent) ?? [];
    siblings.push(node);
    children.set(parent, siblings);
  }

  const build = (parent: string | null): TreeNode[] =>
    (children.get(parent) ?? []).map((node) => {
      const below = build(node.id);

      return {
        id: node.id,
        label: node.label.split('/').at(-1) ?? node.label,
        ...(below.length === 0 ? {} : { children: below }),
      };
    });

  return build(null);
}

/** Whether the actors match what was typed, by name, email or id, ignoring case. */
function matches(actor: PickerActor, search: string): boolean {
  const text = search.trim().toLocaleLowerCase();

  return (
    text === '' ||
    actor.name.toLocaleLowerCase().includes(text) ||
    (actor.email?.toLocaleLowerCase().includes(text) ?? false) ||
    actor.id.includes(text)
  );
}

/**
 * The grants page (PRD 5.10, 13.4): the grants that have not ended on the nodes the person
 * reaches, read with grant.list as the person a page at a time, each with its actor, role, node,
 * effect and languages, from which a person who may run grant.assign gives an actor a role on a
 * node, with pickers for the actor, the role and the node, and one who may run grant.revoke ends a
 * grant, each through the Inertia profile as the person, with the receipt and, for a refusal, the
 * problem details with what the code means shown where the change was made. The props are
 * AccessGrantsPageV1, generated from the page's JSON Schema; the pickers' reads are the optional
 * prop `pickers`, which the page asks for when its form opens; what the person may do comes from
 * the shared prop `palette`, the read of action.list. Below its list the page hosts
 * access.grants.sections@1, where addons add sections, and it keeps its sign-out in its own content.
 */
export default function Grants({ locales, logout, rejection, result }: AccessGrantsPageV1) {
  const { t, locale } = useTranslation();
  const page = usePage();
  const palette = useMemo(() => paletteOf(page.props.palette), [page.props.palette]);
  const read = useMemo(() => readOf(result, rejection, validateGrantListV1), [result, rejection]);
  const pickers = useMemo(() => pickersOf(page.props[PICKERS]), [page.props]);
  const mayAssign = mayRun(palette, 'grant.assign');
  const mayRevoke = mayRun(palette, 'grant.revoke');
  const [assigning, setAssigning] = useState(false);
  const [revoking, setRevoking] = useState<ListedGrantV1 | null>(null);
  const [outcome, setOutcome] = useState<CommandAnswer | null>(null);
  const [loading, setLoading] = useState(false);
  const [cursors, setCursors] = useState<Cursors>([]);
  const after = afterOf(page.url);
  const visit = {
    onStart: () => {
      setLoading(true);
    },
    onFinish: () => {
      setLoading(false);
    },
  };

  function signOut(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault();
    router.post(logout);
  }

  function openAssign() {
    setAssigning(true);
    router.reload({ only: [PICKERS] });
  }

  const assignButton = mayAssign ? (
    <Button variant="primary" icon="plus" onClick={openAssign}>
      {t('panel.grants.assign')}
    </Button>
  ) : undefined;

  return (
    <>
      <Head title={t('panel.grants.title')} />
      <PanelShell page="access.grants">
        <Page
          header={
            <PageHeader
              title={t('panel.grants.title')}
              description={t('panel.grants.description')}
              actions={assignButton}
            />
          }
        >
          {outcome === null ? null : (
            <Outcome answer={outcome} refused="panel.grants.command_refused" />
          )}
          {read.status === 'ready' ? (
            <>
              {mayAssign ? null : <Callout tone="info">{t('panel.grants.cannot_assign')}</Callout>}
              <DataTable<ListedGrantV1>
                label={t('panel.grants.title')}
                columns={[
                  {
                    id: 'actor',
                    title: t('panel.grants.column.actor'),
                    rowHeader: true,
                    render: (grant) =>
                      grant.profile === null ? (
                        <Stack gap="xs">
                          <span>{t('panel.grants.name_withheld')}</span>
                          <code>{grant.actor}</code>
                        </Stack>
                      ) : (
                        <ActorChip
                          name={actorName(t, grant.profile)}
                          email={grant.profile.email}
                          actorClass="staff"
                        />
                      ),
                  },
                  {
                    id: 'role',
                    title: t('panel.grants.column.role'),
                    render: (grant) => <code>{grant.role_handle}</code>,
                  },
                  {
                    id: 'node',
                    title: t('panel.grants.column.node'),
                    render: (grant) => <NodePath segments={grant.node_label.split('/')} />,
                  },
                  {
                    id: 'effect',
                    title: t('panel.grants.column.effect'),
                    render: (grant) => (
                      <Badge tone={grant.effect === 'allow' ? 'success' : 'warning'}>
                        {t(EFFECTS[grant.effect])}
                      </Badge>
                    ),
                  },
                  {
                    id: 'locales',
                    title: t('panel.grants.column.locales'),
                    render: (grant) =>
                      grant.locales === null
                        ? t('panel.access.every_locale')
                        : grant.locales.join(', '),
                  },
                ]}
                rows={read.value.grants}
                rowKey={(grant) => grant.id}
                {...(mayRevoke
                  ? {
                      rowActions: () => [
                        { id: 'revoke', label: t('panel.grants.revoke'), tone: 'danger' as const },
                      ],
                      rowActionsLabel: (grant: ListedGrantV1) =>
                        t('panel.grants.row_actions', {
                          role: grant.role_handle,
                          actor: actorName(t, grant.profile),
                        }),
                      onRowAction: (action: string, grant: ListedGrantV1) => {
                        if (action === 'revoke') {
                          setRevoking(grant);
                        }
                      },
                    }
                  : {})}
                loading={loading ? t('panel.grants.loading') : undefined}
                empty={
                  <EmptyState
                    title={t('panel.grants.empty_title')}
                    description={t(
                      mayAssign ? 'panel.grants.empty_body' : 'panel.grants.empty_body_readonly',
                    )}
                    action={assignButton}
                    headingLevel={3}
                  />
                }
                pagination={
                  after === null && read.value.next === null ? undefined : (
                    <Pagination
                      label={t('panel.access.pagination')}
                      status={t('panel.access.page_status', { count: read.value.grants.length })}
                      onPrevious={
                        after === null
                          ? undefined
                          : () => {
                              const previous = back(cursors);
                              setCursors(previous.cursors);
                              visitPage(page.url, previous.after, visit);
                            }
                      }
                      onNext={
                        read.value.next === null
                          ? undefined
                          : () => {
                              setCursors(forward(cursors, after));
                              visitPage(page.url, read.value.next, visit);
                            }
                      }
                    />
                  )
                }
              />
            </>
          ) : read.status === 'rejected' ? (
            <ProblemDetails
              problem={read.problem}
              explanation={explanationOf(locale, t, read.problem, 'panel.grants.refused')}
            />
          ) : (
            <ErrorState
              title={t('panel.access.unavailable_title')}
              description={t('panel.access.unavailable_body')}
            />
          )}
          <PointHost point="access.grants.sections@1" />
          <Form method="post" action={logout} onSubmit={signOut}>
            <Button type="submit">{t('panel.home.sign_out')}</Button>
          </Form>
        </Page>
        {assigning ? (
          <AssignGrantDialog
            pickers={pickers}
            locales={locales}
            onClose={() => {
              setAssigning(false);
            }}
            onDone={(answer) => {
              setAssigning(false);
              setOutcome(answer);
            }}
          />
        ) : null}
        {revoking === null ? null : (
          <RevokeGrant
            grant={revoking}
            onClose={() => {
              setRevoking(null);
            }}
            onDone={(answer) => {
              setRevoking(null);
              setOutcome(answer);
            }}
          />
        )}
      </PanelShell>
    </>
  );
}

/** What the last command of the page came to: its receipt and, for a refusal, the problem. */
function Outcome({
  answer,
  refused,
}: {
  readonly answer: CommandAnswer;
  readonly refused: TranslationKey;
}) {
  const { t, locale } = useTranslation();

  return (
    <Stack gap="sm">
      <ReceiptStatus receipt={answer.receipt} />
      {answer.problem === null ? null : (
        <ProblemDetails
          problem={answer.problem}
          explanation={explanationOf(locale, t, answer.problem, refused)}
        />
      )}
    </Stack>
  );
}

/** How a dialog's command ended, as the dialog shows it before it closes or stays open. */
type Submission =
  | { readonly status: 'idle' }
  | { readonly status: 'running' }
  | { readonly status: 'rejected'; readonly problem: ProblemV1 | null }
  | { readonly status: 'failed' };

/** The form that assigns a grant: the actor, the role, the node, the effect and the languages. */
function AssignGrantDialog({
  pickers,
  locales,
  onClose,
  onDone,
}: {
  readonly pickers: Pickers;
  readonly locales: readonly string[];
  readonly onClose: () => void;
  readonly onDone: (answer: CommandAnswer) => void;
}) {
  const { t, locale } = useTranslation();
  const { runCommand, notify } = useCoreServices();
  const [actor, setActor] = useState<string | null>(null);
  const [search, setSearch] = useState('');
  const [role, setRole] = useState<string | null>(null);
  const [node, setNode] = useState<string | null>(null);
  const [effect, setEffect] = useState<GrantEffect>('allow');
  const [chosenLocales, setChosenLocales] = useState<string[]>([]);
  const [submission, setSubmission] = useState<Submission>({ status: 'idle' });
  const [touched, setTouched] = useState(false);
  const problem = submission.status === 'rejected' ? submission.problem : null;
  const actors = useMemo(
    () =>
      pickers.status === 'ready' && pickers.actors.status === 'ready'
        ? pickerActors(t, pickers.actors.value.actors)
        : [],
    [pickers, t],
  );
  const roles =
    pickers.status === 'ready' && pickers.roles.status === 'ready'
      ? pickerRoles(t, pickers.roles.value.roles)
      : [];
  const nodes = useMemo(
    () =>
      pickers.status === 'ready' && pickers.nodes.status === 'ready'
        ? nodeTree(pickers.nodes.value.nodes)
        : [],
    [pickers],
  );
  const missing = (value: string | null, key: TranslationKey): string | undefined =>
    touched && value === null ? t(key) : undefined;

  function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault();
    setTouched(true);

    if (actor === null || role === null || node === null || submission.status === 'running') {
      return;
    }

    setSubmission({ status: 'running' });
    runCommand(ASSIGN, {
      grant: uuid7(),
      actor,
      role,
      node,
      effect,
      locales: chosenLocales.length === 0 ? null : chosenLocales,
    }).then(
      (answer) => {
        if (answer.receipt.outcome === 'rejected') {
          setSubmission({ status: 'rejected', problem: answer.problem });

          return;
        }

        notify('success', t('panel.grants.assigned'));
        onDone(answer);
      },
      () => {
        setSubmission({ status: 'failed' });
      },
    );
  }

  /** What a picker says while its read is on its way, or why it has no options. */
  const pickerState = (
    read: ReadState<unknown> | undefined,
  ): { readonly loading?: string; readonly loadError?: string } => {
    if (pickers.status === 'loading') {
      return { loading: t('panel.grants.pickers_loading') };
    }

    if (pickers.status === 'unreadable' || read === undefined || read.status === 'unreadable') {
      return { loadError: t('panel.grants.picker_unavailable') };
    }

    if (read.status === 'rejected') {
      return { loadError: explanationOf(locale, t, read.problem, 'panel.grants.picker_refused') };
    }

    return {};
  };

  const actorState = pickerState(pickers.status === 'ready' ? pickers.actors : undefined);
  const roleState = pickerState(pickers.status === 'ready' ? pickers.roles : undefined);
  const nodeState = pickerState(pickers.status === 'ready' ? pickers.nodes : undefined);

  return (
    <Dialog
      title={t('panel.grants.assign_title')}
      open
      onOpenChange={(open) => {
        if (!open) {
          onClose();
        }
      }}
    >
      <Stack gap="md">
        <p>{t('panel.grants.assign_description')}</p>
        {submission.status === 'rejected' && submission.problem !== null ? (
          <ProblemDetails
            problem={submission.problem}
            explanation={explanationOf(
              locale,
              t,
              submission.problem,
              'panel.grants.command_refused',
            )}
          />
        ) : null}
        {submission.status === 'failed' ? (
          <Callout tone="warning" title={t('panel.access.failed_title')}>
            {t('panel.access.failed_body')}
          </Callout>
        ) : null}
        {pickers.status === 'loading' ? (
          <ProgressLabel>{t('panel.grants.pickers_loading')}</ProgressLabel>
        ) : null}
        <Form onSubmit={submit}>
          <ActorPicker
            label={t('panel.grants.actor')}
            description={t('panel.grants.actor_hint')}
            name="actor"
            required
            actors={actors.filter((candidate) => matches(candidate, search))}
            value={actor}
            onChange={setActor}
            search={search}
            onSearchChange={setSearch}
            loading={actorState.loading}
            loadError={actorState.loadError}
            emptyLabel={t('panel.grants.actor_empty')}
            error={fieldErrorOf(problem, 'actor') ?? missing(actor, 'panel.grants.actor_required')}
          />
          <RolePicker
            label={t('panel.grants.role')}
            description={t('panel.grants.role_hint')}
            name="role"
            required
            roles={roles}
            value={role}
            onChange={setRole}
            loading={roleState.loading}
            loadError={roleState.loadError}
            emptyLabel={t('panel.grants.role_empty')}
            error={fieldErrorOf(problem, 'role') ?? missing(role, 'panel.grants.role_required')}
          />
          <NodePicker
            label={t('panel.grants.node')}
            description={t('panel.grants.node_hint')}
            required
            nodes={nodes}
            value={node}
            onChange={setNode}
            loading={nodeState.loading}
            loadError={
              nodeState.loadError === undefined ? undefined : (
                <ErrorState
                  title={t('panel.grants.node_unavailable_title')}
                  description={nodeState.loadError}
                  headingLevel={3}
                />
              )
            }
            empty={
              <EmptyState
                title={t('panel.grants.node_empty_title')}
                description={t('panel.grants.node_empty_body')}
                headingLevel={3}
              />
            }
            error={fieldErrorOf(problem, 'node') ?? missing(node, 'panel.grants.node_required')}
          />
          <RadioGroup
            label={t('panel.grants.effect')}
            name="effect"
            options={[
              {
                value: 'allow',
                label: t('panel.grants.effect_allow'),
                description: t('panel.grants.effect_allow_hint'),
              },
              {
                value: 'deny',
                label: t('panel.grants.effect_deny'),
                description: t('panel.grants.effect_deny_hint'),
              },
            ]}
            value={effect}
            onChange={(value) => {
              setEffect(value === 'deny' ? 'deny' : 'allow');
            }}
            error={fieldErrorOf(problem, 'effect')}
          />
          <MultiSelect
            label={t('panel.grants.locales')}
            description={t('panel.grants.locales_hint')}
            name="locales"
            options={locales.map((tag) => ({ id: tag, label: tag }))}
            value={chosenLocales}
            onChange={setChosenLocales}
            noneLabel={t('panel.access.every_locale')}
            error={fieldErrorOf(problem, 'locales')}
          />
          {submission.status === 'running' ? (
            <ProgressLabel>{t('panel.access.saving')}</ProgressLabel>
          ) : null}
          <FormActions>
            <Button variant="quiet" onClick={onClose}>
              {t('panel.access.cancel')}
            </Button>
            <Button type="submit" variant="primary" disabled={submission.status === 'running'}>
              {t('panel.grants.submit_assign')}
            </Button>
          </FormActions>
        </Form>
      </Stack>
    </Dialog>
  );
}

/** The question before a grant is revoked, and the revocation once it is confirmed. */
function RevokeGrant({
  grant,
  onClose,
  onDone,
}: {
  readonly grant: ListedGrantV1;
  readonly onClose: () => void;
  readonly onDone: (answer: CommandAnswer) => void;
}) {
  const { t } = useTranslation();
  const { runCommand, notify } = useCoreServices();
  const [running, setRunning] = useState(false);
  const [failed, setFailed] = useState(false);

  function revoke() {
    if (running) {
      return;
    }

    setRunning(true);
    runCommand(REVOKE, { grant: grant.id, version: grant.version }).then(
      (answer) => {
        if (answer.receipt.outcome !== 'rejected') {
          notify('success', t('panel.grants.revoked'));
        }

        onDone(answer);
      },
      () => {
        setRunning(false);
        setFailed(true);
      },
    );
  }

  if (failed) {
    return (
      <Dialog title={t('panel.grants.revoke_title')} open onOpenChange={onClose}>
        <Callout tone="warning" title={t('panel.access.failed_title')}>
          {t('panel.access.failed_body')}
        </Callout>
      </Dialog>
    );
  }

  return (
    <ConfirmDialog
      open
      title={t('panel.grants.revoke_title')}
      message={t('panel.grants.revoke_body', {
        actor: actorName(t, grant.profile),
        role: grant.role_handle,
        node: grant.node_label,
      })}
      confirmLabel={t(running ? 'panel.access.saving' : 'panel.grants.revoke_confirm')}
      cancelLabel={t('panel.access.cancel')}
      tone="danger"
      onConfirm={revoke}
      onOpenChange={(open) => {
        if (!open && !running) {
          onClose();
        }
      }}
    />
  );
}
