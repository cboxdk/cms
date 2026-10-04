// The panel entry of the workbench's fixture addon (PRD 13.4): the module the panel imports as
// cms-addons/fixtureaddon through its import map. The addon contributes no code to the panel, so
// it registers nothing; what it shows is that an addon's prebuilt bundle, signed by its publisher,
// is compiled by cms:build, served by hash and loaded on every page behind the login. The bundle in
// dist/panel is built from this file by `npm run build:fixture-addon` and signed with the test key
// panel-signing-test-key.pem, whose public key the workbench trusts in cbox-cms.addons.publishers.

import { definePanelAddon } from '@cboxdk/cms-panel/extend';

import type { Contributions } from '../generated/contributions';

export default definePanelAddon<Contributions>({});
