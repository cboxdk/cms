import { ActorChip, Badge, Button, Inline, type InlineProps } from '@cboxdk/cms-ui-kit';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  textsOf,
  type Localized,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<InlineProps> = {
  title: 'Components/Layout/Inline',
  component: Inline,
};

export default meta;

const TEXTS: Localized<{ state: string; edit: string }> = {
  da: { state: 'Aktiv', edit: 'Redigér' },
  en: { state: 'Active', edit: 'Edit' },
};

/** Children side by side; between spreads them to the ends of the row. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <Inline justify="between">
        <Inline>
          <ActorChip name="Ada Lovelace" email="ada@example.com" />
          <Badge tone="success">{texts.state}</Badge>
        </Inline>
        <Button>{texts.edit}</Button>
      </Inline>
    );
  },
  play: ({ canvasElement }) => {
    const row = canvasElement.querySelector('[data-justify="between"]');

    check(row instanceof HTMLDivElement, 'the row is rendered');
    check(getComputedStyle(row).justifyContent === 'space-between', 'between spreads the row');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
