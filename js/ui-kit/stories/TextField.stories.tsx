import { TextField, type TextFieldProps } from '@cboxdk/cms-ui-kit';

import { check, single, storyLocale, type Story, type StoryMeta } from './csf';
import { STORY_TEXTS } from './texts';

const meta: StoryMeta<TextFieldProps> = {
  title: 'Components/TextField',
  component: TextField,
};

export default meta;

/** A field with a label: a click on the label focuses the input. */
export const Default: Story = {
  render: (_args, { globals }) => (
    <TextField name="email" type="email" label={STORY_TEXTS[storyLocale(globals)].email} />
  ),
  play: async ({ canvasElement, userEvent }) => {
    const label = single(canvasElement, 'label', HTMLLabelElement);
    const input = single(canvasElement, 'input', HTMLInputElement);

    await userEvent.click(label);
    check(document.activeElement === input, 'a click on the label focuses the input');

    await userEvent.type(input, 'editor@example.com');
    check(input.value === 'editor@example.com', 'the input takes what is typed');
  },
};

/** A required field with a hint: the mark is hidden from a screen reader, the hint is read. */
export const RequiredWithHint: Story = {
  render: (_args, { globals }) => {
    const texts = STORY_TEXTS[storyLocale(globals)];

    return (
      <TextField name="email" type="email" required label={texts.email} hint={texts.emailHint} />
    );
  },
  play: ({ canvasElement }) => {
    const input = single(canvasElement, 'input', HTMLInputElement);
    const hint = single(canvasElement, '.cms-text-field__hint', HTMLParagraphElement);
    const mark = single(canvasElement, '.cms-text-field__required', HTMLSpanElement);

    check(input.required, 'the input is required');
    check(input.getAttribute('aria-describedby') === hint.id, 'the input is described by the hint');
    check(mark.getAttribute('aria-hidden') === 'true', 'the required mark is hidden');
  },
};

/** A field whose value was refused: the input is invalid and described by the error. */
export const WithError: Story = {
  render: (_args, { globals }) => {
    const texts = STORY_TEXTS[storyLocale(globals)];

    return (
      <TextField
        name="email"
        type="email"
        label={texts.email}
        hint={texts.emailHint}
        error={texts.emailError}
        defaultValue="editor"
      />
    );
  },
  play: ({ canvasElement }) => {
    const input = single(canvasElement, 'input', HTMLInputElement);
    const hint = single(canvasElement, '.cms-text-field__hint', HTMLParagraphElement);
    const error = single(canvasElement, '.cms-text-field__error', HTMLParagraphElement);

    check(input.getAttribute('aria-invalid') === 'true', 'the input is invalid');
    check(
      input.getAttribute('aria-describedby') === `${hint.id} ${error.id}`,
      'the input is described by the hint and then the error',
    );
  },
};
