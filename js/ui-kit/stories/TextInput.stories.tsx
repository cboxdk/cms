import { TextInput, type TextInputProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<TextInputProps> = {
  title: 'Components/Forms/TextInput',
  component: TextInput,
};

export default meta;

/** A field with a label: a click on the label focuses the input. */
export const Default: Story = {
  render: (_args, { globals }) => (
    <TextInput name="email" type="email" label={STORY_TEXTS[storyLocale(globals)].email} />
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

/** A required field with a description: the mark is hidden from a screen reader. */
export const RequiredWithDescription: Story = {
  render: (_args, { globals }) => {
    const texts = STORY_TEXTS[storyLocale(globals)];

    return (
      <TextInput
        name="email"
        type="email"
        required
        label={texts.email}
        description={texts.emailHint}
      />
    );
  },
  play: ({ canvasElement }) => {
    const input = single(canvasElement, 'input', HTMLInputElement);
    const description = single(canvasElement, '.cms-field__description', HTMLParagraphElement);
    const mark = single(canvasElement, '.cms-field__required', HTMLSpanElement);

    check(input.required, 'the input is required');
    check(
      input.getAttribute('aria-describedby') === description.id,
      'the input is described by the description',
    );
    check(mark.getAttribute('aria-hidden') === 'true', 'the required mark is hidden');
  },
};

/** A field whose value was refused: the input is invalid and described by the error. */
export const WithError: Story = {
  render: (_args, { globals }) => {
    const texts = STORY_TEXTS[storyLocale(globals)];

    return (
      <TextInput
        name="email"
        type="email"
        label={texts.email}
        description={texts.emailHint}
        error={texts.emailError}
        defaultValue="editor"
      />
    );
  },
  play: ({ canvasElement }) => {
    const input = single(canvasElement, 'input', HTMLInputElement);
    const description = single(canvasElement, '.cms-field__description', HTMLParagraphElement);
    const error = single(canvasElement, '.cms-field__error', HTMLParagraphElement);

    check(input.getAttribute('aria-invalid') === 'true', 'the input is invalid');
    check(
      input.getAttribute('aria-describedby') === `${description.id} ${error.id}`,
      'the input is described by the description and then the error',
    );
  },
};

export const Dark: Story = inDark(WithError);
export const ForcedColors: Story = inForcedColours(WithError);
export const Danish: Story = inDanish(WithError);
