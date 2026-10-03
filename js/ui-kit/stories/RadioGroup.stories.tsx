import { RadioGroup, type RadioGroupProps } from '@cboxdk/cms-ui-kit';

import {
  check,
  focused,
  inDanish,
  inDark,
  inForcedColours,
  single,
  textsOf,
  type Localized,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<RadioGroupProps> = {
  title: 'Components/Forms/RadioGroup',
  component: RadioGroup,
};

export default meta;

const TEXTS: Localized<{
  label: string;
  description: string;
  allow: string;
  allowDescription: string;
  deny: string;
  denyDescription: string;
  error: string;
}> = {
  da: {
    label: 'Virkning',
    description: 'Om adgangen giver eller tager rettigheder.',
    allow: 'Tillad',
    allowDescription: 'Giver rollens rettigheder på noden og under den.',
    deny: 'Afvis',
    denyDescription: 'Tager rollens rettigheder på noden og under den.',
    error: 'Vælg en virkning.',
  },
  en: {
    label: 'Effect',
    description: 'Whether the grant gives or takes rights.',
    allow: 'Allow',
    allowDescription: 'Gives the role’s rights on the node and below it.',
    deny: 'Deny',
    denyDescription: 'Takes the role’s rights on the node and below it.',
    error: 'Choose an effect.',
  },
};

function options(globals: Readonly<Record<string, unknown>>) {
  const texts = textsOf(TEXTS, globals);

  return [
    { value: 'allow', label: texts.allow, description: texts.allowDescription },
    { value: 'deny', label: texts.deny, description: texts.denyDescription },
  ];
}

/** Tab reaches the chosen option; Down moves to the next and chooses it. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <RadioGroup
        label={texts.label}
        description={texts.description}
        options={options(globals)}
        defaultValue="allow"
        name="effect"
      />
    );
  },
  play: async ({ canvasElement, userEvent }) => {
    single(canvasElement, '[role="radiogroup"]', HTMLDivElement);

    await userEvent.tab();
    check(focused(HTMLInputElement).value === 'allow', 'Tab reaches the chosen option');
    await userEvent.keyboard('{ArrowDown}');
    check(focused(HTMLInputElement).value === 'deny', 'Down moves to the next option');
    check(focused(HTMLInputElement).checked, 'and chooses it');
  },
};

/** No option chosen, with an error. */
export const WithError: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <RadioGroup
        label={texts.label}
        error={texts.error}
        options={options(globals)}
        orientation="horizontal"
        required
      />
    );
  },
  play: ({ canvasElement }) => {
    const group = single(canvasElement, '[role="radiogroup"]', HTMLDivElement);

    check(group.getAttribute('aria-invalid') === 'true', 'the group is invalid');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
