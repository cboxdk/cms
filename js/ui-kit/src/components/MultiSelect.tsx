import { useId, useRef } from 'react';
import { useButton, useOverlayTrigger } from 'react-aria';
import { ListBox, ListBoxItem } from 'react-aria-components/ListBox';
import { useOverlayTriggerState, type Selection } from 'react-stately';

import { useKitTranslation } from '../i18n/translations';
import { describedBy, FieldDescription, FieldErrorText, FieldLabel } from './internal/FieldParts';
import type { OptionItem } from './internal/OptionList';
import { Popover } from './internal/Popover';
import { Icon } from './Icon';
import { Tag } from './Tag';

import './multi-select.css';
import './select.css';

/**
 * An option of a MultiSelect.
 *
 * @experimental
 */
export type MultiSelectOption = OptionItem;

/**
 * The props of MultiSelect.
 *
 * @experimental
 */
export interface MultiSelectProps {
  /** The field's label, from the caller's translations. */
  readonly label: string;
  /** What the value must be, shown below the label, from the caller's translations. */
  readonly description?: string | undefined;
  /** What is wrong with the choice, from the caller's translations. */
  readonly error?: string | undefined;
  /** The options, in the order they are shown. */
  readonly options: readonly MultiSelectOption[];
  /** The ids of the chosen options, in any order; the field is controlled. */
  readonly value: readonly string[];
  /** Called with the ids of the chosen options, in the order of the options. */
  readonly onChange: (value: string[]) => void;
  /**
   * What the button says when no option is chosen, from the caller's translations, because
   * choosing none can mean something, such as every locale of a grant.
   */
  readonly noneLabel: string;
  /** The name each chosen id is submitted under in a form, through a hidden input each. */
  readonly name?: string | undefined;
  /** Whether at least one option must be chosen; the label is marked in the kit's own text. */
  readonly required?: boolean | undefined;
  /** Whether the control cannot be used; it is shown dimmed and the keyboard skips it. */
  readonly disabled?: boolean | undefined;
}

/**
 * A choice of any number of options, such as the locales of a grant: a button that opens the list
 * of options, and below it the chosen ones as tags. Enter, Space and Down open the list; the arrow
 * keys move in it, Space and Enter choose and unchoose an option and keep the list open, and Escape
 * or Tab closes it and returns focus to the button. Each tag's remove button unchooses its option.
 *
 * @experimental
 */
export function MultiSelect({
  label,
  description,
  error,
  options,
  value,
  onChange,
  noneLabel,
  name,
  required = false,
  disabled = false,
}: MultiSelectProps) {
  const t = useKitTranslation();
  const id = useId();
  const labelId = `${id}-label`;
  const descriptionId = `${id}-description`;
  const errorId = `${id}-error`;
  const triggerRef = useRef<HTMLButtonElement>(null);
  const state = useOverlayTriggerState({});
  const { triggerProps, overlayProps } = useOverlayTrigger({ type: 'listbox' }, state, triggerRef);
  const { buttonProps } = useButton({ ...triggerProps, isDisabled: disabled }, triggerRef);
  const chosen = options.filter((option) => value.includes(option.id));
  const choose = (ids: readonly string[]) => {
    onChange(options.filter((option) => ids.includes(option.id)).map((option) => option.id));
  };

  return (
    <div className="cms-field">
      <FieldLabel id={labelId} required={required} htmlFor={`${id}-button`}>
        {label}
      </FieldLabel>
      {description === undefined ? null : (
        <FieldDescription id={descriptionId}>{description}</FieldDescription>
      )}
      <button
        {...buttonProps}
        id={`${id}-button`}
        ref={triggerRef}
        className="cms-input cms-select__button"
        aria-labelledby={`${labelId} ${id}-button`}
        aria-describedby={describedBy(
          description !== undefined && descriptionId,
          error !== undefined && errorId,
        )}
        aria-invalid={error === undefined ? undefined : true}
        data-invalid={error !== undefined}
      >
        <span className="cms-select__value" data-empty={chosen.length === 0}>
          {chosen.length === 0 ? noneLabel : t('kit.multiselect.chosen', { count: chosen.length })}
        </span>
        <Icon name="chevron-down" />
      </button>
      {chosen.length === 0 ? null : (
        <ul className="cms-multi-select__chosen" aria-labelledby={labelId}>
          {chosen.map((option) => (
            <li key={option.id}>
              <Tag
                label={option.label}
                onRemove={
                  disabled
                    ? undefined
                    : () => {
                        choose(value.filter((chosenId) => chosenId !== option.id));
                      }
                }
              />
            </li>
          ))}
        </ul>
      )}
      {error === undefined ? null : <FieldErrorText id={errorId}>{error}</FieldErrorText>}
      {name === undefined
        ? null
        : chosen.map((option) => (
            <input key={option.id} type="hidden" name={name} value={option.id} />
          ))}
      {state.isOpen ? (
        <Popover state={state} triggerRef={triggerRef} matchTriggerWidth>
          <ListBox
            {...overlayProps}
            aria-labelledby={labelId}
            items={options}
            selectionMode="multiple"
            selectedKeys={value}
            onSelectionChange={(selection: Selection) => {
              choose(
                selection === 'all'
                  ? options.map((option) => option.id)
                  : [...selection].map((key) => String(key)),
              );
            }}
            autoFocus="first"
            escapeKeyBehavior="none"
            className="cms-option-list"
          >
            {(option) => (
              <ListBoxItem
                id={option.id}
                textValue={option.label}
                className="cms-option cms-multi-select__option"
              >
                {({ isSelected }) => (
                  <>
                    <span className="cms-multi-select__box" aria-hidden="true">
                      {isSelected ? <Icon name="check" size="sm" /> : null}
                    </span>
                    <span className="cms-option__text">
                      <span>{option.label}</span>
                      {option.description === undefined ? null : (
                        <span className="cms-option__description">{option.description}</span>
                      )}
                    </span>
                  </>
                )}
              </ListBoxItem>
            )}
          </ListBox>
        </Popover>
      ) : null}
    </div>
  );
}
