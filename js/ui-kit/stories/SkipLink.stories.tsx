import { SkipLink, type SkipLinkProps } from '@cboxdk/cms-ui-kit';

import {
  check,
  focused,
  inDanish,
  inDark,
  inForcedColours,
  textsOf,
  type Localized,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<SkipLinkProps> = {
  title: 'Components/Navigation/SkipLink',
  component: SkipLink,
};

export default meta;

const TEXTS: Localized<{ skip: string; content: string }> = {
  da: { skip: 'Spring til indholdet', content: 'Indholdet' },
  en: { skip: 'Skip to the content', content: 'The content' },
};

/**
 * The link shown once it has focus, as Tab gives it first on a page; Enter moves focus to the
 * content.
 */
export const Focused: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <div style={{ position: 'relative', minBlockSize: '6rem' }}>
        <SkipLink target="story-content">{texts.skip}</SkipLink>
        <main id="story-content" tabIndex={-1} style={{ paddingBlockStart: '4rem' }}>
          {texts.content}
        </main>
      </div>
    );
  },
  play: async ({ userEvent }) => {
    await userEvent.tab();
    focused(HTMLAnchorElement);
    await userEvent.keyboard('{Enter}');
    check(document.activeElement?.id === 'story-content', 'Enter moves focus to the content');
    await userEvent.tab({ shift: true });
  },
};

export const Dark: Story = inDark(Focused);
export const ForcedColors: Story = inForcedColours(Focused);
export const Danish: Story = inDanish(Focused);
