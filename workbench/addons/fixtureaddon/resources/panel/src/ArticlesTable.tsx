// The table of the fixture addon's articles, shared by its page and its section of the who-am-I
// page: each article's entry id and slug, and its title where the result carries one, which the
// panel never hands the addon because it reads public fields only.

import { usePanelHost, type DataState } from '@cboxdk/cms-panel/extend';
import { DataTable, EmptyState } from '@cboxdk/cms-panel/experimental';

import type { FixtureaddonArticlesResultV1 } from '../generated/contributions';
import { hasTitles, type ArticleRow } from './article-rows';

/** The heading level of the empty state: below the page's title on the page, below a section's on the who-am-I page. */
export type ArticlesHeadingLevel = 2 | 3;

export function ArticlesTable({
  result,
  headingLevel,
}: {
  readonly result: FixtureaddonArticlesResultV1;
  readonly headingLevel: ArticlesHeadingLevel;
}) {
  const { t } = usePanelHost();
  const titles = hasTitles(result);

  return (
    <DataTable<ArticleRow>
      label={t('fixtureaddon.articles.table')}
      columns={[
        {
          id: 'slug',
          title: t('fixtureaddon.articles.slug'),
          rowHeader: true,
          render: (article) =>
            article.slug === null ? t('fixtureaddon.articles.no_slug') : article.slug,
        },
        {
          id: 'entry',
          title: t('fixtureaddon.articles.entry'),
          render: (article) => <code>{article.entry}</code>,
        },
        ...(titles
          ? [
              {
                id: 'title',
                title: t('fixtureaddon.articles.title'),
                render: (article: ArticleRow) => article.title ?? '',
              },
            ]
          : []),
      ]}
      rows={result.articles}
      rowKey={(article) => article.entry}
      empty={
        <EmptyState
          title={t('fixtureaddon.articles.none_title')}
          description={t('fixtureaddon.articles.none_body')}
          headingLevel={headingLevel}
        />
      }
    />
  );
}

/** The table for the state of the data, as the panel hands it over. */
export function ArticlesData({
  data,
  headingLevel,
}: {
  readonly data: DataState<FixtureaddonArticlesResultV1>;
  readonly headingLevel: ArticlesHeadingLevel;
}) {
  const { t } = usePanelHost();

  if (data.status === 'loading') {
    return <p>{t('fixtureaddon.articles.loading')}</p>;
  }

  if (data.status === 'failed') {
    return <p>{t('fixtureaddon.articles.failed', { code: data.code })}</p>;
  }

  return <ArticlesTable result={data.value} headingLevel={headingLevel} />;
}
