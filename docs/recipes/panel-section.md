---
title: Add a section with data
weight: 47
description: "Add a section to a page of the panel with data an addon reads as the viewer: the data query, the slot fill, the component in each state of its data, the bundle, and the tests that hold it."
---

# Add a section with data

A section shows something an addon knows on a page of the panel, such as the viewer's own articles on the who-am-I page. Its data comes from a query of the addon that the server runs as the viewer, so the section sees only what the viewer may read, capped at the addon's `reads` ([slot](../addons/panel/kinds/slot.md)). The fixture addon's `fixtureaddon.my-articles` on `account.me.sections@1` was added this way.

## Inputs

- **namespace** and **id**: the addon's namespace and the contribution's id, such as `fixtureaddon.my-articles`.
- **point**: a slot in a sections region, such as `account.me.sections@1`; its props name the input the query can take.
- **query**: a `#[Query]` of the addon with its codecs, such as `fixtureaddon.articles@1`, whose input members are taken from the point's props by name.
- **permission**: what the viewer must hold to see the section, in the scope's `requires`.

## Files

| Path, in the addon's package | What it holds |
|---|---|
| `src/<Feature>/...` | the query, its action and its codecs, registered under `QueryCodecs::TAG` in the provider ([queries](../addons/queries.md)) |
| `src/<Addon>ServiceProvider.php` | the `SlotFill` with `data` and its `Scope`, and the point in `acceptsExperimental` |
| `resources/panel/generated/contributions.ts` | *generated* by `cms:panel:types <namespace>`: the props and the query's result types |
| `resources/panel/src/<Module>.tsx` and its test | the component, which renders each state of its data, and its test on `expectSlotContract()` |
| `resources/panel/src/<entry>.ts` | the registration, `definePanelAddon<Contributions>()`, with `'<id>': () => import('./<Module>')` |
| `dist/panel` | *generated* by `npm run build`: the signed bundle |

## Steps

1. Write the query and register its codecs. Add the `SlotFill` with `data: <Query>::class` and its scope, and run `vendor/bin/testbench cms:build`.
2. Run `vendor/bin/testbench cms:make:panel fill <namespace> <id>`: it writes a stub of the component with its states, a test with the point's sample props and the query's sample result, and the registration's entry. `vendor/bin/testbench cms:panel:types <namespace>` writes the types.
3. Write the component with the kit's components and texts of the addon's namespace, saying what it waits for while the data loads and what failed when it did not come.
4. Run `npm run typecheck`, `npm run lint` and `npm run test` in the addon, then `npm run build` and `npm run verify`.
5. Run `vendor/bin/testbench cms:build` again and the addon's PHP tests.

## Checks

- `cms:build` refuses a data query that is not the addon's or whose required input the props do not give (`registry_panel_data_query_invalid`).
- `expectSlotContract()` renders the section loading, failed and ready, and fails on one that throws in any state; `expectNoA11yViolations()` runs axe on it.
- A member classified above the addon's `reads` is absent from the result: the fixture addon reads public fields, so an article's title, which is internal, never reaches the section. `tests/Browser/Panel/PanelAddonsTest.php` shows the section, its permission and the reads cap in Chromium.

## Running example

The component:

<!-- example-file: workbench/addons/fixtureaddon/resources/panel/src/MyArticles.tsx -->
```tsx
// The fixture addon's section of the who-am-I page that lists the articles of the type it extends,
// through the same data query as its page, fixtureaddon.articles, run as the viewer; the point's
// props are the viewer's actor id, which the query does not take.

import { usePanelHost, type SlotProps } from '@cboxdk/cms-panel/extend';
import { Section, type AccountMeSectionsV1 } from '@cboxdk/cms-panel/experimental';

import type { FixtureaddonArticlesResultV1 } from '../generated/contributions';
import { ArticlesData } from './ArticlesTable';

export default function MyArticles({
  data,
}: SlotProps<AccountMeSectionsV1, FixtureaddonArticlesResultV1>) {
  const { t } = usePanelHost();

  return (
    <Section
      title={t('fixtureaddon.my_articles.title')}
      description={t('fixtureaddon.my_articles.description')}
    >
      <ArticlesData data={data} headingLevel={3} />
    </Section>
  );
}
```

The registration of the fixture addon's code:

