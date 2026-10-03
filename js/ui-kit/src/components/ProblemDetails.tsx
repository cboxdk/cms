import { useKitTranslation } from '../i18n/translations';
import { Icon } from './Icon';

import './problem-details.css';

/**
 * The part of a problem details document (problem.v1.json, as the generated ProblemV1 types it)
 * ProblemDetails shows; a page passes the problem it got as it is.
 *
 * @experimental
 */
export interface ProblemSummary {
  /** The catalog code, such as validation_failed. */
  readonly code: string;
  /** The concrete cause of this refusal, as the kernel wrote it. */
  readonly detail: string;
  /** The HTTP status of the code. */
  readonly status: number;
  /** Whether trying again later may work. */
  readonly retryable: boolean;
  /** The errors at the inputs they are about, each with its catalog code, cause and path. */
  readonly errors: readonly {
    readonly code: string;
    readonly detail: string;
    readonly field: string | null;
  }[];
}

/**
 * The props of ProblemDetails.
 *
 * @experimental
 */
export interface ProblemDetailsProps {
  /** The problem details the surface answered with. */
  readonly problem: ProblemSummary;
  /**
   * What the code means and what to do, in the reader's language: the panel's translation of the
   * catalog's explanation of the code.
   */
  readonly explanation: string;
  /** The address of the code's section of the error reference, docs/reference/errors.md. */
  readonly docsHref?: string | undefined;
  /** The text of the link to the reference, from the caller's translations. */
  readonly docsLabel?: string | undefined;
  /** The text of a field error, from the caller's translations of its code; its detail by default. */
  readonly errorText?: ((error: ProblemSummary['errors'][number]) => string) | undefined;
}

/**
 * A refusal from the kernel (problem.v1.json) as the panel shows it: what the catalog code means
 * and what to do, the concrete cause, whether trying again may help, each error at the field it is
 * about, the code itself, and a link to its section of the error reference. It is an alert, which a
 * screen reader announces when it appears, and no refusal ends without a way on.
 *
 * @experimental
 */
export function ProblemDetails({
  problem,
  explanation,
  docsHref,
  docsLabel,
  errorText,
}: ProblemDetailsProps) {
  const t = useKitTranslation();

  return (
    <div className="cms-problem-details" role="alert">
      <p className="cms-problem-details__title">
        <Icon name="error" />
        {explanation}
      </p>
      <p className="cms-problem-details__detail">{problem.detail}</p>
      {problem.retryable ? (
        <p className="cms-problem-details__retry">{t('kit.problem.retryable')}</p>
      ) : null}
      {problem.errors.length === 0 ? null : (
        <ul className="cms-problem-details__errors">
          {problem.errors.map((error, index) => (
            <li key={`${String(index)} ${error.code}`}>
              {error.field === null ? null : (
                <>
                  <code>{error.field}</code>:{' '}
                </>
              )}
              {errorText?.(error) ?? error.detail}
            </li>
          ))}
        </ul>
      )}
      <p className="cms-problem-details__code">
        {t('kit.error.code')} <code>{problem.code}</code>
        {docsHref === undefined || docsLabel === undefined ? null : (
          <>
            {' '}
            <a href={docsHref} className="cms-problem-details__docs">
              {docsLabel}
            </a>
          </>
        )}
      </p>
    </div>
  );
}
