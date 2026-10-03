import type { ReactNode } from 'react';

import { Icon, type IconName } from './Icon';
import type { Tone } from './tone';

import './callout.css';

/**
 * The tone of a Callout or a toast: every Tone but neutral.
 *
 * @experimental
 */
export type CalloutTone = Exclude<Tone, 'neutral'>;

/**
 * The props of Callout.
 *
 * @experimental
 */
export interface CalloutProps {
  /** The message, from the caller's translations. */
  readonly children: ReactNode;
  /** info, the default, success, warning or danger. */
  readonly tone?: CalloutTone;
  /** A short heading above the message, from the caller's translations. */
  readonly title?: string | undefined;
  /** What the reader can do next, such as a link or a button, below the message. */
  readonly action?: ReactNode;
}

const ICONS: Readonly<Record<CalloutTone, IconName>> = {
  info: 'info',
  success: 'success',
  warning: 'warning',
  danger: 'error',
};

/**
 * A message about the page's state, such as why a form was refused or that a change is saved. A
 * danger or warning message is an alert, which a screen reader announces as soon as it appears; an
 * info or success message is a status, which it announces when it is idle. The tone is shown by an
 * icon on the tone's soft background as well as by colour, never a coloured edge, and a message that refuses something says what to
 * do next in its action.
 *
 * @experimental
 */
export function Callout({ children, tone = 'info', title, action }: CalloutProps) {
  return (
    <div
      className="cms-callout"
      data-tone={tone}
      role={tone === 'danger' || tone === 'warning' ? 'alert' : 'status'}
    >
      <span className="cms-callout__icon">
        <Icon name={ICONS[tone]} />
      </span>
      <div className="cms-callout__body">
        {title === undefined ? null : <p className="cms-callout__title">{title}</p>}
        <div className="cms-callout__message">{children}</div>
        {action === undefined ? null : <div className="cms-callout__action">{action}</div>}
      </div>
    </div>
  );
}
