import { SegmentedControl, type SegmentedControlProps } from '@cboxdk/cms-ui-kit';
import { useState } from 'react';

import {
  check,
  focused,
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

const meta: StoryMeta<SegmentedControlProps> = {
  title: 'Components/Forms/SegmentedControl',
  component: SegmentedControl,
};

export default meta;

const TEXTS: Localized<{ label: string; comfortable: string; compact: string }> = {
  da: { label: 'Tæthed', comfortable: 'Komfortabel', compact: 'Kompakt' },
  en: { label: 'Density', comfortable: 'Comfortable', compact: 'Compact' },
};

function Density({ globals }: { readonly globals: Readonly<Record<string, unknown>> }) {
  const texts = textsOf(TEXTS, globals);
  const [value, setValue] = useState('comfortable');

  return (
    <SegmentedControl
      label={texts.label}
      options={[
        { id: 'comfortable', label: texts.comfortable },
        { id: 'compact', label: texts.compact },
      ]}
      value={value}
      onChange={setValue}
    />
  );
}

/** Two choices, the first pressed. */
export const Default: Story = {
  render: (_args, { globals }) => <Density globals={globals} />,
  play: ({ canvasElement }) => {
    single(canvasElement, '[role="radiogroup"]', HTMLDivElement);
    check(
      canvasElement.querySelectorAll('[role="radio"][aria-checked="true"]').length === 1,
      'one choice is made',
    );
  },
};

/** Tab reaches the choice made, the arrow keys move to the other and Enter makes it. */
export const Keyboard: Story = {
  render: Default.render,
  play: async ({ canvasElement, userEvent }) => {
    await userEvent.tab();
    check(focused(HTMLButtonElement).textContent.length > 0, 'Tab reaches a choice');
    await userEvent.keyboard('{ArrowRight}{Enter}');
    await waitFor(
      () => canvasElement.querySelectorAll('button')[1]?.matches('[aria-checked="true"]') === true,
      'Enter makes the other choice',
    );
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
