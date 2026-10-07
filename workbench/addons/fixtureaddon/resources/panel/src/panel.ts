// The panel entry of the workbench's fixture addon (PRD 13.4): the module the panel imports as
// cms-addons/fixtureaddon through its import map, which registers the addon's contributions by
// their ids, as the manifest declares them (FixtureAddonServiceProvider): the three checks of
// entry.create's form and, imported when the form runs it, the step that reviews the slug. The
// bundle in dist/panel is built from this file by `npm run build:fixture-addon` and signed with
// the test key panel-signing-test-key.pem, whose public key the workbench trusts in
// cbox-cms.addons.publishers.

import { definePanelAddon } from '@cboxdk/cms-panel/extend';

import type { Contributions } from '../generated/contributions';
import { slugHint, slugOverride, slugShape } from './checks';

export default definePanelAddon<Contributions>({
  'fixtureaddon.slug-hint': slugHint,
  'fixtureaddon.slug-override': slugOverride,
  'fixtureaddon.slug-shape': slugShape,
  'fixtureaddon.slug-review': () => import('./SlugReview'),
});
