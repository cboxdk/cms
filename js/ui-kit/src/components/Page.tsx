import type { ReactNode } from 'react';

import './page.css';

/**
 * The props of Page.
 *
 * @experimental
 */
export interface PageProps {
  /** The page's header, a PageHeader. */
  readonly header: ReactNode;
  /** The page's content, its sections and cards, one below the other. */
  readonly children: ReactNode;
  /** wide, the default, uses the width of the screen; narrow keeps a form at a readable width. */
  readonly width?: 'wide' | 'narrow';
}

/**
 * The content of one page of the panel, inside AppShell's main landmark: its header and its parts,
 * with the kit's padding, which grows with the screen.
 *
 * @experimental
 */
export function Page({ header, children, width = 'wide' }: PageProps) {
  return (
    <div className="cms-page" data-width={width}>
      {header}
      <div className="cms-page__content">{children}</div>
    </div>
  );
}
