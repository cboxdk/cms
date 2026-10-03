import { Timestamp, type TimestampProps } from '@cboxdk/cms-ui-kit';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  single,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<TimestampProps> = {
  title: 'Components/Data display/Timestamp',
  component: Timestamp,
};

export default meta;

/** A time in the page's locale, with the instant in its datetime attribute. */
export const Default: Story = {
  render: () => (
    <p>
      <Timestamp value="2026-10-03T09:30:00.000000Z" timeZone="Europe/Copenhagen" />
      {' · '}
      <Timestamp value="2026-10-03T09:30:00.000000Z" format="date" timeZone="UTC" />
    </p>
  ),
  play: ({ canvasElement }) => {
    const [time] = canvasElement.querySelectorAll('time');

    check(time?.dateTime === '2026-10-03T09:30:00.000Z', 'the instant is in datetime');
    single(canvasElement, 'p', HTMLParagraphElement);
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
