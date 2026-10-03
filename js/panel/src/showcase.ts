// The side of the sign-in pages that says what the product is (cms-planning/design/round-1,
// Main.dc.html): the same on the login, the forgot-password and the reset page, every text from
// the panel's catalogues. Its card names the steps a receipt reports for a published change,
// origin, edge and every site, and shows no numbers, because the page has no change to measure.

import type { TaskScreenShowcase } from '@cboxdk/cms-ui-kit';

import type { Translate } from './i18n/translations';

/** The showcase of the sign-in pages in the locale of the translator. */
export function showcase(t: Translate): TaskScreenShowcase {
  return {
    eyebrow: t('panel.showcase.eyebrow'),
    title: t('panel.showcase.title'),
    description: t('panel.showcase.description'),
    card: {
      title: t('panel.showcase.card.title'),
      status: t('panel.showcase.card.status'),
      facts: [
        { label: t('panel.showcase.card.origin'), value: t('panel.showcase.card.origin_value') },
        { label: t('panel.showcase.card.edge'), value: t('panel.showcase.card.edge_value') },
        { label: t('panel.showcase.card.sites'), value: t('panel.showcase.card.sites_value') },
      ],
      note: t('panel.showcase.card.note'),
    },
  };
}
