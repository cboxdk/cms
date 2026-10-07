// The fixture addon's input of its own value class (section 3.6 of the panel extension
// architecture): the replacement at command.form.field@1 of the input of every member a command
// binds to ArticleSlug, which is the member `slug` of the addon's own command fixtureaddon.slug.set.
// It shapes what is typed into a slug as DeriveSlug shapes a title: lower case, every run of other
// characters than a to z and 0 to 9 as one hyphen, at most MAX_LENGTH characters, and trims the
// hyphens at the ends when the field loses focus, so what the form submits is well formed. It keeps
// the default input's id and name, so the form's error summary still links to it and the value is
// submitted under the member's path, and shows the slug the article gets below the field.

import { usePanelHost } from '@cboxdk/cms-panel/extend';
import { TextInput, type FieldInputProps } from '@cboxdk/cms-panel/experimental';

import { MAX_LENGTH, slugOf } from './slug';

/** What is typed, shaped as it is typed: a hyphen at the end stays, so the next word can follow. */
export function shapeTyped(text: string): string {
  return text
    .toLowerCase()
    .replaceAll(/[^a-z0-9]+/g, '-')
    .replaceAll(/^-+/g, '')
    .slice(0, MAX_LENGTH);
}

export default function SlugInput(props: FieldInputProps) {
  const { t } = usePanelHost();
  const value = props.value ?? '';
  const slug = slugOf(value);

  return (
    <div data-fixtureaddon-slug-input={props.path}>
      <TextInput
        id={props.id}
        name={props.path}
        label={props.label}
        description={t('fixtureaddon.slug_input.description')}
        error={props.errors[0]}
        required={props.presence === 'required'}
        disabled={props.read_only}
        value={value}
        autoComplete="off"
        spellCheck={false}
        onChange={(event) => {
          const shaped = shapeTyped(event.target.value);
          props.onChange(shaped === '' ? null : shaped);
        }}
        onBlur={() => {
          if (slug !== value) {
            props.onChange(slug);
          }
        }}
      />
      <p data-fixtureaddon-slug-preview={slug ?? ''}>
        {slug === null
          ? t('fixtureaddon.slug_input.empty')
          : t('fixtureaddon.slug_input.preview', { slug })}
      </p>
    </div>
  );
}
