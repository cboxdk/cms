import { useKitTranslation } from '../i18n/translations';
import { Button } from './Button';

import './pagination.css';

/**
 * The props of Pagination.
 *
 * @experimental
 */
export interface PaginationProps {
  /** What is paged, such as "Pages of grants", from the caller's translations. */
  readonly label: string;
  /** Where the reader is, such as "Rows 21 to 40", from the caller's translations. */
  readonly status?: string | undefined;
  /** Goes to the page before, or undefined on the first page. */
  readonly onPrevious?: (() => void) | undefined;
  /** Goes to the page after, or undefined on the last page. */
  readonly onNext?: (() => void) | undefined;
}

/**
 * The previous and next buttons of a list read in keyset pages, which have no page numbers: a
 * navigation landmark with a button each way, named in the kit's own text, and where the reader is.
 * A button with no page to go to is disabled, so the keyboard skips it.
 *
 * @experimental
 */
export function Pagination({ label, status, onPrevious, onNext }: PaginationProps) {
  const t = useKitTranslation();

  return (
    <nav aria-label={label} className="cms-pagination">
      <Button icon="chevron-left" disabled={onPrevious === undefined} onClick={onPrevious}>
        {t('kit.pagination.previous')}
      </Button>
      {status === undefined ? null : (
        <p className="cms-pagination__status" aria-live="polite">
          {status}
        </p>
      )}
      <Button disabled={onNext === undefined} onClick={onNext}>
        {t('kit.pagination.next')}
      </Button>
    </nav>
  );
}
