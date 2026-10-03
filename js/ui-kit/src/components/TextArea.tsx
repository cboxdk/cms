import type { Ref, TextareaHTMLAttributes } from 'react';

import { Field } from './Field';

/**
 * The props of TextArea: a textarea element's attributes, without className and style, and the kit's own.
 *
 * @experimental
 */
export interface TextAreaProps extends Omit<
  TextareaHTMLAttributes<HTMLTextAreaElement>,
  'children' | 'className' | 'style'
> {
  /** The field's label, from the caller's translations. */
  readonly label: string;
  /** What the value must be, shown below the label, from the caller's translations. */
  readonly description?: string | undefined;
  /** What is wrong with the value, from the caller's translations, shown below the text area. */
  readonly error?: string | undefined;
  /** Whether the text is code, such as JSON, shown in the monospaced font without spell check. */
  readonly monospace?: boolean | undefined;
  /** A ref to the textarea element. */
  readonly ref?: Ref<HTMLTextAreaElement>;
}

/**
 * A field for text of several lines: a label and a plain textarea element in a Field. Enter makes a
 * new line, Tab leaves the field, and the reader can make it taller where the browser allows.
 *
 * @experimental
 */
export function TextArea({
  label,
  description,
  error,
  monospace = false,
  rows = 4,
  ...rest
}: TextAreaProps) {
  return (
    <Field
      id={rest.id}
      label={label}
      description={description}
      error={error}
      required={rest.required}
    >
      {(control) => (
        <textarea
          spellCheck={monospace ? false : undefined}
          {...rest}
          {...control}
          rows={rows}
          className="cms-input"
          data-monospace={monospace}
        />
      )}
    </Field>
  );
}
