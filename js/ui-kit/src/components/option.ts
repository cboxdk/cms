/**
 * A choice of a Select, a Combobox, a MultiSelect or a picker.
 *
 * @experimental
 */
export interface OptionItem {
  /** The choice's id, unique among the choices; what the component gives back when it is chosen. */
  readonly id: string;
  /** What the choice is called, from the caller's translations or the data. */
  readonly label: string;
  /** A second line that tells choices apart, or undefined. */
  readonly description?: string | undefined;
}
