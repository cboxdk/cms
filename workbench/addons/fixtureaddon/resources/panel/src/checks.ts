// The fixture addon's checks of entry.create's form (section 3.7 of the panel extension
// architecture), each a pure function of the document to issues, run in the browser on every
// edit. They are a courtesy to the viewer; the rule is the addon's hooks on the server:
//
// - slugHint warns, at the title, when no slug can be derived from it and none is set, because
//   RequireSlugOnRelease will refuse the release until the slug is set;
// - slugOverride asks the viewer to acknowledge a slug set by hand, in place of the one DeriveSlug
//   would derive from the title;
// - slugShape blocks a slug that is not well formed, as RequireWellFormedSlug refuses it on the
//   server: the check mirrors the hook, so the mirror rule lets it block, and
//   ../parity/slug-shape.json holds the two to the same verdicts.
//
// Each applies to the addon's own type alone, and says nothing about an entry of another type.

import type { FormCheck } from '@cboxdk/cms-panel/extend';

import type { EntryCreateV1 } from '../generated/contributions';
import { SHAPE, SLUG_PATH, TITLE_PATH, isArticle, slugIn, slugOf, titleOf } from './slug';

export const slugHint: FormCheck<EntryCreateV1> = (document) => {
  if (!isArticle(document) || slugIn(document) !== undefined) {
    return [];
  }

  const title = titleOf(document);

  return slugOf(title ?? '') === null
    ? [
        {
          path: TITLE_PATH,
          code: 'fixtureaddon.slug_hint',
          severity: 'warning',
          message: 'fixtureaddon.slug_hint.message',
        },
      ]
    : [];
};

export const slugOverride: FormCheck<EntryCreateV1> = (document) => {
  const slug = slugIn(document);

  if (!isArticle(document) || slug === undefined) {
    return [];
  }

  const derived = slugOf(titleOf(document) ?? '');

  return derived === slug
    ? []
    : [
        {
          path: SLUG_PATH,
          code: 'fixtureaddon.slug_override',
          severity: 'acknowledge',
          message: 'fixtureaddon.slug_override.message',
          parameters: { derived: derived ?? '' },
        },
      ];
};

export const slugShape: FormCheck<EntryCreateV1> = (document) => {
  const slug = slugIn(document);

  if (!isArticle(document) || slug === undefined || SHAPE.test(slug)) {
    return [];
  }

  return [
    {
      path: SLUG_PATH,
      code: 'fixtureaddon.slug_shape',
      severity: 'error',
      message: 'fixtureaddon.slug_shape.message',
      parameters: { slug },
    },
  ];
};
