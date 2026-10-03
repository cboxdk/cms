import { useId } from 'react';
import { Link } from 'react-aria-components/Link';

import './side-nav.css';

/**
 * An entry of a SideNav: a page.
 *
 * @experimental
 */
export interface SideNavItem {
  /** An id unique among its siblings, which the component gives back. */
  readonly id: string;
  /** What the entry opens, from the caller's translations. */
  readonly label: string;
  /** The address of the page, inside the application. */
  readonly href: string;
  /** Whether the entry is the page that is shown; it is marked as the current page. */
  readonly current?: boolean | undefined;
}

/**
 * A group of the entries of a SideNav.
 *
 * @experimental
 */
export interface SideNavGroup {
  /** An id unique among its siblings, which the component gives back. */
  readonly id: string;
  /** The group's heading, from the caller's translations, or undefined for the first group. */
  readonly label?: string | undefined;
  /** The group's entries, in the order they are shown. */
  readonly items: readonly SideNavItem[];
}

/**
 * The props of SideNav.
 *
 * @experimental
 */
export interface SideNavProps {
  /** The navigation's name, such as "Panel", from the caller's translations. */
  readonly label: string;
  /** The groups of entries, in the order they are shown. */
  readonly groups: readonly SideNavGroup[];
}

/**
 * The navigation of the panel: a navigation landmark with the entries in groups, each group a list
 * named by its heading. The entry of the page that is shown is marked with aria-current and by more
 * than colour, a bar at its edge. The entries are links, so Tab moves through them and Enter opens
 * one; with a KitRouterProvider, they open through the application's router.
 *
 * @experimental
 */
export function SideNav({ label, groups }: SideNavProps) {
  const id = useId();

  return (
    <nav className="cms-side-nav" aria-label={label}>
      {groups.map((group) => (
        <div key={group.id} className="cms-side-nav__group">
          {group.label === undefined ? null : (
            <p id={`${id}-${group.id}`} className="cms-side-nav__heading">
              {group.label}
            </p>
          )}
          <ul
            className="cms-side-nav__list"
            aria-labelledby={group.label === undefined ? undefined : `${id}-${group.id}`}
          >
            {group.items.map((item) => (
              <li key={item.id}>
                <Link
                  href={item.href}
                  className="cms-side-nav__link"
                  aria-current={item.current === true ? 'page' : undefined}
                >
                  {item.label}
                </Link>
              </li>
            ))}
          </ul>
        </div>
      ))}
    </nav>
  );
}
