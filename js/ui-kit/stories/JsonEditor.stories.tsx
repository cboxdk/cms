import { JsonEditor, type JsonEditorProps } from '@cboxdk/cms-ui-kit';
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

const meta: StoryMeta<JsonEditorProps> = {
  title: 'Components/Forms/JsonEditor',
  component: JsonEditor,
};

export default meta;

const TEXTS: Localized<{ label: string; description: string; titleRequired: string }> = {
  da: {
    label: 'Felter',
    description: 'Revisionens felter som JSON.',
    titleRequired: 'Feltet title skal være en tekst.',
  },
  en: {
    label: 'Fields',
    description: 'The fields of the revision as JSON.',
    titleRequired: 'The field title must be a text.',
  },
};

function Editor({
  globals,
  initial,
}: {
  readonly globals: Readonly<Record<string, unknown>>;
  readonly initial: string;
}) {
  const texts = textsOf(TEXTS, globals);
  const [text, setText] = useState(initial);

  return (
    <JsonEditor
      label={texts.label}
      description={texts.description}
      value={text}
      onChange={(next) => {
        setText(next);
      }}
      validate={(value) =>
        typeof value === 'object' &&
        value !== null &&
        typeof (value as Record<string, unknown>)['title'] === 'string'
          ? []
          : [texts.titleRequired]
      }
    />
  );
}

/** A value the validator takes. */
export const Valid: Story = {
  render: (_args, { globals }) => (
    <Editor globals={globals} initial={'{\n  "title": "Launch"\n}'} />
  ),
  play: ({ canvasElement }) => {
    const area = single(canvasElement, 'textarea', HTMLTextAreaElement);

    check(area.getAttribute('aria-invalid') === null, 'a valid value is not marked');
  },
};

/** Text that stops being JSON as it is typed: the editor says why. */
export const NotJson: Story = {
  render: Valid.render,
  play: async ({ canvasElement, userEvent }) => {
    const area = single(canvasElement, 'textarea', HTMLTextAreaElement);

    await userEvent.click(area);
    await userEvent.keyboard('{End},');
    await waitFor(() => area.getAttribute('aria-invalid') === 'true', 'the text is refused');
  },
};

/** JSON the caller's validator refuses: its message is shown below. */
export const Refused: Story = {
  render: (_args, { globals }) => <Editor globals={globals} initial={'{\n  "title": 4\n}'} />,
  play: async ({ canvasElement, userEvent }) => {
    const area = single(canvasElement, 'textarea', HTMLTextAreaElement);

    await userEvent.click(area);
    await userEvent.keyboard('{End} ');
    await waitFor(() => area.getAttribute('aria-invalid') === 'true', 'the value is refused');
  },
};

export const Dark: Story = inDark(Refused);
export const ForcedColors: Story = inForcedColours(Refused);
export const Danish: Story = inDanish(Refused);
