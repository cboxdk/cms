// @vitest-environment jsdom

// A slot contribution to command.form.aside@1, the aside of the generic command form: the fixture
// addon's fixtureaddon.slug-help renders with the point's props, the command the form runs, reaches
// the panel through the host alone and has no accessibility violation. The props are the sample
// props of the point's JSON Schema, as cms:make:panel writes them into a scaffolded test.

import type { CommandFormContextV1 } from '@cboxdk/cms-panel/experimental';
import { expectNoA11yViolations, expectSlotContract } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

import addon from '../../../../workbench/addons/fixtureaddon/resources/panel/src/panel';

const props: CommandFormContextV1 = { command: 'entry.create', title: 'sample', version: 1 };

test('fixtureaddon.slug-help keeps the slot contract in the aside region', async () => {
  const rendered = await expectSlotContract({
    addon,
    id: 'fixtureaddon.slug-help',
    props,
    region: 'aside',
    host: {
      namespace: 'fixtureaddon',
      texts: { 'fixtureaddon.slug_help.body': 'The slug of {command} comes from its title.' },
    },
  });

  expect(rendered.container.textContent).toContain(
    'The slug of entry.create comes from its title.',
  );
  await expectNoA11yViolations(rendered.container);
  await rendered.unmount();
});
