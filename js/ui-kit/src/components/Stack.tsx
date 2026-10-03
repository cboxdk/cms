import type { ReactNode } from 'react';

import './layout.css';

/**
 * A space of the kit between the children of a Stack or an Inline, from none to xl.
 *
 * @experimental
 */
export type Gap = 'none' | 'xs' | 'sm' | 'md' | 'lg' | 'xl';

/**
 * The props of Stack.
 *
 * @experimental
 */
export interface StackProps {
  /** The space between the children: md, the default, is the space between a form's fields. */
  readonly gap?: Gap;
  /** What sits one below the other. */
  readonly children: ReactNode;
}

/**
 * Children one below the other with the kit's spacing between them, so pages need no styles of
 * their own to lay out their parts.
 *
 * @experimental
 */
export function Stack({ gap = 'md', children }: StackProps) {
  return (
    <div className="cms-stack" data-gap={gap}>
      {children}
    </div>
  );
}
