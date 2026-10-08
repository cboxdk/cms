---
title: "access.grants.sections@1"
weight: 47
description: "The sections of the grants page: a section an addon adds below the list of grants, without props."
---

# access.grants.sections@1

<!-- extension-point: Cbox\Cms\Panel\Access\Domain\Dto\AccessGrantsSectionsV1 -->
<!-- extension-point: packages/panel/resources/schemas/points/access.grants.sections.v1.json -->

The sections region of the [grants page](../pages.md#roles-and-grants), below the list of grants.

| | |
|---|---|
| Kind | [slot](../kinds/slot.md), region `Sections`, any number |
| Page | `access.grants`, at `<prefix>/access/grants` |
| Props | none: `AccessGrantsSectionsV1` has no members; the grants are the page's own |
| TypeScript | `AccessGrantsSectionsV1` of `@cboxdk/cms-panel/experimental` |
| Stability | experimental, since panel API 1.0; list it in `acceptsExperimental` |
| The core's own | none |
| Fixture addon | `fixtureaddon.four-eyes-note`, which states its rule that nobody grants a role to themselves |

A contribution is a `SlotFill`. A section that needs data reads it with its own data query, as the viewer.

## Example

The fixture addon's section on the grants page:

<!-- example: examples/Vitest/Panel/Points/access-grants-sections.test.tsx -->
```tsx
// @vitest-environment jsdom

// A slot contribution to access.grants.sections@1, the sections of the grants page: the fixture
// addon's fixtureaddon.four-eyes-note renders in the page's sections region without props, because
// the grants are the page's own, reaches the panel through the host alone and has no accessibility
// violation.

import type { AccessGrantsSectionsV1 } from '@cboxdk/cms-panel/experimental';
import { expectNoA11yViolations, expectSlotContract } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

import addon from '../../../../workbench/addons/fixtureaddon/resources/panel/src/panel';

const props: AccessGrantsSectionsV1 = {};

test('fixtureaddon.four-eyes-note keeps the slot contract on the grants page', async () => {
  const rendered = await expectSlotContract({
    addon,
    id: 'fixtureaddon.four-eyes-note',
    props,
    region: 'sections',
    host: {
      namespace: 'fixtureaddon',
      texts: {
        'fixtureaddon.four_eyes_note.title': 'Four eyes',
        'fixtureaddon.four_eyes_note.body': 'Nobody grants a role to themselves.',
      },
    },
  });

  expect(rendered.container.textContent).toContain('Nobody grants a role to themselves.');
  await expectNoA11yViolations(rendered.container);
  await rendered.unmount();
});
```
