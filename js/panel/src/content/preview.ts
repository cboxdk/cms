// What the editor's Preview shows (PRD 5.4, 8.9, 13.4): the record entry.preview answered with, the
// JSON the delivery API would send for the draft or the revision chosen, as a document a person
// reads. Each field of the type's editor descriptor the record holds is an item, labelled in the
// locale the preview is read in and with its value written for that locale: a text as it is, a
// number, a date or a moment in the locale's form, a choice by its label, rich text as its
// paragraphs, a list item by item and a group by its own fields. A field the record does not hold,
// because it is above public or empty, is left out, as the delivery API leaves it out. Nothing here
// names a type or a field (GUARDRAILS 2.4).

import { editorText } from '@cboxdk/cms-ui-kit';

import type { EditorFieldV1, TypeEditorV1 } from '../generated/protocol/TypeEditorV1';
import type { JsonObject } from '../generated/validation';

/** A value of the record as the preview shows it. */
export type PreviewValue =
  | { readonly kind: 'text'; readonly text: string }
  | { readonly kind: 'yes_no'; readonly value: boolean }
  | { readonly kind: 'paragraphs'; readonly paragraphs: readonly string[] }
  | { readonly kind: 'list'; readonly items: readonly PreviewValue[] }
  | { readonly kind: 'group'; readonly items: readonly PreviewItem[] };

/** A field of the record with its label and value. */
export interface PreviewItem {
  /** The field's path in the descriptor, unique in the type. */
  readonly id: string;
  readonly label: string;
  readonly value: PreviewValue;
}

/** The record's document as the preview shows it: its title, when the type names a title field, and its items. */
export interface PreviewDocument {
  readonly title: string | null;
  readonly items: readonly PreviewItem[];
}

/** The widths the preview is read at: a phone, a column of reading width and a wide screen. */
export const PREVIEW_WIDTHS = ['phone', 'reading', 'wide'] as const;

export type PreviewWidth = (typeof PREVIEW_WIDTHS)[number];

/** Whether the value is a JSON object. */
function isObject(value: unknown): value is JsonObject {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/**
 * The locales the preview may be read in: the locale of the panel, then every locale the type's
 * descriptor has a label in, each once, sorted after the panel's.
 */
export function previewLocales(descriptor: TypeEditorV1, panel: string): readonly string[] {
  const named = new Set<string>();

  for (const text of [
    ...descriptor.labels,
    ...descriptor.fields.flatMap((field) => field.labels),
  ]) {
    named.add(text.locale);
  }

  named.delete(panel);

  return [panel, ...[...named].sort()];
}

/** The value a field has in the object that holds it: an owner's field by its handle, an extender's below ext. */
function valueIn(holder: JsonObject, field: EditorFieldV1, topLevel: boolean): unknown {
  if (!topLevel || field.namespace === null) {
    return holder[field.handle];
  }

  const ext = holder['ext'];
  const namespace = isObject(ext) ? ext[field.namespace] : undefined;

  return isObject(namespace) ? namespace[field.handle] : undefined;
}

/** The text of the paragraphs of a rich text value, each block's spans joined. */
function paragraphsOf(value: unknown): readonly string[] | null {
  if (!Array.isArray(value)) {
    return null;
  }

  return value.flatMap((block: unknown) => {
    if (!isObject(block) || !Array.isArray(block['children'])) {
      return [];
    }

    const text = block['children']
      .map((span: unknown) =>
        isObject(span) && typeof span['text'] === 'string' ? span['text'] : '',
      )
      .join('');

    return text === '' ? [] : [text];
  });
}

/** One value of a field, not a list of them, as the preview writes it in the locale. */
function single(
  descriptor: TypeEditorV1,
  field: EditorFieldV1,
  value: unknown,
  locale: string,
): PreviewValue | null {
  if (value === null || value === undefined) {
    return null;
  }

  switch (field.base) {
    case 'boolean':
      return typeof value === 'boolean' ? { kind: 'yes_no', value } : null;
    case 'integer':
      return typeof value === 'number'
        ? { kind: 'text', text: new Intl.NumberFormat(locale).format(value) }
        : null;
    case 'date': {
      const date = typeof value === 'string' ? new Date(`${value}T00:00:00Z`) : null;

      return date === null || Number.isNaN(date.getTime())
        ? null
        : {
            kind: 'text',
            text: new Intl.DateTimeFormat(locale, { dateStyle: 'long', timeZone: 'UTC' }).format(
              date,
            ),
          };
    }
    case 'datetime': {
      const moment = typeof value === 'string' ? new Date(value) : null;

      return moment === null || Number.isNaN(moment.getTime())
        ? null
        : {
            kind: 'text',
            text: new Intl.DateTimeFormat(locale, { dateStyle: 'long', timeStyle: 'short' }).format(
              moment,
            ),
          };
    }
    case 'select': {
      const choice = field.choices.find((candidate) => candidate.value === value);

      return typeof value === 'string'
        ? {
            kind: 'text',
            text: choice === undefined ? value : editorText(choice.label, choice.labels, locale),
          }
        : null;
    }
    case 'rich_text': {
      const paragraphs = paragraphsOf(value);

      return paragraphs === null || paragraphs.length === 0
        ? null
        : { kind: 'paragraphs', paragraphs };
    }
    case 'group': {
      if (!isObject(value)) {
        return null;
      }

      const items = itemsOf(descriptor, field.path, value, locale, false);

      return items.length === 0 ? null : { kind: 'group', items };
    }
    case 'decimal':
    case 'long_text':
    case 'relation':
    case 'text':
      return typeof value === 'string' && value !== '' ? { kind: 'text', text: value } : null;
  }
}

/** The value of a field, a list when the field holds several, or null when the record holds none. */
function valueOf(
  descriptor: TypeEditorV1,
  field: EditorFieldV1,
  value: unknown,
  locale: string,
): PreviewValue | null {
  if (Array.isArray(value) && field.base !== 'rich_text') {
    const items = value
      .map((item: unknown) => single(descriptor, field, item, locale))
      .filter((item): item is PreviewValue => item !== null);

    return items.length === 0 ? null : { kind: 'list', items };
  }

  return single(descriptor, field, value, locale);
}

/** The items of the fields below the parent, null for the top level, that the holder holds a value of. */
function itemsOf(
  descriptor: TypeEditorV1,
  parent: string | null,
  holder: JsonObject,
  locale: string,
  topLevel: boolean,
): readonly PreviewItem[] {
  return descriptor.fields.flatMap((field) => {
    if (field.parent !== parent || (topLevel && field.path === descriptor.title_field)) {
      return [];
    }

    const value = valueOf(descriptor, field, valueIn(holder, field, topLevel), locale);

    return value === null
      ? []
      : [{ id: field.path, label: editorText(field.label, field.labels, locale), value }];
  });
}

/** The document of the record in the locale: the title from the type's title field, and every other field it holds. */
export function previewDocument(
  descriptor: TypeEditorV1,
  record: JsonObject,
  locale: string,
): PreviewDocument {
  const title = descriptor.title_field === null ? undefined : record[descriptor.title_field];

  return {
    title: typeof title === 'string' && title.trim() !== '' ? title : null,
    items: itemsOf(descriptor, null, record, locale, true),
  };
}

/** The revisions the preview can show, the highest first: every number of the variant for a type with full history, the draft alone otherwise. */
export function previewRevisions(
  descriptor: TypeEditorV1,
  latest: number,
  draft: number,
): readonly number[] {
  if (descriptor.capabilities.history !== 'full') {
    return [draft];
  }

  return Array.from({ length: latest }, (_, index) => latest - index);
}
