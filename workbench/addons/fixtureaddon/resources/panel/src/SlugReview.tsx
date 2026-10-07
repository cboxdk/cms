// The fixture addon's step before the submit of entry.create's form (section 3.8 of the panel
// extension architecture): it shows the slug the article gets, the one set by hand or the one
// derived from the title, lets the viewer take it, which patches the addon's own field of the
// draft, the one path the step's manifest declares, and go on to the core's confirmation, or stop,
// which cancels the flow in the addon's name. An entry of another type has no slug to review, and
// the step offers to go on.

import { usePanelHost, type StepProps } from '@cboxdk/cms-panel/extend';
import { Button, Callout, Inline, Stack } from '@cboxdk/cms-panel/experimental';

import type { EntryCreateV1 } from '../generated/contributions';
import { SLUG_PATH, isArticle, slugIn, slugOf, titleOf } from './slug';

export default function SlugReview({
  draft,
  patch,
  next,
  cancel,
}: StepProps<EntryCreateV1, typeof SLUG_PATH>) {
  const { t } = usePanelHost();
  const article = isArticle(draft);
  const slug = slugIn(draft) ?? slugOf(titleOf(draft) ?? '');

  return (
    <Callout
      tone="info"
      title={t(article ? 'fixtureaddon.slug_review.title' : 'fixtureaddon.slug_review.other_type')}
    >
      <Stack gap="sm">
        {article ? (
          <p data-fixtureaddon-slug={slug ?? ''}>
            {slug === null
              ? t('fixtureaddon.slug_review.none')
              : t('fixtureaddon.slug_review.slug', { slug })}
          </p>
        ) : null}
        <Inline gap="sm">
          <Button
            type="button"
            variant="primary"
            onClick={() => {
              if (article && slug !== null && slugIn(draft) === undefined) {
                patch(SLUG_PATH, slug);
              }

              next();
            }}
          >
            {t('fixtureaddon.slug_review.use')}
          </Button>
          <Button
            type="button"
            variant="quiet"
            onClick={() => {
              cancel('fixtureaddon.slug_review.cancelled');
            }}
          >
            {t('fixtureaddon.slug_review.stop')}
          </Button>
        </Inline>
      </Stack>
    </Callout>
  );
}
