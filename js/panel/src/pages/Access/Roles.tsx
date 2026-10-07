import {
  Button,
  Callout,
  ClassificationBadge,
  DataTable,
  Dialog,
  EmptyState,
  ErrorState,
  Form,
  FormActions,
  Inline,
  MultiSelect,
  Page,
  PageHeader,
  Pagination,
  ProblemDetails,
  ProgressLabel,
  ReceiptStatus,
  Select,
  Stack,
  Tag,
  TextInput,
} from '@cboxdk/cms-ui-kit';
import type { CommandAnswer } from '@cboxdk/cms-panel/extend';
import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState, type SubmitEvent } from 'react';

import { uuid7 } from '../../access/ids';
import { afterOf, back, forward, visitPage, type Cursors } from '../../access/list-pages';
import { mayRun, permissionOptions, type PermissionOption } from '../../access/permissions';
import { explanationOf, fieldErrorOf } from '../../access/problems';
import { readOf } from '../../access/reads';
import type { AccessRolesPageV1 } from '../../generated/pages/AccessRolesPageV1';
import type { ProblemV1 } from '../../generated/protocol/ProblemV1';
import {
  validateRoleListV1,
  type ClassificationAccess,
  type ListedRoleV1,
} from '../../generated/protocol/RoleListV1';
import { PointHost, useCoreServices } from '../../host';
import { lookup, useTranslation, type Locale, type TranslationKey } from '../../i18n/translations';
import { paletteOf } from '../../shell/palette';
import { PanelShell } from '../../shell/PanelShell';

/** The command the page creates a role with, and the one it replaces a role's permissions with. */
const CREATE = 'role.create@1';
const SET_PERMISSIONS = 'role.set_permissions@1';

/** The classification ceilings a role may have, lowest first, each with its text. */
const CEILINGS: readonly { readonly id: ClassificationAccess; readonly key: TranslationKey }[] = [
  { id: 'public', key: 'panel.access.ceiling.public' },
  { id: 'internal', key: 'panel.access.ceiling.internal' },
  { id: 'confidential', key: 'panel.access.ceiling.confidential' },
  { id: 'personal', key: 'panel.access.ceiling.personal' },
  { id: 'sensitive', key: 'panel.access.ceiling.sensitive' },
];

/** The form of a role's handle, as role.create takes it. */
const HANDLE = /^[a-z][a-z0-9_]{0,62}$/;

function isCeiling(value: string): value is ClassificationAccess {
  return CEILINGS.some((ceiling) => ceiling.id === value);
}

/** The text of a permission's action, from the panel's catalogue or the action's schema. */
function permissionTitle(locale: Locale) {
  return (action: { readonly name: string; readonly title: string }): string =>
    lookup(locale, `panel.action.${action.name}.title`) ?? action.title;
}

/**
 * The roles page (PRD 5.10, 13.4): the roles of the installation with their classification
 * ceilings and permissions, read with role.list as the person a page at a time, from which a
 * person who may run role.create creates a role and one who may run role.set_permissions replaces
 * a role's permissions, each through the Inertia profile as the person, with the receipt and, for
 * a refusal, the problem details with what the code means shown where the change was made. The
 * props are AccessRolesPageV1, generated from the page's JSON Schema; what the person may do comes
 * from the shared prop `palette`, the read of action.list. Below its list the page hosts
 * access.roles.sections@1, where addons add sections, and it keeps its sign-out in its own content.
 */
