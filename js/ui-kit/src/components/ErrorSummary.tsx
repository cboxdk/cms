import { useEffect, useId, useRef } from 'react';

import { Icon } from './Icon';

import './error-summary.css';

/**
 * An error an ErrorSummary lists, with the control it is about.
 *
 * @experimental
 */
export interface ErrorSummaryItem {
  /** The id of the control the error is about, which the error's link moves focus to. */
  readonly target: string;
  /** What is wrong, from the caller's translations, as it is shown at the control. */
  readonly message: string;
}

/**
 * The props of ErrorSummary.
 *
 * @experimental
 */
export interface ErrorSummaryProps {
  /** What happened, such as "The form was refused", from the caller's translations. */
  readonly title: string;
  /** The errors, in the order of their controls in the form. */
  readonly errors: readonly ErrorSummaryItem[];
}

/**
 * The list of a refused form's errors, shown above the form. It takes focus when it appears, so a
 * screen reader reads it and the keyboard starts there, and each error is a link that moves focus
 * to its control. A refusal no control is about goes in a Callout instead.
 *
 * @experimental
 */
export function ErrorSummary({ title, errors }: ErrorSummaryProps) {
  const ref = useRef<HTMLDivElement>(null);
  const titleId = `${useId()}-title`;

  useEffect(() => {
    ref.current?.focus();
  }, []);

  return (
    <div
      ref={ref}
      className="cms-error-summary"
      tabIndex={-1}
      aria-labelledby={titleId}
      role="group"
    >
      <h2 id={titleId} className="cms-error-summary__title">
        <Icon name="error" />
        {title}
      </h2>
      <ul className="cms-error-summary__list">
        {errors.map((error) => (
          <li key={`${error.target} ${error.message}`}>
            <a
              href={`#${error.target}`}
              className="cms-error-summary__link"
              onClick={(event) => {
                const control = document.getElementById(error.target);

                if (control !== null) {
                  event.preventDefault();
                  control.focus();
                  control.scrollIntoView({ block: 'center' });
                }
              }}
            >
              {error.message}
            </a>
          </li>
        ))}
      </ul>
    </div>
  );
}
