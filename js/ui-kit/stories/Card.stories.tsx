import { Button, Card, TextLink, type CardProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<CardProps> = {
  title: 'Components/Layout/Card',
  component: Card,
};

export default meta;

const TEXTS: Localized<{ title: string; body: string; edit: string; more: string }> = {
  da: {
    title: 'Profil',
    body: 'Navn, e-mail og sprog for den indloggede medarbejder.',
    edit: 'Redigér',
    more: 'Se alle oplysninger',
  },
  en: {
    title: 'Profile',
    body: 'The name, email and language of the member of staff signed in.',
    edit: 'Edit',
    more: 'See every detail',
  },
};

/** A card with a title is a region named by its heading, with actions and a footer. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <Card
        title={texts.title}
        actions={<Button>{texts.edit}</Button>}
        footer={<TextLink href="#profile">{texts.more}</TextLink>}
      >
        <p>{texts.body}</p>
      </Card>
    );
  },
  play: ({ canvasElement }) => {
    const section = single(canvasElement, 'section', HTMLElement);
    const heading = single(canvasElement, 'h2', HTMLHeadingElement);

    check(
      section.getAttribute('aria-labelledby') === heading.id,
      'the card is named by its heading',
    );
  },
};

/** A card without a title is a plain panel. */
export const Untitled: Story = {
  render: (_args, { globals }) => (
    <Card>
      <p>{textsOf(TEXTS, globals).body}</p>
    </Card>
  ),
  play: ({ canvasElement }) => {
    const section = single(canvasElement, 'section', HTMLElement);

    check(!section.hasAttribute('aria-labelledby'), 'an untitled card is not named');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
