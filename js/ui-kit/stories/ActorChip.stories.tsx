import { ActorChip, type ActorChipProps } from '@cboxdk/cms-ui-kit';

import { check, inDanish, inDark, inForcedColours, type Story, type StoryMeta } from './csf';

const meta: StoryMeta<ActorChipProps> = {
  title: 'Components/Domain/ActorChip',
  component: ActorChip,
};

export default meta;

/** Actors as they are named in lists: active, pending and a service. */
export const Default: Story = {
  render: () => (
    <div style={{ display: 'grid', gap: '1rem' }}>
      <ActorChip name="Ada Lovelace" email="ada@example.com" actorClass="staff" state="active" />
      <ActorChip name="Grace Hopper" email="grace@example.com" actorClass="staff" state="pending" />
      <ActorChip name="Search indexer" actorClass="service" />
    </div>
  ),
  play: ({ canvasElement }) => {
    const avatar = canvasElement.querySelector('.cms-actor-chip__avatar');

    check(avatar !== null, 'the initials are drawn');
    check(avatar.textContent === 'AL', 'the initials are the first and the last name’s');
    check(avatar.getAttribute('aria-hidden') === 'true', 'the initials are decoration');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
