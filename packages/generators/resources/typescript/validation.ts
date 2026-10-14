// The runtime of the TypeScript validators that cms:generate writes (PRD 11.12, GUARDRAILS 2.2):
// the rules of a contract version as data, and one function that checks a JSON value against them.
// Each generated module declares the JSON form of its contract as TypeScript types and its rules as
// an ObjectRule, and its validator calls validate() with them, so every rule is checked here, the
// way the kernel's generated PHP codecs check it with JsonValues.
//
// It checks a value that JSON.parse gave, and needs nothing but the language: no package, no
// Node API and no DOM API but URL and TextEncoder, which every browser and Node have.
//
// cms:generate writes this module unchanged next to the modules it generates, from
// packages/generators/resources/typescript/validation.ts of cboxdk/cms. Change it there: every run
// of cms:generate writes it again.

/** Where a value is: the keys and list indexes from the document to it. */
export type Path = readonly (string | number)[];

/**
 * The code of the kernel's error catalog for a value that breaks a rule, the code the kernel's
 * InputValidator gives the same value of a type's fields (PRD 11.8): validation_required for a
 * value that is missing or null where one is needed, validation_wrong_type for a value of another
 * kind, and one code per rule for the others.
 */
export type IssueCode =
  | 'validation_required'
  | 'validation_wrong_type'
  | 'validation_unknown_field'
  | 'validation_too_short'
  | 'validation_too_long'
  | 'validation_invalid_format'
  | 'validation_below_minimum'
  | 'validation_above_maximum'
  | 'validation_too_many_digits'
  | 'validation_not_an_option'
  | 'validation_too_few_items'
  | 'validation_too_many_items'
  | 'validation_duplicate_item'
  | 'validation_invalid_rich_text'
  | 'validation_rich_text_not_allowed'
  | 'validation_hidden_field';

/** A value of a document that breaks its contract, and why. */
export interface ValidationIssue {
  /**
   * The value, as the kernel's FieldPath writes it: names joined by dots and list indexes in
   * brackets, such as `fixture_sources[1].fixture_source_url`. Null is the document itself.
   */
  readonly path: string | null;
  /** The rule it breaks, as a code of the kernel's error catalog. */
  readonly code: IssueCode;
  readonly reason: string;
}

/** A value that is valid, typed as its contract, or the first issue that makes it invalid. */
export type Validation<T> =
  | { readonly valid: true; readonly value: T }
  | { readonly valid: false; readonly issue: ValidationIssue };

/**
 * Whether a key of an object must be there and whether its value may be null:
 *
 * - `required`: the key is there and its value is not null;
 * - `present`: the key is there, and its value may be null;
 * - `optional`: the key may be missing, and its value may be null;
 * - `omittable`: the key may be missing, and its value is never null. A key with a default is
 *   omittable, and so is a field classified above public, which the kernel leaves out for a caller
 *   whose classification access does not allow it.
 */
export type Presence = 'required' | 'present' | 'optional' | 'omittable';

/** Text; its length is counted in characters, as Postgres counts it. */
export interface TextRule {
  readonly kind: 'text';
  readonly minLength?: number;
  readonly maxLength?: number;
  readonly format?: 'email' | 'url';
}

/** A JSON integer that a JavaScript number holds exactly. */
export interface IntegerRule {
  readonly kind: 'integer';
  readonly min?: number;
  readonly max?: number;
}

/** A decimal number in a string, such as "12.50", that fits a column numeric(precision, scale). */
export interface DecimalRule {
  readonly kind: 'decimal';
  readonly precision: number;
  readonly scale: number;
  readonly min?: string;
  readonly max?: string;
}

export interface BooleanRule {
  readonly kind: 'boolean';
}

/** A full date of RFC 3339, `YYYY-MM-DD`. */
export interface DateRule {
  readonly kind: 'date';
  readonly min?: string;
  readonly max?: string;
}

/** A date-time of RFC 3339 with an offset and at most six decimals. */
export interface DatetimeRule {
  readonly kind: 'datetime';
  readonly min?: string;
  readonly max?: string;
}

/** One of the values of a select field. */
export interface ChoiceRule {
  readonly kind: 'choice';
  readonly choices: readonly string[];
}

/** A list of Portable Text blocks with the styles, decorators, list kinds and link kinds given. */
export interface PortableTextRule {
  readonly kind: 'portable_text';
  readonly styles: readonly string[];
  readonly marks: readonly string[];
  readonly lists: readonly string[];
  readonly links: readonly string[];
}

/** An object of the properties of an ObjectRule. */
export interface ObjectValueRule {
  readonly kind: 'object';
  readonly object: ObjectRule;
}

/** A list of one kind of item. */
export interface ListRule {
  readonly kind: 'list';
  readonly item: ValueRule;
  readonly minItems?: number;
  readonly maxItems?: number;
  readonly distinct?: boolean;
}

/**
 * A string of an id or a value object, in the pattern and lengths its JSON Schema gives. A string
 * that is not in that form is not a value of the kind, validation_wrong_type, as the kernel's
 * InputValidator refuses a relation's target that is not an entry's id. With caseless, upper and
 * lower case letters write one value, as for an entry's id, which the kernel holds in lower case,
 * so a distinct list compares such items in lower case.
 */
export interface StringRule {
  readonly kind: 'string';
  readonly pattern?: string;
  readonly minLength?: number;
  readonly maxLength?: number;
  readonly caseless?: boolean;
}

/**
 * The fields of a revision of any type, in the input form the type's validator reads: the owner's
 * fields by handle, and each extender's under `ext` by its namespace.
 */
export interface FieldsRule {
  readonly kind: 'fields';
}

/**
 * A JSON object of another contract, such as a record or a document of another kernel schema: an
 * object whose keys its own contract's validator checks.
 */
export interface DocumentRule {
  readonly kind: 'document';
}

/**
 * A link: an object of its kind, one of `kinds`, and its target, as the kernel's LinkInput reads it
 * (the contracts' Link).
 */
export interface LinkRule {
  readonly kind: 'link';
  readonly kinds: readonly LinkKind[];
}

/** One of the values of an enum. */
export interface EnumRule {
  readonly kind: 'enum';
  readonly values: readonly (string | number)[];
}

