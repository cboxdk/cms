import { useRef } from 'react';
import { useButton, useMenu, useMenuItem, useMenuTrigger, type AriaMenuOptions } from 'react-aria';
import {
  Item,
  useMenuTriggerState,
  useTreeState,
  type Key,
  type Node,
  type TreeState,
} from 'react-stately';

import { Popover } from './internal/Popover';
import { Icon, type IconName } from './Icon';

import './button.css';
import './icon-button.css';
import './menu.css';

/**
 * An item of a Menu: an action.
 *
 * @experimental
 */
export interface MenuItemSpec {
  /** An id unique in the menu, which onAction gives back. */
  readonly id: string;
  /** What the item does, from the caller's translations. */
  readonly label: string;
  /** danger for an item that removes or revokes; neutral by default. */
  readonly tone?: 'neutral' | 'danger';
  /** Whether the item cannot be chosen now; the arrow keys skip it. */
  readonly disabled?: boolean | undefined;
}

/**
 * The button that opens a Menu.
 *
 * @experimental
 */
export interface MenuTriggerSpec {
  /** What the menu holds, such as "More actions", from the caller's translations. */
  readonly label: string;
  /** With an icon, the trigger shows only the icon and the label is its accessible name. */
  readonly icon?: IconName | undefined;
  /**
   * -1 for a trigger inside a grid cell: the grid is one tab stop, and its roving focus reaches the
   * trigger with the arrow keys (the WAI-ARIA grid pattern), so the cell is not obscured by a second
   * target in the tab order (WCAG 2.2 target size). By default the trigger is a tab stop of its own.
   */
  readonly tabIndex?: -1 | undefined;
}

/**
 * The props of Menu.
 *
 * @experimental
 */
export interface MenuProps {
  /** The button that opens the menu. */
  readonly trigger: MenuTriggerSpec;
  /** The actions, in the order they are shown. */
  readonly items: readonly MenuItemSpec[];
  /** Called with the id of the item chosen; the menu then closes. */
  readonly onAction: (id: string) => void;
  /** Whether the menu cannot be opened now. */
  readonly disabled?: boolean | undefined;
}

/**
 * A button that opens a list of actions, such as the actions of a table row. Enter, Space and Down
 * open the menu on its first item and Up on its last; the arrow keys move between the items,
 * typing jumps to the item that starts with what is typed, Enter or Space chooses, and Escape or
 * Tab closes the menu and returns focus to the button.
 *
 * @experimental
 */
export function Menu({ trigger, items, onAction, disabled = false }: MenuProps) {
  const state = useMenuTriggerState({});
  const ref = useRef<HTMLButtonElement>(null);
  const { menuTriggerProps, menuProps } = useMenuTrigger({ isDisabled: disabled }, state, ref);
  const { buttonProps } = useButton(menuTriggerProps, ref);
  const iconOnly = trigger.icon !== undefined;

  return (
    <>
      <button
        {...buttonProps}
        ref={ref}
        className={iconOnly ? 'cms-icon-button' : 'cms-button'}
        aria-label={iconOnly ? trigger.label : undefined}
        tabIndex={trigger.tabIndex}
        data-variant={iconOnly ? 'quiet' : 'secondary'}
      >
        {trigger.icon === undefined ? (
          <>
            {trigger.label}
            <Icon name="chevron-down" />
          </>
        ) : (
          <Icon name={trigger.icon} />
        )}
      </button>
      {state.isOpen ? (
        <Popover state={state} triggerRef={ref} placement="bottom end">
          <MenuList
            {...menuProps}
            items={items}
            onAction={(key: Key) => {
              onAction(String(key));
            }}
            disabledKeys={items.filter((item) => item.disabled === true).map((item) => item.id)}
          />
        </Popover>
      ) : null}
    </>
  );
}

function MenuList(
  props: AriaMenuOptions<MenuItemSpec> & { readonly items: readonly MenuItemSpec[] },
) {
  const state = useTreeState({
    ...props,
    children: (item: MenuItemSpec) => (
      <Item key={item.id} textValue={item.label}>
        {item.label}
      </Item>
    ),
  });
  const ref = useRef<HTMLUListElement>(null);
  const { menuProps } = useMenu(props, state, ref);

  return (
    <ul {...menuProps} ref={ref} className="cms-option-list">
      {[...state.collection].map((node) => (
        <MenuEntry key={node.key} node={node} state={state} />
      ))}
    </ul>
  );
}

function MenuEntry({
  node,
  state,
}: {
  readonly node: Node<MenuItemSpec>;
  readonly state: TreeState<MenuItemSpec>;
}) {
  const ref = useRef<HTMLLIElement>(null);
  const { menuItemProps, isFocused, isDisabled } = useMenuItem({ key: node.key }, state, ref);

  return (
    <li
      {...menuItemProps}
      ref={ref}
      className="cms-option"
      data-focused={isFocused}
      data-disabled={isDisabled}
      data-tone={node.value?.tone ?? 'neutral'}
    >
      {node.rendered}
    </li>
  );
}
