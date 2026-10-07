// What the fixture addon's page and section make of the result of fixtureaddon.articles, as the
// panel hands it to them: the result its codec wrote at the lower of the viewer's classification
// access and the addon's reads, public, so an article's title, classified internal, is never
// among the members (the reads cap of section 3.2 of the panel extension architecture), and the
// generated type names it optional.

import type { FixtureaddonArticlesResultV1 } from '../generated/contributions';

/** One article as the table shows it. */
export type ArticleRow = FixtureaddonArticlesResultV1['articles'][number];

/** Whether any article of the result carries a title, which only a reader above public gets. */
export function hasTitles(result: FixtureaddonArticlesResultV1): boolean {
  return result.articles.some((article) => typeof article.title === 'string');
}
