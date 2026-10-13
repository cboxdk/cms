import { useRef } from 'react';
import { useMenu, useMenuItem, type AriaMenuOptions } from 'react-aria';
import { Item, useTreeState, type Node, type TreeState } from 'react-stately';

import type { MenuItemSpec } from '../Menu';

// The list of a menu, which Menu and FilterChip open in a popover: an item per action, with the
// focus moved by the arrow keys and typing, and each item's tone.

export function MenuList(
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
