import { Fieldset, TextInput, type FieldsetProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<FieldsetProps> = {
  title: 'Components/Forms/Fieldset',
  component: Fieldset,
};

export default meta;

const TEXTS: Localized<{
  legend: string;
  description: string;
  error: string;
  from: string;
  until: string;
}> = {
  da: {
    legend: 'Synlighedsvindue',
    description: 'Hvornår placeringen er synlig for offentligheden.',
    error: 'Slut skal ligge efter start.',
    from: 'Fra',
    until: 'Til',
  },
  en: {
    legend: 'Visibility window',
    description: 'When the placement is visible to the public.',
    error: 'The end must come after the start.',
    from: 'From',
    until: 'Until',
  },
};

/** The fields of a window together, named by the legend, with an error about both. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <Fieldset legend={texts.legend} description={texts.description} error={texts.error}>
        <TextInput label={texts.from} name="from" defaultValue="2026-10-03T09:00" />
        <TextInput label={texts.until} name="until" defaultValue="2026-10-01T09:00" />
      </Fieldset>
    );
  },
  play: ({ canvasElement }) => {
    const fieldset = single(canvasElement, 'fieldset', HTMLFieldSetElement);

    single(canvasElement, 'legend', HTMLLegendElement);
    check(fieldset.getAttribute('aria-invalid') === 'true', 'the group is invalid');
    check(fieldset.querySelectorAll('input').length === 2, 'the fields are in the group');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
