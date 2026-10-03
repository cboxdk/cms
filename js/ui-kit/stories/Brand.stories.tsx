import { Brand, type BrandProps } from '@cboxdk/cms-ui-kit';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  single,
  storyLocale,
  type Story,
  type StoryMeta,
} from './csf';
import { STORY_TEXTS } from './texts';

const meta: StoryMeta<BrandProps> = {
  title: 'Components/Layout/Brand',
  component: Brand,
};

export default meta;

/** A light and a dark logo drawn as data, so the story loads nothing. */
function logo(fill: string): string {
  return `data:image/svg+xml,${encodeURIComponent(`<svg xmlns="http://www.w3.org/2000/svg" width="64" height="32" viewBox="0 0 64 32"><rect width="64" height="32" rx="6" fill="${fill}"/><circle cx="16" cy="16" r="8" fill="#ffffff"/></svg>`)}`;
}

/** The panel's own name, as it shows without branding. */
export const NameOnly: Story = {
  render: (_args, { globals }) => <Brand name={STORY_TEXTS[storyLocale(globals)].productName} />,
  play: ({ canvasElement }) => {
    check(canvasElement.querySelectorAll('img').length === 0, 'the brand has no logo');
  },
};

/** An installation's logo in the version of the mode, with its alternative text, and its name. */
export const WithLogo: Story = {
  render: (_args, { globals }) => {
    const texts = STORY_TEXTS[storyLocale(globals)];

    return (
      <Brand
        name={texts.brandName}
        logo={{ light: logo('#14532d'), dark: logo('#86efac'), alt: texts.brandLogo }}
      />
    );
  },
  play: ({ canvasElement }) => {
    const shown = [...canvasElement.querySelectorAll('img')].filter(
      (image) => getComputedStyle(image).display !== 'none',
    );

    check(shown.length === 1, 'one logo is shown, the one of the mode');
    check(shown[0]?.alt !== '', 'the logo has its alternative text');
    single(canvasElement, '.cms-brand__name', HTMLSpanElement);
  },
};

export const Dark: Story = inDark(WithLogo);
export const ForcedColors: Story = inForcedColours(WithLogo);
export const Danish: Story = inDanish(WithLogo);
