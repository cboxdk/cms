import type { ReactNode } from 'react';

import './page.css';

/**
 * The props of PageHeader.
 *
 * @experimental
 */
export interface PageHeaderProps {
  /** The page's heading, its h1, from the caller's translations. */
  readonly title: string;
  /** What the page is for, below the heading, from the caller's translations. */
  readonly description?: string | undefined;
  /** Where the page sits, a Breadcrumbs, above the heading. */
  readonly breadcrumbs?: ReactNode;
  /** The page's actions, such as an ActionBar or a primary Button, at the end of the heading. */
  readonly actions?: ReactNode;
  /** Short facts next to the heading, such as a Badge with a state. */
  readonly meta?: ReactNode;
}

/**
 * The top of a page: where it sits, its one h1, what it is for and its actions. On a narrow screen
 * the actions go below the heading.
 *
 * @experimental
 */
export function PageHeader({ title, description, breadcrumbs, actions, meta }: PageHeaderProps) {
  return (
    <div className="cms-page-header">
      {breadcrumbs === undefined ? null : (
        <div className="cms-page-header__breadcrumbs">{breadcrumbs}</div>
      )}
      <div className="cms-page-header__row">
        <div className="cms-page-header__heading">
          <div className="cms-page-header__title-row">
            <h1 className="cms-page-header__title">{title}</h1>
            {meta === undefined ? null : <div className="cms-page-header__meta">{meta}</div>}
          </div>
          {description === undefined ? null : (
            <p className="cms-page-header__description">{description}</p>
          )}
        </div>
        {actions === undefined ? null : <div className="cms-page-header__actions">{actions}</div>}
      </div>
    </div>
  );
}
