// @cboxdk/cms-panel/testing: what an addon's tests render its contributions with. A contribution
// reaches the panel only through usePanelHost(), so a test renders it inside PanelHostProvider with
// a host it builds, and asserts what the contribution asked the host for.

import type { ReactNode } from 'react';

import type { IssuedCommands } from './contributions';
import { PanelHostContext, type AnyCommands, type PanelHost } from './host';

/**
 * The props of PanelHostProvider.
 *
 * @stable
 */
export interface PanelHostProviderProps<I extends IssuedCommands<I>> {
  readonly host: PanelHost<I>;
  readonly children: ReactNode;
}

/**
 * Provides a host to the contributions it renders, as the panel provides its own.
 *
 * @stable
 */
export function PanelHostProvider<I extends IssuedCommands<I>>(
  input: PanelHostProviderProps<I>,
): ReactNode {
  return (
    <PanelHostContext value={input.host as unknown as PanelHost<AnyCommands>}>
      {input.children}
    </PanelHostContext>
  );
}
