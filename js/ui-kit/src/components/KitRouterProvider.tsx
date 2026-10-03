import type { ReactNode } from 'react';
import { RouterProvider } from 'react-aria';

/**
 * The props of KitRouterProvider.
 *
 * @experimental
 */
export interface KitRouterProviderProps {
  /**
   * Goes to an address inside the application without loading the page anew, such as with
   * Inertia's router.visit.
   */
  readonly navigate: (href: string) => void;
  /** The page, whose kit links go through navigate. */
  readonly children: ReactNode;
}

/**
 * Lets the kit's links go to addresses inside the application through the application's own
 * router: a press on a link of SideNav, Breadcrumbs or a menu calls navigate instead of loading the
 * page, while a press with a modifier, such as Ctrl or the middle button, still opens a new tab as
 * the platform does. A page renders it once, around the kit.
 *
 * @experimental
 */
export function KitRouterProvider({ navigate, children }: KitRouterProviderProps) {
  return <RouterProvider navigate={navigate}>{children}</RouterProvider>;
}
