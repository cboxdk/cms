// The rules of a command's document for the generated runtime validator (GUARDRAILS 2.2, PRD 6.1):
// the form the kit reads from the command's JSON Schema (readCommandSchema) as the ObjectRule the
// generated TypeScript validators are made of, so the command form checks a document in the
// browser with the same runtime, validation.ts, and the same rules the kernel's codec checks it
// with, before it submits. The rules match what cms:generate emits for the schema: a string with a
// pattern is the string rule with its pattern and lengths, one with the date-time format the
// datetime rule, any other string the text rule with its lengths, an integer the integer rule with
// its bounds, an enum the enum rule, a nested object an object rule, a list a list rule, and the
// fields of a revision the fields rule; a required key that is not nullable is required, a
// required nullable one present, a key with a default omittable, and a nullable one with a default
// optional. Here too, the errors the server puts in the page's errors prop, by the path of each
// value below `command`, are read back by their path in the document.

import type { FieldShape, FormMember, FormModel, ObjectShape } from '@cboxdk/cms-ui-kit';

import {
  validate,
  type ObjectRule,
  type Presence,
  type PropertyRule,
  type ValueRule,
} from '../generated/validation';

/** The rules of the document of the form, as validate() checks them. */
export function rulesOf(model: FormModel): ObjectRule {
  return objectRule(model.root);
}

function objectRule(object: ObjectShape): ObjectRule {
  return { properties: object.members.map(propertyRule) };
}

function propertyRule(member: FormMember): PropertyRule {
  return { key: member.key, presence: presenceOf(member), value: valueRule(member.shape) };
}

function presenceOf(member: FormMember): Presence {
  if (member.required) {
    return member.nullable ? 'present' : 'required';
  }

  return member.nullable ? 'optional' : 'omittable';
}

function valueRule(shape: FieldShape): ValueRule {
  switch (shape.kind) {
    case 'string':
      if (shape.pattern !== undefined) {
        return {
          kind: 'string',
          pattern: shape.pattern,
          ...(shape.minLength === undefined ? {} : { minLength: shape.minLength }),
          ...(shape.maxLength === undefined ? {} : { maxLength: shape.maxLength }),
        };
      }

      if (shape.format === 'date-time') {
        return { kind: 'datetime' };
      }

      return {
        kind: 'text',
        ...(shape.minLength === undefined ? {} : { minLength: shape.minLength }),
        ...(shape.maxLength === undefined ? {} : { maxLength: shape.maxLength }),
      };
    case 'integer':
      return {
        kind: 'integer',
        ...(shape.minimum === undefined ? {} : { min: shape.minimum }),
        ...(shape.maximum === undefined ? {} : { max: shape.maximum }),
      };
    case 'boolean':
      return { kind: 'boolean' };
    case 'enum':
      return { kind: 'enum', values: shape.values };
    case 'object':
      return { kind: 'object', object: objectRule(shape) };
    case 'list':
      return {
        kind: 'list',
        item: valueRule(shape.item),
        ...(shape.minItems === undefined ? {} : { minItems: shape.minItems }),
        ...(shape.maxItems === undefined ? {} : { maxItems: shape.maxItems }),
      };
    case 'fields':
      return { kind: 'fields' };
  }
}

/** The rule of a document that holds the fields of a revision alone, under FIELDS_KEY. */
const FIELDS_KEY = 'fields';

const FIELDS_RULE: ObjectRule = {
  properties: [{ key: FIELDS_KEY, presence: 'required', value: { kind: 'fields' } }],
};

/**
 * What is wrong with a value that is to be the fields of a revision, as the runtime's fields rule
 * sees it: the reason of the first issue, or nothing when the value is accepted.
 */
export function fieldValuesIssue(value: unknown): string | undefined {
  const checked = validate({ [FIELDS_KEY]: value }, FIELDS_RULE);

  return checked.valid ? undefined : checked.issue.reason;
}

/** The member of the request body the command's document is sent under. */
const COMMAND_MEMBER = 'command.';

/** The member of the request body the envelope is sent under. */
const ENVELOPE_MEMBER = 'envelope.';

/** The path of an error about the form as a whole, the document itself or the envelope. */
export const FORM_PATH = '';

/**
 * The errors of a rejected submit as the Inertia profile left them in the page's errors prop, by
 * the path of each value in the request body, read back by the value's path in the document:
 * `command.window.live_from` is `window.live_from`, and an error about the envelope or about the
 * document as a whole is at FORM_PATH. Anything else in the prop is left out.
 */
export function serverErrors(errors: unknown): Readonly<Record<string, string>> {
  const found: Record<string, string> = {};

  if (typeof errors !== 'object' || errors === null) {
    return found;
  }

  for (const [path, message] of Object.entries(errors)) {
    if (typeof message !== 'string') {
      continue;
    }

    if (path.startsWith(COMMAND_MEMBER)) {
      found[path.slice(COMMAND_MEMBER.length)] = message;
    } else if (path === COMMAND_MEMBER.slice(0, -1) || path.startsWith(ENVELOPE_MEMBER)) {
      found[FORM_PATH] = message;
    }
  }

  return found;
}
