import { useRef } from 'react';
import { useButton, useMenuTrigger } from 'react-aria';
import { useMenuTriggerState, type Key } from 'react-stately';

import { useKitTranslation } from '../i18n/translations';
import { Icon } from './Icon';
import { MenuList } from './internal/MenuList';
import { Popover } from './internal/Popover';

import './filter-chip.css';

/**
 * A value a FilterChip filters by.
 *
 * @experimental
 */
export interface FilterChipOption {
  /** An id unique among the options, which onChange gives back. */
  readonly id: string;
  /** The value's name, from the caller's translations or the data, such as a type's label. */
  readonly label: string;
}

/**
 * The props of FilterChip.
 *
 * @experimental
 */
export interface FilterChipProps {
  /** What the chip filters by, such as "Type", from the caller's translations. */
  readonly label: string;
  /** The values it may filter by, in the order they are offered. */
  readonly options: readonly FilterChipOption[];
  /** The id of the value it filters by now, or null when it does not filter. */
  readonly value: string | null;
  /** Called with the id of the value chosen, or null when the filter is cleared. */
  readonly onChange: (value: string | null) => void;
  /** Whether the chip cannot be used now, such as while a list loads. */
  readonly disabled?: boolean | undefined;
}

/**
 * A filter of a list, such as the type of the entries a content list shows. Without a value it is
 * a dashed chip that names what it filters by, and with one a solid chip that names the value
 * too, followed by a button that clears it, named "Clear <label>" in the kit's own text. The chip
 * itself opens a menu of the values: Enter, Space and Down open it, the arrow keys and typing
 * move between the values, Enter chooses one, and Escape closes the menu and returns focus to the
 * chip. A value that is not among the options shows as the id itself.
 *
 * @experimental
 */
export function FilterChip({ label, options, value, onChange, disabled = false }: FilterChipProps) {
  const t = useKitTranslation();
  const state = useMenuTriggerState({});
  const ref = useRef<HTMLButtonElement>(null);
  const { menuTriggerProps, menuProps } = useMenuTrigger({ isDisabled: disabled }, state, ref);
  const { buttonProps } = useButton(menuTriggerProps, ref);
  const chosen =
    value === null ? null : (options.find((option) => option.id === value)?.label ?? value);

  return (
    <span className="cms-filter-chip" data-set={chosen !== null}>
      <button {...buttonProps} ref={ref} className="cms-filter-chip__trigger">
        {chosen === null ? (
          <>
            <Icon name="plus" size="sm" />
            {label}
          </>
        ) : (
          <>
            <span className="cms-filter-chip__label">{label}</span>
            <span className="cms-filter-chip__value">{chosen}</span>
          </>
        )}
        <Icon name="chevron-down" size="sm" />
      </button>
      {chosen === null ? null : (
        <button
          type="button"
          className="cms-filter-chip__clear"
          aria-label={t('kit.filter_chip.clear', { label })}
          disabled={disabled}
          onClick={() => {
            onChange(null);
          }}
        >
          <Icon name="close" size="sm" />
        </button>
      )}
      {state.isOpen ? (
        <Popover state={state} triggerRef={ref} placement="bottom start">
          <MenuList
            {...menuProps}
            aria-label={label}
            items={options}
            onAction={(key: Key) => {
              onChange(String(key));
            }}
          />
        </Popover>
      ) : null}
    </span>
  );
}
