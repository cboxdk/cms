import { Tag, type TagProps } from '@cboxdk/cms-ui-kit';
import { useState } from 'react';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  textsOf,
  waitFor,
  type Localized,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<TagProps> = {
  title: 'Components/Data display/Tag',
  component: Tag,
};

export default meta;

const TEXTS: Localized<{ danish: string; english: string; deny: string }> = {
  da: { danish: 'Dansk', english: 'Engelsk', deny: 'Afvis' },
  en: { danish: 'Danish', english: 'English', deny: 'Deny' },
};

function Tags({ globals }: { readonly globals: Readonly<Record<string, unknown>> }) {
  const texts = textsOf(TEXTS, globals);
  const [shown, setShown] = useState([texts.danish, texts.english]);

  return (
    <div style={{ display: 'flex', gap: '0.5rem' }}>
      {shown.map((label) => (
        <Tag
          key={label}
          label={label}
          onRemove={() => {
            setShown((current) => current.filter((candidate) => candidate !== label));
          }}
        />
      ))}
      <Tag label={texts.deny} tone="danger" />
    </div>
  );
}

/** Tags with remove buttons, and one that is text only. */
export const Default: Story = {
  render: (_args, { globals }) => <Tags globals={globals} />,
  play: ({ canvasElement }) => {
    const remove = canvasElement.querySelectorAll('button');

    check(remove.length === 2, 'a tag with onRemove has a button');
    check(remove[0]?.getAttribute('aria-label') !== null, 'the button is named');
  },
};

/** Enter on a tag's button removes it. */
export const Keyboard: Story = {
  render: Default.render,
  play: async ({ canvasElement, userEvent }) => {
    await userEvent.tab();
    await userEvent.keyboard('{Enter}');
    await waitFor(() => canvasElement.querySelectorAll('.cms-tag').length === 2, 'it is removed');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
