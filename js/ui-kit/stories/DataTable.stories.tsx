import {
  ActorChip,
  Badge,
  Button,
  DataTable,
  EmptyState,
  ErrorState,
  NodePath,
  Pagination,
  type DataTableColumn,
  type DataTableProps,
  type DataTableSort,
} from '@cboxdk/cms-ui-kit';
import { useState } from 'react';

import {
  check,
  focused,
  inDanish,
  inDark,
  inForcedColours,
  single,
  textsOf,
  waitFor,
  type Localized,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<DataTableProps<Grant>> = {
  title: 'Components/Data display/DataTable',
  component: DataTable,
};

export default meta;

interface Grant {
  readonly id: string;
  readonly actor: string;
  readonly email: string;
  readonly role: string;
  readonly node: readonly string[];
  readonly allow: boolean;
}

const GRANTS: readonly Grant[] = [
  {
    id: 'g1',
    actor: 'Ada Lovelace',
    email: 'ada@example.com',
    role: 'editor',
    node: ['Site', 'News'],
    allow: true,
  },
  {
    id: 'g2',
    actor: 'Grace Hopper',
    email: 'grace@example.com',
    role: 'publisher',
    node: ['Site'],
    allow: true,
  },
  {
    id: 'g3',
    actor: 'Alan Turing',
    email: 'alan@example.com',
    role: 'editor',
    node: ['Site', 'News', 'Sport'],
    allow: false,
  },
];

const TEXTS: Localized<{
  label: string;
  actor: string;
  role: string;
  node: string;
  effect: string;
  allow: string;
  deny: string;
  actions: string;
  revoke: string;
  edit: string;
  pages: string;
  status: string;
  emptyTitle: string;
  emptyDescription: string;
  emptyAction: string;
  loading: string;
  failed: string;
  failedDescription: string;
  retry: string;
}> = {
  da: {
    label: 'Adgange',
    actor: 'Medarbejder',
    role: 'Rolle',
    node: 'Node',
    effect: 'Virkning',
    allow: 'Tillad',
    deny: 'Afvis',
    actions: 'Handlinger for adgangen til',
    revoke: 'Tilbagekald',
    edit: 'Redigér',
    pages: 'Sider af adgange',
    status: 'Række 1 til 3',
    emptyTitle: 'Ingen adgange endnu',
    emptyDescription: 'En adgang giver en medarbejder en rolle på en node.',
    emptyAction: 'Tildel den første adgang',
    loading: 'Henter adgangene',
    failed: 'Adgangene kunne ikke hentes',
    failedDescription: 'Forbindelsen til serveren blev afbrudt. Prøv igen.',
    retry: 'Prøv igen',
  },
  en: {
    label: 'Grants',
    actor: 'Member of staff',
    role: 'Role',
    node: 'Node',
    effect: 'Effect',
    allow: 'Allow',
    deny: 'Deny',
    actions: 'Actions for the grant of',
    revoke: 'Revoke',
    edit: 'Edit',
    pages: 'Pages of grants',
    status: 'Rows 1 to 3',
    emptyTitle: 'No grants yet',
    emptyDescription: 'A grant gives a member of staff a role on a node.',
    emptyAction: 'Assign the first grant',
    loading: 'Loading the grants',
    failed: 'The grants could not be loaded',
    failedDescription: 'The connection to the server was lost. Try again.',
    retry: 'Try again',
  },
};

function columns(globals: Readonly<Record<string, unknown>>): DataTableColumn<Grant>[] {
  const texts = textsOf(TEXTS, globals);

  return [
    {
      id: 'actor',
      title: texts.actor,
      rowHeader: true,
      sortable: true,
      render: (grant) => <ActorChip name={grant.actor} email={grant.email} />,
    },
    { id: 'role', title: texts.role, sortable: true, render: (grant) => <code>{grant.role}</code> },
    { id: 'node', title: texts.node, render: (grant) => <NodePath segments={grant.node} /> },
    {
      id: 'effect',
      title: texts.effect,
      render: (grant) =>
        grant.allow ? (
          <Badge tone="success">{texts.allow}</Badge>
        ) : (
          <Badge tone="danger">{texts.deny}</Badge>
        ),
    },
  ];
}

function Grants({
  globals,
  rows,
  loading,
  failed,
}: {
  readonly globals: Readonly<Record<string, unknown>>;
  readonly rows: readonly Grant[];
  readonly loading?: boolean;
  readonly failed?: boolean;
}) {
  const texts = textsOf(TEXTS, globals);
  const [sort, setSort] = useState<DataTableSort>({ column: 'actor', direction: 'ascending' });
  const sorted = [...rows].sort((first, second) => {
    const key = sort.column === 'role' ? 'role' : 'actor';
    const order = first[key].localeCompare(second[key]);

    return sort.direction === 'ascending' ? order : -order;
  });

  return (
    <DataTable
      label={texts.label}
      columns={columns(globals)}
      rows={sorted}
      rowKey={(grant) => grant.id}
      sort={sort}
      onSortChange={setSort}
      rowActions={() => [
        { id: 'edit', label: texts.edit },
        { id: 'revoke', label: texts.revoke, tone: 'danger' },
      ]}
      rowActionsLabel={(grant) => `${texts.actions} ${grant.actor}`}
      onRowAction={(action, grant) => {
        document.body.dataset['action'] = `${action}:${grant.id}`;
      }}
      {...(loading === true ? { loading: texts.loading } : {})}
      error={
        failed === true ? (
          <ErrorState
            title={texts.failed}
            description={texts.failedDescription}
            action={<Button>{texts.retry}</Button>}
          />
        ) : undefined
      }
      empty={
        <EmptyState
          title={texts.emptyTitle}
          description={texts.emptyDescription}
          action={<Button variant="primary">{texts.emptyAction}</Button>}
          headingLevel={3}
        />
      }
      pagination={
        rows.length === 0 ? undefined : (
          <Pagination label={texts.pages} status={texts.status} onNext={() => undefined} />
        )
      }
    />
  );
}

/** A page of grants, sorted by the member of staff, with a menu of actions on each row. */
export const Default: Story = {
  render: (_args, { globals }) => <Grants globals={globals} rows={GRANTS} />,
  play: ({ canvasElement }) => {
    const table = single(canvasElement, '[role="grid"]', HTMLTableElement);
    const sorted = single(table, '[aria-sort="ascending"]', HTMLTableCellElement);

    check(table.querySelectorAll('tbody tr').length === 3, 'each grant is a row');
    check(sorted.textContent.length > 0, 'the sorted column is told by aria-sort');
  },
};

/**
 * Tab moves into the grid; the arrow keys move between its cells, Enter on a heading sorts by it,
 * and Enter on a row's menu takes an action.
 */
export const Keyboard: Story = {
  render: Default.render,
  play: async ({ canvasElement, userEvent }) => {
    const table = single(canvasElement, '[role="grid"]', HTMLTableElement);

    await userEvent.tab();
    check(table.contains(document.activeElement), 'Tab moves into the table');
    await userEvent.keyboard('{ArrowUp}');
    check(focused(HTMLElement).getAttribute('role') === 'columnheader', 'Up reaches a heading');
    await userEvent.keyboard('{Enter}');
    await waitFor(
      () => table.querySelector('[aria-sort="descending"]') !== null,
      'Enter turns the order around',
    );
    await userEvent.keyboard('{ArrowDown}{End}');
    await userEvent.keyboard('{Enter}');
    await waitFor(() => document.querySelector('[role="menu"]') !== null, 'Enter opens the menu');
    await userEvent.keyboard('{ArrowDown}{Enter}');
    check(document.body.dataset['action']?.startsWith('revoke:') === true, 'the menu takes it');
    delete document.body.dataset['action'];
  },
};

/** No grants: the empty state with the first action. */
export const Empty: Story = {
  render: (_args, { globals }) => <Grants globals={globals} rows={[]} />,
  play: ({ canvasElement }) => {
    single(canvasElement, 'h3', HTMLHeadingElement);
  },
};

/** While the rows load, the table says what it waits for. */
export const Loading: Story = {
  render: (_args, { globals }) => <Grants globals={globals} rows={GRANTS} loading />,
  play: ({ canvasElement }) => {
    single(canvasElement, '[role="status"]', HTMLSpanElement);
  },
};

/** When the rows could not be loaded: the error in place of the table, with what to do. */
export const LoadFailed: Story = {
  render: (_args, { globals }) => <Grants globals={globals} rows={GRANTS} failed />,
  play: ({ canvasElement }) => {
    check(canvasElement.querySelector('table') === null, 'the error takes the table’s place');
    single(canvasElement, '[role="alert"]', HTMLDivElement);
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
