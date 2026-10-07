// The model of a form rendered from a command's JSON Schema (GUARDRAILS 2.2, 8; PRD 6.1): what
// SchemaForm renders, read from the schema alone, so the form names no command and no field. The
// reader takes the subset of JSON Schema draft 2020-12 the kernel's command schemas use, the
// keywords the kernel's generated codecs have a form for: an object with its properties and
// required keys, strings with a pattern, lengths and the date-time format, integers with bounds,
// booleans, enums of strings, nullable members as a type with null or an anyOf with null, defaults,
// nested objects, lists of one kind of item, and the fields of a revision of any type, the member
// that refers to `#/$defs/fields`, which the form edits as JSON. Any other keyword, type or shape
// is SchemaUnsupported, with the pointer of the node and the keyword, so a schema the form cannot
// render fails loudly instead of rendering a field that means something else.

/**
 * A JSON value, as JSON.parse gives it.
 *
 * @experimental
 */
export type JsonValue =
  string | number | boolean | null | readonly JsonValue[] | { readonly [key: string]: JsonValue };

/**
 * A JSON object.
 *
 * @experimental
 */
export type JsonObject = { readonly [key: string]: JsonValue };

/**
 * What readCommandSchema() throws for a schema the form cannot render: the keyword, type or shape,
 * and the JSON pointer of the node that has it.
 *
 * @experimental
 */
export interface UnsupportedSchema {
  /** The JSON pointer of the node, such as `#/properties/window`. */
  readonly pointer: string;
  /** The keyword that is not supported, such as `oneOf`, or the keyword whose value is not, such as `type`. */
  readonly keyword: string;
  /** The pointer, the keyword and the reason, in one line. */
  readonly message: string;
}

/**
 * Thrown by readCommandSchema() for a schema the form cannot render. The kit's entry exports the
 * interface UnsupportedSchema and the guard isSchemaUnsupported() for it, not the class.
 */
export class SchemaUnsupported extends Error implements UnsupportedSchema {
  /** The JSON pointer of the node, such as `#/properties/window`. */
  public readonly pointer: string;

  /** The keyword that is not supported, such as `oneOf`, or the keyword whose value is not, such as `type`. */
  public readonly keyword: string;

  public constructor(pointer: string, keyword: string, reason: string) {
    super(`${pointer}: the keyword "${keyword}" is not supported: ${reason}`);
    this.name = 'SchemaUnsupported';
    this.pointer = pointer;
    this.keyword = keyword;
  }
}

/**
 * Whether the value is what readCommandSchema() throws for a schema the form cannot render.
 *
 * @experimental
 */
export function isSchemaUnsupported(value: unknown): value is UnsupportedSchema {
  return value instanceof SchemaUnsupported;
}

/**
 * Text, with the pattern and lengths the schema gives, and the date-time format, RFC 3339 with an
 * offset, when it says so.
 *
 * @experimental
 */
export interface StringShape {
  readonly kind: 'string';
  readonly pattern?: string | undefined;
  readonly minLength?: number | undefined;
  readonly maxLength?: number | undefined;
  readonly format?: 'date-time' | undefined;
  /** The schema's examples, which the form shows as a hint. */
  readonly examples: readonly string[];
}

/**
 * An integer within the bounds the schema gives.
 *
 * @experimental
 */
export interface IntegerShape {
  readonly kind: 'integer';
  readonly minimum?: number | undefined;
  readonly maximum?: number | undefined;
}

/**
 * A boolean.
 *
 * @experimental
 */
export interface BooleanShape {
  readonly kind: 'boolean';
}

/**
 * One of the schema's values, strings.
 *
 * @experimental
 */
export interface EnumShape {
  readonly kind: 'enum';
  readonly values: readonly string[];
}

/**
 * An object of the members given, with no other key.
 *
 * @experimental
 */
export interface ObjectShape {
  readonly kind: 'object';
  readonly members: readonly FormMember[];
}

/**
 * A list of items of one shape, within the bounds the schema gives.
 *
 * @experimental
 */
