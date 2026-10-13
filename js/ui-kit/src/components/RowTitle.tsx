import './row-title.css';

/**
 * The props of RowTitle.
 *
 * @experimental
 */
export interface RowTitleProps {
  /** What the row is called, such as an entry's title. */
  readonly title: string;
  /** What the row is, shown below the title in a quieter text, such as the entry's type, or undefined. */
  readonly detail?: string | undefined;
  /**
   * Whether the title is the caller's text for a row without one, such as "Untitled": it is shown
   * in a quieter, italic text, so it is not read as the row's own title.
   */
  readonly fallback?: boolean | undefined;
}

/**
 * The title of a row of a list, such as an entry of the content list, with an optional line below
 * it that says what the row is. A long title wraps onto a second line and is cut with an ellipsis
 * after it rather than widening the list, and both lines are text, so a screen reader reads the
 * row by them.
 *
 * @experimental
 */
export function RowTitle({ title, detail, fallback = false }: RowTitleProps) {
  return (
    <span className="cms-row-title">
      <span className="cms-row-title__title" data-fallback={fallback}>
        {title}
      </span>
      {detail === undefined ? null : <span className="cms-row-title__detail">{detail}</span>}
    </span>
  );
}
