// The input of one field of the generic command form (section 3.6 of the panel extension
// architecture): what a replacement at command.form.field@1 receives. The point's props are
// FieldInputPropsV1, which the page builds per field in the browser, and the host adds onChange,
// which hands the form the member's next value: text, or null to empty the field. The core's
// pickers of NodeId, ActorId and RoleId are such replacements, each with the kernel's list as its
// data; an addon's replacement of its own value class is one too.

import type { DataState, Replacement } from '../contributions';
import type { FieldInputPropsV1 } from '../generated/points/FieldInputPropsV1';

/**
 * The props of a replacement of a field's input: the point's props and onChange.
 *
 * @experimental
 */
export type FieldInputProps = FieldInputPropsV1 & {
  /** Hands the form the member's next value as text, or null to empty the field. */
  readonly onChange: (value: string | null) => void;
};

/**
 * A replacement of a field's input, with the result of its data query when it names one.
 *
 * @experimental
 */
export type FieldInput<D = never> = Replacement<FieldInputProps, D>;

/**
 * The data a field input with a data query gets.
 *
 * @experimental
 */
export type FieldInputData<D> = DataState<D>;
