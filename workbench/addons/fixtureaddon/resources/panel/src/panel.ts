// The panel entry of the workbench's fixture addon (PRD 13.4): the module the panel imports as
// cms-addons/fixtureaddon through its import map, which registers the addon's contributions that
// run code by their ids, as the manifest declares them (FixtureAddonServiceProvider): the checks
// and decorators as functions, the observer, and each component imported when a page first shows
// it, the input of the addon's own value class among them. The bundle in dist/panel is built from this file by `npm run build:fixture-addon` and
// signed with the test key panel-signing-test-key.pem, whose public key the workbench trusts in
// cbox-cms.addons.publishers.

import { definePanelAddon } from '@cboxdk/cms-panel/extend';

import type { Contributions } from '../generated/contributions';
import { activity } from './activity';
import { selfGrant, slugHint, slugOverride, slugShape } from './checks';
import { receiptNote, submitNote } from './decorators';

export default definePanelAddon<Contributions>({
  'fixtureaddon.activity': activity,
  'fixtureaddon.articles': () => import('./Articles'),
  'fixtureaddon.articles-permission': () =>
    import('./notes').then((module) => ({ default: module.ArticlesPermissionNote })),
  'fixtureaddon.dry-run-note': () => import('./DryRunNote'),
  'fixtureaddon.faulty': () => import('./Faulty'),
  'fixtureaddon.four-eyes': () => import('./FourEyes'),
  'fixtureaddon.four-eyes-note': () =>
    import('./notes').then((module) => ({ default: module.FourEyesNote })),
  'fixtureaddon.my-articles': () => import('./MyArticles'),
  'fixtureaddon.receipt-note': receiptNote,
  'fixtureaddon.recent-activity': () => import('./RecentActivity'),
  'fixtureaddon.self-grant': selfGrant,
  'fixtureaddon.slug-help': () => import('./SlugHelp'),
  'fixtureaddon.slug-hint': slugHint,
  'fixtureaddon.slug-input': () => import('./SlugInput'),
  'fixtureaddon.slug-override': slugOverride,
  'fixtureaddon.slug-review': () => import('./SlugReview'),
  'fixtureaddon.slug-shape': slugShape,
  'fixtureaddon.submit-note': submitNote,
});
