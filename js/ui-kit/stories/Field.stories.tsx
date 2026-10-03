import { Field, type FieldProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<FieldProps> = {
  title: 'Components/Forms/Field',
  component: Field,
};

export default meta;

const TEXTS: Localized<{ label: string; description: string; error: string }> = {
  da: {
    label: 'Farve',
    description: 'Den farve, temaet bruger til knapper.',
    error: 'Vælg en farve.',
  },
  en: {
    label: 'Colour',
    description: 'The colour the theme uses for buttons.',
    error: 'Choose a colour.',
  },
};

/**
 * The frame of a field around a control the kit has no component for, here a native colour input:
 * the label, the description and the error joined to it.
 */
export const CustomControl: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <Field label={texts.label} description={texts.description} error={texts.error} required>
        {(control) => <input {...control} type="color" defaultValue="#2f5bd3" />}
      </Field>
    );
  },
  play: async ({ canvasElement, userEvent }) => {
    const label = single(canvasElement, 'label', HTMLLabelElement);
    const input = single(canvasElement, 'input', HTMLInputElement);
    const description = single(canvasElement, '.cms-field__description', HTMLParagraphElement);
    const error = single(canvasElement, '.cms-field__error', HTMLParagraphElement);

    check(
      input.getAttribute('aria-describedby') === `${description.id} ${error.id}`,
      'the control is described by the description and then the error',
    );
    check(input.getAttribute('aria-invalid') === 'true', 'the control is invalid');
    check(input.required, 'the control is required');
    await userEvent.click(label);
    check(document.activeElement === input, 'a click on the label focuses the control');
  },
};

export const Dark: Story = inDark(CustomControl);
export const ForcedColors: Story = inForcedColours(CustomControl);
export const Danish: Story = inDanish(CustomControl);
