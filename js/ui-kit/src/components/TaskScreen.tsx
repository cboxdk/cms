import type { ReactNode } from 'react';

import './task-screen.css';

export interface TaskScreenProps {
  /** The page's heading, from the caller's translations. */
  readonly title: string;
  /** What the page is for, from the caller's translations, or undefined. */
  readonly description?: string;
  /** The task itself, such as a form. */
  readonly children: ReactNode;
}

/**
 * A page for one task outside the panel's navigation, such as signing in: the page's main landmark
 * with one centred panel holding the heading, the explanation and the task. It fills the viewport
 * on a phone and stays at a readable width on a desktop.
 */
export function TaskScreen({ title, description, children }: TaskScreenProps) {
  return (
    <main className="cms-task-screen">
      <div className="cms-task-screen__panel">
        <h1 className="cms-task-screen__title">{title}</h1>
        {description === undefined ? null : (
          <p className="cms-task-screen__description">{description}</p>
        )}
        <div className="cms-task-screen__body">{children}</div>
      </div>
    </main>
  );
}
