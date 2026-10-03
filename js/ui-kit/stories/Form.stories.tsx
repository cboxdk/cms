import { Button, Form, TextField, type FormProps } from '@cboxdk/cms-ui-kit';

import { check, single, storyLocale, type Story, type StoryMeta } from './csf';
import { STORY_TEXTS } from './texts';

const meta: StoryMeta<FormProps> = {
  title: 'Components/Form',
  component: Form,
};

export default meta;

/** A form of two fields and a submit button: Enter in a field submits it. */
export const SignIn: Story = {
  render: (_args, { globals }) => {
    const texts = STORY_TEXTS[storyLocale(globals)];

    return (
      <Form
        onSubmit={(event) => {
          event.preventDefault();
          event.currentTarget.dataset['submitted'] = 'true';
        }}
      >
        <TextField name="email" type="email" label={texts.email} />
        <TextField name="password" type="password" label={texts.password} />
        <Button type="submit" variant="primary">
          {texts.signIn}
        </Button>
      </Form>
    );
  },
  play: async ({ canvasElement, userEvent }) => {
    const form = single(canvasElement, 'form', HTMLFormElement);
    const email = single(canvasElement, 'input[name="email"]', HTMLInputElement);

    await userEvent.type(email, 'editor@example.com{Enter}');
    check(form.dataset['submitted'] === 'true', 'Enter in a field submits the form');
  },
};
