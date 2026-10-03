import { Combobox } from './Combobox';

/**
 * A role a RolePicker offers.
 *
 * @experimental
 */
export interface PickerRole {
  /** The role's id, which the picker gives back. */
  readonly id: string;
  /** The role's handle, such as editor. */
  readonly handle: string;
  /** What the role may do, from the caller's translations or the data. */
  readonly description?: string | undefined;
}

/**
 * The props of RolePicker.
 *
 * @experimental
 */
export interface RolePickerProps {
  /** The field's label, such as "Role", from the caller's translations. */
  readonly label: string;
  /** What the value must be, shown below the label, from the caller's translations. */
  readonly description?: string | undefined;
  /** What is wrong with the value, from the caller's translations, shown below the control. */
  readonly error?: string | undefined;
  /** The roles to choose from. */
  readonly roles: readonly PickerRole[];
  /** The chosen role's id, or null for none; the picker is controlled. */
  readonly value: string | null;
  /** Called with the id of the role chosen, or null when the choice is cleared. */
  readonly onChange: (id: string | null) => void;
  /** What the list waits for while the roles load, from the caller's translations. */
  readonly loading?: string | undefined;
  /** Why the roles could not be loaded, from the caller's translations. */
  readonly loadError?: string | undefined;
  /** What the list says when no role matches, from the caller's translations. */
  readonly emptyLabel: string;
  /** The name the value is submitted under in a form. */
  readonly name?: string | undefined;
  /** Whether a value is required; the label is marked in the kit's own text. */
  readonly required?: boolean | undefined;
}

/**
 * A field to choose a role, such as for a grant: a Combobox over the roles by handle, each with
 * what it may do below it, with the same keyboard.
 *
 * @experimental
 */
export function RolePicker({ roles, ...rest }: RolePickerProps) {
  return (
    <Combobox
      {...rest}
      options={roles.map((role) => ({
        id: role.id,
        label: role.handle,
        description: role.description,
      }))}
    />
  );
}
