---
title: "command.form.steps@1"
weight: 51
description: "The steps of the generic command form: numbered steps before the submit or after the receipt of one command, which can cancel but never skip the core's confirmation."
---

# command.form.steps@1

<!-- extension-point: Cbox\Cms\Panel\CommandForm\Domain\Dto\CommandFormStepsV1 -->
<!-- extension-point: packages/panel/resources/schemas/points/command.form.steps.v1.json -->

The steps of the [command form](../command-form.md#steps-and-the-cores-confirmation), run before the submit or after the receipt of one command.

| | |
|---|---|
| Kind | [flow step](../kinds/flow-step.md) |
| Page | `command.form` |
| Props | none: `CommandFormStepsV1` has no members; a step gets `StepProps` with the draft |
| TypeScript | `FlowStep<D, Path, I>` of `@cboxdk/cms-panel/extend` |
| Stability | experimental, since panel API 1.0; list it in `acceptsExperimental` |
| The core's own | none |
| Fixture addon | on `entry.create@1`: `fixtureaddon.slug-review`, which patches `fields.ext.fixtureaddon.fixture_slug`; on `grant.assign@1`: `fixtureaddon.four-eyes`, which patches nothing |

A contribution is a `FlowStep` that names its command, its position (`BeforeSubmit` or `AfterReceipt`), the paths it may patch and its timeout of 1 to 30 seconds. The steps before the submit run in render order once the checks let the run go; the first cancel stops the flow and names its addon, and after the last step the core's own confirmation runs, which alone sends the draft. A dry run runs no step.

## Example

The fixture addon's four-eyes step on the form of `grant.assign`:

<!-- example: examples/Vitest/Panel/Points/command-form-steps.test.tsx -->
```tsx
// @vitest-environment jsdom

// A flow step contribution to command.form.steps@1, a numbered step the viewer goes through before
// the submit of the generic command form: the fixture addon's fixtureaddon.four-eyes renders with
// the draft of grant.assign, patches nothing, because its manifest declares no path, waits for the
// viewer before it ends the flow, and goes on to the core's confirmation when the viewer confirms
// that a second person reviewed the grant. The draft is of the command's generated type, as
// cms:panel:types writes it.

import { expectFlowStepContract, expectNoA11yViolations } from '@cboxdk/cms-panel/testing';
import { act } from 'react';
import { expect, test } from 'vitest';

import type { GrantAssignV1 } from '../../../../workbench/addons/fixtureaddon/resources/panel/generated/contributions';
import addon from '../../../../workbench/addons/fixtureaddon/resources/panel/src/panel';

const draft: GrantAssignV1 = {
  grant: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a21',
  actor: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a22',
  role: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a23',
  node: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a24',
  effect: 'allow',
};

test('fixtureaddon.four-eyes keeps the flow step contract and ends only when the viewer acts', async () => {
  const rendered = await expectFlowStepContract({
    addon,
    id: 'fixtureaddon.four-eyes',
    draft,
    patches: [],
    host: {
      namespace: 'fixtureaddon',
      texts: {
        'fixtureaddon.four_eyes.title': 'Four eyes',
        'fixtureaddon.four_eyes.grantee': 'The grant goes to {actor}.',
        'fixtureaddon.four_eyes.body': 'Has a second person reviewed it?',
        'fixtureaddon.four_eyes.reviewed': 'Yes, reviewed',
        'fixtureaddon.four_eyes.stop': 'Stop',
      },
    },
  });

  expect(rendered.container.textContent).toContain(
    'The grant goes to 0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a22.',
  );
  await expectNoA11yViolations(rendered.container);

  const reviewed = [...rendered.container.querySelectorAll('button')].find(
    (button) => button.textContent === 'Yes, reviewed',
  );

  await act(async () => {
    reviewed?.click();
    await Promise.resolve();
  });

  expect(rendered.step.next).toBe(1);
  expect(rendered.step.cancelled).toBeNull();
  expect(rendered.step.patches).toEqual([]);
  await rendered.unmount();
});
```
