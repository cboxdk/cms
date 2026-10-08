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
