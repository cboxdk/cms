// @vitest-environment jsdom

// The form rendered from a command's JSON Schema (GUARDRAILS 2.2, 8; PRD 6.1): every kernel command
// schema, the files of packages/core/resources/schemas/commands that ProtocolSchemas::commands()
// binds, is read without an unsupported-keyword error and renders a field per member of the
// command's document; a schema planted with a keyword the form has no field for is refused with
// the keyword and the pointer of the node. The reader keeps what the schema says about each member:
// required, nullable, its default and its shape. The form is controlled: typing changes the
// document, emptying a field leaves an optional member out, and a nullable object or list is set
// and unset with its check box.

import { screen, within } from '@testing-library/react';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { useState } from 'react';
import { describe, expect, test } from 'vitest';

import { SchemaForm } from '../../src/components/SchemaForm';
import {
  emptied,
  fieldPathText,
  initialDocument,
  readCommandSchema,
  SchemaUnsupported,
  type FormMember,
  type JsonObject,
} from '../../src/components/schema-form/model';
import { renderKit } from './harness';

/** Where the kernel's command schemas are, the files ProtocolSchemas::commands() binds. */
const COMMAND_SCHEMAS = join(
  import.meta.dirname,
  '../../../../packages/core/resources/schemas/commands',
);

/** The kernel's command schemas by file name. */
function kernelSchemas(): readonly {
  readonly file: string;
  readonly schema: Record<string, unknown>;
}[] {
  return readdirSync(COMMAND_SCHEMAS)
    .filter((file) => file.endsWith('.json'))
    .sort()
    .map((file) => ({
      file,
      schema: JSON.parse(readFileSync(join(COMMAND_SCHEMAS, file), 'utf8')) as Record<
        string,
        unknown
      >,
    }));
}

function schemaOf(file: string): Record<string, unknown> {
  const found = kernelSchemas().find((entry) => entry.file === file);

  if (found === undefined) {
    throw new Error(`No kernel command schema ${file}.`);
  }

  return found.schema;
}

/** The member of the model's root with the key. */
function member(schema: unknown, key: string): FormMember {
  const found = readCommandSchema(schema).root.members.find((candidate) => candidate.key === key);

  if (found === undefined) {
    throw new Error(`No member ${key}.`);
  }

  return found;
}

/** A schema with the property's node changed. */
function planted(
  schema: Record<string, unknown>,
  key: string,
  change: Record<string, unknown>,
): Record<string, unknown> {
  const properties = schema['properties'] as Record<string, Record<string, unknown>>;

  return { ...schema, properties: { ...properties, [key]: { ...properties[key], ...change } } };
}

function Editor({
  schema,
  onDocument,
}: {
  readonly schema: unknown;
  readonly onDocument?: (document: JsonObject) => void;
}) {
  const model = readCommandSchema(schema);
  const [document, setDocument] = useState<JsonObject>(() => initialDocument(model.root));

  return (
    <SchemaForm
      model={model}
      value={document}
      onChange={(next) => {
        setDocument(next);
        onDocument?.(next);
      }}
      idPrefix="form"
    />
  );
}

describe('the kernel command schemas', () => {
  const schemas = kernelSchemas();

  test('are read', () => {
    expect(schemas.length).toBeGreaterThan(10);
  });

  for (const { file, schema } of schemas) {
    test(`${file} is read and rendered without an unsupported-keyword error`, () => {
      const model = readCommandSchema(schema);
      const { container } = renderKit(<Editor schema={schema} />);

      expect(model.root.members.length).toBeGreaterThan(0);

      for (const entry of model.root.members) {
        expect(container.textContent).toContain(entry.title ?? entry.key);
      }
    });
  }
});

