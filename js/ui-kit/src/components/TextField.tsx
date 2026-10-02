import { useId, type InputHTMLAttributes } from 'react';

import './text-field.css';

export type TextFieldType = 'text' | 'email' | 'password';

export interface TextFieldProps extends Omit<
  InputHTMLAttributes<HTMLInputElement>,
  'children' | 'className' | 'id' | 'style' | 'type'
> {
  /** The field's label, from the caller's translations. */
  readonly label: string;
  /** What the value must be, shown below the label, from the caller's translations, or undefined. */
  readonly hint?: string | undefined;
  /** What is wrong with the value, from the caller's translations, or undefined. */
  readonly error?: string | undefined;
  readonly type?: TextFieldType;
}

/**
 * A text field of the kit: a label and an input, joined by an id of their own, so a click on the
 * label focuses the input and a screen reader reads the label with it. A hint is shown below the
 * label and read as the input's description; an error is shown below the input, marks it invalid
 * and is read after the hint. The input is a plain input element, so
 * the keyboard, autofill and password managers work as the platform does.
 */
export function TextField({ label, hint, error, type = 'text', ...rest }: TextFieldProps) {
  const id = useId();
  const hintId = `${id}-hint`;
  const errorId = `${id}-error`;
  const described = [hint === undefined ? null : hintId, error === undefined ? null : errorId]
    .filter((part) => part !== null)
    .join(' ');

  return (
    <div className="cms-text-field">
      <label className="cms-text-field__label" htmlFor={id}>
        {label}
      </label>
      {hint === undefined ? null : (
        <p id={hintId} className="cms-text-field__hint">
          {hint}
        </p>
      )}
      <input
        {...rest}
        id={id}
        type={type}
        className="cms-text-field__input"
        aria-invalid={error === undefined ? undefined : true}
        aria-describedby={described === '' ? undefined : described}
      />
      {error === undefined ? null : (
        <p id={errorId} className="cms-text-field__error">
          {error}
        </p>
      )}
    </div>
  );
}
