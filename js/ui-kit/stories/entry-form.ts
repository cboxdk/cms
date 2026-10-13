// The type editor descriptor the stories of EntryForm render: notes:event, a type of a caller of
// the kit, in the form type.describe answers, with a field of every base, every kind of select,
// a group, a repeated group with a summary template, a relation to one entry and one to several,
// a conditional field, a hidden and a
// read-only field, tabs, a sidebar and every width, labelled in Danish and English.

import type {
  EditorFieldV1,
  EditorPlacementV1,
  EditorSectionV1,
  JsonDocument,
  TypeEditorV1,
} from '@cboxdk/cms-ui-kit';

/** A text in each locale of the kit. */
function texts(da: string, en: string) {
  return [
    { locale: 'da', text: da },
    { locale: 'en', text: en },
  ];
}

const UI = {
  display: null,
  hidden: false,
  input_hint: null,
  input_hint_by_locale: [],
  instructions: null,
  instructions_by_locale: [],
  read_only: false,
  summary: null,
} as const;

/** A field of the type: the owner's, top-level unless it names its group. */
function field(
  path: string,
  base: EditorFieldV1['base'],
  [da, en]: readonly [string, string],
  rule: JsonDocument | null,
  more: Partial<EditorFieldV1> = {},
): EditorFieldV1 {
  const parent = path.includes('.') ? path.slice(0, path.lastIndexOf('.')) : null;

  return {
    agents: true,
    base,
    choices: [],
    classification: 'public',
    default: null,
    description: null,
    descriptions: [],
    encrypted: false,
    fallback: 'variant',
    handle: path.slice(path.lastIndexOf('.') + 1),
    label: en,
    labels: texts(da, en),
    namespace: null,
    parent,
    path,
    presence: 'optional',
    repeat: null,
    rule,
    targets: [],
    translatable: false,
    type: base,
    ui: UI,
    when: null,
    when_hidden: null,
    ...more,
  };
}

/** The choices of a select, each value with its labels. */
function choices(...values: readonly (readonly [string, string, string])[]) {
  return values.map(([value, da, en]) => ({ value, label: en, labels: texts(da, en) }));
}

const FORMATS = choices(['venue', 'På stedet', 'At the venue'], ['online', 'Online', 'Online']);
const LEVELS = choices(
  ['intro', 'Begynder', 'Beginner'],
  ['mid', 'Øvet', 'Intermediate'],
  ['expert', 'Ekspert', 'Expert'],
);
const VENUES = choices(
  ['hall_a', 'Sal A', 'Hall A'],
  ['hall_b', 'Sal B', 'Hall B'],
  ['hall_c', 'Sal C', 'Hall C'],
  ['garden', 'Haven', 'The garden'],
  ['roof', 'Taget', 'The roof'],
);
const TOPICS = choices(
  ['design', 'Design', 'Design'],
  ['code', 'Kode', 'Code'],
  ['data', 'Data', 'Data'],
);
const LANGUAGES = choices(
  ['da', 'Dansk', 'Danish'],
  ['en', 'Engelsk', 'English'],
  ['de', 'Tysk', 'German'],
  ['sv', 'Svensk', 'Swedish'],
  ['no', 'Norsk', 'Norwegian'],
);
const KINDS = choices(['talk', 'Foredrag', 'Talk'], ['workshop', 'Workshop', 'Workshop']);

/** The pattern of an entry id, a UUIDv7, as a relation's value rule has it. */
const ENTRY_ID =
  '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-7[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$';

