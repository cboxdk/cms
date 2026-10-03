import { useRef } from 'react';
import { useButton, useLocale, useNumberField, type AriaButtonProps } from 'react-aria';
import { useNumberFieldState } from 'react-stately';

import { defined } from './internal/defined';
import { describedBy, FieldDescription, FieldErrorText, FieldLabel } from './internal/FieldParts';
import { Icon } from './Icon';

/**
 * The props of NumberInput.
 *
 * @experimental
 */
export interface NumberInputProps {
  /** The id of the input, such as for an ErrorSummary's link to it; one of its own if left out. */
  readonly id?: string | undefined;
  /** The field's label, from the caller's translations. */
  readonly label: string;
  /** What the value must be, such as its bounds, from the caller's translations. */
  readonly description?: string | undefined;
  /** What is wrong with the value, from the caller's translations, shown below the input. */
  readonly error?: string | undefined;
  /** The value, or null when the field is empty; with onChange, the field is controlled. */
  readonly value?: number | null | undefined;
  /** The value the field starts with when it is not controlled, or null for empty. */
  readonly defaultValue?: number | null | undefined;
  /** Called with the value, or null when the field is emptied, when it loses focus or is stepped. */
  readonly onChange?: ((value: number | null) => void) | undefined;
  /** The lowest value; Home goes to it, and a lower one is raised to it. */
  readonly minValue?: number | undefined;
  /** The highest value; End goes to it, and a higher one is lowered to it. */
  readonly maxValue?: number | undefined;
  /** The step of the buttons and the arrow keys; 1 by default. */
  readonly step?: number | undefined;
  /** Whether the value is a whole number; true by default, as the kernel's integers are. */
  readonly integer?: boolean | undefined;
  /** Whether a value is required; the label is marked in the kit's own text. */
  readonly required?: boolean | undefined;
  /** Whether the control cannot be used; it is shown dimmed and the keyboard skips it. */
  readonly disabled?: boolean | undefined;
  /** The name the value is submitted under in a form. */
  readonly name?: string | undefined;
}

/**
 * A field for a number, written and read in the page's locale: 1.234,5 in Danish and 1,234.5 in
 * English. The input is a spin button: Up and Down step the value, Page Up and Page Down step it
 * ten times, Home and End go to the bounds, and the value is kept within them when the field loses
 * focus. Two buttons inside its edge step it with a pointer; they are skipped by Tab, because the
 * keys do the same, and named by React Aria in the page's locale.
 *
 * @experimental
 */
export function NumberInput({
  id,
  label,
  description,
  error,
  value,
  defaultValue,
  onChange,
  minValue,
  maxValue,
  step = 1,
  integer = true,
  required = false,
  disabled = false,
  name,
}: NumberInputProps) {
  const { locale } = useLocale();
  const props = {
    ...defined({
      id,
      description,
      errorMessage: error,
      minValue,
      maxValue,
      name,
      value: value === null ? Number.NaN : value,
      defaultValue: defaultValue === null ? Number.NaN : defaultValue,
    }),
    label,
    isInvalid: error !== undefined,
    isRequired: required,
    isDisabled: disabled,
    step,
    formatOptions: integer ? { maximumFractionDigits: 0 } : {},
    validationBehavior: 'aria' as const,
    onChange: (next: number) => {
      onChange?.(Number.isNaN(next) ? null : next);
    },
  };
  const state = useNumberFieldState({ ...props, locale });
  const inputRef = useRef<HTMLInputElement>(null);
  const {
    labelProps,
    groupProps,
    inputProps,
    incrementButtonProps,
    decrementButtonProps,
    descriptionProps,
    errorMessageProps,
  } = useNumberField(props, state, inputRef);

  return (
    <div className="cms-field">
      <FieldLabel {...labelProps} required={required}>
        {label}
      </FieldLabel>
      {description === undefined ? null : (
        <FieldDescription {...descriptionProps}>{description}</FieldDescription>
      )}
      <div {...groupProps} className="cms-input-group" data-invalid={error !== undefined}>
        <input
          {...inputProps}
          ref={inputRef}
          className="cms-input"
          aria-describedby={describedBy(inputProps['aria-describedby'])}
        />
        <StepButton {...decrementButtonProps} icon="minus" />
        <StepButton {...incrementButtonProps} icon="plus" />
      </div>
      {error === undefined ? null : <FieldErrorText {...errorMessageProps}>{error}</FieldErrorText>}
    </div>
  );
}

function StepButton({ icon, ...props }: AriaButtonProps & { readonly icon: 'plus' | 'minus' }) {
  const ref = useRef<HTMLButtonElement>(null);
  const { buttonProps } = useButton(props, ref);

  return (
    <button {...buttonProps} ref={ref} className="cms-input-group__button">
      <Icon name={icon} />
    </button>
  );
}
