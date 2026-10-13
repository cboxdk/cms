// The model of a form rendered from a type editor descriptor (PRD 11.12, 14.1; GUARDRAILS 2.4):
// what EntryForm reads from the descriptor, type-editor.v1.json as the TypeScript composer
// generate:protocol writes into js/ui-kit/src/generated/protocol types it, so the form names no
// type and no field. A descriptor's fields are a flat list in form order, a nested field naming its
// group's path as parent; the form puts the values in the input form the type's validator reads:
// the owner's fields by handle, a group's fields in an object below it, the items of a repeated
// group in a list, and each extender's fields under `ext` by its namespace.

import type {
  EditorFieldV1,
  EditorTextV1,
  TypeEditorV1,
} from '../../generated/protocol/TypeEditorV1';
import type { JsonObject, JsonValue } from '../schema-form/model';

/** The key of the extension fields in the fields of an entry. */
export const EXTENSIONS_KEY = 'ext';

/**
 * A text of the descriptor in the reader's locale: the text of the locale itself, else of its
 * language (`da` for `da-DK`, or the first `da-…` for `da`), else the text the blueprint gives
 * without a locale.
 *
 * @experimental
 */
export function editorText(
  fallback: string,
  texts: readonly EditorTextV1[],
  locale: string,
): string {
  const wanted = locale.toLowerCase();
  const exact = texts.find((text) => text.locale.toLowerCase() === wanted);

  if (exact !== undefined) {
    return exact.text;
  }

  const language = wanted.split('-')[0] ?? wanted;
  const near = texts.find((text) => (text.locale.toLowerCase().split('-')[0] ?? '') === language);

  return near?.text ?? fallback;
}

/** An optional text of the descriptor in the reader's locale, or undefined when it has none. */
export function optionalEditorText(
  fallback: string | null,
  texts: readonly EditorTextV1[],
  locale: string,
): string | undefined {
  if (fallback === null && texts.length === 0) {
    return undefined;
  }

  return editorText(fallback ?? texts[0]?.text ?? '', texts, locale);
}

/** The fields directly below a group, by its path, or the top-level fields for null, in form order. */
export function fieldsBelow(
  descriptor: TypeEditorV1,
  parent: string | null,
): readonly EditorFieldV1[] {
  return descriptor.fields.filter((field) => field.parent === parent);
}

/** The field with the path, or undefined when the descriptor has none. */
export function fieldAt(descriptor: TypeEditorV1, path: string): EditorFieldV1 | undefined {
  return descriptor.fields.find((field) => field.path === path);
}

/** Whether the field holds a list of items: a group that repeats. */
export function isRepeated(field: EditorFieldV1): boolean {
  return field.base === 'group' && field.repeat !== null;
}

/**
 * Where a top-level field's value is in the fields of an entry: its handle for a field of the
 * owner, and `ext`, the namespace and the handle for an extension field.
 */
export function topLevelKeys(field: EditorFieldV1): readonly string[] {
  return field.namespace === null
    ? [field.handle]
    : [EXTENSIONS_KEY, field.namespace, field.handle];
}

/** Whether the value is a JSON object. */
export function isObject(value: unknown): value is JsonObject {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** The object below the keys, or an empty one when there is none. */
export function objectAt(value: JsonObject, keys: readonly string[]): JsonObject {
  let current: JsonObject = value;

  for (const key of keys) {
    const next = current[key];
    current = isObject(next) ? next : {};
  }

  return current;
}

/** The value below the keys, or undefined when there is none. */
export function valueAt(value: JsonObject, keys: readonly string[]): JsonValue | undefined {
  const last = keys.at(-1);

  if (last === undefined) {
    return value;
  }

  const holder = objectAt(value, keys.slice(0, -1));

  return Object.hasOwn(holder, last) ? holder[last] : undefined;
}

/**
 * The fields with the value below the keys set, or left out for undefined; an object on the way
 * that is left empty is left out too, so the fields hold no empty `ext`.
 */
export function withValueAt(
  value: JsonObject,
  keys: readonly string[],
  next: JsonValue | undefined,
): JsonObject {
  const [key, ...rest] = keys;

  if (key === undefined) {
    return isObject(next) ? next : value;
  }

  const inner = rest.length === 0 ? next : withValueAt(objectAt(value, [key]), rest, next);

  if (
    inner === undefined ||
    (rest.length > 0 && isObject(inner) && Object.keys(inner).length === 0)
  ) {
    return without(value, key);
  }

  return { ...value, [key]: inner };
}

/** The object without the key. */
export function without(value: JsonObject, key: string): JsonObject {
  return Object.fromEntries(Object.entries(value).filter(([current]) => current !== key));
}
