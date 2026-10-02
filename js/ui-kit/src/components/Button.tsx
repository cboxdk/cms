import type { ButtonHTMLAttributes, ReactNode } from 'react';

import './button.css';

export type ButtonVariant = 'secondary' | 'primary' | 'danger';

export interface ButtonProps extends Omit<
  ButtonHTMLAttributes<HTMLButtonElement>,
  'children' | 'className' | 'style'
> {
  /** The button's text, from the caller's translations. */
  readonly children: ReactNode;
  readonly variant?: ButtonVariant;
}

/**
 * A button of the kit. It is a plain button element, so the keyboard, focus and form submission
 * work as the platform does, and it is type="button" unless the caller asks for a submit button.
 */
export function Button({ children, variant = 'secondary', type = 'button', ...rest }: ButtonProps) {
  return (
    <button {...rest} type={type} className="cms-button" data-variant={variant}>
      {children}
    </button>
  );
}
