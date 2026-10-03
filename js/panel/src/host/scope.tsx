// The scope wrapper of a contribution (section 3.2 of the panel extension architecture): the
// element every contribution renders inside, which names its addon, point and contribution, so an
// addon's styles stay in its own subtree, and which provides the contribution's own host, so
// usePanelHost() inside it reaches the panel as that addon and no other.

import { PanelHostProvider } from '@cboxdk/cms-panel/testing';
import type { ReactNode } from 'react';

import { useHostRuntime } from './runtime';

/** The props of ContributionScope. */
export interface ContributionScopeProps {
  readonly addon: string;
  readonly point: string;
  readonly contribution: string;
  readonly children: ReactNode;
}

/** Renders a contribution inside its addon's scope, with its host. */
export function ContributionScope({
  addon,
  point,
  contribution,
  children,
}: ContributionScopeProps) {
  const runtime = useHostRuntime();

  return (
    <div
      className="cms-contribution"
      data-cms-addon={addon}
      data-cms-point={point}
      data-cms-contribution={contribution}
    >
      <PanelHostProvider host={runtime.hostFor(addon, point, contribution)}>
        {children}
      </PanelHostProvider>
    </div>
  );
}
