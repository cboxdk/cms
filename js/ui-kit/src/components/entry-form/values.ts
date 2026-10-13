// The values an entry form starts with and keeps (PRD 11.12, gap G3): the defaults of the
// descriptor on a new entry and on a new item of a repeated group, constants as the record's JSON
// form holds them and the tokens today and now from the clock, and the values of conditional
// fields marked when_hidden clear, which the form takes out while their condition does not hold,
// as the kernel's validators refuse a value on such a field.

import type { EditorFieldV1, TypeEditorV1 } from '../../generated/protocol/TypeEditorV1';
import { conditionHolds, type ConditionRule } from '../../generated/protocol/validation';
import type { JsonObject, JsonValue } from '../schema-form/model';
import { EXTENSIONS_KEY, fieldsBelow, isObject, topLevelKeys, without, withValueAt } from './model';

/** The objects a condition reads, the outermost first and the object of the field last. */
type Scopes = readonly Readonly<Record<string, unknown>>[];

function twoDigits(value: number): string {
  return String(value).padStart(2, '0');
}

/**
 * The day of the instant in the reader's time zone, `YYYY-MM-DD`, as the token today gives it.
 *
 * @experimental
 */
export function localDate(now: Date): string {
  return `${String(now.getFullYear()).padStart(4, '0')}-${twoDigits(now.getMonth() + 1)}-${twoDigits(now.getDate())}`;
}

/**
 * The instant as a date-time of RFC 3339 in the reader's time zone, to the second, with its offset,
 * such as `2026-10-13T09:30:00+02:00`, as the token now gives it.
 *
 * @experimental
 */
export function localDateTime(now: Date): string {
  const offset = -now.getTimezoneOffset();
  const sign = offset < 0 ? '-' : '+';
  const minutes = Math.abs(offset);

  return `${localDate(now)}T${twoDigits(now.getHours())}:${twoDigits(now.getMinutes())}:${twoDigits(now.getSeconds())}${offset === 0 ? 'Z' : `${sign}${twoDigits(Math.floor(minutes / 60))}:${twoDigits(minutes % 60)}`}`;
}

/** The value a field starts with, from its default, or undefined when it has none. */
function defaultOf(field: EditorFieldV1, now: Date): JsonValue | undefined {
  const given = field.default;

  if (given === null) {
    return undefined;
  }

  if (given['kind'] === 'token') {
    return given['token'] === 'now' ? localDateTime(now) : localDate(now);
  }

  return given['value'] as JsonValue;
}

/**
 * The value of a group as a form starts it: the defaults of the fields below it, a required group
 * below it set the same way, and a required repeated group below it with the fewest items it takes.
 */
function groupStart(descriptor: TypeEditorV1, group: EditorFieldV1, now: Date): JsonObject {
  const value: Record<string, JsonValue> = {};

  for (const field of fieldsBelow(descriptor, group.path)) {
    const start = startOf(descriptor, field, now);

    if (start !== undefined) {
      value[field.handle] = start;
    }
  }

  return value;
}

function startOf(descriptor: TypeEditorV1, field: EditorFieldV1, now: Date): JsonValue | undefined {
  if (field.base !== 'group') {
    return defaultOf(field, now);
  }

  if (field.presence !== 'required') {
    return undefined;
  }

  if (field.repeat === null) {
    return groupStart(descriptor, field, now);
  }

  return Array.from({ length: field.repeat.min_items ?? 0 }, () =>
    groupStart(descriptor, field, now),
  );
}

/**
 * The fields of a new entry of the type: each field's default, constants as the record's JSON form
 * holds them and the tokens today and now from the clock given, a required group with the defaults
 * of its fields and a required repeated group with the fewest items it takes; then without the
 * values of the conditional fields marked clear that are hidden.
 *
 * @experimental
 */
export function newEntryFields(descriptor: TypeEditorV1, now: Date): JsonObject {
  let value: JsonObject = {};

  for (const field of fieldsBelow(descriptor, null)) {
    const start = startOf(descriptor, field, now);

    if (start !== undefined) {
      value = withValueAt(value, topLevelKeys(field), start);
    }
  }

  return clearHidden(descriptor, value);
}

/**
 * A new item of a repeated group, or the value of a group that is set: the defaults of the fields
 * below it.
 *
 * @experimental
 */
export function newGroupValue(
  descriptor: TypeEditorV1,
  group: EditorFieldV1,
  now: Date,
): JsonObject {
  return groupStart(descriptor, group, now);
}

/**
 * Whether a field is shown, given the objects its condition reads: always for a field without a
 * condition.
 */
export function isShown(field: EditorFieldV1, scopes: Scopes): boolean {
  return field.when === null || conditionHolds(field.when as unknown as ConditionRule, scopes);
}

/**
 * The object without the values of its hidden fields marked clear, and the same in each object
 * below it; the same object when nothing is taken out.
 */
function clearedIn(
  descriptor: TypeEditorV1,
  object: JsonObject,
  fields: readonly EditorFieldV1[],
  ancestors: Scopes,
): JsonObject {
  const scopes = [...ancestors, object];
  let changed = false;
  let copy: JsonObject = object;

  for (const field of fields) {
    if (!Object.hasOwn(copy, field.handle)) {
      continue;
    }

    const value = copy[field.handle];

    if (field.when_hidden === 'clear' && value !== null && !isShown(field, scopes)) {
      copy = without(copy, field.handle);
      changed = true;

      continue;
    }

    if (field.base !== 'group' || value === undefined) {
      continue;
    }

    const below = fieldsBelow(descriptor, field.path);
    const next = Array.isArray(value)
      ? listCleared(descriptor, value, below, scopes)
      : isObject(value)
        ? clearedIn(descriptor, value, below, scopes)
        : value;

    if (next !== value) {
      copy = { ...copy, [field.handle]: next };
      changed = true;
    }
  }

  return changed ? copy : object;
}

function listCleared(
  descriptor: TypeEditorV1,
  items: readonly JsonValue[],
  fields: readonly EditorFieldV1[],
  scopes: Scopes,
): readonly JsonValue[] {
  const next = items.map((item) =>
    isObject(item) ? clearedIn(descriptor, item, fields, scopes) : item,
  );

  return next.some((item, index) => item !== items[index]) ? next : items;
}

/** One pass over the fields: the owner's fields, then each extender's below `ext`. */
function clearedOnce(descriptor: TypeEditorV1, value: JsonObject): JsonObject {
  const top = fieldsBelow(descriptor, null);
  let next = clearedIn(
    descriptor,
    value,
    top.filter((field) => field.namespace === null),
    [],
  );
  const extensions = next[EXTENSIONS_KEY];

  if (!isObject(extensions)) {
    return next;
  }

  for (const [namespace, fields] of Object.entries(extensions)) {
    if (!isObject(fields)) {
      continue;
    }

    const cleared = clearedIn(
      descriptor,
      fields,
      top.filter((field) => field.namespace === namespace),
      [next, extensions],
    );

    if (cleared !== fields) {
      next = withValueAt(next, [EXTENSIONS_KEY, namespace], cleared);
    }
  }

  return next;
}

/**
 * The fields without the values of the conditional fields marked when_hidden clear whose condition
 * does not hold, in every object of them, until none is left: taking out one value can hide
 * another field. The same object when nothing is taken out.
 *
 * @experimental
 */
export function clearHidden(descriptor: TypeEditorV1, value: JsonObject): JsonObject {
  let current = value;

  for (let pass = 0; pass <= descriptor.fields.length; pass++) {
    const next = clearedOnce(descriptor, current);

    if (next === current) {
      return current;
    }

    current = next;
  }

  return current;
}
