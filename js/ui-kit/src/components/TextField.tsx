import { useId, type InputHTMLAttributes } from 'react';

import './text-field.css';

export type TextFieldType = 'text' | 'email' | 'password';

export interface TextFieldProps extends Omit<
  InputHTMLAttributes<HTMLInputElement>,
  'children' | 'className' | 'id' | 'style' | 'type'
> {
  /** The field's label, from the caller's translations. */
  readonly label: string;
  /** What is wrong with the value, from the caller's translations, or undefined. */
  readonly error?: string | undefined;
  readonly type?: TextFieldType;
}

/**
 * A text field of the kit: a label and an input, joined by an id of their own, so a click on the
 * label focuses the input and a screen reader reads the label with it. An error is shown below the
 * input, marks it invalid and is read as its description. The input is a plain input element, so
 * the keyboard, autofill and password managers work as the platform does.
 */
export function TextField({ label, error, type = 'text', ...rest }: TextFieldProps) {
  const id = useId();
  const errorId = `${id}-error`;

  return (
    <div className="cms-text-field">
      <label className="cms-text-field__label" htmlFor={id}>
        {label}
      </label>
      <input
        {...rest}
        id={id}
        type={type}
        className="cms-text-field__input"
        aria-invalid={error === undefined ? undefined : true}
        aria-describedby={error === undefined ? undefined : errorId}
      />
      {error === undefined ? null : (
        <p id={errorId} className="cms-text-field__error">
          {error}
        </p>
      )}
    </div>
  );
}
