import { Brand, Button, ShellHeader, type ShellHeaderProps } from '@cboxdk/cms-ui-kit';

import { single, storyLocale, type Story, type StoryMeta } from './csf';
import { STORY_TEXTS } from './texts';

const meta: StoryMeta<ShellHeaderProps> = {
  title: 'Components/ShellHeader',
  component: ShellHeader,
};

export default meta;

/** The shell's header with the installation's name and an action at the end. */
export const WithAction: Story = {
  render: (_args, { globals }) => {
    const texts = STORY_TEXTS[storyLocale(globals)];

    return (
      <ShellHeader brand={<Brand name={texts.brandName} />}>
        <Button>{texts.signOut}</Button>
      </ShellHeader>
    );
  },
  play: ({ canvasElement }) => {
    single(canvasElement, 'header', HTMLElement);
    single(canvasElement, 'button', HTMLButtonElement);
  },
};
