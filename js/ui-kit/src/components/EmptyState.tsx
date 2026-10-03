import type { ReactNode } from 'react';

import './empty-state.css';

/**
 * The props of EmptyState.
 *
 * @experimental
 */
export interface EmptyStateProps {
  /** What is empty, such as "No grants yet", from the caller's translations. */
  readonly title: string;
  /** Why it is empty and what fills it, from the caller's translations. */
  readonly description?: string | undefined;
  /** The first thing to do, such as a button that assigns a grant; an empty state is no dead end. */
  readonly action?: ReactNode;
  /** The level of the heading in the page's outline; 2 by default. */
  readonly headingLevel?: 2 | 3 | 4;
}

/**
 * What a list, a table or a page shows when it has nothing yet: what is empty, why, and the first
 * thing to do about it.
 *
 * @experimental
 */
export function EmptyState({ title, description, action, headingLevel = 2 }: EmptyStateProps) {
  const Heading = `h${String(headingLevel)}` as 'h2' | 'h3' | 'h4';

  return (
    <div className="cms-empty-state">
      <Heading className="cms-empty-state__title">{title}</Heading>
      {description === undefined ? null : (
        <p className="cms-empty-state__description">{description}</p>
      )}
      {action === undefined ? null : <div className="cms-empty-state__action">{action}</div>}
    </div>
  );
}
