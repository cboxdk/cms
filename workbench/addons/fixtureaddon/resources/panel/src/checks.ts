// The fixture addon's checks of the generic command form (section 3.7 of the panel extension
// architecture), each a pure function of the document to issues, run in the browser on every
// edit. They are a courtesy to the viewer; the rule is the addon's hooks on the server. On
// entry.create's form:
//
// - slugHint warns, at the title, when no slug can be derived from it and none is set, because
//   RequireSlugOnRelease will refuse the release until the slug is set;
// - slugOverride asks the viewer to acknowledge a slug set by hand, in place of the one DeriveSlug
//   would derive from the title;
// - slugShape blocks a slug that is not well formed, as RequireWellFormedSlug refuses it on the
//   server: the check mirrors the hook, so the mirror rule lets it block, and
//   ../parity/slug-shape.json holds the two to the same verdicts.
//
// Each applies to the addon's own type alone, and says nothing about an entry of another type. On
// grant.assign's form, selfGrant blocks a grant to the viewer themselves, read against the viewer
// the check's context names, as the authorize hook DenySelfGrant refuses it on the server, the
// addon's four-eyes rule; ../parity/self-grant.json holds the two to the same verdicts.

import type { FormCheck } from '@cboxdk/cms-panel/extend';

import type { EntryCreateV1, GrantAssignV1 } from '../generated/contributions';
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

/** The path of the grantee in a grant.assign document, as FieldPath::toString() writes it. */
export const ACTOR_PATH = 'actor';

/** The draft of grant.assign as the form holds it while it is edited: any member may be missing. */
type GrantDraft = { readonly [K in keyof GrantAssignV1]?: GrantAssignV1[K] };

export const selfGrant: FormCheck<GrantAssignV1> = (document, context) => {
  const actor = (document as GrantDraft).actor;

  // The kernel reads an id whatever the case of its hex digits, so the hook compares ids, not text.
  if (
    typeof actor !== 'string' ||
    context.viewer === null ||
    actor.toLowerCase() !== context.viewer.toLowerCase()
  ) {
    return [];
  }

  return [
    {
      path: ACTOR_PATH,
      code: 'fixtureaddon.self_grant',
      severity: 'error',
      message: 'fixtureaddon.self_grant.message',
    },
  ];
};
