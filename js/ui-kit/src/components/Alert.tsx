import type { ReactNode } from 'react';

import './alert.css';

export type AlertTone = 'info' | 'danger';

export interface AlertProps {
  /** The message, from the caller's translations. */
  readonly children: ReactNode;
  readonly tone?: AlertTone;
}

/**
 * A message about the page's state, such as why a form was refused. A danger message is an alert,
 * which a screen reader announces as soon as it appears; an info message is a status, which it
 * announces when it is idle. The tone is also shown by a coloured edge, never by colour alone.
 */
export function Alert({ children, tone = 'info' }: AlertProps) {
  return (
    <div className="cms-alert" data-tone={tone} role={tone === 'danger' ? 'alert' : 'status'}>
      {children}
    </div>
  );
}
