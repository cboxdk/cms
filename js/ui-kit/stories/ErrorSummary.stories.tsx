import { ErrorSummary, TextInput, type ErrorSummaryProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<ErrorSummaryProps> = {
  title: 'Components/Forms/ErrorSummary',
  component: ErrorSummary,
};

export default meta;

const TEXTS: Localized<{
  title: string;
  handle: string;
  handleError: string;
  label: string;
  labelError: string;
}> = {
  da: {
    title: 'Formularen blev afvist',
    handle: 'Håndtag',
    handleError: 'Skriv et håndtag med små bogstaver.',
    label: 'Navn',
    labelError: 'Skriv et navn.',
  },
  en: {
    title: 'The form was refused',
    handle: 'Handle',
    handleError: 'Write a handle in lower case.',
    label: 'Name',
    labelError: 'Write a name.',
  },
};

/** It takes focus when it appears; Enter on an error moves focus to its field. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <div style={{ display: 'grid', gap: '1rem' }}>
        <ErrorSummary
          title={texts.title}
          errors={[
            { target: 'story-handle', message: texts.handleError },
            { target: 'story-label', message: texts.labelError },
          ]}
        />
        <TextInput
          id="story-handle"
          label={texts.handle}
          error={texts.handleError}
          defaultValue="Editor"
        />
        <TextInput id="story-label" label={texts.label} error={texts.labelError} />
      </div>
    );
  },
  play: async ({ canvasElement, userEvent }) => {
    const summary = single(canvasElement, '.cms-error-summary', HTMLDivElement);
    const handle = single(canvasElement, '#story-handle', HTMLInputElement);

    check(document.activeElement === summary, 'the summary takes focus');
    await userEvent.tab();
    await userEvent.keyboard('{Enter}');
    check(document.activeElement === handle, 'the error moves focus to its field');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
