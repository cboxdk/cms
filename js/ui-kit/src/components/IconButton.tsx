import type { ButtonHTMLAttributes, Ref } from 'react';

import { Icon, type IconName } from './Icon';

import './icon-button.css';

/**
 * The props of IconButton: a button element's attributes, without className and style, and the kit's own.
 *
 * @experimental
 */
export interface IconButtonProps extends Omit<
  ButtonHTMLAttributes<HTMLButtonElement>,
  'children' | 'className' | 'style' | 'aria-label'
> {
  /**
   * What the button does, from the caller's translations. An icon says nothing to a screen
   * reader, so the label is required: it is the button's accessible name, and a Tooltip around the
   * button can show it.
   */
  readonly label: string;
  /** The icon the button shows. */
  readonly icon: IconName;
  /** quiet, the default, has no border until hovered; secondary has the border of a button. */
  readonly variant?: 'quiet' | 'secondary';
  /** A ref to the button element, such as for a Tooltip. */
  readonly ref?: Ref<HTMLButtonElement>;
}

/**
 * A button that shows only an icon, such as a close button. It is a plain button element with the
 * label as its accessible name, at least the size of a pointer target (WCAG 2.2, 2.5.8).
 *
 * @experimental
 */
export function IconButton({
  label,
  icon,
  variant = 'quiet',
  type = 'button',
  ...rest
}: IconButtonProps) {
  return (
    <button
      {...rest}
      type={type}
      aria-label={label}
      className="cms-icon-button"
      data-variant={variant}
    >
      <Icon name={icon} />
    </button>
  );
}
