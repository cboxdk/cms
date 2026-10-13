// What the entry editor shows when a save was refused with version_conflict (PRD 5.4, 6.1): someone
// else saved the entry after the person opened it, and the kernel refused to overwrite their
// change. The editor reads the entry again, says who saved and when, and compares the person's
// edits with what was saved per top-level field of the type, the owner's and each extender's, so
// the person can take the saved version and bring back their own edit of each field they choose.
// Nothing here names a type or a field (GUARDRAILS 2.4): the fields are the descriptor's.

import { editorText, type JsonObject, type JsonValue } from '@cboxdk/cms-ui-kit';

import type { EntryHeadV1 } from '../generated/protocol/EntryDetailV1';
import type { ProblemV1 } from '../generated/protocol/ProblemV1';
import type { TypeEditorV1 } from '../generated/protocol/TypeEditorV1';

/** The catalog code of a save refused because the entry changed after it was read. */
export const VERSION_CONFLICT = 'version_conflict';

/** Whether the problem details are of a save refused because someone else saved in between. */
export function isVersionConflict(problem: ProblemV1 | null): boolean {
  return (
    problem !== null &&
    (problem.code === VERSION_CONFLICT ||
      problem.errors.some((error) => error.code === VERSION_CONFLICT))
  );
}

/** One field whose saved value and the person's edit differ. */
export interface FieldDifference {
  /** The field's path in the form, its handle or `ext.<namespace>.<handle>`. */
  readonly path: string;
  /** The field's label in the reader's locale. */
  readonly label: string;
  /** The value someone else saved, undefined when it is empty. */
  readonly theirs: JsonValue | undefined;
  /** The person's edit, undefined when it is empty. */
  readonly mine: JsonValue | undefined;
}

/** The value of a top-level field at its path in the fields, undefined when there is none. */
export function fieldValue(fields: JsonObject, path: string): JsonValue | undefined {
  const segments = path.split('.');

  if (segments.length === 3 && segments[0] === 'ext') {
    const extension = fields['ext'];
    const namespace = isObject(extension) ? extension[segments[1] ?? ''] : undefined;

    return isObject(namespace) ? namespace[segments[2] ?? ''] : undefined;
  }

  return fields[path];
}

/** The fields with the value at the path of a top-level field, or without it for undefined. */
export function withFieldValue(
  fields: JsonObject,
  path: string,
  value: JsonValue | undefined,
): JsonObject {
  const segments = path.split('.');

  if (segments.length === 3 && segments[0] === 'ext') {
    const ns = segments[1] ?? '';
    const handle = segments[2] ?? '';
    const extension = isObject(fields['ext']) ? fields['ext'] : {};
    const namespace = isObject(extension[ns]) ? extension[ns] : {};
    const rest = without(namespace, handle);

    return {
      ...fields,
      ext: { ...extension, [ns]: value === undefined ? rest : { ...rest, [handle]: value } },
    };
  }

  const rest = without(fields, path);

  return value === undefined ? rest : { ...rest, [path]: value };
}

/** The object without the member. */
function without(object: JsonObject, key: string): JsonObject {
  return Object.fromEntries(Object.entries(object).filter(([member]) => member !== key));
}

function isObject(value: unknown): value is JsonObject {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/**
 * A value as JSON with its keys sorted and its empty members left out, so two equal values compare
 * equal and an empty value, none, null, an empty text, list or object, is the empty string.
 */
function canonical(value: JsonValue | undefined): string {
  if (value === undefined || value === null || value === '') {
    return '';
  }

  if (Array.isArray(value)) {
    const items = value as readonly JsonValue[];

    return items.length === 0 ? '' : `[${items.map((item) => canonical(item)).join(',')}]`;
  }

  if (isObject(value)) {
    const members = Object.keys(value)
      .sort()
      .map((key) => [key, canonical(value[key])] as const)
      .filter(([, text]) => text !== '');

    return members.length === 0
      ? ''
      : `{${members.map(([key, text]) => `${JSON.stringify(key)}:${text}`).join(',')}}`;
  }

  return JSON.stringify(value);
}

/** Whether a value counts as empty: none, null, an empty text or an empty list. */
export function isEmptyValue(value: JsonValue | undefined): boolean {
  return canonical(value) === '';
}

/**
 * The top-level fields, the owner's in form order and then each extender's, whose value someone
 * else saved differs from the person's edit.
 */
export function fieldDifferences(
  descriptor: TypeEditorV1,
  theirs: JsonObject,
  mine: JsonObject,
  locale: string,
): FieldDifference[] {
  return descriptor.fields
    .filter((field) => field.parent === null)
    .flatMap((field) => {
      const saved = fieldValue(theirs, field.path);
      const edited = fieldValue(mine, field.path);

      return canonical(saved) === canonical(edited)
        ? []
        : [
            {
              path: field.path,
              label: editorText(field.label, field.labels, locale),
              theirs: isEmptyValue(saved) ? undefined : saved,
              mine: isEmptyValue(edited) ? undefined : edited,
            },
          ];
    });
}

/** A value as one line of text to compare: a text as it is, anything else as compact JSON. */
export function valueText(value: JsonValue | undefined): string | null {
  if (value === undefined || isEmptyValue(value)) {
    return null;
  }

  return typeof value === 'string' ? value : JSON.stringify(value);
}

/** Whether a head read again is newer than the head the person's edits were made on. */
export function isNewerHead(read: EntryHeadV1 | null, base: EntryHeadV1 | null): boolean {
  return read !== null && base !== null && read.version > base.version;
}

/** Whether two sets of fields hold the same values, empty values counting as equal. */
export function sameFields(left: JsonObject, right: JsonObject): boolean {
  return canonical(left) === canonical(right);
}

/** A time a head was changed at, as the reader's locale writes a date and a time. */
export function changedAtText(at: string, locale: string): string {
  return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short' }).format(
    new Date(at),
  );
}
