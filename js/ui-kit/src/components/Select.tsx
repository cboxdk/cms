import { useRef } from 'react';
import { HiddenSelect, useButton, useSelect } from 'react-aria';
import { Item, useSelectState, type Key } from 'react-stately';

import { useKitTranslation } from '../i18n/translations';
import { defined } from './internal/defined';
import { FieldDescription, FieldErrorText, FieldLabel } from './internal/FieldParts';
import { OptionList, type OptionItem } from './internal/OptionList';
import { Popover } from './internal/Popover';
import { Icon } from './Icon';

import './select.css';

/**
 * An option of a Select.
 *
 * @experimental
 */
export type SelectOption = OptionItem;

/**
 * The props of Select.
 *
 * @experimental
 */
export interface SelectProps {
  /** The id of the button, such as for an ErrorSummary's link to it; one of its own if left out. */
  readonly id?: string | undefined;
  /** The field's label, from the caller's translations. */
  readonly label: string;
  /** What the value must be, shown below the label, from the caller's translations. */
  readonly description?: string | undefined;
  /** What is wrong with the choice, from the caller's translations, shown below the button. */
  readonly error?: string | undefined;
  /** The options, in the order they are shown, each with an id unique in the list. */
  readonly options: readonly SelectOption[];
  /** The chosen option's id, or null for none; with onChange, the select is controlled. */
  readonly value?: string | null | undefined;
  /** The id of the option chosen at first, when the select is not controlled. */
  readonly defaultValue?: string | null | undefined;
  /** Called with the id of the option chosen. */
  readonly onChange?: ((value: string) => void) | undefined;
  /** The ids of the options that cannot be chosen. */
  readonly disabledOptions?: readonly string[] | undefined;
  /** The name the chosen id is submitted under in a form, through a hidden native select. */
  readonly name?: string | undefined;
  /** Whether a value is required; the label is marked in the kit's own text. */
  readonly required?: boolean | undefined;
  /** Whether the control cannot be used; it is shown dimmed and the keyboard skips it. */
  readonly disabled?: boolean | undefined;
}

/**
 * A choice of one option from a list that opens below a button, for more options than a
 * RadioGroup shows. The button shows the chosen option, or that none is chosen, in the kit's own
 * text. Enter, Space, Up and Down open the list on the chosen option; the arrow keys move in it,
 * typing jumps to the option that starts with what is typed, Enter chooses, and Escape closes the
 * list and returns focus to the button. A hidden native select submits the choice with a form and
 * lets autofill set it.
 *
 * @experimental
 */
export function Select({
  id,
  label,
  description,
  error,
  options,
  value,
  defaultValue,
  onChange,
  disabledOptions,
  name,
  required = false,
  disabled = false,
}: SelectProps) {
  const t = useKitTranslation();
  const props = {
    ...defined({
      id,
      description,
      errorMessage: error,
      value,
      defaultValue,
      disabledKeys: disabledOptions,
      name,
    }),
    label,
    items: options,
    children: (option: SelectOption) => <Item key={option.id}>{option.label}</Item>,
    isRequired: required,
    isDisabled: disabled,
    isInvalid: error !== undefined,
    validationBehavior: 'aria' as const,
    onChange: (key: Key | null) => {
      if (key !== null) {
        onChange?.(String(key));
      }
    },
  };
  const state = useSelectState(props);
  const triggerRef = useRef<HTMLButtonElement>(null);
  const { labelProps, triggerProps, valueProps, menuProps, descriptionProps, errorMessageProps } =
    useSelect(props, state, triggerRef);
  const { buttonProps } = useButton(triggerProps, triggerRef);
  const chosen = state.selectedItems[0]?.value ?? null;

  return (
    <div className="cms-field">
      <FieldLabel {...labelProps} required={required}>
        {label}
      </FieldLabel>
      {description === undefined ? null : (
        <FieldDescription {...descriptionProps}>{description}</FieldDescription>
      )}
      <HiddenSelect state={state} triggerRef={triggerRef} label={label} {...defined({ name })} />
      <button
        {...buttonProps}
        ref={triggerRef}
        className="cms-input cms-select__button"
        data-invalid={error !== undefined}
        data-open={state.isOpen}
      >
        <span {...valueProps} className="cms-select__value" data-empty={chosen === null}>
          {chosen?.label ?? t('kit.select.none')}
        </span>
        <Icon name="chevron-down" />
      </button>
      {error === undefined ? null : <FieldErrorText {...errorMessageProps}>{error}</FieldErrorText>}
      {state.isOpen ? (
        <Popover state={state} triggerRef={triggerRef} matchTriggerWidth>
          <OptionList {...menuProps} state={state} />
        </Popover>
      ) : null}
    </div>
  );
}
