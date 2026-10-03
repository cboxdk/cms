import { useId, type ReactNode } from 'react';

import './section.css';

/**
 * The props of Section.
 *
 * @experimental
 */
export interface SectionProps {
  /** The section's heading, from the caller's translations. */
  readonly title: string;
  /** What the section is about, below the heading, from the caller's translations. */
  readonly description?: string | undefined;
  /** The level of the heading in the page's outline; 2 by default. */
  readonly headingLevel?: 2 | 3 | 4;
  /** Actions at the end of the heading's row, such as an add button. */
  readonly actions?: ReactNode;
  /** The section's content. */
  readonly children: ReactNode;
}

/**
 * A part of a page with a heading, such as the grants on an actor's page: a section named by its
 * heading, so the page's outline has a heading for every part.
 *
 * @experimental
 */
export function Section({ title, description, headingLevel = 2, actions, children }: SectionProps) {
  const id = useId();
  const Heading = `h${String(headingLevel)}` as 'h2' | 'h3' | 'h4';

  return (
    <section className="cms-section" aria-labelledby={`${id}-title`}>
      <div className="cms-section__header">
        <div className="cms-section__heading">
          <Heading id={`${id}-title`} className="cms-section__title">
            {title}
          </Heading>
          {description === undefined ? null : (
            <p className="cms-section__description">{description}</p>
          )}
        </div>
        {actions === undefined ? null : <div className="cms-section__actions">{actions}</div>}
      </div>
      <div className="cms-section__body">{children}</div>
    </section>
  );
}
