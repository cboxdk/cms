// The fixture addon's decorators of entry.create's form (section 3.5 of the panel extension
// architecture), each a pure function of the target's props to what it adds: submitNote tightens
// the description of the form's actions, the one prop its manifest declares, so the viewer reads
// that the addon derives the slug on the run; receiptNote puts a badge on the receipt, in the
// addon's name, and tightens nothing.

import type { Decorator } from '@cboxdk/cms-panel/extend';
import type { CommandFormReceiptV1, CommandFormSubmitV1 } from '@cboxdk/cms-panel/experimental';

export const submitNote: Decorator<CommandFormSubmitV1, 'description'> = () => ({
  tighten: { description: 'fixtureaddon.submit_note.description' },
});

export const receiptNote: Decorator<CommandFormReceiptV1> = (props) => ({
  badge: {
    tone: props.problem === null ? 'info' : 'warning',
    label: props.problem === null ? 'fixtureaddon.receipt_note.saved' : 'fixtureaddon.receipt_note.refused',
  },
});
