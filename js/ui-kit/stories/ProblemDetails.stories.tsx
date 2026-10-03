import { ProblemDetails, type ProblemDetailsProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<ProblemDetailsProps> = {
  title: 'Components/Domain/ProblemDetails',
  component: ProblemDetails,
};

export default meta;

const TEXTS: Localized<{
  validation: string;
  conflict: string;
  docs: string;
  required: string;
}> = {
  da: {
    validation: 'Værdierne overholder ikke typens regler. Ret felterne og send igen.',
    conflict: 'Nogen har ændret det samme imens. Hent den nye udgave og prøv igen.',
    docs: 'Læs om koden',
    required: 'Feltet skal udfyldes.',
  },
  en: {
    validation: 'The values break the rules of the type. Correct the fields and send it again.',
    conflict: 'Someone changed the same thing meanwhile. Load the new version and try again.',
    docs: 'Read about the code',
    required: 'The field is required.',
  },
};

/** A validation refusal with its errors at their fields and a link to the code's reference. */
export const Validation: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <ProblemDetails
        explanation={texts.validation}
        docsHref="#validation_failed"
        docsLabel={texts.docs}
        errorText={(error) =>
          error.code === 'validation_required' ? texts.required : error.detail
        }
        problem={{
          code: 'validation_failed',
          detail: 'The command has 2 invalid values.',
          status: 422,
          retryable: false,
          errors: [
            { code: 'validation_required', detail: 'Missing.', field: 'fields.label' },
            { code: 'validation_too_long', detail: 'At most 63 characters.', field: 'handle' },
          ],
        }}
      />
    );
  },
  play: ({ canvasElement }) => {
    single(canvasElement, '[role="alert"]', HTMLDivElement);
    check(canvasElement.querySelectorAll('li').length === 2, 'each error is listed');
    check(canvasElement.textContent.includes('validation_failed'), 'the code is shown');
  },
};

/** A conflict that may work when tried again. */
export const Retryable: Story = {
  render: (_args, { globals }) => (
    <ProblemDetails
      explanation={textsOf(TEXTS, globals).conflict}
      problem={{
        code: 'version_conflict',
        detail: 'The variant is at version 7, not 6.',
        status: 409,
        retryable: true,
        errors: [],
      }}
    />
  ),
};

export const Dark: Story = inDark(Validation);
export const ForcedColors: Story = inForcedColours(Validation);
export const Danish: Story = inDanish(Validation);
