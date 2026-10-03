import { FieldErrorText } from './internal/FieldParts';

/**
 * The props of FieldError.
 *
 * @experimental
 */
export interface FieldErrorProps {
  /** The id the control names in its aria-describedby, so a screen reader reads the error. */
  readonly id: string;
  /** What is wrong, from the caller's translations. */
  readonly children: string;
}

/**
 * The error of a control, shown below it with the kit's mark of an error and in the danger colour,
 * for a control built outside a Field. The control names its id in aria-describedby and sets
 * aria-invalid; every field of the kit does that itself.
 *
 * @experimental
 */
export function FieldError({ id, children }: FieldErrorProps) {
  return <FieldErrorText id={id}>{children}</FieldErrorText>;
}
