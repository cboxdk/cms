---
title: "account.me.sections@1"
weight: 45
description: "The sections of the who-am-I page: a section about the viewer, with the viewer's actor id as its props and a data query read as the viewer."
---

# account.me.sections@1

<!-- extension-point: Cbox\Cms\Panel\Account\Domain\Dto\AccountMeSectionsV1 -->
<!-- extension-point: packages/panel/resources/schemas/points/account.me.sections.v1.json -->

The sections region of the [who-am-I page](../pages.md#the-who-am-i-page), below the page's own profile and grants.

| | |
|---|---|
| Kind | [slot](../kinds/slot.md), region `Sections`, any number |
| Page | `account.me`, at `<prefix>/account/me` |
| Props | `AccountMeSectionsV1`: `actor`, the viewer's actor id, which a section's data query takes as its input |
| TypeScript | `AccountMeSectionsV1` of `@cboxdk/cms-panel/experimental` |
| Stability | experimental, since panel API 1.0; list it in `acceptsExperimental` |
| The core's own | none |
| Fixture addon | `fixtureaddon.recent-activity` at 10, `fixtureaddon.my-articles` at 20 with its data query, and `fixtureaddon.faulty` at 30, which throws on purpose and which the workbench disables with the kill switch |

A contribution is a `SlotFill`. The profile and the grants are the page's own and are never handed to a section: a section that shows something about the viewer reads it with its own data query, run as the viewer and capped at the addon's `reads` ([add a section with data](../../../recipes/panel-section.md)).

## Example

The fixture addon's section of recent activity:

<!-- example: examples/Vitest/Panel/Points/account-me-sections.test.tsx -->
```tsx
// @vitest-environment jsdom

// A slot contribution to account.me.sections@1, the sections of the who-am-I page: the fixture
// addon's fixtureaddon.recent-activity renders with the point's props, the viewer's actor id, in the
// page's sections region, and has no accessibility violation. The section reads what the addon's
// observer recorded in the browser's session storage, so with nothing recorded it shows its empty
// state. The props are the sample props of the point's JSON Schema, as cms:make:panel writes them
// into a scaffolded test.

import type { AccountMeSectionsV1 } from '@cboxdk/cms-panel/experimental';
import { expectNoA11yViolations, expectSlotContract } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

import addon from '../../../../workbench/addons/fixtureaddon/resources/panel/src/panel';

const props: AccountMeSectionsV1 = { actor: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01' };

test('fixtureaddon.recent-activity keeps the slot contract and shows its empty state', async () => {
  window.sessionStorage.clear();

  const rendered = await expectSlotContract({
    addon,
    id: 'fixtureaddon.recent-activity',
    props,
    region: 'sections',
    host: {
      namespace: 'fixtureaddon',
      texts: {
        'fixtureaddon.recent_activity.title': 'Recent activity',
        'fixtureaddon.recent_activity.none_title': 'No command yet',
        'fixtureaddon.recent_activity.none_body': 'Run a command and come back.',
      },
    },
  });

  expect(rendered.container.textContent).toContain('Recent activity');
  expect(rendered.container.textContent).toContain('No command yet');
  await expectNoA11yViolations(rendered.container);
  await rendered.unmount();
});
```
