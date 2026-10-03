import { useId, type ReactNode } from 'react';

import './task-screen.css';

/**
 * One fact on the card of a TaskScreen's showcase: a short label and its value, which is shown in
 * the monospaced face, as the Cbox design language shows states, ids and numbers.
 *
 * @experimental
 */
export interface TaskScreenFact {
  /** What the fact is about, from the caller's translations. */
  readonly label: string;
  /** The fact itself, from the caller's translations. */
  readonly value: string;
}

/**
 * The card of a TaskScreen's showcase: a heading, an optional state beside it, the facts in a row
 * and an optional note below them.
 *
 * @experimental
 */
export interface TaskScreenShowcaseCard {
  /** The card's heading, from the caller's translations. */
  readonly title: string;
  /** A state beside the heading, shown in the success tone, or undefined. */
  readonly status?: string | undefined;
  /** The facts, in the order they are shown. */
  readonly facts: readonly TaskScreenFact[];
  /** A sentence below the facts, or undefined. */
  readonly note?: string | undefined;
}

/**
 * The side of a TaskScreen that says what the product is: a short label above the heading, the
 * heading, a sentence and a card. Every text comes from the caller's translations.
 *
 * @experimental
 */
export interface TaskScreenShowcase {
  /** A short label above the heading, shown in the monospaced face, or undefined. */
  readonly eyebrow?: string | undefined;
  /** The showcase's heading. */
  readonly title: string;
  /** A sentence below the heading, or undefined. */
  readonly description?: string | undefined;
  /** The card below the sentence, or undefined. */
  readonly card?: TaskScreenShowcaseCard | undefined;
}

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
  /** The installation's brand, a Brand, at the top of the page, or undefined. */
  readonly brand?: ReactNode;
  /** The side that says what the product is, beside the task on a wide screen, or undefined. */
  readonly showcase?: TaskScreenShowcase | undefined;
}

function Showcase({ eyebrow, title, description, card }: TaskScreenShowcase) {
  const headingId = useId();

  return (
    <aside className="cms-task-screen__showcase" aria-labelledby={headingId}>
      <div className="cms-task-screen__pitch">
        {eyebrow === undefined ? null : <span className="cms-task-screen__eyebrow">{eyebrow}</span>}
        <h2 className="cms-task-screen__pitch-title" id={headingId}>
          {title}
        </h2>
        {description === undefined ? null : (
          <p className="cms-task-screen__pitch-description">{description}</p>
        )}
      </div>
      {card === undefined ? null : (
        <div className="cms-task-screen__card">
          <div className="cms-task-screen__card-header">
            <p className="cms-task-screen__card-title">{card.title}</p>
            {card.status === undefined ? null : (
              <span className="cms-task-screen__status">{card.status}</span>
            )}
          </div>
          <dl className="cms-task-screen__facts">
            {card.facts.map((fact) => (
              <div className="cms-task-screen__fact" key={fact.label}>
                <dt className="cms-task-screen__fact-label">{fact.label}</dt>
                <dd className="cms-task-screen__fact-value">{fact.value}</dd>
              </div>
            ))}
          </dl>
          {card.note === undefined ? null : <p className="cms-task-screen__note">{card.note}</p>}
        </div>
      )}
    </aside>
  );
}

/**
 * A page for one task outside the panel's navigation, such as signing in, in the layout of the
 * Cbox design language: the installation's brand at the top, the heading, the explanation, the task
 * and the links away from it in a column of a readable width, and, when the caller gives a
 * showcase, a side on the cool canvas beside it that says what the product is. The task is the
 * page's main landmark and the showcase a complementary one named by its heading.
 * On a phone the column fills the screen and the showcase follows it below.
 *
 * @experimental
 */
export function TaskScreen({
  title,
  description,
  children,
  footer,
  brand,
  showcase,
}: TaskScreenProps) {
  return (
    <div className="cms-task-screen" data-layout={showcase === undefined ? 'single' : 'split'}>
      <main className="cms-task-screen__main">
        {brand === undefined ? null : <div className="cms-task-screen__brand">{brand}</div>}
        <div className="cms-task-screen__center">
          <div className="cms-task-screen__panel" data-cms-part="task-screen">
            <div className="cms-task-screen__intro">
              <h1 className="cms-task-screen__title">{title}</h1>
              {description === undefined ? null : (
                <p className="cms-task-screen__description">{description}</p>
              )}
            </div>
            <div className="cms-task-screen__body">{children}</div>
            {footer === undefined ? null : <div className="cms-task-screen__footer">{footer}</div>}
          </div>
        </div>
      </main>
      {showcase === undefined ? null : <Showcase {...showcase} />}
    </div>
  );
}
