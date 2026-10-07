import { useId, type ReactNode } from 'react';

import { describedBy, FieldErrorText } from './internal/FieldParts';

import './fieldset.css';

/**
 * The props of Fieldset.
 *
 * @experimental
 */
export interface FieldsetProps {
  /** The fieldset's id, such as for an ErrorSummary's link to it; one of its own if left out. */
  readonly id?: string | undefined;
  /** What the fields have in common, from the caller's translations, read before each of them. */
  readonly legend: string;
  /** What the fields are for, below the legend, from the caller's translations. */
  readonly description?: string | undefined;
  /** What is wrong with the fields together, from the caller's translations. */
  readonly error?: string | undefined;
  /** The fields, in the order the keyboard reaches them. */
  readonly children: ReactNode;
}

/**
 * A group of fields that belong together, such as the parts of an address or a nested object of a
 * command: a native fieldset whose legend a screen reader reads with each field in it.
 *
 * @experimental
 */
export function Fieldset({ id: given, legend, description, error, children }: FieldsetProps) {
  const generated = useId();
  const id = given ?? generated;

  return (
    <fieldset
      id={id}
      className="cms-fieldset"
      aria-describedby={describedBy(
        description !== undefined && `${id}-description`,
        error !== undefined && `${id}-error`,
      )}
      aria-invalid={error === undefined ? undefined : true}
    >
      <legend className="cms-fieldset__legend">{legend}</legend>
      {description === undefined ? null : (
        <p id={`${id}-description`} className="cms-fieldset__description">
          {description}
        </p>
      )}
      <div className="cms-fieldset__fields">{children}</div>
      {error === undefined ? null : <FieldErrorText id={`${id}-error`}>{error}</FieldErrorText>}
    </fieldset>
  );
}
