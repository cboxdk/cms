import { ProgressLabel, type ProgressLabelProps } from '@cboxdk/cms-ui-kit';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  single,
  textsOf,
  type Localized,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<ProgressLabelProps> = {
  title: 'Components/Feedback/ProgressLabel',
  component: ProgressLabel,
};

export default meta;

const TEXTS: Localized<{ waiting: string }> = {
  da: { waiting: 'Venter på, at cachen bliver ryddet' },
  en: { waiting: 'Waiting for the cache to be cleared' },
};

/** A wait that says what it waits for, as a status. */
export const Default: Story = {
  render: (_args, { globals }) => <ProgressLabel>{textsOf(TEXTS, globals).waiting}</ProgressLabel>,
  play: ({ canvasElement, globals }) => {
    const status = single(canvasElement, '[role="status"]', HTMLSpanElement);

    check(status.textContent === textsOf(TEXTS, globals).waiting, 'it says what it waits for');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
