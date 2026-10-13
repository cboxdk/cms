import {
  EntryForm,
  type EntryFormProps,
  type JsonObject,
  type TypeEditorV1,
} from '@cboxdk/cms-ui-kit';
import { useState } from 'react';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  single,
  storyLocale,
  waitFor,
  type Story,
  type StoryGlobals,
  type StoryMeta,
} from './csf';
import { EVENT, only } from './entry-form';

const meta: StoryMeta<EntryFormProps> = {
  title: 'Components/Forms/EntryForm',
  component: EntryForm,
};

export default meta;

/** The clock of the stories, so a new item's defaults are the same on every run. */
const NOW = new Date('2026-10-13T08:00:00Z');

function Editor({
  globals,
  descriptor = EVENT,
  start = {},
  showErrors,
  stage,
}: {
  readonly globals: StoryGlobals;
  readonly descriptor?: TypeEditorV1;
  readonly start?: JsonObject;
  readonly showErrors?: EntryFormProps['showErrors'];
  readonly stage?: EntryFormProps['stage'];
}) {
  const [fields, setFields] = useState<JsonObject>(start);

  return (
    <div data-fields={JSON.stringify(fields)}>
      <EntryForm
        descriptor={descriptor}
        value={fields}
        onChange={setFields}
        locale={storyLocale(globals)}
        idPrefix="event"
        showErrors={showErrors}
        stage={stage}
        now={() => NOW}
      />
    </div>
  );
}

/** The fields the story's form holds now. */
function held(canvas: HTMLElement): string {
  return single(canvas, '[data-fields]', HTMLDivElement).dataset['fields'] ?? '';
}

/** The form of notes:event: its tabs, its sections, one of them collapsible, and its sidebar. */
export const Default: Story = {
  render: (_args, { globals }) => (
    <Editor
      globals={globals}
      start={{ format: 'venue', seats: 40, created_at: '2026-10-01T09:00:00Z' }}
    />
  ),
  play: async ({ canvasElement, userEvent }) => {
    await userEvent.type(
      single(canvasElement, '[id="event-title"]', HTMLInputElement),
      'Design day',
    );
    await waitFor(
      () => held(canvasElement).includes('"title":"Design day"'),
      'typing changes the fields',
    );
  },
};

/** Text: plain, an email address and a URL, each with its own keyboard. */
export const Text: Story = {
  render: (_args, { globals }) => (
    <Editor
      globals={globals}
      descriptor={only('title', 'contact', 'website')}
      start={{ website: 'https://example.org' }}
    />
  ),
  play: ({ canvasElement }) => {
    check(
      single(canvasElement, '[id="event-contact"]', HTMLInputElement).type === 'email',
      'an email field',
    );
    check(
      single(canvasElement, '[id="event-website"]', HTMLInputElement).type === 'url',
      'a url field',
    );
  },
};

/** Long text, in a text area. */
export const LongText: Story = {
  render: (_args, { globals }) => (
    <Editor
      globals={globals}
      descriptor={only('summary')}
      start={{ summary: 'Talks and workshops\nall day.' }}
    />
  ),
  play: ({ canvasElement }) => {
    check(
      single(canvasElement, '[id="event-summary"]', HTMLTextAreaElement).value.includes('\n'),
      'lines kept',
    );
  },
};

/** An integer within its bounds. */
export const Integer: Story = {
  render: (_args, { globals }) => (
    <Editor globals={globals} descriptor={only('seats')} start={{ seats: 40 }} />
  ),
  play: ({ canvasElement }) => {
    check(canvasElement.querySelector('[id="event-seats"]') !== null, 'the number field is there');
  },
};

/** A decimal, written as text so no digit is lost. */
export const Decimal: Story = {
  render: (_args, { globals }) => (
    <Editor globals={globals} descriptor={only('price')} start={{ price: '12.50' }} />
  ),
  play: ({ canvasElement }) => {
    check(
      single(canvasElement, '[id="event-price"]', HTMLInputElement).inputMode === 'decimal',
      'a decimal keyboard',
    );
  },
};

/** A boolean, as a check box. */
export const BooleanField: Story = {
  name: 'Boolean',
  render: (_args, { globals }) => <Editor globals={globals} descriptor={only('free')} />,
  play: async ({ canvasElement, userEvent }) => {
    await userEvent.click(single(canvasElement, 'input[type="checkbox"]', HTMLInputElement));
    await waitFor(
      () => held(canvasElement).includes('"free":true'),
      'checking the box sets the field',
    );
  },
};

/** A date, in the browser's date control. */
export const DateField: Story = {
  name: 'Date',
  render: (_args, { globals }) => (
    <Editor globals={globals} descriptor={only('starts_on')} start={{ starts_on: '2026-11-01' }} />
  ),
  play: ({ canvasElement }) => {
    check(
      single(canvasElement, '[id="event-starts_on"]', HTMLInputElement).type === 'date',
      'a date control',
    );
  },
};

/** A date and a time, and one that is read-only. */
export const Datetime: Story = {
  render: (_args, { globals }) => (
    <Editor
      globals={globals}
      descriptor={only('starts_at', 'created_at')}
      start={{ starts_at: '2026-11-01T09:30:00Z', created_at: '2026-10-01T09:00:00Z' }}
    />
  ),
  play: ({ canvasElement }) => {
    check(
      single(canvasElement, '[id="event-created_at"]', HTMLInputElement).readOnly,
      'a read-only field',
    );
  },
};

