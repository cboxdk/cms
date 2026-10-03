import { Skeleton, type SkeletonProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<SkeletonProps> = {
  title: 'Components/Feedback/Skeleton',
  component: Skeleton,
};

export default meta;

const TEXTS: Localized<{ lines: string; block: string }> = {
  da: { lines: 'Henter profilen', block: 'Henter adgangene' },
  en: { lines: 'Loading the profile', block: 'Loading the grants' },
};

/** Lines and a block; a screen reader hears what loads, not the shapes. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <div style={{ display: 'grid', gap: '1.5rem', maxInlineSize: '30rem' }}>
        <Skeleton label={texts.lines} />
        <Skeleton label={texts.block} shape="block" />
      </div>
    );
  },
  play: ({ canvasElement, globals }) => {
    const [first] = canvasElement.querySelectorAll('[role="status"]');

    check(
      first?.getAttribute('aria-label') === textsOf(TEXTS, globals).lines,
      'it says what loads',
    );
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
