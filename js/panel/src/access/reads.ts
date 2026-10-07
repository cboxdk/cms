// What a read of a panel page gave (PRD 13.4): a page that reads carries the query's result as the
// result codec wrote it, or the problem details of a rejected read, in its props, and checks the
// result with the generated validator before it shows it; a document the panel cannot read, or a
// read the pipeline could not make, is a state of its own, so the page shows what to do instead of
// nothing. The roles and grants pages and the pickers of the grants page read this way.

import { validateProblemV1, type ProblemV1 } from '../generated/protocol/ProblemV1';
import type { Validation } from '../generated/validation';

/** What a read gave: the result, the problem details of a rejection, or a document the panel cannot read. */
export type ReadState<T> =
  | { readonly status: 'ready'; readonly value: T }
  | { readonly status: 'rejected'; readonly problem: ProblemV1 }
  | { readonly status: 'unreadable' };

/**
 * Reads a result and a rejection as a page's props carry them, checking the result with its
 * validator; a document that is neither, or that the validator refuses, is unreadable.
 */
export function readOf<T>(
  result: unknown,
  rejection: unknown,
  validate: (value: unknown) => Validation<T>,
): ReadState<T> {
  if (result !== null && result !== undefined) {
    const checked = validate(result);

    return checked.valid ? { status: 'ready', value: checked.value } : { status: 'unreadable' };
  }

  if (rejection !== null && rejection !== undefined) {
    const problem = validateProblemV1(rejection);

    return problem.valid
      ? { status: 'rejected', problem: problem.value }
      : { status: 'unreadable' };
  }

  return { status: 'unreadable' };
}
