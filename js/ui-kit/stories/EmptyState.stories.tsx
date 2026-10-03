import { Button, EmptyState, type EmptyStateProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<EmptyStateProps> = {
  title: 'Components/Feedback/EmptyState',
  component: EmptyState,
};

export default meta;

const TEXTS: Localized<{ title: string; description: string; action: string }> = {
  da: {
    title: 'Ingen adgange endnu',
    description: 'En adgang giver en medarbejder en rolle på en node og alt under den.',
    action: 'Tildel den første adgang',
  },
  en: {
    title: 'No grants yet',
    description: 'A grant gives a member of staff a role on a node and everything below it.',
    action: 'Assign the first grant',
  },
};

/** What is empty, why, and the first thing to do. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <EmptyState
        title={texts.title}
        description={texts.description}
        action={<Button variant="primary">{texts.action}</Button>}
      />
    );
  },
  play: ({ canvasElement }) => {
    single(canvasElement, 'h2', HTMLHeadingElement);
    check(canvasElement.querySelector('button') !== null, 'an empty state offers the first action');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
