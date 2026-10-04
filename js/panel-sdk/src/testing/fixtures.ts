// The answers a fake host gives a command (section 7 of the panel extension architecture): a
// receipt of receipt.v1.json and problem details of problem.v1.json, each in the form the panel's
// generated codecs write and the generated validators accept, so a contribution's test sees what
// the panel would hand it.

import type { CommandAnswer } from '../host';
import type { CatalogErrorV1, ErrorCode, ProblemV1 } from '../generated/protocol/ProblemV1';
import type { ReceiptV1 } from '../generated/protocol/ReceiptV1';

/** The changeset id of a committed receipt unless a test gives another: a UUIDv7. */
const CHANGESET_ID = '01920000-0000-7000-8000-000000000001';

/**
 * A receipt of a command that committed, at wait level commit, with no projection pending.
 *
 * @stable
 */
export function committedReceipt(overrides: Partial<ReceiptV1> = {}): CommandAnswer {
  return {
    receipt: {
      changeset_id: CHANGESET_ID,
      consistency_token: null,
      outcome: 'committed',
      position: '1000',
      projections: [],
      retention_class: 'standard',
      wait_level: 'commit',
      ...overrides,
    },
    problem: null,
  };
}

/**
 * A receipt of a dry run: the plan was computed and nothing committed.
 *
 * @stable
 */
export function dryRunReceipt(overrides: Partial<ReceiptV1> = {}): CommandAnswer {
  return {
    receipt: {
      changeset_id: null,
      consistency_token: null,
      outcome: 'dry_run',
      position: null,
      projections: [],
      retention_class: 'standard',
      wait_level: 'commit',
      ...overrides,
    },
    problem: null,
  };
}

/**
 * One reason a write was rejected, at the input it is about.
 *
 * @stable
 */
export interface RejectionError {
  readonly code: ErrorCode;
  /** The path of the input, such as `fields.title`, or null for the command as a whole. */
  readonly field?: string | null;
  readonly detail?: string;
}

/**
 * The answer of a command the kernel rejected: a rejected receipt and problem details with the
 * code's catalog entry, and the errors at their paths, `validation_failed` at `fields.title` for
 * instance. The title, type and status are the catalog's for the common codes, and a plain form
 * for any other.
 *
 * @stable
 */
export function rejectedProblem(
  code: ErrorCode,
  errors: readonly RejectionError[] = [],
  overrides: Partial<ProblemV1> = {},
): CommandAnswer {
  const problem: ProblemV1 = {
    code,
    detail: `The command was rejected with ${code}.`,
    errors: errors.map((error): CatalogErrorV1 => ({
      code: error.code,
      detail: error.detail ?? `The input was refused with ${error.code}.`,
      field: error.field ?? null,
    })),
    instance: null,
    retryable: RETRYABLE.includes(code),
    status: STATUS[code] ?? 422,
    title: `The command was rejected with ${code}.`,
    type: `docs/reference/errors.md#${code}`,
    ...overrides,
  };

  return {
    receipt: {
      changeset_id: null,
      consistency_token: null,
      outcome: 'rejected',
      position: null,
      projections: [],
      retention_class: 'standard',
      wait_level: 'commit',
    },
    problem,
  };
}

/** The HTTP status of the codes a contribution meets most, as the catalog gives them. */
const STATUS: Partial<Record<ErrorCode, number>> = {
  unauthorized: 403,
  validation_failed: 422,
  validation_hook_failed: 422,
  version_conflict: 409,
  idempotency_conflict: 409,
  idempotency_in_flight: 409,
};

/** The codes of those a retry makes sense for. */
const RETRYABLE: readonly ErrorCode[] = ['idempotency_in_flight'];
