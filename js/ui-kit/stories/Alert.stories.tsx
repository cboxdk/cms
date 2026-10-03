import { Alert, type AlertProps } from '@cboxdk/cms-ui-kit';

import { check, single, storyLocale, type Story, type StoryMeta } from './csf';
import { STORY_TEXTS } from './texts';

const meta: StoryMeta<AlertProps> = {
  title: 'Components/Alert',
  component: Alert,
};

export default meta;

/** An info message is a status, which a screen reader announces when it is idle. */
export const Info: Story = {
  render: (_args, { globals }) => <Alert>{STORY_TEXTS[storyLocale(globals)].saved}</Alert>,
  play: ({ canvasElement }) => {
    const alert = single(canvasElement, '.cms-alert', HTMLDivElement);

    check(alert.getAttribute('role') === 'status', 'an info message has the role status');
  },
};

/** A danger message is an alert, which a screen reader announces as soon as it appears. */
export const Danger: Story = {
  render: (_args, { globals }) => (
    <Alert tone="danger">{STORY_TEXTS[storyLocale(globals)].refused}</Alert>
  ),
  play: ({ canvasElement }) => {
    const alert = single(canvasElement, '.cms-alert', HTMLDivElement);

    check(alert.getAttribute('role') === 'alert', 'a danger message has the role alert');
  },
};
