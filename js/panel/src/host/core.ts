// The core's own contributions to the panel's points (PRD 13.4), in the namespace cms: what the
// panel's pages give their own points, registered here as an addon registers its code, so the host
// renders them through the same order, boundaries and overrides as an addon's, and holds this
// registration to cms:build's list of the core's contributions
// (Cbox\Cms\Panel\Contributions\Domain\CoreContributions) as it holds an addon's bundle to its
// manifest. A page that renders a point adds the core's contribution to it here and there; until a
// page renders a point, the core contributes nothing.

import { definePanelAddon, type NoContributions } from '@cboxdk/cms-panel/extend';

/** The registration of the core's own contributions, part of the panel's build. */
export const CORE_CONTRIBUTIONS = definePanelAddon<NoContributions>({});