<!-- example-file: workbench/addons/fixtureaddon/resources/panel/src/panel.ts -->
```ts
// The panel entry of the workbench's fixture addon (PRD 13.4): the module the panel imports as
// cms-addons/fixtureaddon through its import map, which registers the addon's contributions that
// run code by their ids, as the manifest declares them (FixtureAddonServiceProvider): the checks
// and decorators as functions, the observer, and each component imported when a page first shows
// it, the input of the addon's own value class among them. The bundle in dist/panel is built from this file by `npm run build:fixture-addon` and
// signed with the test key panel-signing-test-key.pem, whose public key the workbench trusts in
// cbox-cms.addons.publishers.

import { definePanelAddon } from '@cboxdk/cms-panel/extend';

import type { Contributions } from '../generated/contributions';
import { activity } from './activity';
import { selfGrant, slugHint, slugOverride, slugShape } from './checks';
import { receiptNote, submitNote } from './decorators';

export default definePanelAddon<Contributions>({
  'fixtureaddon.activity': activity,
  'fixtureaddon.articles': () => import('./Articles'),
  'fixtureaddon.articles-permission': () =>
    import('./notes').then((module) => ({ default: module.ArticlesPermissionNote })),
  'fixtureaddon.dry-run-note': () => import('./DryRunNote'),
  'fixtureaddon.faulty': () => import('./Faulty'),
  'fixtureaddon.four-eyes': () => import('./FourEyes'),
  'fixtureaddon.four-eyes-note': () =>
    import('./notes').then((module) => ({ default: module.FourEyesNote })),
  'fixtureaddon.my-articles': () => import('./MyArticles'),
  'fixtureaddon.receipt-note': receiptNote,
  'fixtureaddon.recent-activity': () => import('./RecentActivity'),
  'fixtureaddon.self-grant': selfGrant,
  'fixtureaddon.slug-help': () => import('./SlugHelp'),
  'fixtureaddon.slug-hint': slugHint,
  'fixtureaddon.slug-input': () => import('./SlugInput'),
  'fixtureaddon.slug-override': slugOverride,
  'fixtureaddon.slug-review': () => import('./SlugReview'),
  'fixtureaddon.slug-shape': slugShape,
  'fixtureaddon.submit-note': submitNote,
});
```

The section in each state of its data:

<!-- example: examples/Vitest/Panel/Recipes/sidebar-section.test.tsx -->
```tsx
// @vitest-environment jsdom

// A section with data on the who-am-I page, the recipe's real run: the fixture addon's
// fixtureaddon.my-articles is a slot contribution to account.me.sections@1 with the data query
// fixtureaddon.articles, which the panel runs as the viewer on the server, and its component is
// MyArticles, registered in the addon's panel.ts. The section renders in every state of its data,
// loading, failed and ready, because a page never fails when a section's data does, and with the
// data ready it lists the articles; a title is absent, because the addon reads public fields. The
// props and the data are of the generated types cms:panel:types writes.

import type { AccountMeSectionsV1 } from '@cboxdk/cms-panel/experimental';
import { expectNoA11yViolations, expectSlotContract, renderSlot } from '@cboxdk/cms-panel/testing';
import { expect, test } from 'vitest';

import type { FixtureaddonArticlesResultV1 } from '../../../../workbench/addons/fixtureaddon/resources/panel/generated/contributions';
import addon from '../../../../workbench/addons/fixtureaddon/resources/panel/src/panel';

const props: AccountMeSectionsV1 = { actor: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01' };

const data: FixtureaddonArticlesResultV1 = {
  articles: [{ entry: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a11', slug: 'a-quiet-week' }],
  count: 1,
};

const texts = {
  'fixtureaddon.my_articles.title': 'My articles',
  'fixtureaddon.my_articles.description': 'The articles with their slugs.',
  'fixtureaddon.articles.table': 'Articles',
  'fixtureaddon.articles.slug': 'Slug',
  'fixtureaddon.articles.entry': 'Entry',
  'fixtureaddon.articles.loading': 'Loading the articles.',
  'fixtureaddon.articles.failed': 'The articles could not be read ({code}).',
};

test('fixtureaddon.my-articles keeps the slot contract in every state of its data', async () => {
  const rendered = await expectSlotContract<
    typeof addon.contributions,
    FixtureaddonArticlesResultV1
  >({
    addon,
    id: 'fixtureaddon.my-articles',
    props,
    region: 'sections',
    data,
    host: { namespace: 'fixtureaddon', texts },
  });

  expect(rendered.container.textContent).toContain('My articles');
  expect(rendered.container.textContent).toContain('a-quiet-week');
  await expectNoA11yViolations(rendered.container);
  await rendered.unmount();
});

test('fixtureaddon.my-articles says what it waits for, and what failed', async () => {
  const loading = await renderSlot<typeof addon.contributions, FixtureaddonArticlesResultV1>({
    addon,
    id: 'fixtureaddon.my-articles',
    props,
    data: { status: 'loading' },
    host: { namespace: 'fixtureaddon', texts },
  });

  expect(loading.container.textContent).toContain('Loading the articles.');
  await loading.unmount();

  const failed = await renderSlot<typeof addon.contributions, FixtureaddonArticlesResultV1>({
    addon,
    id: 'fixtureaddon.my-articles',
    props,
    data: { status: 'failed', code: 'query_over_budget' },
    host: { namespace: 'fixtureaddon', texts },
  });

  expect(failed.container.textContent).toContain(
    'The articles could not be read (query_over_budget).',
  );
  await failed.unmount();
});
```
