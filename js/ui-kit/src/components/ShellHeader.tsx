import type { ReactNode } from 'react';

import './shell-header.css';

export interface ShellHeaderProps {
  /** The installation's brand, a Brand. */
  readonly brand: ReactNode;
  /** What the header holds after the brand, such as signing out, or undefined. */
  readonly children?: ReactNode;
}

/**
 * The header of the panel's shell: the page's banner landmark, with the installation's brand at
 * the start and the header's actions at the end.
 */
export function ShellHeader({ brand, children }: ShellHeaderProps) {
  return (
    <header className="cms-shell-header">
      <div className="cms-shell-header__brand">{brand}</div>
      {children === undefined ? null : <div className="cms-shell-header__actions">{children}</div>}
    </header>
  );
}
