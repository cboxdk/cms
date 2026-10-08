---
title: Flow step
weight: 28
description: "A flow step: a numbered step of a command form before the submit or after the receipt, which patches only its declared paths and can cancel but never skip the core's confirmation."
---

# Flow step

A flow step is a step the viewer goes through when a command form runs: before the submit, such as a review, or after the receipt, such as a follow-up. Steps are not enforcement; the rule is a hook on the server.

| | |
|---|---|
| Manifest | `new FlowStep($id, 'command.form.steps@1', '<command>@<version>', StepPosition::BeforeSubmit, patches: ['<path>'], timeoutSeconds: 30)` |
| Bundle | a `FlowStep<D, Path, I>` of `@cboxdk/cms-panel/extend`, registered as `() => import('./Module')` |
| Receives | `StepProps`: the `draft`, `dryRun()`, the `receipt` after the submit, `patch()` for the declared paths, `issue()` for the addon's commands, `next()` and `cancel()` |
| Scaffold | `cms:make:panel step <namespace> <id> --command=<name>@<version> --position=<position> --patch=<path>` |
| Test | `expectFlowStepContract()` and `expectNoA11yViolations()` |
| Points | [`command.form.steps@1`](../points/command-form-steps.md) |

## Rules

- A step patches only the paths its manifest declares, each below `ext.<namespace>` of its addon or in a command the addon declares; `cms:build` refuses another path with `registry_panel_flow_path_unknown`, and the host refuses and reports a patch outside them.
- The first cancel stops the flow with a notice that names the addon; a throw or a timeout counts as a cancel.
- After the last step the core's own confirmation runs, which alone sends the draft, and no step can skip it.
- A step after the receipt may run the addon's own commands through `issue()`, as the viewer, with the step's provenance.

## Example

The addon `acme/cms-notes` asks whether a note is ready for review and patches the answer into its own field:

<!-- example: examples/Vitest/Panel/Kinds/flow-step.test.tsx -->
```tsx
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
```
