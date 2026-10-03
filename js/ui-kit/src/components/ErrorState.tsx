import type { ReactNode } from 'react';

import { useKitTranslation } from '../i18n/translations';
import { Icon } from './Icon';

import './error-state.css';

/**
 * The props of ErrorState.
 *
 * @experimental
 */
export interface ErrorStateProps {
  /** What failed, such as "The grants could not be loaded", from the caller's translations. */
  readonly title: string;
  /**
   * Why and what to do, from the caller's translations: for a catalog code, the catalog's
   * explanation of it.
   */
  readonly description: string;
  /** The catalog code, such as query_over_budget, shown so the reader can look it up. */
  readonly code?: string | undefined;
  /** What the reader can do, such as a button that tries again; an error is no dead end. */
  readonly action?: ReactNode;
  /** The level of the heading in the page's outline; 2 by default. */
  readonly headingLevel?: 2 | 3 | 4;
}

/**
 * What a list, a table or a page shows when its content could not be loaded: what failed, why and
 * what to do, with the catalog code when there is one. It is an alert, so a screen reader announces
 * it when it replaces content that was loading.
 *
 * @experimental
 */
export function ErrorState({
  title,
  description,
  code,
  action,
  headingLevel = 2,
}: ErrorStateProps) {
  const t = useKitTranslation();
  const Heading = `h${String(headingLevel)}` as 'h2' | 'h3' | 'h4';

  return (
    <div className="cms-error-state" role="alert">
      <span className="cms-error-state__icon">
        <Icon name="error" />
      </span>
      <Heading className="cms-error-state__title">{title}</Heading>
      <p className="cms-error-state__description">{description}</p>
      {code === undefined ? null : (
        <p className="cms-error-state__code">
          {t('kit.error.code')} <code>{code}</code>
        </p>
      )}
      {action === undefined ? null : <div className="cms-error-state__action">{action}</div>}
    </div>
  );
}
