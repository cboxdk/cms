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
