// The fixture addon's page at /x/fixtureaddon/articles (section 3.4 of the panel extension
// architecture): the articles of the type the addon extends with their slugs, from its data query
// fixtureaddon.articles, run as the viewer on the server and handed over as the page's only props.

import { usePanelHost, type PageProps } from '@cboxdk/cms-panel/extend';
import { Page, PageHeader } from '@cboxdk/cms-panel/experimental';

import type { FixtureaddonArticlesResultV1 } from '../generated/contributions';
import { ArticlesData } from './ArticlesTable';

export default function Articles({ data }: PageProps<FixtureaddonArticlesResultV1>) {
  const { t } = usePanelHost();

  return (
    <Page
      header={
        <PageHeader
          title={t('fixtureaddon.articles.page_title')}
          description={t('fixtureaddon.articles.page_description')}
        />
      }
    >
      <ArticlesData data={data} headingLevel={2} />
    </Page>
  );
}
