import type { ReactNode } from 'react';

import './form-actions.css';

/**
 * The props of FormActions.
 *
 * @experimental
 */
export interface FormActionsProps {
  /**
   * The form's buttons in the order they are read, the primary one last: they line up at the end of
   * the row on a wide screen and stack in the same order on a narrow one, so what is seen and what
   * the keyboard reaches never differ.
   */
  readonly children: ReactNode;
}

/**
 * The row of a form's buttons, below its fields.
 *
 * @experimental
 */
export function FormActions({ children }: FormActionsProps) {
  return <div className="cms-form-actions">{children}</div>;
}