export type ValueRule =
  | TextRule
  | IntegerRule
  | DecimalRule
  | BooleanRule
  | DateRule
  | DatetimeRule
  | ChoiceRule
  | PortableTextRule
  | ObjectValueRule
  | ListRule
  | StringRule
  | LinkRule
  | EnumRule
  | FieldsRule
  | DocumentRule;

/** A value a condition compares a field with: text, a whole number or a boolean. */
export type ConditionValue = string | number | boolean;

/**
 * The field a condition reads: `key` in the object that holds the conditional field, or `up`
 * objects above it, an item of a repeated group being one object.
 */
export interface FieldReference {
  readonly up: number;
  readonly key: string;
}

/**
 * The condition of a conditional field (gap G3), typed and without expressions: a field compared
 * with `eq`, `neq` or `in`, asked for a value with `null` or `not_null`, or `all` or `any` of other
 * conditions. A field without a value, left out or null, equals nothing, so `neq` holds for it.
 */
export type ConditionRule =
  | { readonly op: 'eq' | 'neq'; readonly field: FieldReference; readonly value: ConditionValue }
  | {
      readonly op: 'in';
      readonly field: FieldReference;
      readonly values: readonly ConditionValue[];
    }
  | { readonly op: 'null' | 'not_null'; readonly field: FieldReference }
  | { readonly op: 'all' | 'any'; readonly conditions: readonly ConditionRule[] };

/**
 * A key of an object, in the order the kernel's codec reads it. A conditional key (gap G3) has its
 * condition, `when`, and what happens to its value while it is hidden, `whenHidden`: a hidden key
 * is never required, and holds no value when it is `clear`. `requiredWhenShown` says it needs a
 * value while it is shown.
 */
export interface PropertyRule {
  readonly key: string;
  readonly presence: Presence;
  readonly value: ValueRule;
  readonly when?: ConditionRule;
  readonly whenHidden?: 'clear' | 'keep';
  readonly requiredWhenShown?: boolean;
}

/** An object that has only the keys of its properties. */
export interface ObjectRule {
  readonly properties: readonly PropertyRule[];
}

/** A span of a Portable Text block: its text and its marks. */
export interface PortableTextSpan {
  readonly _type: 'span';
  readonly _key: string;
  readonly text: string;
  readonly marks?: readonly string[];
  readonly [key: string]: unknown;
}

/** A mark definition of a Portable Text block: a link to an absolute http or https URL. */
export interface PortableTextMarkDefinition {
  readonly _type: string;
  readonly _key: string;
  readonly href: string;
  readonly [key: string]: unknown;
}

/** A block of Portable Text (PRD 11.10). Other keys are kept, as Portable Text allows. */
export interface PortableTextBlock {
  readonly _type: 'block';
  readonly _key: string;
  readonly children: readonly PortableTextSpan[];
  readonly style?: string;
  readonly listItem?: string;
  readonly level?: number;
  readonly markDefs?: readonly PortableTextMarkDefinition[];
  readonly [key: string]: unknown;
}

/** What a link points at: an entry, a node of the structure or a URL. */
export type LinkKind = 'entry' | 'node' | 'url';

/** A relation a link to a URL declares, as the HTML attribute `rel` writes it. */
export type LinkRel = 'nofollow' | 'noopener' | 'noreferrer' | 'sponsored' | 'ugc';

/**
 * The value of a link field: an entry by its id, with the locale of the variant it points at or
 * none for the reader's language; a node by its id; or an absolute http or https URL of at most
 * 2048 characters with the rels it declares, each once.
 */
export type Link =
  | { readonly kind: 'entry'; readonly entry: string; readonly locale?: string }
  | { readonly kind: 'node'; readonly node: string }
  | { readonly kind: 'url'; readonly url: string; readonly rel?: readonly LinkRel[] };

/** The value of a rich text field: a list of Portable Text blocks. */
export type PortableText = readonly PortableTextBlock[];

/**
 * A field's value as JSON gives it: a string, an integer, a boolean, null, a list of values or an
 * object of values by key. A decimal, a date and a date-time are strings, and a group an object of
 * its fields.
 */
export type FieldValue =
  string | number | boolean | null | readonly FieldValue[] | { readonly [key: string]: FieldValue };

/**
 * A JSON object of another contract, whose own validator checks its keys, such as the record of a
 * type with the validator of its record contract.
 */
export type JsonObject = { readonly [key: string]: unknown };

/**
 * The fields of a revision of any type, in the input form the type's validator reads: the owner's
 * fields by handle, and each extender's fields under `ext` by its namespace. The kernel checks the
 * values against the type's schema when it writes them.
 */
export type FieldValues = { readonly [handle: string]: FieldValue } & {
  readonly ext?: { readonly [namespace: string]: { readonly [handle: string]: FieldValue } };
};

/**
 * Checks a JSON value against the rules of its contract, and gives it typed as the contract or the
 * first value that breaks a rule.
 */
export function validate<T>(value: unknown, rule: ObjectRule): Validation<T> {
  try {
    checkObject(value, [], rule);
    checkConditions(record(value, []), [], rule, []);
  } catch (error) {
    if (error instanceof Invalid) {
      return { valid: false, issue: error.issue() };
    }

    throw error;
  }

  return { valid: true, value: value as T };
}

/**
 * Checks a JSON value against the rules of its contract, as validate() does, and gives every value
 * that breaks one instead of the first: one issue per key of each object and per item of each
 * list, in the order validate() reaches them, the first being the one validate() gives. A key whose
 * value breaks a rule has no issue of its condition. Empty when the value is valid.
 */
export function validateAll(value: unknown, rule: ObjectRule): readonly ValidationIssue[] {
  const issues: Invalid[] = [];

  try {
    checkObject(value, [], rule, issues);
  } catch (error) {
    if (error instanceof Invalid) {
      return [error.issue()];
    }

    throw error;
  }

  if (isRecord(value)) {
    checkConditions(value, [], rule, [], issues);
  }

  return issues.map((issue) => issue.issue());
}

/**
 * Runs a check, and records what it finds wrong when issues are collected, so the check of the
 * next key or item still runs; it throws when they are not.
 */
