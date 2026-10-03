import { FieldError, type FieldErrorProps } from '@cboxdk/cms-ui-kit';

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

const meta: StoryMeta<FieldErrorProps> = {
  title: 'Components/Forms/FieldError',
  component: FieldError,
};

export default meta;

const TEXTS: Localized<{ label: string; error: string }> = {
  da: { label: 'Slug', error: 'En anden placering under noden har denne slug.' },
  en: { label: 'Slug', error: 'Another placement below the node has this slug.' },
};

/** The error of a control built outside a Field, joined to it by its id. */
export const Default: Story = {
  render: (_args, { globals }) => {
    const texts = textsOf(TEXTS, globals);

    return (
      <div style={{ display: 'grid', gap: '0.25rem' }}>
        <label htmlFor="story-slug">{texts.label}</label>
        <input
          id="story-slug"
          defaultValue="news"
          aria-invalid="true"
          aria-describedby="story-slug-error"
        />
        <FieldError id="story-slug-error">{texts.error}</FieldError>
      </div>
    );
  },
  play: ({ canvasElement }) => {
    const error = single(canvasElement, '.cms-field__error', HTMLParagraphElement);

    check(error.id === 'story-slug-error', 'the error has the id the control names');
  },
};

export const Dark: Story = inDark(Default);
export const ForcedColors: Story = inForcedColours(Default);
export const Danish: Story = inDanish(Default);
