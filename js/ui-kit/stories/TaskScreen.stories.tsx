import {
  Button,
  Form,
  TaskScreen,
  TextInput,
  TextLink,
  type TaskScreenProps,
  type TaskScreenShowcase,
} from '@cboxdk/cms-ui-kit';

import {
  check,
  single,
  storyLocale,
  type Story,
  type StoryMeta,
  inDanish,
  inDark,
  inForcedColours,
  textsOf,
  type Localized,
} from './csf';
import { STORY_TEXTS } from './texts';

const meta: StoryMeta<TaskScreenProps> = {
  title: 'Components/Feedback/TaskScreen',
  component: TaskScreen,
};

export default meta;

/** The page of one task, here signing in, with a link away from it below. */
export const SignIn: Story = {
  render: (_args, { globals }) => {
    const texts = STORY_TEXTS[storyLocale(globals)];

    return (
      <TaskScreen
        title={texts.signIn}
        description={texts.signInDescription}
        footer={<TextLink href="#password-reset">{texts.forgotPassword}</TextLink>}
      >
        <Form>
          <TextInput name="email" type="email" required label={texts.email} />
          <TextInput name="password" type="password" required label={texts.password} />
          <Button type="submit" variant="primary">
            {texts.signIn}
          </Button>
        </Form>
      </TaskScreen>
    );
  },
  play: ({ canvasElement }) => {
    single(canvasElement, 'main', HTMLElement);
    const heading = single(canvasElement, 'h1', HTMLHeadingElement);

    check(heading.textContent !== '', 'the page has a heading');
  },
};

/** The side that says what the product is, in each locale of the kit. */
const SHOWCASES: Localized<TaskScreenShowcase> = {
  da: {
    eyebrow: 'Udgivet · live på kanten',
    title: 'Ét sted til hvert site, hver side og hver ændring.',
    description: 'Redigér, udgiv, og se hvornår en ændring er live på alle sites.',
    card: {
      title: 'En udgivet ændring',
      status: 'Live',
      facts: [
        { label: 'Origin', value: 'invalideret' },
        { label: 'Kanten', value: 'tømt' },
        { label: 'Sites', value: 'alle live' },
      ],
      note: 'Kvitteringen for hver ændring viser, hvilke af trinnene den har nået.',
    },
  },
  en: {
    eyebrow: 'Published · live at the edge',
    title: 'One place for every site, every page and every change.',
    description: 'Edit, publish and see when a change is live on every site.',
    card: {
      title: 'A published change',
      status: 'Live',
      facts: [
        { label: 'Origin', value: 'invalidated' },
        { label: 'Edge', value: 'purged' },
        { label: 'Sites', value: 'all live' },
      ],
      note: 'The receipt of every change says which of these steps it has reached.',
    },
  },
};

/**
 * Signing in with the showcase beside the task, as the panel's sign-in pages show it: the task is
 * the main landmark and the showcase a complementary one named by its heading.
 */
export const WithShowcase: Story = {
  render: (_args, { globals }) => {
    const texts = STORY_TEXTS[storyLocale(globals)];

    return (
      <TaskScreen
        title={texts.signIn}
        description={texts.signInDescription}
        showcase={textsOf(SHOWCASES, globals)}
        footer={<TextLink href="#password-reset">{texts.forgotPassword}</TextLink>}
      >
        <Form>
          <TextInput name="email" type="email" required label={texts.email} />
          <TextInput name="password" type="password" required label={texts.password} />
          <Button type="submit" variant="primary">
            {texts.signIn}
          </Button>
        </Form>
      </TaskScreen>
    );
  },
  play: ({ canvasElement }) => {
    single(canvasElement, 'main', HTMLElement);
    const aside = single(canvasElement, 'aside', HTMLElement);
    const heading = single(aside, 'h2', HTMLHeadingElement);

    check(
      aside.getAttribute('aria-labelledby') === heading.id,
      'the showcase is named by its heading',
    );
    check(aside.querySelectorAll('dt').length === 3, 'the card shows its three facts');
  },
};

export const Dark: Story = inDark(SignIn);
export const ForcedColors: Story = inForcedColours(SignIn);
export const Danish: Story = inDanish(SignIn);
export const WithShowcaseDark: Story = inDark(WithShowcase);
export const WithShowcaseDanish: Story = inDanish(WithShowcase);
