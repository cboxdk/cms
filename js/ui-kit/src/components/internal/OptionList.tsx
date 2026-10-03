// The list of choices of a select and a combobox: a listbox whose options each show a label and,
// optionally, a description, with a check by the chosen one. It is internal to the kit and built on
// React Aria's useListBox and useOption hooks, which the select and the combobox hooks drive.

import { useRef, type RefObject } from 'react';
import { useListBox, useOption, type AriaListBoxOptions } from 'react-aria';
import type { ListState, Node } from 'react-stately';

import { Icon } from '../Icon';
import type { OptionItem } from '../option';

import '../shared.css';

export type { OptionItem } from '../option';

interface OptionListProps<T extends OptionItem> extends AriaListBoxOptions<T> {
  readonly state: ListState<T>;
  readonly listBoxRef?: RefObject<HTMLUListElement | null>;
}

export function OptionList<T extends OptionItem>({
  state,
  listBoxRef,
  ...props
}: OptionListProps<T>) {
  const ownRef = useRef<HTMLUListElement>(null);
  const ref = listBoxRef ?? ownRef;
  const { listBoxProps } = useListBox(props, state, ref);

  return (
    <ul {...listBoxProps} ref={ref} className="cms-option-list">
      {[...state.collection].map((node) => (
        <Option key={node.key} node={node} state={state} />
      ))}
    </ul>
  );
}

function Option<T extends OptionItem>({
  node,
  state,
}: {
  readonly node: Node<T>;
  readonly state: ListState<T>;
}) {
  const ref = useRef<HTMLLIElement>(null);
  const { optionProps, labelProps, descriptionProps, isSelected, isFocused, isDisabled } =
    useOption({ key: node.key }, state, ref);
  const item = node.value;

  return (
    <li
      {...optionProps}
      ref={ref}
      className="cms-option"
      data-focused={isFocused}
      data-selected={isSelected}
      data-disabled={isDisabled}
    >
      <span className="cms-option__text">
        <span {...labelProps}>{item?.label ?? node.textValue}</span>
        {item?.description === undefined ? null : (
          <span {...descriptionProps} className="cms-option__description">
            {item.description}
          </span>
        )}
      </span>
      {isSelected ? (
        <span className="cms-option__check">
          <Icon name="check" />
        </span>
      ) : null}
    </li>
  );
}
