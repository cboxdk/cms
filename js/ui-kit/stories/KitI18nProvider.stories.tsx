import {
  KitI18nProvider,
  TextField,
  type KitI18nProviderProps,
  type KitLocale,
} from '@cboxdk/cms-ui-kit';

import { check, single, type Story, type StoryMeta } from './csf';
import { STORY_TEXTS } from './texts';

const meta: StoryMeta<KitI18nProviderProps> = {
  title: 'Foundations/KitI18nProvider',
  component: KitI18nProvider,
};

export default meta;

function requiredField(locale: KitLocale) {
  return (
    <KitI18nProvider locale={locale}>
      <TextField name="email" type="email" required label={STORY_TEXTS[locale].email} />
    </KitI18nProvider>
  );
}

/** The kit's own texts, here the mark of a required field, in Danish. */
export const Danish: Story = {
  render: () => requiredField('da'),
  play: ({ canvasElement }) => {
    const mark = single(canvasElement, '.cms-text-field__required', HTMLSpanElement);

    check(mark.textContent === 'Påkrævet', 'the kit marks a required field in Danish');
  },
};

/** The kit's own texts in English. */
export const English: Story = {
  render: () => requiredField('en'),
  play: ({ canvasElement }) => {
    const mark = single(canvasElement, '.cms-text-field__required', HTMLSpanElement);

    check(mark.textContent === 'Required', 'the kit marks a required field in English');
  },
};
