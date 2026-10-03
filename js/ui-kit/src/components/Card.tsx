import { useId, type ReactNode } from 'react';

import './card.css';

/**
 * The props of Card.
 *
 * @experimental
 */
export interface CardProps {
  /** The card's heading, from the caller's translations; with it, the card is a labelled region. */
  readonly title?: string | undefined;
  /** The level of the heading in the page's outline; 2 by default. */
  readonly headingLevel?: 2 | 3 | 4;
  /** Actions at the end of the card's header, such as an edit button. */
  readonly actions?: ReactNode;
  /** What the card holds. */
  readonly children: ReactNode;
  /** A row below the content, such as a link to more. */
  readonly footer?: ReactNode;
}

/**
 * A panel on the raised surface that holds one thing, such as the grants of an actor. With a
 * title it is a section named by its heading, which a screen reader lists among the page's
 * regions.
 *
 * @experimental
 */
export function Card({ title, headingLevel = 2, actions, children, footer }: CardProps) {
  const id = useId();
  const Heading = `h${String(headingLevel)}` as 'h2' | 'h3' | 'h4';

  return (
    <section className="cms-card" aria-labelledby={title === undefined ? undefined : `${id}-title`}>
      {title === undefined && actions === undefined ? null : (
        <div className="cms-card__header">
          {title === undefined ? null : (
            <Heading id={`${id}-title`} className="cms-card__title">
              {title}
            </Heading>
          )}
          {actions === undefined ? null : <div className="cms-card__actions">{actions}</div>}
        </div>
      )}
      <div className="cms-card__body">{children}</div>
      {footer === undefined ? null : <div className="cms-card__footer">{footer}</div>}
    </section>
  );
}
