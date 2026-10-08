---
title: "shell.page@1"
weight: 42
description: "A page of the addon below /x/<namespace>/ in the panel's shell, whose only props are the result of its data query."
---

# shell.page@1

<!-- extension-point: Cbox\Cms\Panel\Shell\Domain\Dto\ShellPageV1 -->
<!-- extension-point: packages/panel/resources/schemas/points/shell.page.v1.json -->

A page of the addon at `<prefix>/x/<namespace>/<path>`, rendered in the [shell](../shell.md#pages) as the Inertia page `Addon`.

| | |
|---|---|
| Kind | [page](../kinds/page.md) |
| Page | `shell` |
| Props | none: `ShellPageV1` has no members; the component gets `data`, the result of its query |
| TypeScript | `PageComponent<D>` of `@cboxdk/cms-panel/extend`, with `D` the query's result type `cms:panel:types` writes |
| Stability | experimental, since panel API 1.0; list it in `acceptsExperimental` |
| The core's own | none |
| Fixture addon | `fixtureaddon.articles` at `articles`, reading its query `fixtureaddon.articles` and requiring the permission of the same name |

A contribution is a `PageContribution` with its path and a `#[Query]` of its addon without required input. The server runs the query on the page alone, as the viewer, at the lower of the viewer's access and the addon's `reads`, and sends the result as the deferred prop `ext.<namespace>`, so the same data can be read over REST with the same credential. A viewer who may not open the page, and a path no addon has, get the panel's page for an address it does not have, with 404.

## Example

The fixture addon's page of articles, in each state of its data:

<!-- example: examples/Vitest/Panel/Points/shell-page.test.tsx -->
```tsx
// @vitest-environment jsdom

// A page contribution to shell.page@1, a page of the addon below /x/<namespace>/: the fixture
// addon's fixtureaddon.articles renders in every state of its data query, loading, failed and ready,
// because a page never fails when its data does, and has no accessibility violation with the data
// ready. The data is of the query's generated result type, as cms:panel:types writes it; a title is
// absent, because the addon reads public fields and the title is classified internal.

import { expectNoA11yViolations, expectPageContract } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

import type { FixtureaddonArticlesResultV1 } from '../../../../workbench/addons/fixtureaddon/resources/panel/generated/contributions';
import addon from '../../../../workbench/addons/fixtureaddon/resources/panel/src/panel';

const data: FixtureaddonArticlesResultV1 = {
  articles: [
    { entry: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a11', slug: 'a-quiet-week' },
    { entry: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a12', slug: null },
  ],
  count: 2,
};

test('fixtureaddon.articles keeps the page contract in every state of its data', async () => {
  const rendered = await expectPageContract<
    typeof addon.contributions,
    FixtureaddonArticlesResultV1
  >({
    addon,
    id: 'fixtureaddon.articles',
    data,
    host: {
      namespace: 'fixtureaddon',
      texts: {
        'fixtureaddon.articles.page_title': 'Articles',
        'fixtureaddon.articles.page_description': 'The articles with their slugs.',
        'fixtureaddon.articles.table': 'Articles',
        'fixtureaddon.articles.slug': 'Slug',
        'fixtureaddon.articles.entry': 'Entry',
        'fixtureaddon.articles.no_slug': 'No slug yet',
      },
    },
  });

  expect(rendered.container.textContent).toContain('a-quiet-week');
  expect(rendered.container.textContent).toContain('No slug yet');
  await expectNoA11yViolations(rendered.container);
  await rendered.unmount();
});
```
