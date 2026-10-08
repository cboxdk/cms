// @vitest-environment jsdom

// A slot contribution to access.grants.sections@1, the sections of the grants page: the fixture
// addon's fixtureaddon.four-eyes-note renders in the page's sections region without props, because
// the grants are the page's own, reaches the panel through the host alone and has no accessibility
// violation.

import type { AccessGrantsSectionsV1 } from '@cboxdk/cms-panel/experimental';
import { expectNoA11yViolations, expectSlotContract } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

import addon from '../../../../workbench/addons/fixtureaddon/resources/panel/src/panel';

const props: AccessGrantsSectionsV1 = {};

test('fixtureaddon.four-eyes-note keeps the slot contract on the grants page', async () => {
  const rendered = await expectSlotContract({
    addon,
    id: 'fixtureaddon.four-eyes-note',
    props,
    region: 'sections',
    host: {
      namespace: 'fixtureaddon',
      texts: {
        'fixtureaddon.four_eyes_note.title': 'Four eyes',
        'fixtureaddon.four_eyes_note.body': 'Nobody grants a role to themselves.',
      },
    },
  });

  expect(rendered.container.textContent).toContain('Nobody grants a role to themselves.');
  await expectNoA11yViolations(rendered.container);
  await rendered.unmount();
});