export interface ListShape {
  readonly kind: 'list';
  readonly item: FieldShape;
  readonly minItems?: number | undefined;
  readonly maxItems?: number | undefined;
}

/**
 * The fields of a revision of any type, in the input form the type's validator reads: the member
 * that refers to `#/$defs/fields`, edited as JSON and checked by the caller.
 *
 * @experimental
 */
export interface FieldsShape {
  readonly kind: 'fields';
}

/**
 * The shape of a member's value.
 *
 * @experimental
 */
export type FieldShape =
  StringShape | IntegerShape | BooleanShape | EnumShape | ObjectShape | ListShape | FieldsShape;

/**
 * A member of an object: its key, the schema's title and description, whether it is required and
 * whether it may be null, its default when the schema gives one, and the shape of its value.
 *
 * @experimental
 */
export interface FormMember {
  readonly key: string;
  readonly title: string | undefined;
  readonly description: string | undefined;
  readonly required: boolean;
  readonly nullable: boolean;
  /** Whether the schema gives a default; `defaultValue` is it, which may be null. */
  readonly hasDefault: boolean;
  readonly defaultValue: JsonValue | undefined;
  readonly shape: FieldShape;
}

/**
 * A form read from a command's JSON Schema: the schema's title and description, and the object of
 * the command's document.
 *
 * @experimental
 */
export interface FormModel {
  readonly title: string | undefined;
  readonly description: string | undefined;
  readonly root: ObjectShape;
}

/** The keywords of the document besides those of an object. */
const DOCUMENT_KEYWORDS = ['$schema', '$defs'];

/** The keywords of every node that the form only shows or ignores. */
const ANNOTATION_KEYWORDS = ['title', 'description', 'examples', 'default'];

/** The keywords an object node may have. */
const OBJECT_KEYWORDS = ['type', 'additionalProperties', 'properties', 'required'];

/** The keywords a string node may have. */
const STRING_KEYWORDS = ['type', 'pattern', 'minLength', 'maxLength', 'format', 'enum'];

/** The keywords an integer node may have. */
const INTEGER_KEYWORDS = ['type', 'minimum', 'maximum'];

/** The keywords a boolean node may have. */
const BOOLEAN_KEYWORDS = ['type'];

/** The keywords a list node may have. */
const LIST_KEYWORDS = ['type', 'items', 'minItems', 'maxItems'];

/** The keywords an enum node without a type may have. */
const ENUM_KEYWORDS = ['enum'];

/** Every keyword some node may have; any other is refused before the node's type is looked at. */
const KNOWN_KEYWORDS = [
  '$ref',
  ...OBJECT_KEYWORDS,
  ...STRING_KEYWORDS,
  ...INTEGER_KEYWORDS,
  ...BOOLEAN_KEYWORDS,
  ...LIST_KEYWORDS,
  ...ENUM_KEYWORDS,
];

/** The reference of the member that holds the fields of a revision of any type. */
const FIELDS_REFERENCE = '#/$defs/fields';

/** The definitions the fields member's reference needs, as the kernel's FieldValuesSchema has them. */
const FIELDS_DEFINITIONS = [
  'fields',
  'extension_fields',
  'field_handle',
  'field_namespace',
  'field_value',
];

type Node = Readonly<Record<string, unknown>>;

/** The node without the keyword. */
function without(node: Node, keyword: string): Node {
  return Object.fromEntries(Object.entries(node).filter(([key]) => key !== keyword));
}

