import type { ReactNode } from 'react';

import type { Gap } from './Stack';

import './layout.css';

/**
 * The props of Inline.
 *
 * @experimental
 */
export interface InlineProps {
  /** The space between the children: sm by default. */
  readonly gap?: Gap;
  /** How the children line up across the row: center by default. */
  readonly align?: 'start' | 'center' | 'end' | 'baseline';
  /** Where the children sit along the row: start by default; between spreads them out. */
  readonly justify?: 'start' | 'end' | 'between';
  /** Whether the children wrap onto a new row when there is no room; true by default. */
  readonly wrap?: boolean;
  /** What sits side by side. */
  readonly children: ReactNode;
}

/**
 * Children side by side in a row with the kit's spacing between them, wrapping onto a new row on a
 * narrow screen, such as a badge next to a name.
 *
 * @experimental
 */
export function Inline({
  gap = 'sm',
  align = 'center',
  justify = 'start',
  wrap = true,
  children,
}: InlineProps) {
  return (
    <div
      className="cms-inline"
      data-gap={gap}
      data-align={align}
      data-justify={justify}
      data-wrap={wrap}
    >
      {children}
    </div>
  );
}
