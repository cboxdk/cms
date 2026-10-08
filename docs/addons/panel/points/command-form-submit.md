---
title: "command.form.submit@1"
weight: 52
description: "The actions of the generic command form: a decorator adds content and a badge around them and tightens the disabled reason, the description or the tone."
---

# command.form.submit@1

<!-- extension-point: Cbox\Cms\Panel\CommandForm\Domain\Dto\CommandFormSubmitV1 -->
<!-- extension-point: packages/panel/resources/schemas/points/command.form.submit.v1.json -->

The actions of the [command form](../command-form.md), the run and the dry run, which a decorator adds to and tightens.

| | |
|---|---|
| Kind | [decorator](../kinds/decorator.md), tightening `disabled_reason`, `description` and `tone_towards_danger` |
| Page | `command.form` |
| Props | `CommandFormSubmitV1`: the `command`, its contract `version` and the `title` of its JSON Schema |
| TypeScript | `Decorator<CommandFormSubmitV1, T>` of `@cboxdk/cms-panel/extend` |
| Stability | experimental, since panel API 1.0; list it in `acceptsExperimental` |
| The core's own | none |
| Fixture addon | `fixtureaddon.submit-note`, which tightens the description alone, on `entry.create@1` |

A contribution is a `DecoratorContribution` that declares the props it tightens. A disabled reason holds the run, so a decorator that tightens it mirrors a hook of its addon on the one command it is scoped to. The page names the command as its subject, so a contribution whose scope names commands is active on the form of those commands alone.

## Example

The fixture addon's note on the run of `entry.create`:

<!-- example: examples/Vitest/Panel/Points/command-form-submit.test.tsx -->
```tsx
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
```
