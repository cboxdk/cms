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
