import { NumberInput, type NumberInputProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<NumberInputProps> = {
  title: 'Components/Forms/NumberInput',
  component: NumberInput,
};

export default meta;

const TEXTS: Localized<{ label: string; description: string; error: string }> = {
  da: {
    label: 'Prioritet',
    description: 'Fra 1 til 10.',
    error: 'Prioriteten skal være højst 10.',
  },
  en: {
    label: 'Priority',
    description: 'From 1 to 10.',
    error: 'The priority must be at most 10.',
  },
};

/** Up steps the value, Home and End go to the bounds; the buttons are skipped by Tab. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <NumberInput
        label={texts.label}
        description={texts.description}
        defaultValue={4}
        minValue={1}
        maxValue={10}
        name="priority"
      />
    );
  },
  play: async ({ canvasElement, userEvent }) => {
    const input = single(canvasElement, 'input.cms-input', HTMLInputElement);
    const hidden = single(canvasElement, 'input[type="hidden"]', HTMLInputElement);
    const value = () => input.value;

    check(hidden.name === 'priority' && hidden.value === '4', 'the form gets the number by name');

    await userEvent.tab();
    check(document.activeElement === input, 'Tab reaches the input');
    await userEvent.keyboard('{ArrowUp}');
    check(value() === '5', 'Up steps the value');
    await userEvent.keyboard('{End}');
    check(value() === '10', 'End goes to the highest value');
    await userEvent.tab();
    check(!canvasElement.contains(document.activeElement), 'Tab skips the buttons');
  },
};

/** A large number in the page's locale, with an error. */
export const LocalisedWithError: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return <NumberInput label={texts.label} error={texts.error} defaultValue={12345} />;
  },
  play: ({ canvasElement }) => {
    const input = single(canvasElement, 'input', HTMLInputElement);

    check(input.getAttribute('aria-invalid') === 'true', 'the input is invalid');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(LocalisedWithError);
