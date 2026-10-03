import type { ReactNode } from 'react';

import './task-screen.css';

/**
 * The props of TaskScreen.
 *
 * @experimental
 */
export interface TaskScreenProps {
  /** The page's heading, from the caller's translations. */
  readonly title: string;
  /** What the page is for, from the caller's translations, or undefined. */
  readonly description?: string | undefined;
  /** The task itself, such as a form. */
  readonly children: ReactNode;
  /** Links away from the task, such as back to signing in, below it in a row, or undefined. */
  readonly footer?: ReactNode;
  /** The installation's brand, a Brand, above the heading, or undefined. */
  readonly brand?: ReactNode;
}

/**
 * A page for one task outside the panel's navigation, such as signing in: the page's main landmark
 * with one centred panel holding the installation's brand, the heading, the explanation, the task
 * and the links away from it.
 * It fills the viewport on a phone and stays at a readable width on a desktop.
 *
 * @experimental
 */
export function TaskScreen({ title, description, children, footer, brand }: TaskScreenProps) {
  return (
    <main className="cms-task-screen">
      <div className="cms-task-screen__panel" data-cms-part="task-screen">
        {brand === undefined ? null : <div className="cms-task-screen__brand">{brand}</div>}
        <h1 className="cms-task-screen__title">{title}</h1>
        {description === undefined ? null : (
          <p className="cms-task-screen__description">{description}</p>
        )}
        <div className="cms-task-screen__body">{children}</div>
        {footer === undefined ? null : <div className="cms-task-screen__footer">{footer}</div>}
      </div>
    </main>
  );
}
