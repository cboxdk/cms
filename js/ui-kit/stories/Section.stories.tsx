import { Button, Section, type SectionProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<SectionProps> = {
  title: 'Components/Layout/Section',
  component: Section,
};

export default meta;

const TEXTS: Localized<{ title: string; description: string; add: string; body: string }> = {
  da: {
    title: 'Adgange',
    description: 'Rollerne medarbejderen har, og hvor.',
    add: 'Tildel adgang',
    body: 'Medarbejderen har ingen adgange endnu.',
  },
  en: {
    title: 'Grants',
    description: 'The roles the member of staff has, and where.',
    add: 'Assign a grant',
    body: 'The member of staff has no grants yet.',
  },
};

/** A part of a page named by its heading, with an action at the end of its row. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <Section
        title={texts.title}
        description={texts.description}
        actions={<Button>{texts.add}</Button>}
      >
        <p>{texts.body}</p>
      </Section>
    );
  },
  play: ({ canvasElement }) => {
    const section = single(canvasElement, 'section', HTMLElement);
    const heading = single(canvasElement, 'h2', HTMLHeadingElement);

    check(
      section.getAttribute('aria-labelledby') === heading.id,
      'the section is named by its heading',
    );
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
