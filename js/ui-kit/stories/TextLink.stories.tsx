import { TextLink, type TextLinkProps } from '@cboxdk/cms-ui-kit';

import { check, single, storyLocale, type Story, type StoryMeta } from './csf';
import { STORY_TEXTS } from './texts';

const meta: StoryMeta<TextLinkProps> = {
  title: 'Components/TextLink',
  component: TextLink,
};

export default meta;

/** A plain anchor, underlined and at least 24 pixels high (WCAG 2.2, 2.5.8). */
export const Default: Story = {
  render: (_args, { globals }) => (
    <TextLink href="#password-reset">{STORY_TEXTS[storyLocale(globals)].forgotPassword}</TextLink>
  ),
  play: async ({ canvasElement, userEvent }) => {
    const link = single(canvasElement, 'a', HTMLAnchorElement);

    await userEvent.tab();
    check(document.activeElement === link, 'Tab reaches the link');
    check(link.getBoundingClientRect().height >= 24, 'the link is at least 24 pixels high');
  },
};