function isRecord(value: unknown): value is Node {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isJsonValue(value: unknown): value is JsonValue {
  switch (typeof value) {
    case 'string':
    case 'boolean':
      return true;
    case 'number':
      return Number.isFinite(value);
    case 'object':
      return (
        value === null ||
        (Array.isArray(value) ? value.every(isJsonValue) : Object.values(value).every(isJsonValue))
      );
    default:
      return false;
  }
}

/** The keywords of the node that are not among those allowed, as an error for the first. */
function onlyKeywords(node: Node, pointer: string, allowed: readonly string[]): void {
  for (const keyword of Object.keys(node)) {
    if (!allowed.includes(keyword) && !ANNOTATION_KEYWORDS.includes(keyword)) {
      throw new SchemaUnsupported(pointer, keyword, 'the form has no field for it');
    }
  }
}

function text(node: Node, keyword: 'title' | 'description'): string | undefined {
  const value = node[keyword];

  return typeof value === 'string' && value.trim() !== '' ? value : undefined;
}

function integer(node: Node, pointer: string, keyword: string): number | undefined {
  if (!Object.hasOwn(node, keyword)) {
    return undefined;
  }

  const value = node[keyword];

  if (typeof value !== 'number' || !Number.isInteger(value)) {
    throw new SchemaUnsupported(pointer, keyword, 'it is not an integer');
  }

  return value;
}

/**
 * Reads the schema of a command's document into the form's model.
 *
 * @throws SchemaUnsupported for a keyword, type or shape the form cannot render
 *
 * @experimental
 */
export function readCommandSchema(schema: unknown): FormModel {
  if (!isRecord(schema)) {
    throw new SchemaUnsupported('#', 'type', 'the schema is not an object');
  }

  const definitions = schema['$defs'];

  if (Object.hasOwn(schema, '$defs') && !isRecord(definitions)) {
    throw new SchemaUnsupported('#', '$defs', 'it is not an object of definitions');
  }

  const reader = new Reader(isRecord(definitions) ? definitions : {});
  const root = reader.object(schema, '#', DOCUMENT_KEYWORDS);

  return { title: text(schema, 'title'), description: text(schema, 'description'), root };
}

class Reader {
  public constructor(private readonly definitions: Node) {}

  public object(node: Node, pointer: string, extra: readonly string[] = []): ObjectShape {
    onlyKeywords(node, pointer, [...OBJECT_KEYWORDS, ...extra]);

    if (node['type'] !== 'object') {
      throw new SchemaUnsupported(pointer, 'type', 'an object has the type "object"');
    }

    if (node['additionalProperties'] !== false) {
      throw new SchemaUnsupported(
        pointer,
        'additionalProperties',
        'an object has "additionalProperties": false, so its keys are its properties',
      );
    }

    const properties = node['properties'];
    const required = node['required'] ?? [];

    if (!isRecord(properties)) {
      throw new SchemaUnsupported(pointer, 'properties', 'an object has its properties');
    }

    if (!Array.isArray(required) || !required.every((key) => typeof key === 'string')) {
      throw new SchemaUnsupported(pointer, 'required', 'it is not a list of keys');
    }

    for (const key of required) {
      if (!Object.hasOwn(properties, key)) {
        throw new SchemaUnsupported(pointer, 'required', `"${key}" is not a property`);
      }
    }

    return {
      kind: 'object',
      members: Object.entries(properties).map(([key, property]) =>
        this.member(key, property, `${pointer}/properties/${key}`, required.includes(key)),
      ),
    };
  }

  private member(key: string, property: unknown, pointer: string, required: boolean): FormMember {
    if (!isRecord(property)) {
      throw new SchemaUnsupported(pointer, 'type', 'a property is an object schema');
    }

    const resolved = this.resolve(property, pointer);
    const { node, nullable } = this.nullability(resolved, pointer);
    const hasDefault = Object.hasOwn(property, 'default') || Object.hasOwn(resolved, 'default');
    const defaultValue = Object.hasOwn(property, 'default')
      ? property['default']
      : resolved['default'];

    if (hasDefault && !isJsonValue(defaultValue)) {
      throw new SchemaUnsupported(pointer, 'default', 'it is not a JSON value');
    }

    if (!required && !hasDefault) {
      throw new SchemaUnsupported(
        pointer,
        'required',
        'a key that is not required has a default, so the form knows what leaving it out means',
      );
    }

    return {
      key,
      title: text(property, 'title') ?? text(resolved, 'title'),
      description: text(property, 'description') ?? text(resolved, 'description'),
      required,
      nullable,
      hasDefault,
      defaultValue: hasDefault && isJsonValue(defaultValue) ? defaultValue : undefined,
      shape: this.shape(node, pointer),
    };
  }

  /**
   * The node a `$ref` names with the keywords of the referring node besides it, or the node
   * itself; a reference to the fields of a revision is kept as it is, for shape() to see.
   */
  private resolve(node: Node, pointer: string): Node {
    const reference = node['$ref'];

    if (reference === undefined) {
      return node;
    }

    if (typeof reference !== 'string' || !reference.startsWith('#/$defs/')) {
      throw new SchemaUnsupported(
        pointer,
        '$ref',
        'a reference names a definition, `#/$defs/<name>`',
      );
    }

    if (reference === FIELDS_REFERENCE) {
      for (const name of FIELDS_DEFINITIONS) {
        if (!isRecord(this.definitions[name])) {
          throw new SchemaUnsupported(
            pointer,
            '$ref',
            `the fields of a revision need the definition "${name}"`,
          );
        }
      }

      return node;
    }

    const name = reference.slice('#/$defs/'.length);
    const definition = this.definitions[name];

    if (!isRecord(definition)) {
      throw new SchemaUnsupported(pointer, '$ref', `there is no definition "${name}"`);
    }

    if (Object.hasOwn(definition, '$ref')) {
      throw new SchemaUnsupported(pointer, '$ref', 'a definition does not refer to another');
    }

    return { ...definition, ...without(node, '$ref') };
  }

  /**
   * Whether the node allows null, as a type with null or an anyOf of a node and null, and the
   * node of the value itself.
   */
  private nullability(node: Node, pointer: string): { node: Node; nullable: boolean } {
    const type = node['type'];

    if (Array.isArray(type)) {
      if (
        type.length !== 2 ||
        !type.includes('null') ||
        !type.every((t) => typeof t === 'string')
      ) {
        throw new SchemaUnsupported(pointer, 'type', 'a list of types is one type and "null"');
      }

      return { node: { ...node, type: type.find((t) => t !== 'null') }, nullable: true };
    }

    if (!Object.hasOwn(node, 'anyOf')) {
      return { node, nullable: false };
    }

    const anyOf = node['anyOf'];

    if (!Array.isArray(anyOf) || anyOf.length !== 2) {
      throw new SchemaUnsupported(pointer, 'anyOf', 'an anyOf is one schema and null');
    }

    const [first, second] = anyOf as [unknown, unknown];
    const nullNode = [first, second].find((entry) => isRecord(entry) && entry['type'] === 'null');
    const valueNode = [first, second].find((entry) => entry !== nullNode);

    if (!isRecord(nullNode) || !isRecord(valueNode) || Object.keys(nullNode).length !== 1) {
      throw new SchemaUnsupported(pointer, 'anyOf', 'an anyOf is one schema and null');
    }

    return {
      node: { ...this.resolve(valueNode, `${pointer}/anyOf`), ...without(node, 'anyOf') },
      nullable: true,
    };
  }

  private shape(node: Node, pointer: string): FieldShape {
    onlyKeywords(node, pointer, KNOWN_KEYWORDS);

    if (node['$ref'] === FIELDS_REFERENCE) {
      onlyKeywords(node, pointer, ['$ref']);

      return { kind: 'fields' };
    }

    const type = node['type'];

    if (type === undefined && Object.hasOwn(node, 'enum')) {
      onlyKeywords(node, pointer, ENUM_KEYWORDS);

      return this.enumeration(node, pointer);
    }

    switch (type) {
      case 'object':
        return this.object(node, pointer);
      case 'string':
        return this.string(node, pointer);
      case 'integer':
        onlyKeywords(node, pointer, INTEGER_KEYWORDS);

        return {
          kind: 'integer',
          minimum: integer(node, pointer, 'minimum'),
          maximum: integer(node, pointer, 'maximum'),
        };
      case 'boolean':
        onlyKeywords(node, pointer, BOOLEAN_KEYWORDS);

        return { kind: 'boolean' };
      case 'array':
        return this.list(node, pointer);
      default:
        throw new SchemaUnsupported(
          pointer,
          'type',
          typeof type === 'string'
            ? `the form has no field for the type "${type}"`
            : 'a value has one of the types object, string, integer, boolean or array, or an enum',
        );
    }
  }

  private string(node: Node, pointer: string): StringShape | EnumShape {
    onlyKeywords(node, pointer, STRING_KEYWORDS);

    if (Object.hasOwn(node, 'enum')) {
      return this.enumeration(node, pointer);
    }

    const pattern = node['pattern'];
    const format = node['format'];
    const examples = node['examples'];

    if (pattern !== undefined && typeof pattern !== 'string') {
      throw new SchemaUnsupported(pointer, 'pattern', 'it is not a regular expression');
    }

    if (format !== undefined && format !== 'date-time') {
      throw new SchemaUnsupported(pointer, 'format', 'the form has only the format "date-time"');
    }

    if (
      examples !== undefined &&
      (!Array.isArray(examples) || !examples.every((example) => typeof example === 'string'))
    ) {
      throw new SchemaUnsupported(pointer, 'examples', 'the examples of a string are strings');
    }

    return {
      kind: 'string',
      pattern,
      minLength: integer(node, pointer, 'minLength'),
      maxLength: integer(node, pointer, 'maxLength'),
      format,
      examples: Array.isArray(examples) ? examples : [],
    };
  }

  private enumeration(node: Node, pointer: string): EnumShape {
    const values = node['enum'];

    if (
      !Array.isArray(values) ||
      values.length === 0 ||
      !values.every((value) => typeof value === 'string')
    ) {
      throw new SchemaUnsupported(pointer, 'enum', 'an enum is a list of strings');
    }

    return { kind: 'enum', values };
  }

  private list(node: Node, pointer: string): ListShape {
    onlyKeywords(node, pointer, LIST_KEYWORDS);

    const items = node['items'];

    if (!isRecord(items)) {
      throw new SchemaUnsupported(pointer, 'items', 'a list has the schema of its items');
    }

    const resolved = this.resolve(items, `${pointer}/items`);

    if (Array.isArray(resolved['type']) || Object.hasOwn(resolved, 'anyOf')) {
      throw new SchemaUnsupported(`${pointer}/items`, 'type', 'an item of a list is never null');
    }

    return {
      kind: 'list',
      item: this.shape(resolved, `${pointer}/items`),
      minItems: integer(node, pointer, 'minItems'),
      maxItems: integer(node, pointer, 'maxItems'),
    };
  }
}

/**
 * Where a value is in the document: the keys and list indexes from the document to it.
 *
 * @experimental
 */
export type FieldPath = readonly (string | number)[];

/**
 * The path as the kernel's FieldPath writes it and the generated validators report it: names
 * joined by dots and list indexes in brackets, such as `slugs[1].slug`; '' for the document.
 *
 * @experimental
 */
export function fieldPathText(path: FieldPath): string {
  let out = '';

  for (const segment of path) {
    out += typeof segment === 'number' ? `[${String(segment)}]` : (out === '' ? '' : '.') + segment;
  }

  return out;
}

/**
 * The document a form starts with: every member with a default has it, a required object its
 * members' starts, a required list is empty, a required boolean is false, and every other member
 * is left out until the reader gives it a value.
 *
 * @experimental
 */
export function initialDocument(object: ObjectShape): JsonObject {
  const document: Record<string, JsonValue> = {};

  for (const member of object.members) {
    const value = initialValue(member);

    if (value !== undefined) {
      document[member.key] = value;
    }
  }

  return document;
}

function initialValue(member: FormMember): JsonValue | undefined {
  if (member.hasDefault) {
    return member.defaultValue;
  }

  if (!member.required) {
    return undefined;
  }

  switch (member.shape.kind) {
    case 'object':
      return member.nullable ? null : initialDocument(member.shape);
    case 'list':
      return member.nullable ? null : [];
    case 'boolean':
      return false;
    default:
      return undefined;
  }
}

/**
 * What a member's value becomes when the reader empties its field: left out when the key may be
 * left out, null when the value may be null, and otherwise missing, which the validator reports as
 * required.
 *
 * @experimental
 */
export function emptied(member: FormMember): JsonValue | undefined {
  if (!member.required) {
    return undefined;
  }

  return member.nullable ? null : undefined;
}