function collected(issues: Invalid[] | undefined, check: () => void): void {
  if (issues === undefined) {
    check();

    return;
  }

  try {
    check();
  } catch (error) {
    if (!(error instanceof Invalid)) {
      throw error;
    }

    issues.push(error);
  }
}

/**
 * Whether a condition holds for the objects that hold the conditional field: the object itself
 * last, and each object it is in before it, the outermost first.
 */
export function conditionHolds(
  condition: ConditionRule,
  scopes: readonly Readonly<Record<string, unknown>>[],
): boolean {
  switch (condition.op) {
    case 'all':
      return condition.conditions.every((inner) => conditionHolds(inner, scopes));
    case 'any':
      return condition.conditions.some((inner) => conditionHolds(inner, scopes));
    case 'null':
      return referenced(condition.field, scopes) === null;
    case 'not_null':
      return referenced(condition.field, scopes) !== null;
    case 'eq':
      return referenced(condition.field, scopes) === condition.value;
    case 'neq':
      return referenced(condition.field, scopes) !== condition.value;
    case 'in':
      return condition.values.some((value) => referenced(condition.field, scopes) === value);
  }
}

/** The value a reference names, or null when it has none. */
function referenced(
  field: FieldReference,
  scopes: readonly Readonly<Record<string, unknown>>[],
): unknown {
  const object = scopes[scopes.length - 1 - field.up];

  return object === undefined || !Object.hasOwn(object, field.key) ? null : object[field.key];
}

/**
 * Checks the conditions of the conditional keys once every value passed its rules, as the
 * kernel's codec does: each key before the keys of the objects it holds, the first key that
 * breaks one reported. A shown key with `requiredWhenShown` needs a value, and a hidden key of
 * `clear` has none.
 */
function checkConditions(
  object: Readonly<Record<string, unknown>>,
  at: Path,
  rule: ObjectRule,
  ancestors: readonly Readonly<Record<string, unknown>>[],
  issues?: Invalid[],
): void {
  const scopes = [...ancestors, object];

  for (const property of rule.properties) {
    const path = [...at, property.key];
    const value = Object.hasOwn(object, property.key) ? object[property.key] : null;
    const shown = property.when === undefined || conditionHolds(property.when, scopes);

    if (issues !== undefined && issues.some((issue) => within(issue.segments, path))) {
      continue;
    }

    if (value === null || value === undefined) {
      if (shown && property.requiredWhenShown === true) {
        collected(issues, () => {
          throw new Invalid(
            'validation_required',
            path,
            'is missing, and the field is shown and required',
          );
        });
      }

      continue;
    }

    if (!shown && property.whenHidden === 'clear') {
      collected(issues, () => {
        throw new Invalid(
          'validation_hidden_field',
          path,
          'has a value, and the field is hidden because its condition does not hold',
        );
      });

      continue;
    }

    const inner = property.value.kind === 'list' ? property.value.item : property.value;

    if (inner.kind !== 'object') {
      continue;
    }

    if (Array.isArray(value)) {
      const items: readonly unknown[] = value;
      items.forEach((item, index) => {
        if (isRecord(item)) {
          checkConditions(item, [...path, index], inner.object, scopes, issues);
        }
      });
    } else if (isRecord(value)) {
      checkConditions(value, path, inner.object, scopes, issues);
    }
  }
}

/** Whether a path is the other path or a path below it. */
function within(path: Path, other: Path): boolean {
  return other.length <= path.length && other.every((segment, index) => path[index] === segment);
}

/** The path as the kernel's FieldPath writes it, or null for the document itself. */
export function pathText(path: Path): string | null {
  let text = '';

  for (const segment of path) {
    text +=
      typeof segment === 'number' ? `[${String(segment)}]` : (text === '' ? '' : '.') + segment;
  }

  return path.length === 0 ? null : text;
}

class Invalid extends Error {
  readonly segments: Path;

  readonly code: IssueCode;

  readonly reason: string;

  constructor(code: IssueCode, path: Path, reason: string) {
    const text = pathText(path);
    super(text === null ? reason : `${text}: ${reason}`);
    this.segments = path;
    this.code = code;
    this.reason = reason;
  }

  issue(): ValidationIssue {
    return { path: pathText(this.segments), code: this.code, reason: this.reason };
  }
}

/** The start of a date in year zero, which Postgres does not have. */
const YEAR_ZERO = '0000-';

const DATE = /^([0-9]{4})-([0-9]{2})-([0-9]{2})$/;

const DATETIME =
  /^([0-9]{4})-([0-9]{2})-([0-9]{2})T([0-9]{2}):([0-9]{2}):([0-9]{2})(?:\.([0-9]{1,6}))?(Z|[+-](?:[01][0-9]|2[0-3]):[0-5][0-9])$/;

const DECIMAL = /^(-?)([0-9]+)(?:\.([0-9]+))?$/;

const SURROGATE_PAIR = /[\uD800-\uDBFF][\uDC00-\uDFFF]/g;

/** The longest key of an object in rich text, in bytes. */
const MAX_KEY_BYTES = 255;

/** A key that is a segment of a path. */
const NAME = /^[A-Za-z_][A-Za-z0-9_]*$/;

/** The key of the extension fields in an object of fields. */
const EXTENSIONS_KEY = 'ext';

/** A field handle: lowercase snake_case of at most 63 bytes, never `ext` or starting with `cms_`. */
const FIELD_HANDLE = /^[a-z][a-z0-9]*(_[a-z0-9]+)*$/;

const MAX_HANDLE_BYTES = 63;

/** A namespace of extension fields: 1 to 20 lowercase letters and digits, starting with a letter, never `ext`. */
const FIELD_NAMESPACE = /^[a-z][a-z0-9]{0,19}$/;

/** The `_type` of a mark definition for each link kind. */
const LINK_TYPES: Readonly<Record<string, string>> = { url: 'link' };

const WEB_URL = /^https?:\/\//i;

/** Visible ASCII: a URL of the url format has nothing else. */
const VISIBLE_ASCII = /^[!-~]+$/;

const HOST_NAME =
  /^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*$/;

