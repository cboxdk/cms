import { Checkbox, type CheckboxProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<CheckboxProps> = {
  title: 'Components/Forms/Checkbox',
  component: Checkbox,
};

export default meta;

const TEXTS: Localized<{
  dryRun: string;
  dryRunDescription: string;
  all: string;
  terms: string;
  termsError: string;
}> = {
  da: {
    dryRun: 'Prøvekørsel',
    dryRunDescription: 'Vis hvad ændringen ville gøre, uden at gemme den.',
    all: 'Alle sprog',
    terms: 'Jeg har læst politikken',
    termsError: 'Bekræft, at du har læst politikken.',
  },
  en: {
    dryRun: 'Dry run',
    dryRunDescription: 'Show what the change would do, without saving it.',
    all: 'Every locale',
    terms: 'I have read the policy',
    termsError: 'Confirm that you have read the policy.',
  },
};

/** Space checks the box; its description is read with it. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <div style={{ display: 'grid', gap: '1rem' }}>
        <Checkbox label={texts.dryRun} description={texts.dryRunDescription} name="dry_run" />
        <Checkbox label={texts.all} indeterminate />
      </div>
    );
  },
  play: async ({ canvasElement, userEvent }) => {
    const [box] = canvasElement.querySelectorAll('input');

    check(box instanceof HTMLInputElement, 'the box is a native checkbox');
    check(box.getAttribute('aria-describedby') !== null, 'the box is described');
    await userEvent.tab();
    check(document.activeElement === box, 'Tab reaches the box');
    await userEvent.keyboard(' ');
    check(box.checked, 'Space checks the box');
  },
};

/** A required box with its error. */
export const WithError: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return <Checkbox label={texts.terms} error={texts.termsError} required />;
  },
  play: ({ canvasElement }) => {
    const box = single(canvasElement, 'input', HTMLInputElement);

    check(box.getAttribute('aria-invalid') === 'true', 'the box is invalid');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(WithError);
