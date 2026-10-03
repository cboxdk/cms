import type { ReactNode } from 'react';

import './status-screen.css';

/**
 * The props of StatusScreen.
 *
 * @experimental
 */
export interface StatusScreenProps {
  /** A short code that names the status, such as an HTTP status; shown above the title. */
  readonly code?: string;
  /** The page's heading, from the caller's translations. */
  readonly title: string;
  /** What happened and what the reader can do, from the caller's translations. */
  readonly description: string;
  /** The actions the reader can take next, such as a link back. */
  readonly children?: ReactNode;
}

/**
 * A page that only says something about the panel's state, such as an address it does not have:
 * the page's main landmark with one centred panel holding the heading, the explanation and the
 * actions. It fills the viewport on a phone and stays at a readable width on a desktop.
 *
 * @experimental
 */
export function StatusScreen({ code, title, description, children }: StatusScreenProps) {
  return (
    <main className="cms-status-screen">
      <div className="cms-status-screen__panel" data-cms-part="status-screen">
        {code === undefined ? null : <p className="cms-status-screen__code">{code}</p>}
        <h1 className="cms-status-screen__title">{title}</h1>
        <p className="cms-status-screen__description">{description}</p>
        {children === undefined ? null : (
          <div className="cms-status-screen__actions">{children}</div>
        )}
      </div>
    </main>
  );
}
