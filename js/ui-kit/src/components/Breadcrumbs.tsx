import { Breadcrumb, Breadcrumbs as AriaBreadcrumbs } from 'react-aria-components/Breadcrumbs';
import { Link } from 'react-aria-components/Link';

import { Icon } from './Icon';

import './breadcrumbs.css';

/**
 * A page of a Breadcrumbs trail.
 *
 * @experimental
 */
export interface BreadcrumbItem {
  /** An id unique among its siblings, which the component gives back. */
  readonly id: string;
  /** The page's name, from the caller's translations or the data. */
  readonly label: string;
  /** Where the page is; the last item is the page that is shown and is not a link. */
  readonly href?: string | undefined;
}

/**
 * The props of Breadcrumbs.
 *
 * @experimental
 */
export interface BreadcrumbsProps {
  /** The trail's name, such as "Breadcrumbs", from the caller's translations. */
  readonly label: string;
  /** The pages from the top down, the page that is shown last. */
  readonly items: readonly BreadcrumbItem[];
}

/**
 * Where a page sits: the pages above it as links, in a navigation landmark of its own, and the page
 * itself last, marked as the current page. The separators are decoration a screen reader skips.
 *
 * @experimental
 */
export function Breadcrumbs({ label, items }: BreadcrumbsProps) {
  return (
    <nav aria-label={label} className="cms-breadcrumbs">
      <AriaBreadcrumbs items={items} className="cms-breadcrumbs__list">
        {(item) => (
          <Breadcrumb id={item.id} className="cms-breadcrumbs__item">
            {({ isCurrent }) => (
              <>
                <Link
                  {...(isCurrent || item.href === undefined ? {} : { href: item.href })}
                  className="cms-breadcrumbs__link"
                >
                  {item.label}
                </Link>
                {isCurrent ? null : (
                  <span className="cms-breadcrumbs__separator">
                    <Icon name="chevron-right" size="sm" />
                  </span>
                )}
              </>
            )}
          </Breadcrumb>
        )}
      </AriaBreadcrumbs>
    </nav>
  );
}
