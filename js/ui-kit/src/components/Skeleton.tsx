import './skeleton.css';

/**
 * The props of Skeleton.
 *
 * @experimental
 */
export interface SkeletonProps {
  /**
   * What is loading, such as "Loading the grants", from the caller's translations. A screen reader
   * hears it in place of the shapes, which are hidden from it.
   */
  readonly label: string;
  /** How many lines of text the shapes stand in for; 3 by default. */
  readonly lines?: number;
  /** text, the default, draws lines; block draws one box, such as for a card or a table. */
  readonly shape?: 'text' | 'block';
}

/**
 * The shape of content that is still loading, so the page does not jump when it arrives. It is a
 * status that says what loads; the grey shapes are decoration. Use it where the shape of what
 * comes is known, and a ProgressLabel where it is not.
 *
 * @experimental
 */
export function Skeleton({ label, lines = 3, shape = 'text' }: SkeletonProps) {
  return (
    <div className="cms-skeleton" role="status" aria-label={label}>
      {shape === 'block' ? (
        <span className="cms-skeleton__block" aria-hidden="true" />
      ) : (
        Array.from({ length: Math.max(1, lines) }, (_value, index) => (
          <span key={index} className="cms-skeleton__line" aria-hidden="true" />
        ))
      )}
    </div>
  );
}