const EMAIL_LOCAL_ATOM = "[A-Za-z0-9!#$%&'*+/=?^_`{|}~-]+";

const EMAIL_LOCAL = new RegExp(
  `^(?:${EMAIL_LOCAL_ATOM}(?:\\.${EMAIL_LOCAL_ATOM})*|"(?:[\\x21\\x23-\\x5b\\x5d-\\x7e]|\\\\[\\x20-\\x7e])*")$`,
);

const EMAIL_DOMAIN =
  /^(?:(?:(?:xn--)?[A-Za-z0-9]+(?:-+[A-Za-z0-9]+)*\.)+(?:[A-Za-z][A-Za-z0-9]*|xn--[A-Za-z0-9]+)(?:-+[A-Za-z0-9]+)*|\[(?:[0-9]{1,3}(?:\.[0-9]{1,3}){3}|IPv6:[0-9A-Fa-f:.]+)\])$/;

/** The characters of a text: its code points, as Postgres' char_length() counts them. */
function characters(value: string): number {
  return value.replace(SURROGATE_PAIR, ' ').length;
}

function isRecord(value: unknown): value is Readonly<Record<string, unknown>> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function checkObject(value: unknown, at: Path, rule: ObjectRule, issues?: Invalid[]): void {
  if (!isRecord(value)) {
    throw new Invalid(
      'validation_wrong_type',
      at,
      at.length === 0 ? 'the document is not a JSON object' : 'is not an object',
    );
  }

  const keys = rule.properties.map((property) => property.key);

  for (const key of Object.keys(value)) {
    if (!keys.includes(key)) {
      collected(issues, () => {
        throw new Invalid(
          'validation_unknown_field',
          at,
          `has the key "${key}", which is not a field of the contract`,
        );
      });
    }
  }

  for (const property of rule.properties) {
    collected(issues, () => {
      checkProperty(value, at, property, issues);
    });
  }
}

function checkProperty(
  object: Readonly<Record<string, unknown>>,
  at: Path,
  property: PropertyRule,
  issues?: Invalid[],
): void {
  const path = [...at, property.key];

  if (!Object.hasOwn(object, property.key)) {
    if (property.presence === 'required' || property.presence === 'present') {
      throw new Invalid('validation_required', path, 'is missing, and the field is required');
    }

    return;
  }

  const value = object[property.key];

  if (value === null) {
    if (property.presence === 'required' || property.presence === 'omittable') {
      throw new Invalid('validation_required', path, 'is null, and the field is not nullable');
    }

    return;
  }

  checkValue(value, path, property.value, issues);
}

function checkValue(value: unknown, at: Path, rule: ValueRule, issues?: Invalid[]): void {
  switch (rule.kind) {
    case 'text':
      checkText(value, at, rule);
      break;
    case 'integer':
      checkInteger(value, at, rule);
      break;
    case 'decimal':
      checkDecimal(value, at, rule);
      break;
    case 'boolean':
      if (typeof value !== 'boolean') {
        throw new Invalid('validation_wrong_type', at, 'is not a boolean');
      }
      break;
    case 'date':
      checkDate(value, at, rule);
      break;
    case 'datetime':
      checkDatetime(value, at, rule);
      break;
    case 'choice':
      if (typeof value !== 'string') {
        throw new Invalid('validation_wrong_type', at, 'is not a string');
      }

      if (!rule.choices.includes(value)) {
        throw new Invalid(
          'validation_not_an_option',
          at,
          `is not one of ${rule.choices.join(', ')}`,
        );
      }
      break;
    case 'portable_text':
      checkPortableText(value, at, rule);
      break;
    case 'object':
      checkObject(value, at, rule.object, issues);
      break;
    case 'list':
      checkList(value, at, rule, issues);
      break;
    case 'string':
      checkString(value, at, rule);
      break;
    case 'fields':
      checkFields(value, at);
      break;
    case 'document':
      if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        throw new Invalid('validation_wrong_type', at, 'is not an object');
      }
      break;
    case 'link':
      checkLink(value, at, rule);
      break;
    case 'enum':
      if (typeof value !== 'string' && typeof value !== 'number') {
        throw new Invalid('validation_wrong_type', at, 'is neither a string nor a number');
      }

      if (!rule.values.includes(value)) {
        throw new Invalid(
          'validation_not_an_option',
          at,
          `is not one of ${rule.values.map(String).join(', ')}`,
        );
      }
      break;
  }
}

function checkText(value: unknown, at: Path, rule: TextRule): void {
  if (typeof value !== 'string') {
    throw new Invalid('validation_wrong_type', at, 'is not a string');
  }

  const length = characters(value);

  if (rule.minLength !== undefined && length < rule.minLength) {
    throw new Invalid(
      'validation_too_short',
      at,
      `has ${String(length)} characters, fewer than the ${String(rule.minLength)} the field requires`,
    );
  }

  if (rule.maxLength !== undefined && length > rule.maxLength) {
    throw new Invalid(
      'validation_too_long',
      at,
      `has ${String(length)} characters, more than the ${String(rule.maxLength)} the field allows`,
    );
  }

  if (rule.format !== undefined && !matchesFormat(rule.format, value)) {
    throw new Invalid('validation_invalid_format', at, `is not in the format ${rule.format}`);
  }
}

/**
 * Whether the text is in the format: `email`, an address, or `url`, an absolute http or https URL
 * of visible ASCII with a host name or an IP address.
 */
function matchesFormat(format: 'email' | 'url', value: string): boolean {
  if (format === 'email') {
    const at = value.lastIndexOf('@');
    const local = value.slice(0, at);
    const domain = value.slice(at + 1);

    return (
      at > 0 &&
      value.length < 255 &&
      local.length <= 64 &&
      EMAIL_LOCAL.test(local) &&
      EMAIL_DOMAIN.test(domain) &&
      domain.split('.').every((label) => label.length < 64)
    );
  }

  if (!WEB_URL.test(value) || !VISIBLE_ASCII.test(value) || !URL.canParse(value)) {
    return false;
  }

  const host = new URL(value).hostname;

  return host.startsWith('[') || HOST_NAME.test(host);
}

