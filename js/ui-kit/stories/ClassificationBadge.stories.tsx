import { ClassificationBadge, type ClassificationBadgeProps } from '@cboxdk/cms-ui-kit';

import { check, inDanish, inDark, inForcedColours, type Story, type StoryMeta } from './csf';

const meta: StoryMeta<ClassificationBadgeProps> = {
  title: 'Components/Domain/ClassificationBadge',
  component: ClassificationBadge,
};

export default meta;

/** Every level, from public to sensitive. */
export const Levels: Story = {
  render: () => (
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0.5rem' }}>
      <ClassificationBadge classification="public" />
      <ClassificationBadge classification="internal" />
      <ClassificationBadge classification="confidential" />
      <ClassificationBadge classification="personal" />
      <ClassificationBadge classification="sensitive" />
    </div>
  ),
  play: ({ canvasElement }) => {
    check(canvasElement.querySelectorAll('.cms-badge').length === 5, 'every level is shown');
  },
};

export const Dark: Story = inDark(Levels);
export const ForcedColors: Story = inForcedColours(Levels);
export const Danish: Story = inDanish(Levels);
