import type { ButtonHTMLAttributes, ReactNode, Ref } from 'react';

import { Icon, type IconName } from './Icon';

import './button.css';

/**
 * How much a Button draws the eye, and whether it removes or revokes.
 *
 * @experimental
 */
export type ButtonVariant = 'secondary' | 'primary' | 'danger' | 'quiet';

/**
 * The props of Button: a button element's attributes, without className and style, and the kit's own.
 *
 * @experimental
 */
export interface ButtonProps extends Omit<
  ButtonHTMLAttributes<HTMLButtonElement>,
  'children' | 'className' | 'style'
> {
  /** The button's text, from the caller's translations. */
  readonly children: ReactNode;
  /**
   * secondary, the default, for most actions; primary for the one main action of a form or page;
   * danger for an action that removes or revokes; quiet for an action that should not draw the eye.
   */
  readonly variant?: ButtonVariant;
  /** An icon before the text; the text still says what the button does. */
  readonly icon?: IconName | undefined;
  /** A ref to the button element, such as for a Tooltip. */
  readonly ref?: Ref<HTMLButtonElement>;
}

/**
 * A button of the kit. It is a plain button element, so the keyboard, focus and form submission
 * work as the platform does: Tab reaches it, and Enter and Space press it. It is type="button"
 * unless the caller asks for a submit button.
 *
 * @experimental
 */
export function Button({
  children,
  variant = 'secondary',
  icon,
  type = 'button',
  ...rest
}: ButtonProps) {
  return (
    <button {...rest} type={type} className="cms-button" data-variant={variant}>
      {icon === undefined ? null : <Icon name={icon} />}
      {children}
    </button>
  );
}