describe('a planted schema', () => {
  const schema = schemaOf('actor.activate.v1.json');

  test('with a keyword the form has no field for is refused with the keyword and the pointer', () => {
    expect(() => readCommandSchema(planted(schema, 'version', { multipleOf: 2 }))).toThrow(
      SchemaUnsupported,
    );

    try {
      readCommandSchema(planted(schema, 'version', { multipleOf: 2 }));
    } catch (failure: unknown) {
      expect(failure).toBeInstanceOf(SchemaUnsupported);
      expect((failure as SchemaUnsupported).keyword).toBe('multipleOf');
      expect((failure as SchemaUnsupported).pointer).toBe('#/properties/version');
    }
  });

  test.each([
    ['a format other than date-time', { type: 'string', format: 'email' }, 'format'],
    ['a type the form has no field for', { type: 'number' }, 'type'],
    ['a oneOf', { oneOf: [{ type: 'string' }, { type: 'integer' }] }, 'oneOf'],
    [
      'an anyOf that is not a value and null',
      { anyOf: [{ type: 'string' }, { type: 'integer' }] },
      'anyOf',
    ],
    ['an enum of numbers', { enum: [1, 2] }, 'enum'],
    ['a list of types other than a value and null', { type: ['string', 'integer'] }, 'type'],
    ['a reference to a definition there is none of', { $ref: '#/$defs/missing' }, '$ref'],
  ])('with %s is refused', (_name, change, keyword) => {
    const withoutRef = { ...planted(schema, 'actor', change) };
    const properties = withoutRef['properties'] as Record<string, Record<string, unknown>>;

    if (!('$ref' in change)) {
      delete properties['actor']?.['$ref'];
    }

    expect(() => readCommandSchema(withoutRef)).toThrow(
      expect.objectContaining({ keyword }) as object,
    );
  });

  test('whose key is not required and has no default is refused', () => {
    expect(() => readCommandSchema({ ...schema, required: ['actor'] })).toThrow(
      expect.objectContaining({ keyword: 'required' }) as object,
    );
  });

  test('whose fields member lacks the definitions of the fields of a revision is refused', () => {
    const create = schemaOf('entry.create.v1.json');
    const definitions = Object.fromEntries(
      Object.entries(create['$defs'] as Record<string, unknown>).filter(
        ([name]) => name !== 'field_value',
      ),
    );

    expect(() => readCommandSchema({ ...create, $defs: definitions })).toThrow(
      expect.objectContaining({ keyword: '$ref', pointer: '#/properties/fields' }) as object,
    );
  });
});

describe('the model', () => {
  test('keeps what the schema says about each member', () => {
    const locales = member(schemaOf('grant.assign.v1.json'), 'locales');
    const effect = member(schemaOf('grant.assign.v1.json'), 'effect');
    const window = member(schemaOf('placement.set_window.v1.json'), 'window');
    const fields = member(schemaOf('entry.create.v1.json'), 'fields');
    const name = member(schemaOf('actor.register.v1.json'), 'display_name');
    const slugs = member(schemaOf('placement.create.v1.json'), 'slugs');

    expect(locales).toMatchObject({
      required: false,
      nullable: true,
      hasDefault: true,
      defaultValue: null,
      shape: { kind: 'list', minItems: 1, item: { kind: 'string' } },
    });
    expect(effect.shape).toEqual({ kind: 'enum', values: ['allow', 'deny'] });
    expect(window).toMatchObject({ required: true, nullable: true, hasDefault: false });
    expect(window.shape.kind).toBe('object');

    if (window.shape.kind === 'object') {
      expect(window.shape.members.map((entry) => entry.key)).toEqual(['live_from', 'live_until']);
      expect(window.shape.members[0]).toMatchObject({
        required: false,
        nullable: true,
        defaultValue: null,
        shape: { kind: 'string', format: 'date-time' },
      });
    }

    expect(fields.shape).toEqual({ kind: 'fields' });
    expect(name.shape).toMatchObject({ kind: 'string', minLength: 1, maxLength: 200 });
    expect(slugs.shape.kind).toBe('list');

    if (slugs.shape.kind === 'list') {
      expect(slugs.shape.item.kind).toBe('object');
    }

    expect(fieldPathText(['slugs', 0, 'slug'])).toBe('slugs[0].slug');
    expect(fieldPathText([])).toBe('');
  });

  test('starts the document with the defaults, the required objects and lists, and nothing else', () => {
    expect(initialDocument(readCommandSchema(schemaOf('grant.assign.v1.json')).root)).toEqual({
      locales: null,
    });
    expect(
      initialDocument(readCommandSchema(schemaOf('placement.set_window.v1.json')).root),
    ).toEqual({
      window: null,
    });
    expect(initialDocument(readCommandSchema(schemaOf('placement.create.v1.json')).root)).toEqual({
      slugs: [],
    });
    expect(emptied(member(schemaOf('actor.register.v1.json'), 'responsible'))).toBeUndefined();
    expect(emptied(member(schemaOf('placement.set_window.v1.json'), 'window'))).toBeNull();
    expect(emptied(member(schemaOf('actor.activate.v1.json'), 'actor'))).toBeUndefined();
  });
});