const FIELDS: readonly EditorFieldV1[] = [
  field(
    'title',
    'text',
    ['Titel', 'Title'],
    { kind: 'text', minLength: 1, maxLength: 120 },
    {
      presence: 'required',
      ui: {
        ...UI,
        input_hint: 'A day of design',
        input_hint_by_locale: texts('En dag om design', 'A day of design'),
      },
    },
  ),
  field('contact', 'text', ['Kontakt', 'Contact'], {
    kind: 'text',
    maxLength: 255,
    format: 'email',
  }),
  field('website', 'text', ['Hjemmeside', 'Website'], {
    kind: 'text',
    maxLength: 255,
    format: 'url',
  }),
  field(
    'summary',
    'long_text',
    ['Resumé', 'Summary'],
    { kind: 'text', maxLength: 2000 },
    {
      descriptions: texts('Et par linjer til oversigten.', 'A few lines for the overview.'),
      description: 'A few lines for the overview.',
    },
  ),
  field(
    'seats',
    'integer',
    ['Pladser', 'Seats'],
    { kind: 'integer', min: 1, max: 500 },
    {
      default: { kind: 'constant', value: 40 },
    },
  ),
  field(
    'price',
    'decimal',
    ['Pris', 'Price'],
    { kind: 'decimal', precision: 8, scale: 2, min: '0.00' },
    {
      presence: 'required',
    },
  ),
  field('free', 'boolean', ['Gratis', 'Free'], { kind: 'boolean' }),
  field('starts_on', 'date', ['Dato', 'Date'], {
    kind: 'date',
    min: '2000-01-01',
    max: '2099-12-31',
  }),
  field('starts_at', 'datetime', ['Starter', 'Starts'], { kind: 'datetime' }),
  field(
    'format',
    'select',
    ['Form', 'Format'],
    { kind: 'choice', choices: ['venue', 'online'] },
    {
      presence: 'required',
      choices: FORMATS,
      ui: {
        ...UI,
        display: 'radio',
        instructions: 'Where the guests take part.',
        instructions_by_locale: texts('Hvor gæsterne deltager.', 'Where the guests take part.'),
      },
    },
  ),
  field(
    'online_link',
    'text',
    ['Link til mødet', 'Meeting link'],
    { kind: 'text', maxLength: 255, format: 'url' },
    {
      presence: 'required',
      when: { op: 'eq', field: { up: 0, key: 'format' }, value: 'online' },
      when_hidden: 'clear',
    },
  ),
  field(
    'level',
    'select',
    ['Niveau', 'Level'],
    { kind: 'choice', choices: ['intro', 'mid', 'expert'] },
    {
      choices: LEVELS,
      ui: { ...UI, display: 'buttons' },
    },
  ),
  field(
    'venue',
    'select',
    ['Sal', 'Venue'],
    { kind: 'choice', choices: VENUES.map((choice) => choice.value) },
    {
      choices: VENUES,
      ui: { ...UI, display: 'dropdown' },
    },
  ),
  field(
    'topics',
    'select',
    ['Emner', 'Topics'],
    {
      kind: 'list',
      item: { kind: 'choice', choices: ['design', 'code', 'data'] },
      minItems: 1,
      maxItems: 2,
      distinct: true,
    },
    { choices: TOPICS },
  ),
  field(
    'languages',
    'select',
    ['Sprog', 'Languages'],
    {
      kind: 'list',
      item: { kind: 'choice', choices: LANGUAGES.map((choice) => choice.value) },
      distinct: true,
    },
    { choices: LANGUAGES, ui: { ...UI, display: 'dropdown' } },
  ),
  field('agenda', 'rich_text', ['Program', 'Agenda'], {
    kind: 'portable_text',
    styles: ['normal', 'h2'],
    marks: ['strong', 'em'],
    lists: ['bullet'],
    links: ['url'],
  }),
  field(
    'follows',
    'relation',
    ['Følger efter', 'Follows'],
    { kind: 'string', pattern: ENTRY_ID },
    { targets: ['notes:event'] },
  ),
  field(
    'speakers',
    'relation',
    ['Talere', 'Speakers'],
    { kind: 'list', item: { kind: 'string', pattern: ENTRY_ID }, maxItems: 12, distinct: true },
    { targets: ['notes:person', 'notes:organisation'] },
  ),
  field('organiser', 'group', ['Arrangør', 'Organiser'], null),
  field(
    'organiser.name',
    'text',
    ['Navn', 'Name'],
    { kind: 'text', maxLength: 255 },
    { presence: 'required' },
  ),
  field('organiser.phone', 'text', ['Telefon', 'Phone'], { kind: 'text', maxLength: 30 }),
  field('sessions', 'group', ['Programpunkter', 'Sessions'], null, {
    repeat: { min_items: 1, max_items: 6 },
    ui: { ...UI, summary: '{title} ({minutes} min)' },
  }),
  field(
    'sessions.title',
    'text',
    ['Titel', 'Title'],
    { kind: 'text', maxLength: 120 },
    { presence: 'required' },
  ),
  field('sessions.minutes', 'integer', ['Minutter', 'Minutes'], {
    kind: 'integer',
    min: 5,
    max: 480,
  }),
  field(
    'sessions.kind',
    'select',
    ['Slags', 'Kind'],
    { kind: 'choice', choices: ['talk', 'workshop'] },
    {
      choices: KINDS,
    },
  ),
  field(
    'internal_code',
    'text',
    ['Intern kode', 'Internal code'],
    { kind: 'text', maxLength: 20 },
    {
      ui: { ...UI, hidden: true },
    },
  ),
  field(
    'created_at',
    'datetime',
    ['Oprettet', 'Created'],
    { kind: 'datetime' },
    {
      ui: { ...UI, read_only: true },
    },
  ),
];