/** One choice: radio buttons, buttons in a row and a dropdown, by the ui display. */
export const Select: Story = {
  render: (_args, { globals }) => (
    <Editor
      globals={globals}
      descriptor={only('format', 'level', 'venue')}
      start={{ format: 'venue', level: 'mid' }}
    />
  ),
  play: ({ canvasElement }) => {
    check(canvasElement.querySelectorAll('[role="radiogroup"]').length === 2, 'two radio groups');
    check(
      canvasElement.querySelector('[id="event-venue"]') instanceof HTMLButtonElement,
      'a dropdown',
    );
  },
};

/** Several choices: check boxes, and a multiple select as a dropdown. */
export const MultipleSelect: Story = {
  render: (_args, { globals }) => (
    <Editor
      globals={globals}
      descriptor={only('topics', 'languages')}
      start={{ topics: ['design'], languages: ['da', 'en'] }}
    />
  ),
  play: async ({ canvasElement, userEvent }) => {
    const boxes = [...canvasElement.querySelectorAll('input[type="checkbox"]')];
    const code = boxes.at(1);
    check(code instanceof HTMLInputElement, 'a check box per topic');
    await userEvent.click(code);
    await waitFor(
      () => held(canvasElement).includes('"topics":["design","code"]'),
      'a topic is added',
    );
  },
};

/** A group: a fieldset of its fields. */
export const Group: Story = {
  render: (_args, { globals }) => (
    <Editor
      globals={globals}
      descriptor={only('organiser')}
      start={{ organiser: { name: 'Ada' } }}
    />
  ),
  play: ({ canvasElement }) => {
    check(canvasElement.querySelector('fieldset') !== null, 'a fieldset');
  },
};

/** A repeated group: items collapsed to their summary, which the editor adds, moves and removes. */
export const RepeatedGroup: Story = {
  render: (_args, { globals }) => (
    <Editor
      globals={globals}
      descriptor={only('sessions')}
      start={{
        sessions: [
          { title: 'Opening', minutes: 15, kind: 'talk' },
          { title: 'Sketching', minutes: 90 },
        ],
      }}
    />
  ),
  play: async ({ canvasElement, userEvent }) => {
    const add = [...canvasElement.querySelectorAll('button')].at(-1);
    check(add instanceof HTMLButtonElement, 'a button that adds an item');
    await userEvent.click(add);
    await waitFor(() => held(canvasElement).endsWith(',{}]}'), 'an item is added');
  },
};

/** Rich text, as Portable Text in JSON until the rich text editor takes its place. */
export const RichText: Story = {
  render: (_args, { globals }) => (
    <Editor
      globals={globals}
      descriptor={only('agenda')}
      start={{
        agenda: [
          {
            _type: 'block',
            _key: 'a',
            style: 'normal',
            children: [{ _type: 'span', _key: 's', text: 'Doors open at nine.' }],
          },
        ],
      }}
    />
  ),
  play: ({ canvasElement }) => {
    check(
      single(canvasElement, '[id="event-agenda"]', HTMLTextAreaElement).value.includes(
        'Doors open',
      ),
      'the JSON',
    );
  },
};

/** Relations: the id of the event this one follows, and the ids of its speakers one a line. */
export const Relation: Story = {
  render: (_args, { globals }) => (
    <Editor
      globals={globals}
      descriptor={only('follows', 'speakers')}
      start={{ speakers: ['0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01'] }}
    />
  ),
  play: async ({ canvasElement, userEvent }) => {
    await userEvent.type(
      single(canvasElement, '[id="event-speakers"]', HTMLTextAreaElement),
      '\n0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02',
    );
    await waitFor(
      () =>
        held(canvasElement).includes(
          '"speakers":["0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a01","0199a3c1-2b4d-7e5f-8a6b-1c2d3e4f5a02"]',
        ),
      'a speaker is added on a line of its own',
    );
  },
};

/** A conditional field: the meeting link is shown for an online event and cleared when hidden. */
export const Conditional: Story = {
  render: (_args, { globals }) => (
    <Editor
      globals={globals}
      descriptor={only('format', 'online_link')}
      start={{ format: 'venue' }}
    />
  ),
  play: async ({ canvasElement, userEvent }) => {
    check(canvasElement.querySelector('[id="event-online_link"]') === null, 'hidden for a venue');
    const online = [...canvasElement.querySelectorAll('input[type="radio"]')].at(1);
    check(online instanceof HTMLInputElement, 'a radio button for online');
    await userEvent.click(online);
    await waitFor(
      () => canvasElement.querySelector('[id="event-online_link"]') !== null,
      'shown for online',
    );
  },
};

/** Every error of the client, with the summary above the form, on the tab of the first. */
export const WithErrors: Story = {
  render: (_args, { globals }) => (
    <Editor
      globals={globals}
      showErrors="all"
      stage="release"
      start={{ format: 'online', price: '1.234', seats: 900 }}
    />
  ),
  play: ({ canvasElement }) => {
    check(
      single(canvasElement, '[id="event-title"]', HTMLInputElement).getAttribute('aria-invalid') ===
        'true',
      'a field with an error is marked',
    );
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