describe('the form', () => {
  test('changes the document as the reader types, and leaves an emptied optional member out', async () => {
    const documents: JsonObject[] = [];
    const { user } = renderKit(
      <Editor
        schema={schemaOf('actor.register.v1.json')}
        onDocument={(document) => {
          documents.push(document);
        }}
      />,
    );

    await user.type(screen.getByLabelText(/^display_name/), 'Ida');
    await user.selectOptions(
      screen.getByRole('combobox', { name: /^class/, hidden: true }),
      'staff',
    );
    await user.type(screen.getByLabelText(/^responsible/), 'x');
    await user.clear(screen.getByLabelText(/^responsible/));

    expect(documents.at(-1)).toEqual({ display_name: 'Ida', class: 'staff' });
  });

  test('sets and unsets a nullable object with its check box, and adds and removes the items of a list', async () => {
    const documents: JsonObject[] = [];
    const { user } = renderKit(
      <Editor
        schema={schemaOf('placement.set_window.v1.json')}
        onDocument={(document) => {
          documents.push(document);
        }}
      />,
    );
    const window = screen.getByRole('group', { name: /^window/ });

    await user.click(within(window).getByRole('checkbox', { name: 'Set a value' }));
    expect(documents.at(-1)).toEqual({ window: { live_from: null, live_until: null } });

    await user.type(within(window).getByLabelText(/^live_from/), '2026-10-07T09:00:00+02:00');
    expect(documents.at(-1)).toEqual({
      window: { live_from: '2026-10-07T09:00:00+02:00', live_until: null },
    });

    await user.click(within(window).getByRole('checkbox', { name: 'Set a value' }));
    expect(documents.at(-1)).toEqual({ window: null });

    documents.length = 0;
    const list = renderKit(
      <Editor
        schema={schemaOf('placement.create.v1.json')}
        onDocument={(document) => {
          documents.push(document);
        }}
      />,
    );
    const slugs = list.getByRole('group', { name: /^slugs/ });

    await list.user.click(within(slugs).getByRole('button', { name: 'Add to slugs' }));
    expect(documents.at(-1)).toEqual({ slugs: [{}] });

    await list.user.type(within(slugs).getByLabelText(/^slug/), 'welcome');
    expect(documents.at(-1)).toEqual({ slugs: [{ slug: 'welcome' }] });

    await list.user.click(within(slugs).getByRole('button', { name: 'Remove slugs 1' }));
    expect(documents.at(-1)).toEqual({ slugs: [] });
  });

  test("shows each error at its field, with the control's id from the prefix", () => {
    const schema = schemaOf('actor.activate.v1.json');
    const model = readCommandSchema(schema);

    renderKit(
      <SchemaForm
        model={model}
        value={{}}
        onChange={() => undefined}
        errors={{ actor: 'is missing, and the field is required' }}
        idPrefix="activate"
      />,
    );

    const actor = screen.getByLabelText(/^actor/);
    const version = screen.getByLabelText(/^version/);

    expect(actor.id).toBe('activate-actor');
    expect(actor.getAttribute('aria-invalid')).toBe('true');
    expect(screen.getByText('is missing, and the field is required')).not.toBeNull();
    // The number field's id is the path too, so an ErrorSummary's link reaches it; its name is on
    // the hidden input that holds the number, as React Aria lays a number field out.
    expect(version.id).toBe('activate-version');
    expect(version.getAttribute('name')).toBeNull();
    expect(document.querySelector('input[type="hidden"][name="version"]')).not.toBeNull();
  });

  test("renders a field's input as renderInput gives it, with the field and the default input, and leaves the others", async () => {
    const schema = schemaOf('actor.activate.v1.json');
    const model = readCommandSchema(schema);
    const documents: JsonObject[] = [];
    const seen: string[] = [];
    const PICKED = 'Picked';

    function Editor() {
      const [value, setValue] = useState<JsonObject>(() => initialDocument(model.root));

      return (
        <SchemaForm
          model={model}
          value={value}
          onChange={(next) => {
            setValue(next);
            documents.push(next);
          }}
          idPrefix="activate"
          errors={{ actor: 'is not an id' }}
          renderInput={(field, input) => {
            seen.push(`${field.path}:${field.id}:${String(field.required)}:${field.error ?? ''}`);

            if (field.path !== 'actor') {
              return input;
            }

            return (
              <label>
                {[PICKED, field.label].join(' ')}
                <input
                  id={field.id}
                  name={field.name}
                  value={typeof field.value === 'string' ? field.value : ''}
                  onChange={(event) => {
                    field.onChange(event.target.value === '' ? field.emptied : event.target.value);
                  }}
                />
              </label>
            );
          }}
        />
      );
    }

    const { user } = renderKit(<Editor />);

    const picked = screen.getByLabelText(/^Picked actor/);
    expect(picked.id).toBe('activate-actor');
    expect(screen.queryByLabelText(/^actor/)).toBeNull();
    expect(screen.getByLabelText(/^version/)).not.toBeNull();
    expect(seen.slice(0, 2)).toEqual([
      'actor:activate-actor:true:is not an id',
      'version:activate-version:true:',
    ]);

    await user.type(picked, 'abc');
    await user.clear(picked);

    expect(documents.at(-2)).toEqual({ actor: 'abc' });
    expect(documents.at(-1)).toEqual({});
  });
});
