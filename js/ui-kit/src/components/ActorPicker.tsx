import { Combobox } from './Combobox';

/**
 * An actor an ActorPicker offers.
 *
 * @experimental
 */
export interface PickerActor {
  /** The actor's id, which the picker gives back. */
  readonly id: string;
  /** The actor's name, from its profile. */
  readonly name: string;
  /** The actor's email, which tells actors of the same name apart. */
  readonly email?: string | undefined;
}

/**
 * The props of ActorPicker.
 *
 * @experimental
 */
export interface ActorPickerProps {
  /** The field's label, such as "Member of staff", from the caller's translations. */
  readonly label: string;
  /** What the value must be, shown below the label, from the caller's translations. */
  readonly description?: string | undefined;
  /** What is wrong with the value, from the caller's translations, shown below the control. */
  readonly error?: string | undefined;
  /** The actors that match what is typed, as the caller found them. */
  readonly actors: readonly PickerActor[];
  /** The chosen actor's id, or null for none; the picker is controlled. */
  readonly value: string | null;
  /** Called with the id of the actor chosen, or null when the choice is cleared. */
  readonly onChange: (id: string | null) => void;
  /** What is typed; the caller finds the actors that match it, such as with actor.list. */
  readonly search: string;
  /** Called with what is typed, so the caller can find the actors that match it. */
  readonly onSearchChange: (text: string) => void;
  /** What the list waits for while the actors load, from the caller's translations. */
  readonly loading?: string | undefined;
  /** Why the actors could not be loaded, from the caller's translations. */
  readonly loadError?: string | undefined;
  /** What the list says when no actor matches, from the caller's translations. */
  readonly emptyLabel: string;
  /** The name the value is submitted under in a form. */
  readonly name?: string | undefined;
  /** Whether a value is required; the label is marked in the kit's own text. */
  readonly required?: boolean | undefined;
}

/**
 * A field to find and choose an actor, such as the member of staff a grant is for: a Combobox whose
 * options the caller finds as the reader types, each the actor's name with the email below it, with
 * the same keyboard.
 *
 * @experimental
 */
export function ActorPicker({ actors, search, onSearchChange, ...rest }: ActorPickerProps) {
  return (
    <Combobox
      {...rest}
      inputValue={search}
      onInputChange={onSearchChange}
      options={actors.map((actor) => ({
        id: actor.id,
        label: actor.name,
        description: actor.email,
      }))}
    />
  );
}
