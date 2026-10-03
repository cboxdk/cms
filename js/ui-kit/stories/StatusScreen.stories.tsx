import { StatusScreen, TextLink, type StatusScreenProps } from '@cboxdk/cms-ui-kit';

import { single, storyLocale, type Story, type StoryMeta } from './csf';
import { STORY_TEXTS } from './texts';

const meta: StoryMeta<StatusScreenProps> = {
  title: 'Components/StatusScreen',
  component: StatusScreen,
};

export default meta;

/** The page for an address the panel does not have, with the way back. */
export const NotFound: Story = {
  render: (_args, { globals }) => {
    const texts = STORY_TEXTS[storyLocale(globals)];

    return (
      <StatusScreen
        code={texts.missingCode}
        title={texts.missingTitle}
        description={texts.missingDescription}
      >
        <TextLink href="#panel">{texts.backToPanel}</TextLink>
      </StatusScreen>
    );
  },
  play: ({ canvasElement }) => {
    single(canvasElement, 'main', HTMLElement);
    single(canvasElement, 'h1', HTMLHeadingElement);
  },
};
