// @vitest-environment jsdom

import { screen, waitFor, within } from '@testing-library/react';
import { useState } from 'react';
import { describe, expect, test, vi } from 'vitest';

import { DataTable, type DataTableSort } from '../../src/components/DataTable';
import { active, renderKit } from './harness';

interface Row {
  readonly id: string;
  readonly name: string;
  readonly role: string;
}

const ROWS: readonly Row[] = [
  { id: 'a', name: 'Ada', role: 'editor' },
  { id: 'g', name: 'Grace', role: 'publisher' },
];

/** The texts the test renders, as a caller's translations would give them. */
const TEXT = { label: 'Grants', name: 'Name', role: 'Role', revoke: 'Revoke', empty: 'No grants' };

function Harness({
  onSortChange,
  onRowAction,
}: {
  readonly onSortChange: (sort: DataTableSort) => void;
  readonly onRowAction: (action: string, row: Row) => void;
}) {
  const [sort, setSort] = useState<DataTableSort>({ column: 'name', direction: 'ascending' });

  return (
    <DataTable
      label={TEXT.label}
      columns={[
        {
          id: 'name',
          title: TEXT.name,
          rowHeader: true,
          sortable: true,
          render: (row) => row.name,
        },
        { id: 'role', title: TEXT.role, render: (row) => row.role },
      ]}
      rows={ROWS}
      rowKey={(row) => row.id}
      sort={sort}
      onSortChange={(next) => {
        onSortChange(next);
        setSort(next);
      }}
      rowActions={() => [{ id: 'revoke', label: TEXT.revoke, tone: 'danger' }]}
      rowActionsLabel={(row) => `Actions for ${row.name}`}
      onRowAction={onRowAction}
      empty={<p>{TEXT.empty}</p>}
    />
  );
}

function renderTable() {
  const onSortChange = vi.fn<(sort: DataTableSort) => void>();
  const onRowAction = vi.fn<(action: string, row: Row) => void>();

  return {
    ...renderKit(<Harness onSortChange={onSortChange} onRowAction={onRowAction} />),
    onSortChange,
    onRowAction,
  };
}

describe('the keyboard contract of DataTable', () => {
  test('is a grid named by its label, with the sorted column told by aria-sort', () => {
    renderTable();
    const grid = screen.getByRole('grid', { name: 'Grants' });

    expect(within(grid).getByRole('columnheader', { name: /Name/ }).getAttribute('aria-sort')).toBe(
      'ascending',
    );
    expect(within(grid).getAllByRole('row')).toHaveLength(3);
  });

  test('Tab moves into the grid in one stop, and the arrow keys move from cell to cell', async () => {
    const { user } = renderTable();
    const grid = screen.getByRole('grid');

    await user.tab();
    expect(grid.contains(active())).toBe(true);
    await user.keyboard('{ArrowRight}');
    expect(active().textContent).toBe('Ada');
    await user.keyboard('{ArrowRight}');
    expect(active().textContent).toBe('editor');
    await user.keyboard('{ArrowDown}');
    expect(active().textContent).toBe('publisher');
  });

  test('Enter on a sortable heading sorts by it and turns the order around', async () => {
    const { user, onSortChange } = renderTable();

    await user.tab();
    await user.keyboard('{ArrowRight}{ArrowUp}');
    expect(active().getAttribute('role')).toBe('columnheader');
    await user.keyboard('{Enter}');

    expect(onSortChange).toHaveBeenLastCalledWith({ column: 'name', direction: 'descending' });
    await waitFor(() => {
      expect(screen.getByRole('columnheader', { name: /Name/ }).getAttribute('aria-sort')).toBe(
        'descending',
      );
    });
  });

  test('Enter on a row’s menu opens it, and Enter on an item takes the action for the row', async () => {
    const { user, onRowAction } = renderTable();

    await user.tab();
    await user.keyboard('{ArrowRight}{End}');
    expect(active().getAttribute('aria-label')).toBe('Actions for Ada');
    await user.keyboard('{Enter}');
    await screen.findByRole('menu');
    await user.keyboard('{Enter}');

    expect(onRowAction).toHaveBeenCalledExactlyOnceWith('revoke', ROWS[0]);
  });
});
