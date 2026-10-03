import {
  Button,
  Form,
  TaskScreen,
  TextInput,
  TextLink,
  type TaskScreenProps,
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

export const Dark: Story = inDark(SignIn);
export const ForcedColors: Story = inForcedColours(SignIn);
export const Danish: Story = inDanish(SignIn);
