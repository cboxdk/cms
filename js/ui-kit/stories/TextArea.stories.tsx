import { TextArea, type TextAreaProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<TextAreaProps> = {
  title: 'Components/Forms/TextArea',
  component: TextArea,
};

export default meta;

const TEXTS: Localized<{ label: string; description: string; error: string; value: string }> = {
  da: {
    label: 'Begrundelse',
    description: 'Hvorfor ændringen laves; den gemmes i revisionssporet.',
    error: 'Skriv en begrundelse.',
    value: 'Rettet stavefejl i overskriften.',
  },
  en: {
    label: 'Reason',
    description: 'Why the change is made; it is kept in the audit trail.',
    error: 'Write a reason.',
    value: 'Fixed a typo in the heading.',
  },
};

/** Enter makes a new line; Tab leaves the field. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <TextArea
        label={texts.label}
        description={texts.description}
        name="reason"
        defaultValue={texts.value}
      />
    );
  },
  play: async ({ canvasElement, userEvent }) => {
    const area = single(canvasElement, 'textarea', HTMLTextAreaElement);

    await userEvent.click(area);
    await userEvent.keyboard('{End}{Enter}x');
    check(area.value.includes('\nx'), 'Enter makes a new line');
  },
};

/** Code, such as JSON, in the monospaced font, refused with an error. */
export const MonospaceWithError: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <TextArea label={texts.label} error={texts.error} monospace rows={3} defaultValue="{}" />
    );
  },
  play: ({ canvasElement }) => {
    const area = single(canvasElement, 'textarea', HTMLTextAreaElement);

    check(area.getAttribute('aria-invalid') === 'true', 'the text area is invalid');
    check(!area.spellcheck, 'code is not spell checked');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
