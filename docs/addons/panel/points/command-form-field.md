---
title: "command.form.field@1"
weight: 54
description: "The input of one field of the generic command form: a replacement keyed by the value class the command binds the member to, for a class the addon owns."
---

# command.form.field@1

<!-- extension-point: Cbox\Cms\Panel\CommandForm\Domain\Dto\FieldInputPropsV1 -->
<!-- extension-point: packages/panel/resources/schemas/points/command.form.field.v1.json -->

The input of one member of a command's document in the [command form](../command-form.md#the-field-replacement-and-the-cores-pickers).

| | |
|---|---|
| Kind | [replacement](../kinds/replacement.md), keyed by value class, `Ownership::Own`, one per key |
| Page | `command.form` |
| Props | `FieldInputPropsV1`: the `command` and `version`, the member's `path`, the `id`, `label` and `description` of the default input, the member's `schema` node, its `value` as text, its `errors`, whether the form is `read_only`, the `locale` and the member's `presence`; in the browser the host adds `onChange` |
| TypeScript | `FieldInput` and `FieldInputProps` of `@cboxdk/cms-panel/experimental` |
| Stability | experimental, since panel API 1.0; list it in `acceptsExperimental` |
| The core's own | `cms.node-picker`, `cms.actor-picker` and `cms.role-picker`, for `NodeId`, `ActorId` and `RoleId`, each reading `node.list`, `actor.list` or `role.list` as its data |
| Fixture addon | `fixtureaddon.slug-input`, for its own value class `ArticleSlug`, the member `slug` of its command `fixtureaddon.slug.set` |

A contribution is a `ReplacementContribution` whose key is a value class its addon's package declares; an addon replaces no class it does not own (`registry_panel_unowned_target`). The page reads, per member, the class the command binds it to, and renders the replacement that won that key, or the default input. A replacement keeps the default input's id and name, so the error summary still links to it and the value is submitted under the member's path; one that throws gives the default input back.

## Example

The fixture addon's input of its slug:

<!-- example: examples/Vitest/Panel/Points/command-form-field.test.tsx -->
```tsx
// @vitest-environment jsdom

// A replacement contribution to command.form.field@1, the input of one field of the generic
// command form: the fixture addon's fixtureaddon.slug-input takes the place of the default input of
// every member a command binds to the addon's own value class ArticleSlug, renders something with
// exactly the point's props, keeps the default input's id and name, so the error summary still
// links to it and the value is submitted under the member's path, and hands the form a well-formed
// slug through onChange when the field loses focus. The props are the sample props of the point's
// JSON Schema with the member's value, and onChange, which the host adds in the browser.

import type { FieldInputProps } from '@cboxdk/cms-panel/experimental';
import { expectNoA11yViolations, expectReplacementContract } from '@cboxdk/cms-panel/testing';
import { act } from 'react';
import { expect, test } from 'vitest';

import addon from '../../../../workbench/addons/fixtureaddon/resources/panel/src/panel';

test('fixtureaddon.slug-input keeps the replacement contract and shapes the slug', async () => {
  const changes: (string | null)[] = [];
  const props: FieldInputProps = {
    command: 'fixtureaddon.slug.set',
    version: 1,
    path: 'slug',
    id: 'command-slug',
    label: 'Slug',
    description: 'The article s slug.',
    schema: { type: 'string' },
    value: 'A Quiet Week',
    errors: [],
    read_only: false,
    locale: 'en',
    presence: 'required',
    onChange: (value) => {
      changes.push(value);
    },
  };

  const rendered = await expectReplacementContract({
    addon,
    id: 'fixtureaddon.slug-input',
    props,
    host: {
      namespace: 'fixtureaddon',
      texts: {
        'fixtureaddon.slug_input.description': 'Lowercase letters and digits, joined by hyphens.',
        'fixtureaddon.slug_input.preview': 'The article gets the slug {slug}.',
        'fixtureaddon.slug_input.empty': 'No slug yet.',
      },
    },
  });

  const input = rendered.container.querySelector('input');

  expect(input?.id).toBe('command-slug');
  expect(input?.name).toBe('slug');
  expect(rendered.container.textContent).toContain('The article gets the slug a-quiet-week.');
  await expectNoA11yViolations(rendered.container);

  await act(async () => {
    input?.dispatchEvent(new FocusEvent('focusout', { bubbles: true }));
    await Promise.resolve();
  });

  expect(changes).toEqual(['a-quiet-week']);
  await rendered.unmount();
});
```
