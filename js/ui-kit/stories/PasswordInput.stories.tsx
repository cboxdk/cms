import { PasswordInput, type PasswordInputProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<PasswordInputProps> = {
  title: 'Components/Forms/PasswordInput',
  component: PasswordInput,
};

export default meta;

const TEXTS: Localized<{ label: string; description: string; error: string }> = {
  da: {
    label: 'Ny adgangskode',
    description: 'Mindst 12 tegn.',
    error: 'Adgangskoden er for kort.',
  },
  en: {
    label: 'New password',
    description: 'At least 12 characters.',
    error: 'The password is too short.',
  },
};

/** Tab from the input reaches the reveal button; Enter shows what is typed and Enter hides it. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <PasswordInput
        label={texts.label}
        description={texts.description}
        name="password"
        autoComplete="new-password"
        defaultValue="correct horse"
        required
      />
    );
  },
  play: async ({ canvasElement, userEvent }) => {
    const input = single(canvasElement, 'input', HTMLInputElement);
    const toggle = single(canvasElement, 'button[aria-pressed]', HTMLButtonElement);
    const type = () => input.type;

    check(type() === 'password', 'the password starts hidden');
    await userEvent.click(input);
    await userEvent.tab();
    check(document.activeElement === toggle, 'Tab reaches the reveal button');
    await userEvent.keyboard('{Enter}');
    check(type() === 'text', 'Enter shows the password');
    check(toggle.getAttribute('aria-pressed') === 'true', 'the button is pressed');
    await userEvent.keyboard('{Enter}');
    check(type() === 'password', 'Enter hides it again');
  },
};

/** A refused password: the group shows the error's colour, and the input is invalid. */
export const WithError: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <PasswordInput
        label={texts.label}
        description={texts.description}
        error={texts.error}
        name="password"
        defaultValue="short"
      />
    );
  },
  play: ({ canvasElement }) => {
    const input = single(canvasElement, 'input', HTMLInputElement);

    check(input.getAttribute('aria-invalid') === 'true', 'the input is invalid');
  },
};

export const Dark: Story = inDark(WithError);
export const ForcedColors: Story = inForcedColours(WithError);
export const Danish: Story = inDanish(WithError);
