---
title: "command.form.dryrun@1"
weight: 55
description: "The sections below a dry run of the generic command form: what an addon adds below what the dry run would change."
---

# command.form.dryrun@1

<!-- extension-point: Cbox\Cms\Panel\CommandForm\Domain\Dto\DryRunViewV1 -->
<!-- extension-point: packages/panel/resources/schemas/points/command.form.dryrun.v1.json -->

The sections below the summary of a dry run in the [command form](../command-form.md).

| | |
|---|---|
| Kind | [slot](../kinds/slot.md), region `Sections`, any number |
| Page | `command.form` |
| Props | `DryRunViewV1`: the `command`, its `version`, the `summary` as [dry run JSON](../../dry-run-json.md) writes it, and the dry run's `receipt` |
| TypeScript | `DryRunViewV1` of `@cboxdk/cms-panel/experimental` |
| Stability | experimental, since panel API 1.0; list it in `acceptsExperimental` |
| The core's own | none |
| Fixture addon | `fixtureaddon.dry-run-note`, on `entry.create@1` |

A contribution is a `SlotFill`. The props exist only in the browser, once the dry run answered, so the server resolves the contributions without props and the page builds them. The page names the command as its subject, so a contribution whose scope names commands is active on the form of those commands alone.

## Example

The fixture addon's note below a dry run of `entry.create`:

<!-- example: examples/Vitest/Panel/Points/command-form-dryrun.test.tsx -->
```tsx
// @vitest-environment jsdom

// A slot contribution to command.form.dryrun@1, the sections below what a dry run of the generic
// command form would change: the fixture addon's fixtureaddon.dry-run-note renders with the point's
// props, the command, its version, the dry run's summary and its receipt, reaches the panel
// through the host alone and has no accessibility violation. The props exist only in the browser,
// once the dry run answered, so the page builds them; here they are the sample props of the
// point's JSON Schema.

import type { DryRunViewV1 } from '@cboxdk/cms-panel/experimental';
import { expectNoA11yViolations, expectSlotContract } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

import addon from '../../../../workbench/addons/fixtureaddon/resources/panel/src/panel';

const props: DryRunViewV1 = {
  command: 'entry.create',
  version: 1,
  receipt: { outcome: 'dry_run' },
  summary: { blast_radius: { aggregates: [], mutations: 0 } },
};

test('fixtureaddon.dry-run-note keeps the slot contract below the dry run', async () => {
  const rendered = await expectSlotContract({
    addon,
    id: 'fixtureaddon.dry-run-note',
    props,
    region: 'sections',
    host: {
      namespace: 'fixtureaddon',
      texts: {
        'fixtureaddon.dry_run_note.title': 'About the slug',
        'fixtureaddon.dry_run_note.body':
          'A dry run of {command} shows no slug; it is derived on the run.',
      },
    },
  });

  expect(rendered.container.textContent).toContain('A dry run of entry.create shows no slug');
  await expectNoA11yViolations(rendered.container);
  await rendered.unmount();
});
```
