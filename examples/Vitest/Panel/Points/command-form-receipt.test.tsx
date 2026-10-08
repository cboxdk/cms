// @vitest-environment jsdom

// A decorator contribution to command.form.receipt@1, the receipt of the generic command form: the
// fixture addon's fixtureaddon.receipt-note keeps the default, which renders once, tightens nothing,
// because a receipt is never hidden or disabled, and puts a badge on it in the addon's name: info
// for a committed run, warning for a rejection with its problem details. The props exist only in
// the browser, once the command answered, so the page builds them; here they are the sample props
// of the point's JSON Schema with and without a problem.

import type { CommandFormReceiptV1 } from '@cboxdk/cms-panel/experimental';
import { expectDecoratorKeepsDefault } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

import addon from '../../../../workbench/addons/fixtureaddon/resources/panel/src/panel';

const committed: CommandFormReceiptV1 = {
  command: 'entry.create',
  version: 1,
  receipt: { outcome: 'committed' },
  problem: null,
};

const rejected: CommandFormReceiptV1 = {
  ...committed,
  receipt: { outcome: 'rejected' },
  problem: { code: 'validation_failed' },
};

test('fixtureaddon.receipt-note keeps the default and badges the receipt by its outcome', async () => {
  const saved = await expectDecoratorKeepsDefault({
    addon,
    id: 'fixtureaddon.receipt-note',
    props: committed,
    tightens: [],
    host: { namespace: 'fixtureaddon' },
  });

  expect(saved.badges).toEqual([{ tone: 'info', label: 'fixtureaddon.receipt_note.saved' }]);
  expect(saved.tightened.disabled).toBe(false);
  await saved.unmount();

  const refused = await expectDecoratorKeepsDefault({
    addon,
    id: 'fixtureaddon.receipt-note',
    props: rejected,
    tightens: [],
    host: { namespace: 'fixtureaddon' },
  });

  expect(refused.badges).toEqual([{ tone: 'warning', label: 'fixtureaddon.receipt_note.refused' }]);
  await refused.unmount();
});
