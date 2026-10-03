import type { InputHTMLAttributes, Ref } from 'react';

import { Field } from './Field';

/**
 * The kinds of text a TextInput takes, as the input element's type.
 *
 * @experimental
 */
export type TextInputType = 'text' | 'email' | 'password' | 'search' | 'tel' | 'url';

/**
 * The props of TextInput: an input element's attributes, without className, style and type, and the kit's own.
 *
 * @experimental
 */
export interface TextInputProps extends Omit<
  InputHTMLAttributes<HTMLInputElement>,
  'children' | 'className' | 'style' | 'type'
> {
  /** The field's label, from the caller's translations. */
  readonly label: string;
  /** What the value must be, shown below the label, from the caller's translations. */
  readonly description?: string | undefined;
  /** What is wrong with the value, from the caller's translations, shown below the input. */
  readonly error?: string | undefined;
  /** The kind of text, which decides the keyboard on a phone and the browser's checks; text by default. */
  readonly type?: TextInputType;
  /** A ref to the input element. */
  readonly ref?: Ref<HTMLInputElement>;
}

/**
 * A text field: a label and an input in a Field. The input is a plain input element, so the
 * keyboard, autofill and password managers work as the platform does. A description is read as
 * the input's description and an error marks it invalid and is read after the description; a
 * required field is marked after its label in the kit's own text.
 *
 * @experimental
 */
export function TextInput({ label, description, error, type = 'text', ...rest }: TextInputProps) {
  return (
    <Field
      id={rest.id}
      label={label}
      description={description}
      error={error}
      required={rest.required}
    >
      {(control) => <input {...rest} {...control} type={type} className="cms-input" />}
    </Field>
  );
}
