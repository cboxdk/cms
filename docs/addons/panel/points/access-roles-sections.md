---
title: "access.roles.sections@1"
weight: 46
description: "The sections of the roles page: a section an addon adds below the list of roles, without props."
---

# access.roles.sections@1

<!-- extension-point: Cbox\Cms\Panel\Access\Domain\Dto\AccessRolesSectionsV1 -->
<!-- extension-point: packages/panel/resources/schemas/points/access.roles.sections.v1.json -->

The sections region of the [roles page](../pages.md#roles-and-grants), below the list of roles.

| | |
|---|---|
| Kind | [slot](../kinds/slot.md), region `Sections`, any number |
| Page | `access.roles`, at `<prefix>/access/roles` |
| Props | none: `AccessRolesSectionsV1` has no members; the roles are the page's own |
| TypeScript | `AccessRolesSectionsV1` of `@cboxdk/cms-panel/experimental` |
| Stability | experimental, since panel API 1.0; list it in `acceptsExperimental` |
| The core's own | none |
| Fixture addon | `fixtureaddon.articles-permission`, which explains the permission its page needs |

A contribution is a `SlotFill`. A section that needs data reads it with its own data query, as the viewer.

## Example

The fixture addon's section on the roles page:

<!-- example: examples/Vitest/Panel/Points/access-roles-sections.test.tsx -->
```tsx
// @vitest-environment jsdom

// A slot contribution to access.roles.sections@1, the sections of the roles page: the fixture
// addon's fixtureaddon.articles-permission renders in the page's sections region without props,
// because the roles are the page's own, reaches the panel through the host alone and has no
// accessibility violation. The props are AccessRolesSectionsV1, the type composer generate:protocol
// writes from the point's schema, access.roles.sections.v1.json, which describes no member.

import type { AccessRolesSectionsV1 } from '@cboxdk/cms-panel/experimental';
import { expectNoA11yViolations, expectSlotContract } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

import addon from '../../../../workbench/addons/fixtureaddon/resources/panel/src/panel';

const props: AccessRolesSectionsV1 = {};

test('fixtureaddon.articles-permission keeps the slot contract on the roles page', async () => {
  const rendered = await expectSlotContract({
    addon,
    id: 'fixtureaddon.articles-permission',
    props,
    region: 'sections',
    host: {
      namespace: 'fixtureaddon',
      texts: {
        'fixtureaddon.articles_permission.title': 'The articles page',
        'fixtureaddon.articles_permission.body':
          'A role that names fixtureaddon.articles opens the articles page.',
      },
    },
  });

  expect(rendered.container.textContent).toContain('The articles page');
  await expectNoA11yViolations(rendered.container);
  await rendered.unmount();
});
```
