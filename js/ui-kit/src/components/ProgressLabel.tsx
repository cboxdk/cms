import './progress-label.css';

/**
 * The props of ProgressLabel.
 *
 * @experimental
 */
export interface ProgressLabelProps {
  /**
   * What is being waited for, such as "Loading the roles" or "Waiting for the cache to be cleared",
   * from the caller's translations: a wait always says what it waits for.
   */
  readonly children: string;
}

/**
 * A wait in progress: a spinner and the text of what is being waited for, in a status a screen
 * reader announces when it is idle. The spinner stops turning when the reader prefers reduced
 * motion; the text still says that the page waits.
 *
 * @experimental
 */
export function ProgressLabel({ children }: ProgressLabelProps) {
  return (
    <span className="cms-progress-label" role="status">
      <span className="cms-progress-label__spinner" aria-hidden="true" />
      <span>{children}</span>
    </span>
  );
}
