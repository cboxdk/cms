import type { Tone } from './tone';

import './badge.css';

/**
 * The props of Badge.
 *
 * @experimental
 */
export interface BadgeProps {
  /** The badge's text, such as a state, from the caller's translations. */
  readonly children: string;
  /** The tone of the state; neutral by default. */
  readonly tone?: Tone;
}

/**
 * A short label of a state, such as "Active" or "Pending", next to what it describes. Its tone
 * colours its border and a dot before the text, and the text itself says the state, so the colour
 * never carries the meaning alone.
 *
 * @experimental
 */
export function Badge({ children, tone = 'neutral' }: BadgeProps) {
  return (
    <span className="cms-badge" data-tone={tone}>
      <span className="cms-badge__dot" aria-hidden="true" />
      {children}
    </span>
  );
}
