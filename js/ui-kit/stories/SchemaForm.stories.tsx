import {
  SchemaForm,
  initialDocument,
  readCommandSchema,
  type JsonObject,
  type SchemaFormProps,
} from '@cboxdk/cms-ui-kit';
import { useState } from 'react';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  single,
  textsOf,
  waitFor,
  type Localized,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<SchemaFormProps> = {
  title: 'Components/Forms/SchemaForm',
  component: SchemaForm,
};

export default meta;

/**
 * A schema of the kind the kernel's commands have: an id with a pattern, a version with a bound, an
 * enum, a boolean, a nullable object of two date-times, a list of objects and the fields of a
 * revision. The stories are callers of the kit, so the schema is theirs.
 */
const SCHEMA = {
  $schema: 'https://json-schema.org/draft/2020-12/schema',
  title: 'notes.publish, contract version 1',
  description: 'Publishes a note on the sites given, now or in a window.',
  type: 'object',
  additionalProperties: false,
  required: ['note', 'version', 'visibility', 'window', 'targets', 'fields'],
  properties: {
    note: { description: 'The id of the note.', $ref: '#/$defs/id' },
    version: {
      description: 'The version of the note the caller read.',
      type: 'integer',
      minimum: 1,
    },
    visibility: { description: 'Who sees the note.', enum: ['public', 'members'] },
    notify: { description: 'Whether to tell the subscribers.', type: 'boolean', default: false },
    window: {
      description: 'When the note is live, or null for now.',
      anyOf: [{ $ref: '#/$defs/window' }, { type: 'null' }],
    },
    targets: {
      description: 'The sites and locales the note is published on, at least one.',
      type: 'array',
      minItems: 1,
      items: { $ref: '#/$defs/target' },
    },
    fields: { description: 'The fields of the note.', $ref: '#/$defs/fields' },
  },
  $defs: {
    id: {
      type: 'string',
      pattern:
        '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-7[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$',
      examples: ['0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01'],
    },
    window: {
      type: 'object',
      additionalProperties: false,
      properties: {
        live_from: {
          description: 'From when, RFC 3339.',
          type: ['string', 'null'],
          format: 'date-time',
          default: null,
        },
        live_until: {
          description: 'Until when, RFC 3339.',
          type: ['string', 'null'],
          format: 'date-time',
          default: null,
        },
      },
    },
    target: {
      type: 'object',
      additionalProperties: false,
      required: ['site', 'locale'],
      properties: {
        site: {
          description: 'The site.',
          type: 'string',
          pattern: '^[a-z][a-z0-9_]{0,62}$',
          examples: ['main'],
        },
        locale: {
          description: 'The locale.',
          type: 'string',
          pattern: '^[a-zA-Z]{2,3}(-[a-zA-Z]{4})?$',
          examples: ['da'],
        },
      },
    },
    fields: { type: 'object', additionalProperties: {} },
    extension_fields: { type: 'object' },
    field_handle: { type: 'string' },
    field_namespace: { type: 'string' },
    field_value: {},
  },
};

const MODEL = readCommandSchema(SCHEMA);

const TEXTS: Localized<{
  labels: Record<string, string>;
  options: Record<string, string>;
  fieldsError: string;
}> = {
  da: {
    labels: {
      note: 'Note',
      version: 'Version',
      visibility: 'Synlighed',
      notify: 'Giv abonnenterne besked',
      window: 'Vindue',
      live_from: 'Fra',
      live_until: 'Til',
      targets: 'Mål',
      site: 'Site',
      locale: 'Sprog',
      fields: 'Felter',
    },
    options: { public: 'Alle', members: 'Medlemmer' },
    fieldsError: 'Felterne skal være et objekt af værdier.',
  },
  en: {
    labels: {
      note: 'Note',
      version: 'Version',
      visibility: 'Visibility',
      notify: 'Tell the subscribers',
      window: 'Window',
      live_from: 'From',
      live_until: 'Until',
      targets: 'Targets',
      site: 'Site',
      locale: 'Locale',
      fields: 'Fields',
    },
    options: { public: 'Everyone', members: 'Members' },
    fieldsError: 'The fields must be an object of values.',
  },
};

function Editor({
  globals,
  errors,
}: {
  readonly globals: Readonly<Record<string, unknown>>;
  readonly errors?: Readonly<Record<string, string>>;
}) {
  const texts = textsOf(TEXTS, globals);
  const [document, setDocument] = useState<JsonObject>(() => initialDocument(MODEL.root));

  return (
    <div data-document={JSON.stringify(document)}>
      <SchemaForm
        model={MODEL}
        value={document}
        onChange={setDocument}
        errors={errors}
        idPrefix="notes-publish"
        texts={{
          label: (keys, fallback) => texts.labels[keys.at(-1) ?? ''] ?? fallback,
          option: (_keys, value) => texts.options[value] ?? value,
        }}
        checkJson={(value) =>
          typeof value === 'object' && value !== null && !Array.isArray(value)
            ? []
            : [texts.fieldsError]
        }
      />
    </div>
  );
}

/** The form as it starts: the required fields, the defaults, and the nullable object unset. */
export const Default: Story = {
  render: (_args, { globals }) => <Editor globals={globals} />,
  play: async ({ canvasElement, userEvent }) => {
    const note = single(canvasElement, '#notes-publish-note', HTMLInputElement);
    const holder = single(canvasElement, '[data-document]', HTMLDivElement);

    await userEvent.type(note, '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01');
    await waitFor(
      () => holder.dataset['document']?.includes('"note":"0199a3c1') === true,
      'typing into a field changes the document',
    );
    check(
      holder.dataset['document']?.includes('"notify":false') === true,
      'a boolean with a default starts with it',
    );
  },
};

/** The errors the validator or the server gave, each at its field. */
export const WithErrors: Story = {
  render: (_args, { globals }) => (
    <Editor
      globals={globals}
      errors={{
        note: 'is missing, and the field is required',
        version: 'is less than 1',
        targets: 'has fewer than 1 items',
      }}
    />
  ),
  play: ({ canvasElement }) => {
    const note = single(canvasElement, '#notes-publish-note', HTMLInputElement);

    check(note.getAttribute('aria-invalid') === 'true', 'a field with an error is marked');
  },
};

/** An item added to the list, and the nullable object set. */
export const Expanded: Story = {
  render: Default.render,
  play: async ({ canvasElement, userEvent }) => {
    const holder = single(canvasElement, '[data-document]', HTMLDivElement);
    const buttons = [...canvasElement.querySelectorAll('button')];
    const add = buttons.find(
      (button) => button.textContent.includes('Targets') || button.textContent.includes('Mål'),
    );
    const set = [...canvasElement.querySelectorAll('input[type="checkbox"]')].at(-1);

    check(add instanceof HTMLButtonElement, 'the list has a button that adds an item');
    check(set instanceof HTMLInputElement, 'the nullable object has a check box that sets it');
    await userEvent.click(add);
    await userEvent.click(set);
    await waitFor(
      () =>
        holder.dataset['document']?.includes('"targets":[{') === true &&
        holder.dataset['document'].includes('"window":{"live_from":null'),
      'an item is added and the object is set',
    );
  },
};

export const Dark: Story = inDark(Expanded);
export const ForcedColors: Story = inForcedColours(Expanded);
export const Danish: Story = inDanish(Expanded);
