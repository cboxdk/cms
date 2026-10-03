import type { ReactNode } from 'react';

import './shell-header.css';

/**
 * The props of ShellHeader.
 *
 * @experimental
 */
export interface ShellHeaderProps {
  /** The installation's brand, a Brand. */
  readonly brand: ReactNode;
  /** What the header holds after the brand, such as signing out, or undefined. */
  readonly children?: ReactNode;
}

/**
 * The header of the panel's shell: the page's banner landmark, with the installation's brand at
 * the start and the header's actions at the end. It holds no keyboard behaviour of its own; the
 * brand and the actions keep theirs, in reading order.
 *
 * @experimental
 */
export function ShellHeader({ brand, children }: ShellHeaderProps) {
  return (
    <header className="cms-shell-header">
      <div className="cms-shell-header__brand">{brand}</div>
      {children === undefined ? null : <div className="cms-shell-header__actions">{children}</div>}
    </header>
  );
}
