// A field of the command form as a replacement of its input gets it (section 3.6 of the panel
// extension architecture): the props of command.form.field@1 built from what the kit's SchemaForm
// gives renderInput, with the member's JSON Schema node rebuilt from the form's model, its value
// as text, its presence as the kernel's validators say it, and onChange, which hands the form the
// member's next value, read back into the member's kind.

import type { FieldInputProps } from '@cboxdk/cms-panel/experimental';
import type { FieldShape, FormMember, JsonObject, SchemaFormField } from '@cboxdk/cms-ui-kit';

import { presenceOf } from './rules';

/** What the form is about, which every field's props carry. */
export interface FieldContext {
  readonly command: string;
  readonly version: number;
  readonly locale: string;
}

/** The member's JSON Schema node, as the form's model read it, with its definition resolved. */
export function schemaNodeOf(member: FormMember): JsonObject {
  const node: Record<string, string | number | boolean | readonly string[] | JsonObject> = {
    ...shapeNode(member.shape),
    ...(member.title === undefined ? {} : { title: member.title }),
    ...(member.description === undefined ? {} : { description: member.description }),
  };

  if (member.nullable) {
    const type = node.type;

    if (typeof type === 'string') {
      node.type = [type, 'null'];
    }
  }

  return node;
}

function shapeNode(shape: FieldShape): JsonObject {
  switch (shape.kind) {
    case 'string':
      return {
        type: 'string',
        ...(shape.pattern === undefined ? {} : { pattern: shape.pattern }),
        ...(shape.minLength === undefined ? {} : { minLength: shape.minLength }),
        ...(shape.maxLength === undefined ? {} : { maxLength: shape.maxLength }),
        ...(shape.format === undefined ? {} : { format: shape.format }),
        ...(shape.examples.length === 0 ? {} : { examples: shape.examples }),
      };
    case 'integer':
      return {
        type: 'integer',
        ...(shape.minimum === undefined ? {} : { minimum: shape.minimum }),
        ...(shape.maximum === undefined ? {} : { maximum: shape.maximum }),
      };
    case 'boolean':
      return { type: 'boolean' };
    case 'enum':
      return { enum: shape.values };
    case 'fields':
      return { $ref: '#/$defs/fields' };
    case 'object':
      return {
        type: 'object',
        additionalProperties: false,
        required: shape.members.filter((member) => member.required).map((member) => member.key),
        properties: Object.fromEntries(
          shape.members.map((member) => [member.key, schemaNodeOf(member)]),
        ),
      };
    case 'list':
      return {
        type: 'array',
        items: shapeNode(shape.item),
        ...(shape.minItems === undefined ? {} : { minItems: shape.minItems }),
        ...(shape.maxItems === undefined ? {} : { maxItems: shape.maxItems }),
      };
  }
}

/** The value of the field as text, or null while it is empty. */
function textOf(field: SchemaFormField): string | null {
  const { value } = field;

  if (typeof value === 'string') {
    return value;
  }

  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value);
  }

  return null;
}

/** The props a replacement of the field's input gets. */
export function fieldInputProps(field: SchemaFormField, context: FieldContext): FieldInputProps {
  const { member } = field;

  return {
    command: context.command,
    version: context.version,
    path: field.path,
    id: field.id,
    label: field.label,
    description: field.description ?? null,
    schema: schemaNodeOf(member),
    value: textOf(field),
    errors: field.error === undefined ? [] : [field.error],
    read_only: field.disabled,
    locale: context.locale,
    presence: presenceOf(member),
    onChange: (value) => {
      if (value === null || value === '') {
        field.onChange(field.emptied);
      } else if (member.shape.kind === 'integer') {
        const number = Number(value);
        field.onChange(Number.isInteger(number) ? number : value);
      } else if (member.shape.kind === 'boolean') {
        field.onChange(value === 'true');
      } else {
        field.onChange(value);
      }
    },
  };
}
