import { Button, FormActions, type FormActionsProps } from '@cboxdk/cms-ui-kit';

import {
  check,
  inDanish,
  inDark,
  inForcedColours,
  textsOf,
  type Localized,
  type Story,
  type StoryMeta,
} from './csf';

const meta: StoryMeta<FormActionsProps> = {
  title: 'Components/Forms/FormActions',
  component: FormActions,
};

export default meta;

const TEXTS: Localized<{ cancel: string; dryRun: string; save: string }> = {
  da: { cancel: 'Annullér', dryRun: 'Prøvekør', save: 'Gem' },
  en: { cancel: 'Cancel', dryRun: 'Dry run', save: 'Save' },
};

/** The buttons in the order they are read, the primary last: what is seen is what Tab reaches. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <FormActions>
        <Button variant="quiet">{texts.cancel}</Button>
        <Button>{texts.dryRun}</Button>
        <Button type="submit" variant="primary">
          {texts.save}
        </Button>
      </FormActions>
    );
  },
  play: ({ canvasElement }) => {
    const buttons = [...canvasElement.querySelectorAll('button')];
    const lefts = buttons.map((button) => button.getBoundingClientRect().left);

    check(
      lefts.every((left, index) => index === 0 || left > (lefts[index - 1] ?? 0)),
      'the buttons are shown in their order',
    );
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
