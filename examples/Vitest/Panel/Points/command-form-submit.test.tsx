// @vitest-environment jsdom

// A decorator contribution to command.form.submit@1, the actions of the generic command form: the
// fixture addon's fixtureaddon.submit-note keeps the default, which renders once with what the
// decorator adds, and tightens only the description, the one prop its manifest declares, so the
// viewer reads that the addon derives the slug on the run. The props are the sample props of the
// point's JSON Schema.

import type { CommandFormSubmitV1 } from '@cboxdk/cms-panel/experimental';
import { expectDecoratorKeepsDefault } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

import addon from '../../../../workbench/addons/fixtureaddon/resources/panel/src/panel';

const props: CommandFormSubmitV1 = { command: 'entry.create', title: 'sample', version: 1 };

test('fixtureaddon.submit-note keeps the default and tightens only the description', async () => {
  const rendered = await expectDecoratorKeepsDefault({
    addon,
    id: 'fixtureaddon.submit-note',
    props,
    tightens: ['description'],
    host: {
      namespace: 'fixtureaddon',
      texts: {
        'fixtureaddon.submit_note.description': 'The slug is derived when the run commits.',
      },
    },
  });

  expect(rendered.tightened.descriptions).toEqual(['The slug is derived when the run commits.']);
  expect(rendered.tightened.disabled).toBe(false);
  expect(rendered.tightened.tone).toBe('neutral');
  expect(rendered.badges).toEqual([]);
  await rendered.unmount();
});
