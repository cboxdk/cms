import { useId, type ReactNode } from 'react';

import { describedBy, FieldDescription, FieldErrorText, FieldLabel } from './internal/FieldParts';

/**
 * What a control inside a Field spreads on itself, so it is joined to the field's label,
 * description and error.
 *
 * @experimental
 */
export interface FieldControlProps {
  /** The control's id, which the label names. */
  readonly id: string;
  /** The ids of the description and the error that are shown. */
  readonly 'aria-describedby': string | undefined;
  /** true while the field shows an error. */
  readonly 'aria-invalid': true | undefined;
  /** Whether a value is required. */
  readonly required: boolean | undefined;
}

/**
 * The props of Field.
 *
 * @experimental
 */
export interface FieldProps {
  /** The control's id, such as for an ErrorSummary's link to it; one of the field's own if left out. */
  readonly id?: string | undefined;
  /** The field's label, from the caller's translations. */
  readonly label: string;
  /** What the value must be, shown below the label, from the caller's translations. */
  readonly description?: string | undefined;
  /** What is wrong with the value, from the caller's translations, shown below the control. */
  readonly error?: string | undefined;
  /** Whether a value is required; the label is marked, and the control gets required. */
  readonly required?: boolean | undefined;
  /** The control, given the props that join it to the label, the description and the error. */
  readonly children: (control: FieldControlProps) => ReactNode;
}

/**
 * The frame of a field for a control the kit has no component for: the label, the description and
 * the error, joined to the control by ids of their own. A click on the label focuses the control; a
 * screen reader reads the label, then the description and then the error with it, and hears that
 * it is required from the control's own state, so the visible mark is hidden from it. Every field of
 * the kit, such as TextInput, is built the same way.
 *
 * @experimental
 */
export function Field({ id: given, label, description, error, required, children }: FieldProps) {
  const generated = useId();
  const id = given ?? generated;
  const descriptionId = `${id}-description`;
  const errorId = `${id}-error`;

  return (
    <div className="cms-field">
      <FieldLabel htmlFor={id} required={required}>
        {label}
      </FieldLabel>
      {description === undefined ? null : (
        <FieldDescription id={descriptionId}>{description}</FieldDescription>
      )}
      {children({
        id,
        'aria-describedby': describedBy(
          description !== undefined && descriptionId,
          error !== undefined && errorId,
        ),
        'aria-invalid': error === undefined ? undefined : true,
        required,
      })}
      {error === undefined ? null : <FieldErrorText id={errorId}>{error}</FieldErrorText>}
    </div>
  );
}