export default function Roles({ logout, rejection, result }: AccessRolesPageV1) {
  const { t, locale } = useTranslation();
  const page = usePage();
  const palette = useMemo(() => paletteOf(page.props.palette), [page.props.palette]);
  const read = useMemo(() => readOf(result, rejection, validateRoleListV1), [result, rejection]);
  const mayCreate = mayRun(palette, 'role.create');
  const mayEdit = mayRun(palette, 'role.set_permissions');
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<ListedRoleV1 | null>(null);
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

  const createButton = mayCreate ? (
    <Button
      variant="primary"
      icon="plus"
      onClick={() => {
        setCreating(true);
      }}
    >
      {t('panel.roles.create')}
    </Button>
  ) : undefined;

  return (
    <>
      <Head title={t('panel.roles.title')} />
      <PanelShell page="access.roles">
        <Page
          header={
            <PageHeader
              title={t('panel.roles.title')}
              description={t('panel.roles.description')}
              actions={createButton}
            />
          }
        >
          {outcome === null ? null : (
            <Outcome answer={outcome} refused="panel.roles.command_refused" />
          )}
          {read.status === 'ready' ? (
            <>
              {mayCreate ? null : <Callout tone="info">{t('panel.roles.cannot_create')}</Callout>}
              <DataTable<ListedRoleV1>
                label={t('panel.roles.title')}
                columns={[
                  {
                    id: 'handle',
                    title: t('panel.roles.column.handle'),
                    rowHeader: true,
                    render: (role) => <code>{role.handle}</code>,
                  },
                  {
                    id: 'ceiling',
                    title: t('panel.roles.column.ceiling'),
                    render: (role) => <ClassificationBadge classification={role.ceiling} />,
                  },
                  {
                    id: 'permissions',
                    title: t('panel.roles.column.permissions'),
                    render: (role) =>
                      role.permissions.length === 0 ? (
                        t('panel.roles.no_permissions')
                      ) : (
                        <Inline gap="xs">
                          {role.permissions.map((permission) => (
                            <Tag key={permission} label={permission} />
                          ))}
                        </Inline>
                      ),
                  },
                ]}
                rows={read.value.roles}
                rowKey={(role) => role.id}
                {...(mayEdit
                  ? {
                      rowActions: () => [
                        { id: 'permissions', label: t('panel.roles.edit_permissions') },
                      ],
                      rowActionsLabel: (role: ListedRoleV1) =>
                        t('panel.roles.row_actions', { handle: role.handle }),
                      onRowAction: (action: string, role: ListedRoleV1) => {
                        if (action === 'permissions') {
                          setEditing(role);
                        }
                      },
                    }
                  : {})}
                loading={loading ? t('panel.roles.loading') : undefined}
                empty={
                  <EmptyState
                    title={t('panel.roles.empty_title')}
                    description={t(
                      mayCreate ? 'panel.roles.empty_body' : 'panel.roles.empty_body_readonly',
                    )}
                    action={createButton}
                    headingLevel={3}
                  />
                }
                pagination={
                  after === null && read.value.next === null ? undefined : (
                    <Pagination
                      label={t('panel.access.pagination')}
                      status={t('panel.access.page_status', { count: read.value.roles.length })}
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
              explanation={explanationOf(locale, t, read.problem, 'panel.roles.refused')}
            />
          ) : (
            <ErrorState
              title={t('panel.access.unavailable_title')}
              description={t('panel.access.unavailable_body')}
            />
          )}
          <PointHost point="access.roles.sections@1" />
          <Form method="post" action={logout} onSubmit={signOut}>
            <Button type="submit">{t('panel.home.sign_out')}</Button>
          </Form>
        </Page>
        {creating ? (
          <CreateRoleDialog
            options={permissionOptions(palette, [], permissionTitle(locale))}
            onClose={() => {
              setCreating(false);
            }}
            onDone={(answer) => {
              setCreating(false);
              setOutcome(answer);
            }}
          />
        ) : null}
        {editing === null ? null : (
          <EditPermissionsDialog
            role={editing}
            options={permissionOptions(palette, editing.permissions, permissionTitle(locale))}
            onClose={() => {
              setEditing(null);
            }}
            onDone={(answer) => {
              setEditing(null);
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
  | {
      readonly status: 'rejected';
      readonly problem: ProblemV1 | null;
      readonly answer: CommandAnswer;
    }
  | { readonly status: 'failed' };

/** The options of the permissions of a role form: each permission by its name, with its action's text below. */
function permissionChoices(options: readonly PermissionOption[]) {
  return options.map((option) => ({
    id: option.name,
    label: option.name,
    description: option.title === option.name ? undefined : option.title,
  }));
}

/** The form that creates a role: its handle, its ceiling and its permissions. */
function CreateRoleDialog({
  options,
  onClose,
  onDone,
}: {
  readonly options: readonly PermissionOption[];
  readonly onClose: () => void;
  readonly onDone: (answer: CommandAnswer) => void;
}) {
  const { t, locale } = useTranslation();
  const { runCommand, notify } = useCoreServices();
  const [handle, setHandle] = useState('');
  const [ceiling, setCeiling] = useState<ClassificationAccess>('internal');
  const [permissions, setPermissions] = useState<string[]>([]);
  const [submission, setSubmission] = useState<Submission>({ status: 'idle' });
  const [touched, setTouched] = useState(false);
  const problem = submission.status === 'rejected' ? submission.problem : null;
  const handleInvalid = touched && !HANDLE.test(handle);
  const handleError =
    fieldErrorOf(problem, 'handle') ??
    (handleInvalid ? t('panel.roles.handle_invalid') : undefined);

  function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault();
    setTouched(true);

    if (!HANDLE.test(handle) || submission.status === 'running') {
      return;
    }

    setSubmission({ status: 'running' });
    runCommand(CREATE, { role: uuid7(), handle, ceiling, permissions }).then(
      (answer) => {
        if (answer.receipt.outcome === 'rejected') {
          setSubmission({ status: 'rejected', problem: answer.problem, answer });

          return;
        }

        notify('success', t('panel.roles.created', { handle }));
        onDone(answer);
      },
      () => {
        setSubmission({ status: 'failed' });
      },
    );
  }

  return (
    <Dialog
      title={t('panel.roles.create_title')}
      open
      onOpenChange={(open) => {
        if (!open) {
          onClose();
        }
      }}
    >
      <Stack gap="md">
        <p>{t('panel.roles.create_description')}</p>
        {submission.status === 'rejected' && submission.problem !== null ? (
          <ProblemDetails
            problem={submission.problem}
            explanation={explanationOf(
              locale,
              t,
              submission.problem,
              'panel.roles.command_refused',
            )}
          />
        ) : null}
        {submission.status === 'failed' ? (
          <Callout tone="warning" title={t('panel.access.failed_title')}>
            {t('panel.access.failed_body')}
          </Callout>
        ) : null}
        <Form onSubmit={submit}>
          <TextInput
            label={t('panel.roles.handle')}
            description={t('panel.roles.handle_hint')}
            name="handle"
            required
            autoComplete="off"
            value={handle}
            onChange={(event) => {
              setHandle(event.target.value);
            }}
            onBlur={() => {
              setTouched(true);
            }}
            error={handleError}
          />
          <Select
            label={t('panel.roles.ceiling')}
            description={t('panel.roles.ceiling_hint')}
            name="ceiling"
            options={CEILINGS.map((option) => ({ id: option.id, label: t(option.key) }))}
            value={ceiling}
            onChange={(value) => {
              if (isCeiling(value)) {
                setCeiling(value);
              }
            }}
            error={fieldErrorOf(problem, 'ceiling')}
          />
          <MultiSelect
            label={t('panel.roles.permissions')}
            description={t('panel.roles.permissions_hint')}
            name="permissions"
            options={permissionChoices(options)}
            value={permissions}
            onChange={setPermissions}
            noneLabel={t('panel.roles.no_permissions')}
            error={fieldErrorOf(problem, 'permissions')}
          />
          {submission.status === 'running' ? (
            <ProgressLabel>{t('panel.access.saving')}</ProgressLabel>
          ) : null}
          <FormActions>
            <Button variant="quiet" onClick={onClose}>
              {t('panel.access.cancel')}
            </Button>
            <Button type="submit" variant="primary" disabled={submission.status === 'running'}>
              {t('panel.roles.submit_create')}
            </Button>
          </FormActions>
        </Form>
      </Stack>
    </Dialog>
  );
}

/** Whether two lists of permissions hold the same names. */
function samePermissions(a: readonly string[], b: readonly string[]): boolean {
  const sortedA = [...a].sort();
  const sortedB = [...b].sort();

  return (
    sortedA.length === sortedB.length && sortedA.every((name, index) => name === sortedB[index])
  );
}

/** The form that replaces a role's permissions in full. */
function EditPermissionsDialog({
  role,
  options,
  onClose,
  onDone,
}: {
  readonly role: ListedRoleV1;
  readonly options: readonly PermissionOption[];
  readonly onClose: () => void;
  readonly onDone: (answer: CommandAnswer) => void;
}) {
  const { t, locale } = useTranslation();
  const { runCommand, notify } = useCoreServices();
  const [permissions, setPermissions] = useState<string[]>([...role.permissions]);
  const [submission, setSubmission] = useState<Submission>({ status: 'idle' });
  const problem = submission.status === 'rejected' ? submission.problem : null;
  const unchanged = samePermissions(permissions, role.permissions);

  function submit(event: SubmitEvent<HTMLFormElement>) {
    event.preventDefault();

    if (unchanged || submission.status === 'running') {
      return;
    }

    setSubmission({ status: 'running' });
    runCommand(SET_PERMISSIONS, { role: role.id, version: role.version, permissions }).then(
      (answer) => {
        if (answer.receipt.outcome === 'rejected') {
          setSubmission({ status: 'rejected', problem: answer.problem, answer });

          return;
        }

        notify('success', t('panel.roles.permissions_saved', { handle: role.handle }));
        onDone(answer);
      },
      () => {
        setSubmission({ status: 'failed' });
      },
    );
  }

  return (
    <Dialog
      title={t('panel.roles.edit_title', { handle: role.handle })}
      open
      onOpenChange={(open) => {
        if (!open) {
          onClose();
        }
      }}
    >
      <Stack gap="md">
        <p>{t('panel.roles.edit_description')}</p>
        {submission.status === 'rejected' && submission.problem !== null ? (
          <ProblemDetails
            problem={submission.problem}
            explanation={explanationOf(
              locale,
              t,
              submission.problem,
              'panel.roles.command_refused',
            )}
          />
        ) : null}
        {submission.status === 'failed' ? (
          <Callout tone="warning" title={t('panel.access.failed_title')}>
            {t('panel.access.failed_body')}
          </Callout>
        ) : null}
        <Form onSubmit={submit}>
          <MultiSelect
            label={t('panel.roles.permissions')}
            description={t('panel.roles.permissions_hint')}
            name="permissions"
            options={permissionChoices(options)}
            value={permissions}
            onChange={setPermissions}
            noneLabel={t('panel.roles.no_permissions')}
            error={fieldErrorOf(problem, 'permissions')}
          />
          {unchanged ? <p>{t('panel.roles.unchanged')}</p> : null}
          {submission.status === 'running' ? (
            <ProgressLabel>{t('panel.access.saving')}</ProgressLabel>
          ) : null}
          <FormActions>
            <Button variant="quiet" onClick={onClose}>
              {t('panel.access.cancel')}
            </Button>
            <Button
              type="submit"
              variant="primary"
              disabled={unchanged || submission.status === 'running'}
            >
              {t('panel.roles.submit_permissions')}
            </Button>
          </FormActions>
        </Form>
      </Stack>
    </Dialog>
  );
}
