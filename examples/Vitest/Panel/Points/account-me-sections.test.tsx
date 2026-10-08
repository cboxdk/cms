// @vitest-environment jsdom

// A slot contribution to account.me.sections@1, the sections of the who-am-I page: the fixture
// addon's fixtureaddon.recent-activity renders with the point's props, the viewer's actor id, in the
// page's sections region, and has no accessibility violation. The section reads what the addon's
// observer recorded in the browser's session storage, so with nothing recorded it shows its empty
// state. The props are the sample props of the point's JSON Schema, as cms:make:panel writes them
// into a scaffolded test.

import type { AccountMeSectionsV1 } from '@cboxdk/cms-panel/experimental';
import { expectNoA11yViolations, expectSlotContract } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

import addon from '../../../../workbench/addons/fixtureaddon/resources/panel/src/panel';

const props: AccountMeSectionsV1 = { actor: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01' };

test('fixtureaddon.recent-activity keeps the slot contract and shows its empty state', async () => {
  window.sessionStorage.clear();

  const rendered = await expectSlotContract({
    addon,
    id: 'fixtureaddon.recent-activity',
    props,
    region: 'sections',
    host: {
      namespace: 'fixtureaddon',
      texts: {
        'fixtureaddon.recent_activity.title': 'Recent activity',
        'fixtureaddon.recent_activity.none_title': 'No command yet',
        'fixtureaddon.recent_activity.none_body': 'Run a command and come back.',
      },
    },
  });

  expect(rendered.container.textContent).toContain('Recent activity');
  expect(rendered.container.textContent).toContain('No command yet');
  await expectNoA11yViolations(rendered.container);
  await rendered.unmount();
});
