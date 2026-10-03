import type { ReactNode } from 'react';
import { Cell, Column, Row, Table, TableBody, TableHeader } from 'react-aria-components/Table';

import { useKitTranslation } from '../i18n/translations';
import { Icon } from './Icon';
import { Menu, type MenuItemSpec } from './Menu';
import { ProgressLabel } from './ProgressLabel';

import './data-table.css';
import './shared.css';

/** The class of a heading or a cell, with the modifier of a column whose values line up at its end. */
function columnClass(part: 'column' | 'cell', align: 'start' | 'end' | undefined): string {
  return align === 'end'
    ? `cms-data-table__${part} cms-data-table__${part}--end`
    : `cms-data-table__${part}`;
}

/**
 * Which way the rows of a DataTable are sorted.
 *
 * @experimental
 */
export type SortDirection = 'ascending' | 'descending';

/**
 * How the rows of a DataTable are sorted.
 *
 * @experimental
 */
export interface DataTableSort {
  /** The id of the column the rows are sorted by. */
  readonly column: string;
  /** ascending or descending. */
  readonly direction: SortDirection;
}

/**
 * A column of a DataTable.
 *
 * @experimental
 */
export interface DataTableColumn<RowData> {
  /** An id unique among the columns, which the sort names. */
  readonly id: string;
  /** The column's heading, from the caller's translations. */
  readonly title: string;
  /** What a row shows in the column. */
  readonly render: (row: RowData) => ReactNode;
  /**
   * Whether the column names the row, as a person's name does; a screen reader reads it with every
   * cell of the row. One column should.
   */
  readonly rowHeader?: boolean | undefined;
  /** Whether the rows can be sorted by the column. */
  readonly sortable?: boolean | undefined;
  /** end for numbers, so their digits line up; start by default. */
  readonly align?: 'start' | 'end' | undefined;
}

/**
 * The props of DataTable.
 *
 * @experimental
 */
export interface DataTableProps<RowData> {
  /** What the table lists, such as "Grants", from the caller's translations. */
  readonly label: string;
  /** The columns, in the order they are shown. */
  readonly columns: readonly DataTableColumn<RowData>[];
  /** The rows of the page shown. */
  readonly rows: readonly RowData[];
  /** A key unique among the rows, such as the row's id. */
  readonly rowKey: (row: RowData) => string;
  /** How the rows are sorted, as the caller sorted them. */
  readonly sort?: DataTableSort | undefined;
  /** Called when the reader asks to sort by a column; the caller sorts the rows, or asks for them. */
  readonly onSortChange?: ((sort: DataTableSort) => void) | undefined;
  /** The actions of a row, which a menu at the end of the row offers. */
  readonly rowActions?: ((row: RowData) => readonly MenuItemSpec[]) | undefined;
  /** Called with the id of the action taken and its row. */
  readonly onRowAction?: ((action: string, row: RowData) => void) | undefined;
  /** The name of a row's menu, such as "Actions for Ada", from the caller's translations. */
  readonly rowActionsLabel?: ((row: RowData) => string) | undefined;
  /** What the table waits for while its rows load, from the caller's translations. */
  readonly loading?: string | undefined;
  /** Why the rows could not be loaded and what to do, such as an ErrorState, in place of the table. */
  readonly error?: ReactNode;
  /** What the table shows when it has no rows, such as an EmptyState with the first action. */
  readonly empty: ReactNode;
  /** The pages of the rows, a Pagination, below the table. */
  readonly pagination?: ReactNode;
}

/**
 * A table of rows, such as the grants of an installation, read a keyset page at a time. It is a
 * grid: Tab moves into it and out of it in one stop, the arrow keys move from cell to cell, Home
 * and End go to the ends of a row, and Ctrl+Home and Ctrl+End to the first and the last cell. On a
 * sortable column's heading, Enter sorts by it and Enter again turns the order around; the sort is
 * told to a screen reader by aria-sort and shown by an arrow. A row's actions are in a menu at its
 * end, which Enter opens. Without rows, it shows the empty state; while they load, what it waits
 * for; when they fail, the error in place of the table, with what to do.
 *
 * @experimental
 */
export function DataTable<RowData>({
  label,
  columns,
  rows,
  rowKey,
  sort,
  onSortChange,
  rowActions,
  onRowAction,
  rowActionsLabel,
  loading,
  error,
  empty,
  pagination,
}: DataTableProps<RowData>) {
  const t = useKitTranslation();

  if (error !== undefined) {
    return <div className="cms-data-table">{error}</div>;
  }

  const actionColumn = rowActions !== undefined;

  return (
    <div className="cms-data-table">
      <div className="cms-data-table__scroller">
        <Table
          aria-label={label}
          aria-busy={loading === undefined ? undefined : true}
          className="cms-data-table__table"
          {...(sort === undefined ? {} : { sortDescriptor: sort })}
          onSortChange={(descriptor) => {
            onSortChange?.({ column: String(descriptor.column), direction: descriptor.direction });
          }}
        >
          <TableHeader className="cms-data-table__header">
            {columns.map((column) => (
              <Column
                key={column.id}
                id={column.id}
                isRowHeader={column.rowHeader === true}
                allowsSorting={column.sortable === true}
                className={columnClass('column', column.align)}
              >
                {({ allowsSorting, sortDirection }) => (
                  <span className="cms-data-table__heading">
                    {column.title}
                    {allowsSorting ? (
                      <span
                        className="cms-data-table__sort"
                        data-active={sortDirection !== undefined}
                      >
                        <Icon
                          name={
                            sortDirection === 'descending' ? 'sort-descending' : 'sort-ascending'
                          }
                          size="sm"
                        />
                      </span>
                    ) : null}
                  </span>
                )}
              </Column>
            ))}
            {actionColumn ? (
              <Column id="cms-actions" className={columnClass('column', 'end')}>
                <span className="cms-visually-hidden">{t('kit.table.actions')}</span>
              </Column>
            ) : null}
          </TableHeader>
          <TableBody
            items={loading === undefined ? rows : []}
            renderEmptyState={() =>
              loading === undefined ? (
                <div className="cms-data-table__state">{empty}</div>
              ) : (
                <div className="cms-data-table__state">
                  <ProgressLabel>{loading}</ProgressLabel>
                </div>
              )
            }
            className="cms-data-table__body"
          >
            {(row) => (
              <Row id={rowKey(row)} className="cms-data-table__row">
                {columns.map((column) => (
                  <Cell key={column.id} className={columnClass('cell', column.align)}>
                    {column.render(row)}
                  </Cell>
                ))}
                {actionColumn ? (
                  <Cell className={columnClass('cell', 'end')}>
                    <Menu
                      trigger={{
                        label: rowActionsLabel?.(row) ?? t('kit.table.actions'),
                        icon: 'more',
                      }}
                      items={rowActions(row)}
                      onAction={(action) => {
                        onRowAction?.(action, row);
                      }}
                    />
                  </Cell>
                ) : null}
              </Row>
            )}
          </TableBody>
        </Table>
      </div>
      {pagination === undefined ? null : (
        <div className="cms-data-table__pagination">{pagination}</div>
      )}
    </div>
  );
}
