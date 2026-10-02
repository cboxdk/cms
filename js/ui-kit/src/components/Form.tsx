import type { FormHTMLAttributes, ReactNode } from 'react';

import './form.css';

export interface FormProps extends Omit<
  FormHTMLAttributes<HTMLFormElement>,
  'children' | 'className' | 'style'
> {
  /** The form's fields and buttons, in the order the keyboard reaches them. */
  readonly children: ReactNode;
}

/**
 * A form of the kit: a plain form element that stacks its fields and buttons with the kit's
 * spacing, so Enter submits it and the browser checks required fields as the platform does.
 */
export function Form({ children, ...rest }: FormProps) {
  return (
    <form {...rest} className="cms-form">
      {children}
    </form>
  );
}
