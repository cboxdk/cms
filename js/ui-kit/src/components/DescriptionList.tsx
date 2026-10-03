import type { ReactNode } from 'react';

import { ProgressLabel } from './ProgressLabel';

import './description-list.css';

/**
 * A term and its value in a DescriptionList.
 *
 * @experimental
 */
export interface DescriptionListItem {
  /** An id unique among its siblings, which the component gives back. */
  readonly id: string;
  /** What the value is, such as "Email", from the caller's translations. */
  readonly term: string;
  /** The value, such as the address. */
  readonly description: ReactNode;
}

/**
 * The props of DescriptionList.
 *
 * @experimental
 */
export interface DescriptionListProps {
  /** The terms and their values, in the order they are shown. */
  readonly items: readonly DescriptionListItem[];
  /** columns, the default, puts each term beside its value on a wide screen; stacked never does. */
  readonly layout?: 'columns' | 'stacked';
  /** What the list waits for while its values load, from the caller's translations. */
  readonly loading?: string | undefined;
  /** Why the values could not be loaded and what to do, such as an ErrorState, in place of them. */
  readonly error?: ReactNode;
  /** What the list shows when it has no items, such as an EmptyState. */
  readonly empty?: ReactNode;
}

/**
 * Facts about one thing as terms and their values, such as an actor's id, class and email: a
 * native description list, which a screen reader reads as pairs.
 *
 * @experimental
 */
export function DescriptionList({
  items,
  layout = 'columns',
  loading,
  error,
  empty,
}: DescriptionListProps) {
  if (loading !== undefined) {
    return <ProgressLabel>{loading}</ProgressLabel>;
  }

  if (error !== undefined) {
    return <>{error}</>;
  }

  if (items.length === 0 && empty !== undefined) {
    return <>{empty}</>;
  }

  return (
    <dl className="cms-description-list" data-layout={layout}>
      {items.map((item) => (
        <div key={item.id} className="cms-description-list__item">
          <dt className="cms-description-list__term">{item.term}</dt>
          <dd className="cms-description-list__description">{item.description}</dd>
        </div>
      ))}
    </dl>
  );
}
