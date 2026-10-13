// The client validation of an entry form (PRD 11.8, 11.12; GUARDRAILS 2.2): the rules of a type's
// fields as the generated validation runtime takes them, built from the descriptor's value rules,
// so the form checks a value with the same interpreter, validation.ts, the generated validators of
// the type's records use, and never with a validator of its own. Each leaf field of the descriptor
// carries its ValueRule as the record's TypeScript module declares it; a group's ObjectRule is
// made of the fields below it, a repeated group's ListRule of that and its repeat, and each field's
// presence and condition become its PropertyRule, as the kernel's InputValidator reads them: a
// field required on release is optional at a write, and a conditional field is never required
// while it is hidden. The issues carry the error catalog's codes, the codes the InputValidator
// gives the same values.

import type { EditorFieldV1, TypeEditorV1 } from '../../generated/protocol/TypeEditorV1';
import {
  validateAll,
  type ConditionRule,
  type IssueCode,
  type ObjectRule,
  type PropertyRule,
  type ValueRule,
} from '../../generated/protocol/validation';
import type { JsonObject } from '../schema-form/model';
import { EXTENSIONS_KEY, fieldsBelow, isObject } from './model';

/**
 * Which presence the client checks: `write`, as a command that writes a revision does, where a
 * field required on release may be empty, or `release`, where it may not.
 *
 * @experimental
 */
export type EntryStage = 'write' | 'release';

/**
 * A value of an entry's fields that breaks a rule of its type: its path, as the kernel's FieldPath
 * writes it with list indexes, the error catalog's code, which the kernel's InputValidator gives
 * the same value, and why, in English.
 *
 * @experimental
 */
export interface EntryIssue {
  /** The value, such as `dimensions[1].width`; null for the fields themselves. */
  readonly path: string | null;
  readonly code: IssueCode;
  readonly reason: string;
}

/** The rules of a type's fields, in the input form its validator reads. */
export function entryRule(descriptor: TypeEditorV1, stage: EntryStage): ObjectRule {
  const owner = fieldsBelow(descriptor, null).filter((field) => field.namespace === null);
  const extensions = new Map<string, EditorFieldV1[]>();

  for (const field of fieldsBelow(descriptor, null)) {
    if (field.namespace !== null) {
      extensions.set(field.namespace, [...(extensions.get(field.namespace) ?? []), field]);
    }
  }

  const properties = owner.map((field) => propertyOf(descriptor, field, stage));

  if (extensions.size > 0) {
    properties.push({
      key: EXTENSIONS_KEY,
      presence: 'optional',
      value: {
        kind: 'object',
        object: {
          properties: [...extensions.entries()]
            .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0))
            .map(([namespace, fields]) => ({
              key: namespace,
              presence: 'optional',
              value: { kind: 'object', object: objectRule(descriptor, fields, stage) },
            })),
        },
      },
    });
  }

  return { properties: sorted(properties) };
}

/**
 * Checks an entry's fields against the rules of its type with the generated validation runtime,
 * and gives every value that breaks one, in the order the runtime reaches them; empty when the
 * fields are valid. The fields are read with an object for each extender's namespace that has
 * none, so a field required on release that is missing is reported at its own path, such as
 * `ext.app.tax_code`, as the kernel's InputValidator reports it.
 *
 * @experimental
 */
export function validateEntry(
  descriptor: TypeEditorV1,
  fields: JsonObject,
  stage: EntryStage = 'write',
): readonly EntryIssue[] {
  return validateAll(withNamespaces(descriptor, fields), entryRule(descriptor, stage)).map(
    (issue) => ({ path: issue.path, code: issue.code, reason: issue.reason }),
  );
}

/** The fields with an empty object for each extender's namespace they have no object of. */
function withNamespaces(descriptor: TypeEditorV1, fields: JsonObject): JsonObject {
  const namespaces = [
    ...new Set(
      fieldsBelow(descriptor, null).flatMap((field) =>
        field.namespace === null ? [] : [field.namespace],
      ),
    ),
  ];
  const extensions = fields[EXTENSIONS_KEY];

  if (namespaces.length === 0 || (extensions !== undefined && !isObject(extensions))) {
    return fields;
  }

  const present: JsonObject = extensions ?? {};
  const missing: [string, JsonObject][] = namespaces
    .filter((namespace) => !Object.hasOwn(present, namespace))
    .map((namespace) => [namespace, {}]);

  return { ...fields, [EXTENSIONS_KEY]: { ...Object.fromEntries(missing), ...present } };
}

/** The properties by key, as the generated record modules declare them. */
function sorted(properties: readonly PropertyRule[]): readonly PropertyRule[] {
  return [...properties].sort((a, b) => (a.key < b.key ? -1 : a.key > b.key ? 1 : 0));
}

function objectRule(
  descriptor: TypeEditorV1,
  fields: readonly EditorFieldV1[],
  stage: EntryStage,
): ObjectRule {
  return { properties: sorted(fields.map((field) => propertyOf(descriptor, field, stage))) };
}

function propertyOf(
  descriptor: TypeEditorV1,
  field: EditorFieldV1,
  stage: EntryStage,
): PropertyRule {
  const required =
    field.presence === 'required' ||
    (stage === 'release' && field.presence === 'required_on_release');
  const value = valueOf(descriptor, field, stage);

  if (field.when === null) {
    return { key: field.handle, presence: required ? 'required' : 'optional', value };
  }

  return {
    key: field.handle,
    presence: 'optional',
    value,
    when: field.when as unknown as ConditionRule,
    whenHidden: field.when_hidden ?? 'keep',
    ...(required ? { requiredWhenShown: true } : {}),
  };
}

function valueOf(descriptor: TypeEditorV1, field: EditorFieldV1, stage: EntryStage): ValueRule {
  if (field.base !== 'group') {
    if (field.rule === null) {
      throw new TypeError(`The field ${field.path} of ${descriptor.name} has no value rule.`);
    }

    return field.rule as unknown as ValueRule;
  }

  const object: ValueRule = {
    kind: 'object',
    object: objectRule(descriptor, fieldsBelow(descriptor, field.path), stage),
  };

  if (field.repeat === null) {
    return object;
  }

  return {
    kind: 'list',
    item: object,
    ...(field.repeat.min_items === null ? {} : { minItems: field.repeat.min_items }),
    ...(field.repeat.max_items === null ? {} : { maxItems: field.repeat.max_items }),
  };
}
