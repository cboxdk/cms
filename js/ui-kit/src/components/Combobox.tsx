import { useRef, type ReactNode } from 'react';
import { useButton, useComboBox, useFilter } from 'react-aria';
import { Item, useComboBoxState, type Key } from 'react-stately';

import { defined } from './internal/defined';
import { FieldDescription, FieldErrorText, FieldLabel } from './internal/FieldParts';
import { OptionList, type OptionItem } from './internal/OptionList';
import { Popover } from './internal/Popover';
import { Icon } from './Icon';
import { ProgressLabel } from './ProgressLabel';

import './select.css';

/**
 * An option of a Combobox.
 *
 * @experimental
 */
export type ComboboxOption = OptionItem;

/**
 * The props of Combobox.
 *
 * @experimental
 */
export interface ComboboxProps {
  /** The id of the input, such as for an ErrorSummary's link to it; one of its own if left out. */
  readonly id?: string | undefined;
  /** The field's label, from the caller's translations. */
  readonly label: string;
  /** What the value must be, shown below the label, from the caller's translations. */
  readonly description?: string | undefined;
  /** What is wrong with the value, from the caller's translations, shown below the input. */
  readonly error?: string | undefined;
  /**
   * The options. Without onInputChange the combobox filters them itself by what is typed, ignoring
   * case and accents; with it, the caller filters them, such as by asking the server.
   */
  readonly options: readonly ComboboxOption[];
  /** The chosen option's id, or null for none; with onChange, the combobox is controlled. */
  readonly value?: string | null | undefined;
  /** The id of the option chosen at first, when the combobox is not controlled. */
  readonly defaultValue?: string | null | undefined;
  /** Called with the id of the option chosen, or null when the choice is cleared. */
  readonly onChange?: ((value: string | null) => void) | undefined;
  /** The text in the input; with onInputChange, the text is controlled. */
  readonly inputValue?: string | undefined;
  /** Called with what is typed; with it, the caller filters the options. */
  readonly onInputChange?: ((text: string) => void) | undefined;
  /**
   * What the list is waiting for, such as "Finding actors", shown in it while the options load,
   * from the caller's translations; undefined when they are loaded.
   */
  readonly loading?: string | undefined;
  /** Why the options could not be loaded, from the caller's translations, shown in the list. */
  readonly loadError?: string | undefined;
  /** What the list says when no option matches, from the caller's translations. */
  readonly emptyLabel: string;
  /** The name the chosen id is submitted under in a form. */
  readonly name?: string | undefined;
  /** Whether a value is required; the label is marked in the kit's own text. */
  readonly required?: boolean | undefined;
  /** Whether the control cannot be used; it is shown dimmed and the keyboard skips it. */
  readonly disabled?: boolean | undefined;
}

/**
 * A field to find and choose one option by typing: an input with a list of the options that match
 * below it. Typing or Down opens the list; Up and Down move through it while focus stays in the
 * input, Enter chooses, Escape closes the list and then clears what was typed, and Tab leaves with
 * the choice kept. A screen reader hears the option in focus through the input's active
 * descendant. The button at its edge opens the whole list for a pointer. While the options load,
 * the list says what it is waiting for; when they fail to load or none matches, it says so.
 *
 * @experimental
 */
export function Combobox({
  id,
  label,
  description,
  error,
  options,
  value,
  defaultValue,
  onChange,
  inputValue,
  onInputChange,
  loading,
  loadError,
  emptyLabel,
  name,
  required = false,
  disabled = false,
}: ComboboxProps) {
  const { contains } = useFilter({ sensitivity: 'base' });
  const props = {
    ...defined({
      id,
      description,
      errorMessage: error,
      value,
      defaultValue,
      inputValue,
      onInputChange,
      name,
    }),
    label,
    // The combobox filters options it is given as defaultItems; options given as items are the
    // caller's to filter.
    ...(onInputChange === undefined ? { defaultItems: options } : { items: options }),
    children: (option: ComboboxOption) => (
      <Item key={option.id} textValue={option.label}>
        {option.label}
      </Item>
    ),
    isRequired: required,
    isDisabled: disabled,
    isInvalid: error !== undefined,
    validationBehavior: 'aria' as const,
    allowsEmptyCollection: true,
    menuTrigger: 'input' as const,
    onChange: (key: Key | null) => {
      onChange?.(key === null ? null : String(key));
    },
  };
  const state = useComboBoxState({
    ...props,
    ...(onInputChange === undefined ? { defaultFilter: contains } : {}),
  });
  const inputRef = useRef<HTMLInputElement>(null);
  const buttonRef = useRef<HTMLButtonElement>(null);
  const listBoxRef = useRef<HTMLUListElement>(null);
  const popoverRef = useRef<HTMLDivElement>(null);
  const {
    buttonProps: triggerProps,
    inputProps,
    listBoxProps,
    labelProps,
    descriptionProps,
    errorMessageProps,
  } = useComboBox({ ...props, inputRef, buttonRef, listBoxRef, popoverRef }, state);
  const { buttonProps } = useButton(triggerProps, buttonRef);
  let content: ReactNode;

  // In place of the list, a message takes the list's id, so the input's aria-controls names it.
  if (loading !== undefined) {
    content = (
      <div id={listBoxProps.id} className="cms-option-list__message">
        <ProgressLabel>{loading}</ProgressLabel>
      </div>
    );
  } else if (loadError !== undefined) {
    content = (
      <p id={listBoxProps.id} className="cms-option-list__message" role="alert">
        {loadError}
      </p>
    );
  } else if (state.collection.size === 0) {
    content = (
      <p id={listBoxProps.id} className="cms-option-list__message" role="status">
        {emptyLabel}
      </p>
    );
  } else {
    content = <OptionList {...listBoxProps} listBoxRef={listBoxRef} state={state} />;
  }

  return (
    <div className="cms-field">
      <FieldLabel {...labelProps} required={required}>
        {label}
      </FieldLabel>
      {description === undefined ? null : (
        <FieldDescription {...descriptionProps}>{description}</FieldDescription>
      )}
      <div className="cms-input-group" data-invalid={error !== undefined}>
        <input {...inputProps} ref={inputRef} className="cms-input" />
        <button {...buttonProps} ref={buttonRef} className="cms-input-group__button">
          <Icon name="chevron-down" />
        </button>
      </div>
      {error === undefined ? null : <FieldErrorText {...errorMessageProps}>{error}</FieldErrorText>}
      {state.isOpen ? (
        <Popover
          state={state}
          triggerRef={inputRef}
          popoverRef={popoverRef}
          nonModal
          matchTriggerWidth
        >
          {content}
        </Popover>
      ) : null}
    </div>
  );
}
