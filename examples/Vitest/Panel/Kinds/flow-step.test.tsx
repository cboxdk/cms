// @vitest-environment jsdom

// A flow step contribution, as cms:make:panel step scaffolds it: the addon acme/cms-notes asks the
// viewer, before the submit of notes.create, whether the note is ready for review, and patches the
// answer into the one path its manifest declares, ext.notes.ready, its own part of the document.
// The step ends only when the viewer acts, with next() or cancel(), and the core's confirmation
// still runs after it. expectFlowStepContract() holds the step to its declared paths.

import { definePanelAddon, usePanelHost, type StepProps } from '@cboxdk/cms-panel/extend';
import { expectFlowStepContract, expectNoA11yViolations } from '@cboxdk/cms-panel/testing';
import { act } from 'react';
import { expect, test } from 'vitest';

interface NotesCreateV1 {
  readonly title: string;
}

/** Asks whether the note is ready for review. */
function ReadyForReview(step: StepProps<NotesCreateV1, 'ext.notes.ready'>) {
  const panel = usePanelHost();

  return (
    <section aria-label={panel.t('notes.ready.title')}>
      <p>{panel.t('notes.ready.body', { title: step.draft.title })}</p>
      <button
        type="button"
        onClick={() => {
          step.patch('ext.notes.ready', true);
          step.next();
        }}
      >
        {panel.t('notes.ready.yes')}
      </button>
      <button
        type="button"
        onClick={() => {
          step.cancel('notes.ready.cancelled');
        }}
      >
        {panel.t('notes.ready.stop')}
      </button>
    </section>
  );
}

const addon = definePanelAddon({
  'notes.ready': () => Promise.resolve({ default: ReadyForReview }),
});

test('notes.ready patches only its declared path and goes on when the viewer says yes', async () => {
  const rendered = await expectFlowStepContract({
    addon,
    id: 'notes.ready',
    draft: { title: 'Field notes' },
    patches: ['ext.notes.ready'],
    host: {
      namespace: 'notes',
      texts: {
        'notes.ready.title': 'Review',
        'notes.ready.body': 'Is {title} ready for review?',
        'notes.ready.yes': 'Yes',
        'notes.ready.stop': 'Stop',
      },
    },
  });

  expect(rendered.container.textContent).toContain('Is Field notes ready for review?');
  await expectNoA11yViolations(rendered.container);

  await act(async () => {
    rendered.container.querySelector('button')?.click();
    await Promise.resolve();
  });

  expect(rendered.step.patches).toEqual([{ path: 'ext.notes.ready', value: true }]);
  expect(rendered.step.next).toBe(1);
  await rendered.unmount();
});