/** The id of an entry or a node: a UUIDv7, in upper or lower case. */
const UUID7 =
  /^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-7[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/;

/** A locale: a BCP 47 tag of a language, an optional script and an optional region. */
const LOCALE = /^[a-z]{2,3}(?:-[a-z]{4})?(?:-(?:[a-z]{2}|[0-9]{3}))?$/i;

/** The most characters of a link's URL. */
const MAX_URL_LENGTH = 2048;

const LINK_RELS: readonly string[] = ['nofollow', 'noopener', 'noreferrer', 'sponsored', 'ugc'];

/** The members a link of each kind has. */
const LINK_MEMBERS: Readonly<Record<LinkKind, readonly string[]>> = {
  entry: ['kind', 'entry', 'locale'],
  node: ['kind', 'node'],
  url: ['kind', 'url', 'rel'],
};

/**
 * Checks a link in the order of the kernel's LinkInput, and throws the first rule it breaks: an
 * object; its kind, there, text and one the field allows; only the members of its kind; then its
 * target, an entry's or a node's id, or a URL of at most 2048 characters in the url format, with an
 * optional locale or list of rels. An optional member is left out, never null.
 */
function checkLink(value: unknown, at: Path, rule: LinkRule): void {
  if (!isRecord(value)) {
    throw new Invalid(
      'validation_wrong_type',
      at,
      'is not a link, an object of its kind and its target',
    );
  }

  const kind = value.kind;

  if (kind === undefined || kind === null) {
    throw new Invalid(
      'validation_required',
      [...at, 'kind'],
      'is missing; a link has the kind of its target',
    );
  }

  if (typeof kind !== 'string') {
    throw new Invalid('validation_wrong_type', [...at, 'kind'], 'is not text');
  }

  const allowed = rule.kinds.find((one) => one === kind);

  if (allowed === undefined) {
    throw new Invalid(
      'validation_not_an_option',
      [...at, 'kind'],
      `is not one of the kinds the field allows: ${rule.kinds.join(', ')}`,
    );
  }

  const members = LINK_MEMBERS[allowed];

  for (const key of Object.keys(value)) {
    if (!members.includes(key)) {
      throw new Invalid(
        'validation_unknown_field',
        at,
        `has the key "${key}", which a link to ${allowed === 'url' ? 'a URL' : `an ${allowed}`} does not have`,
      );
    }
  }

  if (allowed === 'entry' || allowed === 'node') {
    const id = value[allowed];

    if (id === undefined || id === null) {
      throw new Invalid(
        'validation_required',
        [...at, allowed],
        `is missing; a link to ${allowed === 'entry' ? 'an entry' : 'a node'} names it`,
      );
    }

    if (typeof id !== 'string' || !UUID7.test(id)) {
      throw new Invalid(
        'validation_wrong_type',
        [...at, allowed],
        `is not ${allowed === 'entry' ? "an entry's" : "a node's"} id, a UUIDv7`,
      );
    }

    if (allowed === 'entry' && Object.hasOwn(value, 'locale')) {
      const locale = value.locale;

      if (typeof locale !== 'string') {
        throw new Invalid('validation_wrong_type', [...at, 'locale'], 'is not text');
      }

      if (!LOCALE.test(locale)) {
        throw new Invalid(
          'validation_invalid_format',
          [...at, 'locale'],
          'is not a locale, a BCP 47 tag such as "da" or "en-GB"',
        );
      }
    }

    return;
  }

  const url = value.url;

  if (url === undefined || url === null) {
    throw new Invalid('validation_required', [...at, 'url'], 'is missing; a link to a URL has it');
  }

  if (typeof url !== 'string') {
    throw new Invalid('validation_wrong_type', [...at, 'url'], 'is not text');
  }

  const length = characters(url);

  if (length > MAX_URL_LENGTH) {
    throw new Invalid(
      'validation_too_long',
      [...at, 'url'],
      `has ${String(length)} characters; it may have at most ${String(MAX_URL_LENGTH)}`,
    );
  }

  if (!matchesFormat('url', url)) {
    throw new Invalid(
      'validation_invalid_format',
      [...at, 'url'],
      'is not an absolute http or https URL',
    );
  }

  if (!Object.hasOwn(value, 'rel')) {
    return;
  }

  const rels = value.rel;

  if (!Array.isArray(rels)) {
    throw new Invalid('validation_wrong_type', [...at, 'rel'], 'is not a list of rels');
  }

  const items: readonly unknown[] = rels;
  const seen: string[] = [];

  items.forEach((item, index) => {
    if (typeof item !== 'string') {
      throw new Invalid('validation_wrong_type', [...at, 'rel', index], 'is not text');
    }

    if (!LINK_RELS.includes(item)) {
      throw new Invalid(
        'validation_not_an_option',
        [...at, 'rel', index],
        `is not one of the rels: ${LINK_RELS.join(', ')}`,
      );
    }

    if (seen.includes(item)) {
      throw new Invalid(
        'validation_duplicate_item',
        [...at, 'rel', index],
        `is the same rel as item ${String(seen.indexOf(item))}`,
      );
    }

    seen.push(item);
  });
}

function checkInteger(value: unknown, at: Path, rule: IntegerRule): void {
  if (typeof value !== 'number' || !Number.isSafeInteger(value)) {
    throw new Invalid('validation_wrong_type', at, 'is not an integer');
  }

  if (rule.min !== undefined && value < rule.min) {
    throw new Invalid(
      'validation_below_minimum',
      at,
      `is ${String(value)}, less than the minimum ${String(rule.min)}`,
    );
  }

  if (rule.max !== undefined && value > rule.max) {
    throw new Invalid(
      'validation_above_maximum',
      at,
      `is ${String(value)}, more than the maximum ${String(rule.max)}`,
    );
  }
}

/** A decimal number: its sign, its digits before the point without leading zeros and after it without trailing zeros. */
interface DecimalNumber {
  readonly negative: boolean;
  readonly whole: string;
  readonly fraction: string;
}

function decimalNumber(value: string): DecimalNumber | null {
  const match = DECIMAL.exec(value);

  if (match === null) {
    return null;
  }

  const whole = (match[2] ?? '').replace(/^0+/, '') || '0';
  const fraction = (match[3] ?? '').replace(/0+$/, '');

  return { negative: match[1] === '-' && (whole !== '0' || fraction !== ''), whole, fraction };
}

function compareDecimals(a: DecimalNumber, b: DecimalNumber): number {
  if (a.negative !== b.negative) {
    return a.negative ? -1 : 1;
  }

  let magnitude = Math.sign(a.whole.length - b.whole.length);

  if (magnitude === 0) {
    const left = a.whole + a.fraction;
    const right = b.whole + b.fraction;
    magnitude = left === right ? 0 : left < right ? -1 : 1;
  }

  return a.negative ? -magnitude : magnitude;
}

function checkDecimal(value: unknown, at: Path, rule: DecimalRule): void {
  const number = typeof value === 'string' ? decimalNumber(value) : null;
  const digits = rule.precision - rule.scale;

  if (number === null) {
    throw new Invalid(
      'validation_wrong_type',
      at,
      `is not a decimal number in a string with at most ${String(digits)} digits before the point and ${String(rule.scale)} after it`,
    );
  }

  if (
    number.fraction.length > rule.scale ||
    (number.whole === '0' ? 0 : number.whole.length) > digits
  ) {
    throw new Invalid(
      'validation_too_many_digits',
      at,
      `is not a decimal number in a string with at most ${String(digits)} digits before the point and ${String(rule.scale)} after it`,
    );
  }

  if (rule.min !== undefined && compareDecimals(number, bound(rule.min)) < 0) {
    throw new Invalid('validation_below_minimum', at, `is less than the minimum ${rule.min}`);
  }

  if (rule.max !== undefined && compareDecimals(number, bound(rule.max)) > 0) {
    throw new Invalid('validation_above_maximum', at, `is more than the maximum ${rule.max}`);
  }
}

function bound(value: string): DecimalNumber {
  const number = decimalNumber(value);

  if (number === null) {
    throw new TypeError(`The bound "${value}" is not a decimal number.`);
  }

  return number;
}

function isLeapYear(year: number): boolean {
  return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0;
}

/** Whether the year, month and day name a day of the calendar. */
function isDay(year: number, month: number, day: number): boolean {
  const days = [31, isLeapYear(year) ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

  return month >= 1 && month <= 12 && day >= 1 && day <= (days[month - 1] ?? 0);
}

function checkDate(value: unknown, at: Path, rule: DateRule): void {
  const match = typeof value === 'string' ? DATE.exec(value) : null;

  if (
    typeof value !== 'string' ||
    match === null ||
    value.startsWith(YEAR_ZERO) ||
    !isDay(Number(match[1]), Number(match[2]), Number(match[3]))
  ) {
    throw new Invalid('validation_wrong_type', at, 'is not a date in the form YYYY-MM-DD');
  }

  if (rule.min !== undefined && value < rule.min) {
    throw new Invalid(
      'validation_below_minimum',
      at,
      `is ${value}, before the minimum ${rule.min}`,
    );
  }

  if (rule.max !== undefined && value > rule.max) {
    throw new Invalid('validation_above_maximum', at, `is ${value}, after the maximum ${rule.max}`);
  }
}

/** The microseconds since the Unix epoch of a date-time of RFC 3339, or null when it is not one. */
function instant(value: string): bigint | null {
  const match = DATETIME.exec(value);

  if (match === null || value.startsWith(YEAR_ZERO)) {
    return null;
  }

  const [year, month, day, hour, minute, second] = match.slice(1, 7).map(Number);
  const offset = match[8] ?? 'Z';

  if (
    year === undefined ||
    month === undefined ||
    day === undefined ||
    hour === undefined ||
    minute === undefined ||
    second === undefined ||
    !isDay(year, month, day) ||
    hour > 23 ||
    minute > 59 ||
    second > 59 ||
    offset === '-00:00'
  ) {
    return null;
  }

  const date = new Date(0);
  date.setUTCFullYear(year, month - 1, day);
  date.setUTCHours(hour, minute, second, 0);
  const offsetMinutes =
    offset === 'Z'
      ? 0
      : (offset.startsWith('-') ? -1 : 1) *
        (Number(offset.slice(1, 3)) * 60 + Number(offset.slice(4, 6)));
  const seconds = BigInt(date.getTime() / 1000 - offsetMinutes * 60);

  return seconds * 1_000_000n + BigInt((match[7] ?? '').padEnd(6, '0'));
}

function checkDatetime(value: unknown, at: Path, rule: DatetimeRule): void {
  const time = typeof value === 'string' ? instant(value) : null;

  if (time === null) {
    throw new Invalid(
      'validation_wrong_type',
      at,
      'is not a date-time of RFC 3339 with an offset, such as 2026-01-01T12:00:00Z',
    );
  }

  if (rule.min !== undefined && time < instantBound(rule.min)) {
    throw new Invalid('validation_below_minimum', at, `is before the minimum ${rule.min}`);
  }

  if (rule.max !== undefined && time > instantBound(rule.max)) {
    throw new Invalid('validation_above_maximum', at, `is after the maximum ${rule.max}`);
  }
}

function instantBound(value: string): bigint {
  const time = instant(value);

  if (time === null) {
    throw new TypeError(`The bound "${value}" is not a date-time of RFC 3339.`);
  }

  return time;
}

function checkList(value: unknown, at: Path, rule: ListRule, issues?: Invalid[]): void {
  if (!Array.isArray(value)) {
    throw new Invalid('validation_wrong_type', at, 'is not a list');
  }

  const items: readonly unknown[] = value;

  if (rule.minItems !== undefined && items.length < rule.minItems) {
    throw new Invalid(
      'validation_too_few_items',
      at,
      `has ${String(items.length)} items, fewer than the ${String(rule.minItems)} the field requires`,
    );
  }

  if (rule.maxItems !== undefined && items.length > rule.maxItems) {
    throw new Invalid(
      'validation_too_many_items',
      at,
      `has ${String(items.length)} items, more than the ${String(rule.maxItems)} the field allows`,
    );
  }

  const seen: unknown[] = [];
  const caseless = rule.item.kind === 'string' && rule.item.caseless === true;

  items.forEach((item, index) => {
    const compared = caseless && typeof item === 'string' ? item.toLowerCase() : item;

    collected(issues, () => {
      checkValue(item, [...at, index], rule.item, issues);

      // Objects are never equal to one another, as the kernel's codec compares what it reads.
      if (rule.distinct === true && typeof item !== 'object' && seen.includes(compared)) {
        throw new Invalid(
          'validation_duplicate_item',
          [...at, index],
          'is an item the list already has',
        );
      }
    });

    seen.push(compared);
  });
}

function checkString(value: unknown, at: Path, rule: StringRule): void {
  if (typeof value !== 'string') {
    throw new Invalid('validation_wrong_type', at, 'is not a string');
  }

  const length = characters(value);

  if (
    (rule.pattern !== undefined && !new RegExp(rule.pattern, 'u').test(value)) ||
    (rule.minLength !== undefined && length < rule.minLength) ||
    (rule.maxLength !== undefined && length > rule.maxLength)
  ) {
    throw new Invalid(
      'validation_wrong_type',
      at,
      'is not valid: it is not in the form of its contract',
    );
  }
}

/**
 * Checks that a value is JSON the kernel can hold in rich text: every number an integer, and every
 * key of an object 1 to 255 bytes.
 */
function checkJson(value: unknown, at: Path): void {
  if (typeof value === 'number') {
    if (!Number.isSafeInteger(value)) {
      throw new Invalid(
        'validation_invalid_rich_text',
        at,
        'holds a number that is not an integer',
      );
    }

    return;
  }

  if (Array.isArray(value)) {
    const items: readonly unknown[] = value;
    items.forEach((item, index) => {
      checkJson(item, [...at, index]);
    });

    return;
  }

  if (isRecord(value)) {
    for (const [key, entry] of Object.entries(value)) {
      const bytes = new TextEncoder().encode(key).length;

      if (bytes === 0 || bytes > MAX_KEY_BYTES) {
        throw new Invalid(
          'validation_invalid_rich_text',
          at,
          `has a key of ${String(bytes)} bytes; a key has 1 to ${String(MAX_KEY_BYTES)}`,
        );
      }

      checkJson(entry, NAME.test(key) ? [...at, key] : at);
    }
  }
}

/**
 * The fields of a revision of any type: an object of the owner's fields by handle, each a field
 * value, and under `ext` an object of namespaces, each an object of that extender's fields by
 * handle.
 */
function checkFields(value: unknown, at: Path): void {
  if (!isRecord(value)) {
    throw new Invalid('validation_wrong_type', at, 'is not an object of fields by handle');
  }

  for (const [key, field] of Object.entries(value)) {
    if (key === EXTENSIONS_KEY) {
      checkExtensionFields(field, [...at, key]);

      continue;
    }

    checkNamedValue(key, field, at);
  }
}

function checkExtensionFields(value: unknown, at: Path): void {
  if (!isRecord(value)) {
    throw new Invalid(
      'validation_wrong_type',
      at,
      'is not an object of extension fields by namespace',
    );
  }

  for (const [namespace, fields] of Object.entries(value)) {
    if (!FIELD_NAMESPACE.test(namespace) || namespace === EXTENSIONS_KEY) {
      throw new Invalid(
        'validation_unknown_field',
        at,
        `has the key "${namespace}", which is not a namespace`,
      );
    }

    if (!isRecord(fields)) {
      throw new Invalid(
        'validation_wrong_type',
        [...at, namespace],
        'is not an object of fields by handle',
      );
    }

    for (const [handle, field] of Object.entries(fields)) {
      checkNamedValue(handle, field, [...at, namespace]);
    }
  }
}

function checkNamedValue(handle: string, value: unknown, at: Path): void {
  if (
    handle.length > MAX_HANDLE_BYTES ||
    !FIELD_HANDLE.test(handle) ||
    handle === EXTENSIONS_KEY ||
    handle.startsWith('cms_')
  ) {
    throw new Invalid(
      'validation_unknown_field',
      at,
      `has the key "${handle}", which is not a field handle`,
    );
  }

  checkFieldValue(value, [...at, handle]);
}

/**
 * A field's value as JSON gives it: a string, an integer, a boolean, null, a list of values or an
 * object of values by a key of 1 to 255 bytes.
 */
function checkFieldValue(value: unknown, at: Path): void {
  if (typeof value === 'string' || typeof value === 'boolean' || value === null) {
    return;
  }

  if (typeof value === 'number') {
    if (!Number.isSafeInteger(value)) {
      throw new Invalid('validation_wrong_type', at, 'holds a number that is not an integer');
    }

    return;
  }

  if (Array.isArray(value)) {
    const items: readonly unknown[] = value;
    items.forEach((item, index) => {
      checkFieldValue(item, [...at, index]);
    });

    return;
  }

  if (!isRecord(value)) {
    throw new Invalid('validation_wrong_type', at, 'holds a number that is not an integer');
  }

  for (const [key, entry] of Object.entries(value)) {
    const bytes = new TextEncoder().encode(key).length;

    if (bytes === 0 || bytes > MAX_KEY_BYTES) {
      throw new Invalid(
        'validation_wrong_type',
        at,
        `has a key of ${String(bytes)} bytes; a key has 1 to ${String(MAX_KEY_BYTES)}`,
      );
    }

    checkFieldValue(entry, NAME.test(key) ? [...at, key] : at);
  }
}

function shown(values: readonly string[]): string {
  return values.length === 0 ? 'none' : values.join(', ');
}

function record(value: unknown, at: Path): Readonly<Record<string, unknown>> {
  if (!isRecord(value)) {
    throw new Invalid('validation_invalid_rich_text', at, 'is not an object');
  }

  return value;
}

function list(value: unknown, at: Path): readonly unknown[] {
  if (!Array.isArray(value)) {
    throw new Invalid('validation_invalid_rich_text', at, 'is not a list');
  }

  return value;
}

function optionalText(
  object: Readonly<Record<string, unknown>>,
  key: string,
  at: Path,
): string | null {
  if (!Object.hasOwn(object, key)) {
    return null;
  }

  const value = object[key];

  if (typeof value !== 'string') {
    throw new Invalid('validation_invalid_rich_text', [...at, key], 'is not a string');
  }

  return value;
}

function text(object: Readonly<Record<string, unknown>>, key: string, at: Path): string {
  const value = optionalText(object, key, at);

  if (value === null) {
    throw new Invalid('validation_invalid_rich_text', [...at, key], 'is missing');
  }

  return value;
}

/** Records the object's `_key`, which is text that no earlier object of its scope has. */
function uniqueKey(
  object: Readonly<Record<string, unknown>>,
  at: Path,
  keys: string[],
  scope: string,
): void {
  const key = text(object, '_key', at);

  if (key === '') {
    throw new Invalid('validation_invalid_rich_text', [...at, '_key'], 'is empty');
  }

  if (keys.includes(key)) {
    throw new Invalid(
      'validation_invalid_rich_text',
      [...at, '_key'],
      `"${key}" is the key of ${scope}`,
    );
  }

  keys.push(key);
}

function oneOf(value: string | null, allowed: readonly string[], at: Path, what: string): void {
  if (value !== null && !allowed.includes(value)) {
    throw new Invalid(
      'validation_rich_text_not_allowed',
      at,
      `is the ${what} "${value}", which the field does not allow (${shown(allowed)})`,
    );
  }
}

/**
 * A rich text document of Portable Text blocks (PRD 11.10). A block has `_type` "block", a `_key`
 * unique in the document, at least one span in `children`, and optionally a `style`, a `listItem`,
 * a `level` of 1 or more with it, and `markDefs`. A span has `_type` "span", a `_key` unique in its
 * block, `text` and optionally `marks`, each an allowed decorator or the key of a mark definition of
 * its block. Other keys are not checked.
 */
function checkPortableText(value: unknown, at: Path, rule: PortableTextRule): void {
  checkJson(value, at);

  if (!Array.isArray(value)) {
    throw new Invalid('validation_wrong_type', at, 'is not a list of blocks');
  }

  const blocks: readonly unknown[] = value;
  const blockKeys: string[] = [];

  blocks.forEach((item, index) => {
    const blockAt = [...at, index];
    const block = record(item, blockAt);

    if (text(block, '_type', blockAt) !== 'block') {
      throw new Invalid(
        'validation_invalid_rich_text',
        [...blockAt, '_type'],
        'is not "block": a rich text field of the blueprint schema v1 holds only blocks',
      );
    }

    uniqueKey(block, blockAt, blockKeys, 'another block of the document');
    oneOf(optionalText(block, 'style', blockAt), rule.styles, [...blockAt, 'style'], 'style');
    checkListItem(block, blockAt, rule.lists);
    const definitions = checkMarkDefinitions(block, blockAt, rule.links);
    checkChildren(block, blockAt, rule.marks, definitions);
  });
}

function checkListItem(
  block: Readonly<Record<string, unknown>>,
  at: Path,
  lists: readonly string[],
): void {
  const listItem = optionalText(block, 'listItem', at);
  oneOf(listItem, lists, [...at, 'listItem'], 'list kind');

  if (!Object.hasOwn(block, 'level')) {
    return;
  }

  if (listItem === null) {
    throw new Invalid(
      'validation_invalid_rich_text',
      [...at, 'level'],
      'is given without a listItem',
    );
  }

  const level = block['level'];

  if (typeof level !== 'number' || !Number.isSafeInteger(level) || level < 1) {
    throw new Invalid(
      'validation_invalid_rich_text',
      [...at, 'level'],
      'is not an integer of 1 or more',
    );
  }
}

function checkMarkDefinitions(
  block: Readonly<Record<string, unknown>>,
  at: Path,
  links: readonly string[],
): string[] {
  if (!Object.hasOwn(block, 'markDefs')) {
    return [];
  }

  const keys: string[] = [];
  const allowed = links.flatMap((link) => {
    const type = LINK_TYPES[link];

    return type === undefined ? [] : [type];
  });

  list(block['markDefs'], [...at, 'markDefs']).forEach((item, index) => {
    const definitionAt = [...at, 'markDefs', index];
    const definition = record(item, definitionAt);
    uniqueKey(definition, definitionAt, keys, 'another mark definition of the block');
    const type = text(definition, '_type', definitionAt);

    if (!allowed.includes(type)) {
      throw new Invalid(
        'validation_rich_text_not_allowed',
        [...definitionAt, '_type'],
        `is the mark definition "${type}", which the field does not allow (${shown(allowed)})`,
      );
    }

    if (!matchesFormat('url', text(definition, 'href', definitionAt))) {
      throw new Invalid(
        'validation_invalid_format',
        [...definitionAt, 'href'],
        'is not an absolute http or https URL',
      );
    }
  });

  return keys;
}

function checkChildren(
  block: Readonly<Record<string, unknown>>,
  at: Path,
  marks: readonly string[],
  definitions: readonly string[],
): void {
  if (!Object.hasOwn(block, 'children')) {
    throw new Invalid('validation_invalid_rich_text', [...at, 'children'], 'is missing');
  }

  const spans = list(block['children'], [...at, 'children']);

  if (spans.length === 0) {
    throw new Invalid(
      'validation_invalid_rich_text',
      [...at, 'children'],
      'is empty: a block has at least one span',
    );
  }

  const keys: string[] = [];

  spans.forEach((item, index) => {
    const spanAt = [...at, 'children', index];
    const span = record(item, spanAt);

    if (text(span, '_type', spanAt) !== 'span') {
      throw new Invalid('validation_invalid_rich_text', [...spanAt, '_type'], 'is not "span"');
    }

    uniqueKey(span, spanAt, keys, 'another span of the block');
    text(span, 'text', spanAt);

    if (!Object.hasOwn(span, 'marks')) {
      return;
    }

    list(span['marks'], [...spanAt, 'marks']).forEach((mark, markIndex) => {
      if (typeof mark !== 'string' || (!marks.includes(mark) && !definitions.includes(mark))) {
        throw new Invalid(
          'validation_rich_text_not_allowed',
          [...spanAt, 'marks', markIndex],
          `is neither a decorator the field allows (${shown(marks)}) nor the key of a mark definition of the block`,
        );
      }
    });
  });
}
