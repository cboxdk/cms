// A form check contribution, as cms:make:panel check scaffolds it: the addon acme/cms-notes checks
// the draft of its command notes.create as the viewer edits it. A check is a pure function of the
// document to issues, synchronous and within 16 ms, each issue at the path of its value with a
// code in the addon's namespace. This one only warns, so it needs no mirrored hook; a check that
// blocks with an error must mirror a ValidateHook or AuthorizeHook of the addon on the same
// command. expectFormCheckContract() runs it twice on each document within the host's budget.

import { definePanelAddon, type FormCheck } from '@cboxdk/cms-panel/extend';
import { expectFormCheckContract } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

interface NotesCreateV1 {
  readonly title?: string;
}

/** Warns about a title of more than 80 characters, which lists cut short. */
const longTitle: FormCheck<NotesCreateV1> = (document) =>
  (document.title?.length ?? 0) > 80
    ? [
        {
          path: 'title',
          code: 'notes.long_title',
          severity: 'warning',
          message: 'notes.long_title.message',
          parameters: { length: document.title?.length ?? 0 },
        },
      ]
    : [];

const addon = definePanelAddon({ 'notes.long-title': longTitle });

test('notes.long-title keeps the form check contract and warns at the title', () => {
  const issues = expectFormCheckContract({
    addon,
    id: 'notes.long-title',
    namespace: 'notes',
    severity: 'warning',
    documents: [{ title: 'Field notes' }, { title: 'x'.repeat(81) }, {}],
  });

  expect(issues).toEqual([
    [],
    [
      {
        path: 'title',
        code: 'notes.long_title',
        severity: 'warning',
        message: 'notes.long_title.message',
        parameters: { length: 81 },
      },
    ],
    [],
  ]);
});
