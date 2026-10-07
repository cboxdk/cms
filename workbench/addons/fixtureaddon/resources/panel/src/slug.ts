// What the fixture addon knows of a slug in the panel (PRD 13.4): the type it extends, the slug it
// derives from a title, as DeriveSlug derives it on the server, and the shape a slug set by hand
// must have, as RequireWellFormedSlug requires it on the server. The checks and the step of the
// addon read an entry.create document with them; the kernel knows no content type, so the addon
// decides which documents are its own by the type id. A check runs on the draft as the viewer
// edits it, which may still lack members the schema requires, so each reader guards what it reads.

import type { EntryCreateV1 } from '../generated/contributions';

/** The draft of entry.create as the form holds it while it is edited: any member may be missing. */
export type Draft = { readonly [K in keyof EntryCreateV1]?: EntryCreateV1[K] };

/** The type id of app:fixture_article, as FixtureArticle::TYPE_ID names it. */
export const ARTICLE_TYPE = '01a0df3e-8cef-7e9f-8daf-9faa60f1faa6';

/** The path of the addon's field in a command document, as FieldPath::toString() writes it. */
export const SLUG_PATH = 'fields.ext.fixtureaddon.fixture_slug';

/** The path of the owner's title, which the slug is derived from. */
export const TITLE_PATH = 'fields.fixture_title';

/** The longest slug, the blueprint's max_length, as DeriveSlug::MAX_LENGTH cuts it. */
export const MAX_LENGTH = 120;

/** A well-formed slug, as RequireWellFormedSlug::SHAPE requires it. */
export const SHAPE = /^[a-z0-9]+(-[a-z0-9]+)*$/;

/**
 * The slug of a title, as DeriveSlug::slugOf() derives it: lower case, every run of other
 * characters than a to z and 0 to 9 as one hyphen, without hyphens at its ends, and at most
 * MAX_LENGTH characters; null for a title with no letter or digit.
 */
export function slugOf(title: string): string | null {
  const slug = trimHyphens(
    trimHyphens(title.toLowerCase().replaceAll(/[^a-z0-9]+/g, '-')).slice(0, MAX_LENGTH),
  );

  return slug === '' ? null : slug;
}

function trimHyphens(text: string): string {
  return text.replaceAll(/^-+|-+$/g, '');
}

/** Whether the document creates an entry of the addon's type. */
export function isArticle(document: Draft): boolean {
  return document.type === ARTICLE_TYPE;
}

/** The owner's title of the document, or undefined when it has none. */
export function titleOf(document: Draft): string | undefined {
  const title = document.fields?.fixture_title;

  return typeof title === 'string' ? title : undefined;
}

/** The addon's slug of the document, or undefined when it sets none. */
export function slugIn(document: Draft): string | undefined {
  const own = document.fields?.ext?.fixtureaddon;
  const slug = own === undefined ? undefined : own.fixture_slug;

  return typeof slug === 'string' ? slug : undefined;
}
