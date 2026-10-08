---
title: "command.form.aside@1"
weight: 49
description: "The aside of the generic command form: help and context beside the form of the command being run."
---

# command.form.aside@1

<!-- extension-point: Cbox\Cms\Panel\CommandForm\Domain\Dto\CommandFormContextV1 -->
<!-- extension-point: packages/panel/resources/schemas/points/command.form.aside.v1.json -->

The aside region of the [command form](../command-form.md), beside the fields.

| | |
|---|---|
| Kind | [slot](../kinds/slot.md), region `Aside`, any number |
| Page | `command.form`, at `<prefix>/commands/<name>/v<version>` |
| Props | `CommandFormContextV1`: the `command`, its contract `version` and the `title` of its JSON Schema |
| TypeScript | `CommandFormContextV1` of `@cboxdk/cms-panel/experimental` |
| Stability | experimental, since panel API 1.0; list it in `acceptsExperimental` |
| The core's own | none |
| Fixture addon | `fixtureaddon.slug-help`, scoped to `entry.create@1` |

A contribution is a `SlotFill` that adds help about the command in the addon's own terms, or a link to its documentation. The page names the command as its subject, so a contribution whose scope names commands is active on the form of those commands alone.

## Example

The fixture addon's help on the form of `entry.create`:

<!-- example: examples/Vitest/Panel/Points/command-form-aside.test.tsx -->
```tsx
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
```
