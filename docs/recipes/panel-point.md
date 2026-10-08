---
title: Add a point to a core page
weight: 51
description: "Add a panel point to a page of the panel in cboxdk/cms: the props class with #[PanelPoint], its schema and generated code, the page that renders it, its story, its docs page and the tests that hold each."
---

# Add a point to a core page

A panel point is public API that addons build on, so a point is added only where a consumer needs it: the core's own contribution or the fixture addon's ([panel points](../addons/panel/points/_index.md)). This recipe is for this repository. `access.roles.sections@1`, the sections of the roles page, was added this way.

## Inputs

- **page**: the page that renders the point, a `PageName` such as `access.roles`.
- **name**: the point's name, such as `access.roles.sections`, and its version, 1 for a new point.
- **kind**: a case of `PointKind`, with its region for a slot, its multiplicity, its ownership and key for a replacement, and what a decorator may tighten.
- **props**: the members a contribution receives, each a type the codecs have a form for, or none.
- **label**: the key of the point's name in the panel's catalogue, `panel.points.<name with underscores>`.

## Files

| Path | What it holds |
|---|---|
| `packages/panel/src/<Page>/Domain/<Page>.php` | the point's name as a constant beside the page's |
| `packages/panel/src/<Page>/Domain/Dto/<Props>V1.php` | the props class, `final readonly`, `#[Experimental]` and `#[PanelPoint(...)]` |
| `packages/panel/resources/schemas/points/<name>.v1.json` | the props' JSON Schema, with `examples` for every member with a pattern |
| `tools/src/Protocol/Domain/PanelPointSchemas.php` | the binding of the schema to the props class |
| `packages/panel/src/Boundary/Generated/Points`, `js/panel-sdk/src/generated` and `packages/panel/resources/points.lock.json` | *generated* by `composer generate:protocol`: the codec, the TypeScript with its validator and sample, the subpath exports and the lock |
| `packages/panel/src/<Page>/Boundary/<Page>Request.php` | the page's view names the point with its props, a `RenderedPoint`, or `RenderedPoint::heldByPage()` for props only the browser has |
| `js/panel/src/pages/<Page>.tsx` and `js/panel/src/i18n/catalogues/*.json` | `<PointHost point="<name>@1" />` where the point renders, and its label in both catalogues |
| `js/panel/stories/generated` and `js/ui-kit/visual-baselines` | *generated* by `cms:panel:stories` and `npm run storybook:baselines`: the point's story and its baseline |
| `workbench/addons/fixtureaddon` | the fixture addon's contribution, its rebuilt and signed bundle and its regenerated types |
| `examples/Vitest/Panel/Points/<name>.test.tsx` and `docs/addons/panel/points/<name>.md` | the running example and the point's page, which declares its props class and its schema |

## Steps

1. Write the props class and the schema, bind them, and run `composer generate:protocol`.
2. Name the point in the page's view and render it with `PointHost`; add the label to both catalogues.
3. Run `vendor/bin/testbench cms:build` and `vendor/bin/testbench cms:panel:stories`, then `composer image:run -- npm run storybook:baselines` for the new story.
4. Add the fixture addon's contribution, accept the point, run `vendor/bin/testbench cms:panel:types fixtureaddon`, write its code, and rebuild its bundle with `npm run build:fixture-addon`.
5. Write the running example and the point's page, add the point to the table of [panel points](../addons/panel/points/_index.md#the-points-of-block-b1), and run `composer docs:check` and `composer check`. Record the new tests in `CHECKS-LOG.md`.

## Checks

- `cms:build` refuses an attribute that breaks its rules, a duplicate id, a props class without one stability attribute and one that is not `final readonly`; `tests/Feature/Panel/RenderedPanelPointsTest.php` fails on a declared point no page renders and on a page that names an undeclared one.
- `tests/Codecs/PanelPointCodecsTest.php` reads the sample props through the codec and holds the TypeScript validator to the PHP codec; gate 6 holds the generated files.
- `packages/generators/tests/Cli/PanelStoriesCommandTest.php` and `js/ui-kit/tests/story-screenshots.test.js` fail on a point without its story and baseline.
- `tests/Feature/Tooling/Docs/PanelDocsTest.php` fails on a point without its own page, and `composer docs:check` on a page without a running example.

## Running example

The props class of the roles page's sections:

<!-- example-file: packages/panel/src/Access/Domain/Dto/AccessRolesSectionsV1.php -->
```php
<?php

declare(strict_types=1);

namespace Cbox\Cms\Panel\Access\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Contracts\PanelPoints\PanelPoint;
use Cbox\Cms\Contracts\PanelPoints\PointKind;
use Cbox\Cms\Contracts\PanelPoints\Region;
use Cbox\Cms\Panel\Access\Domain\AccessRoles;

/**
 * The props of access.roles.sections@1, the sections of the roles page (PRD 5.10, 13.4): a slot in
 * the page's sections region, below the page's own list of roles, where an addon adds a section
 * about the roles of the installation, such as how its own permissions are spread over them. It
 * has no props: the roles are the page's own, read as the person, and a section's data query
 * reads what it needs as the viewer.
 */
#[Experimental]
#[PanelPoint(name: AccessRoles::SECTIONS, version: 1, kind: PointKind::Slot, page: AccessRoles::PAGE, since: '1.0', label: 'panel.points.access_roles_sections', region: Region::Sections)]
final readonly class AccessRolesSectionsV1 {}
```

Its schema:

<!-- example-file: packages/panel/resources/schemas/points/access.roles.sections.v1.json -->
```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "title": "access.roles.sections props, contract version 1",
  "description": "The props of the sections of the roles page, a slot point in its sections region (PRD 5.10, 13.4): none. The roles are the page's own, read as the person, and a section's data query reads what it needs as the viewer. The PHP form is Cbox\\Cms\\Panel\\Access\\Domain\\Dto\\AccessRolesSectionsV1.",
  "type": "object",
  "additionalProperties": false,
  "properties": {}
}
```

The fixture addon's section, on the props the schema gives:

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