/** A section of the layout, without a heading unless it is given labels. */
function section(
  handle: string,
  fields: readonly EditorPlacementV1[],
  more: Partial<EditorSectionV1> = {},
): EditorSectionV1 {
  return {
    collapsible: false,
    fields,
    handle,
    instructions: null,
    instructions_by_locale: [],
    label: null,
    labels: [],
    ...more,
  };
}

function placed(path: string, width: EditorPlacementV1['width'] = 'full'): EditorPlacementV1 {
  return { path, width };
}

/** notes:event with its layout: two tabs and a sidebar. */
export const EVENT: TypeEditorV1 = {
  capabilities: { history: 'full', localization: 'none', routable: true, stages: 'draft-release' },
  description: null,
  descriptions: [],
  extensions: [],
  fields: FIELDS,
  id: '0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a77',
  label: 'Event',
  labels: texts('Arrangement', 'Event'),
  layout: {
    tabs: [
      {
        handle: 'content',
        label: 'Content',
        labels: texts('Indhold', 'Content'),
        sections: [
          section('main', [
            placed('title', 'two_thirds'),
            placed('price', 'third'),
            placed('summary'),
            placed('format', 'half'),
            placed('online_link', 'half'),
            placed('agenda'),
          ]),
          section('people', [placed('organiser'), placed('speakers'), placed('sessions')], {
            label: 'People',
            labels: texts('Personer', 'People'),
            collapsible: true,
            instructions: 'Who runs the event and what happens when.',
            instructions_by_locale: texts(
              'Hvem står for arrangementet, og hvad sker der hvornår.',
              'Who runs the event and what happens when.',
            ),
          }),
        ],
      },
      {
        handle: 'practical',
        label: 'Practical',
        labels: texts('Praktisk', 'Practical'),
        sections: [
          section('place', [
            placed('venue', 'half'),
            placed('seats', 'half'),
            placed('level'),
            placed('topics', 'half'),
            placed('languages', 'half'),
            placed('contact', 'half'),
            placed('website', 'half'),
            placed('follows'),
            placed('internal_code'),
          ]),
        ],
      },
    ],
    sidebar: [
      section(
        'when',
        [placed('starts_on'), placed('starts_at'), placed('free'), placed('created_at')],
        {
          label: 'When',
          labels: texts('Hvornår', 'When'),
        },
      ),
    ],
  },
  name: 'notes:event',
  title_field: 'title',
  version: 1,
};

/** notes:event with one tab and one section of the fields at the paths, at their full width. */
export function only(...paths: readonly string[]): TypeEditorV1 {
  const [first] = EVENT.layout.tabs;

  if (first === undefined) {
    throw new Error('notes:event has no tab.');
  }

  return {
    ...EVENT,
    layout: {
      tabs: [
        {
          ...first,
          sections: [
            section(
              'only',
              paths.map((path) => placed(path)),
            ),
          ],
        },
      ],
      sidebar: [],
    },
  };
}
