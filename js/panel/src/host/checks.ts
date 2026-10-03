// The check runner of a command form (section 3.7 of the panel extension architecture): each check
// is a pure function of the command document to issues. The checks run in their render order on a
// frozen copy of the document, within a budget of 16 ms each; a check that throws or overruns is
// skipped for the rest of the session and reported with its addon. A check that answers with a
// promise, an asynchronous check (experimental), gets 300 ms, and is cancelled through the signal
// of its context on the next edit. Checks only add issues: concatenated, sorted by path, then
// addon. An issue's code must be in its addon's namespace, and it weighs at most what the check's
// manifest declares, so only a check that mirrors a hook of its addon blocks the submit. An issue
// of severity acknowledge holds the submit until the viewer ticks it.

import type { CheckContext, Issue, IssueSeverity } from '@cboxdk/cms-panel/extend';

import type { HostReport } from './reports';

/** The budget of one run of a check, in milliseconds. */
export const CHECK_BUDGET_MILLISECONDS = 16;

/** The budget of one run of an asynchronous check, in milliseconds. */
export const ASYNC_CHECK_BUDGET_MILLISECONDS = 300;

const SEVERITY_RANK: Readonly<Record<IssueSeverity, number>> = {
  info: 0,
  warning: 1,
  acknowledge: 2,
  error: 3,
};

/** What an asynchronous check's run settles with when it ran out of time. */
const OVERRUN = Symbol('overrun');

/** What a check's context holds: the panel's locale, and the signal of the next edit. */
export type RunContext = CheckContext & { readonly signal: AbortSignal };

/** A check of a form, with whose it is and the most its issues weigh. */
export interface FormCheckEntry {
  readonly addon: string;
  readonly contribution: string;
  readonly severity: IssueSeverity;
  readonly check: (document: object, context: RunContext) => unknown;
}

/** An issue with the addon and check that found it. */
export interface FoundIssue extends Issue {
  readonly addon: string;
  readonly contribution: string;
}

/** What one run of the checks found. */
export interface CheckRun {
  /** Every issue, sorted by path, then addon. */
  readonly issues: readonly FoundIssue[];
  /** The issues that block the submit: errors. */
  readonly blocking: readonly FoundIssue[];
  /** The issues the viewer must acknowledge before the submit. */
  readonly acknowledge: readonly FoundIssue[];
}

/** What a run gives: the issues of the synchronous checks now, and of every check once the asynchronous ones answered. */
export interface CheckRuns {
  readonly now: CheckRun;
  /** Every check's issues; the issues of a run cancelled by an edit are those of now. */
  readonly settled: Promise<CheckRun>;
}

/** What the runner needs besides the checks. */
export interface CheckEnvironment {
  readonly context: CheckContext;
  /** The checks skipped for the session, by contribution id; the runner adds to it. */
  readonly skipped: Set<string>;
  readonly report: (report: Omit<HostReport, 'point'>) => void;
  /** A clock in milliseconds; performance.now() unless a test gives another. */
  readonly now?: () => number;
}

/** A deep copy of a JSON document, frozen at every level, so a check cannot change what it reads. */
export function frozenCopy<T>(document: T): T {
  return deepFreeze(structuredClone(document));
}

function deepFreeze<T>(value: T): T {
  if (typeof value === 'object' && value !== null) {
    for (const member of Object.values(value)) {
      deepFreeze(member);
    }

    Object.freeze(value);
  }

  return value;
}

/** The key of an issue the viewer acknowledges: the same issue across runs has the same key. */
export function issueKey(issue: FoundIssue): string {
  return `${issue.contribution}\n${issue.code}\n${issue.path}`;
}

/**
 * Whether the form may be submitted after a run: no blocking issue, and every issue that asks for
 * an acknowledgement acknowledged.
 */
export function maySubmit(run: CheckRun, acknowledged: ReadonlySet<string>): boolean {
  return (
    run.blocking.length === 0 && run.acknowledge.every((issue) => acknowledged.has(issueKey(issue)))
  );
}

/**
 * Runs the checks on the document. `edits` aborts on the next edit, which cancels the
 * asynchronous checks of this run.
 */
export function runChecks(
  checks: readonly FormCheckEntry[],
  document: object,
  environment: CheckEnvironment,
  edits: AbortSignal = new AbortController().signal,
): CheckRuns {
  const now = environment.now ?? (() => performance.now());
  const frozen = frozenCopy(document);
  const found: FoundIssue[] = [];
  const pending: Promise<FoundIssue[]>[] = [];

  for (const entry of checks) {
    if (environment.skipped.has(entry.contribution)) {
      continue;
    }

    const budget = new AbortController();
    const context: RunContext = {
      ...environment.context,
      signal: AbortSignal.any([edits, budget.signal]),
    };
    const started = now();
    let answer: unknown;

    try {
      answer = entry.check(frozen, context);
    } catch {
      skip(entry, 'panel_check_failed', environment);

      continue;
    }

    if (now() - started > CHECK_BUDGET_MILLISECONDS) {
      budget.abort();
      skip(entry, 'panel_check_over_budget', environment);

      continue;
    }

    if (isThenable(answer)) {
      pending.push(settle(entry, answer, budget, edits, environment));

      continue;
    }

    found.push(...accepted(entry, answer, environment));
  }

  const first = summary(found);

  return {
    now: first,
    settled:
      pending.length === 0
        ? Promise.resolve(first)
        : Promise.all(pending).then((later) =>
            edits.aborted ? first : summary([...found, ...later.flat()]),
          ),
  };
}

async function settle(
  entry: FormCheckEntry,
  answer: PromiseLike<unknown>,
  budget: AbortController,
  edits: AbortSignal,
  environment: CheckEnvironment,
): Promise<FoundIssue[]> {
  let timer: ReturnType<typeof setTimeout> | undefined;
  const overrun = new Promise<typeof OVERRUN>((resolve) => {
    timer = setTimeout(() => {
      budget.abort();
      resolve(OVERRUN);
    }, ASYNC_CHECK_BUDGET_MILLISECONDS);
  });

  try {
    const outcome = await Promise.race([answer, overrun]);

    if (edits.aborted) {
      return [];
    }

    if (outcome === OVERRUN) {
      skip(entry, 'panel_check_over_budget', environment);

      return [];
    }

    return accepted(entry, outcome, environment);
  } catch {
    if (!edits.aborted) {
      skip(entry, 'panel_check_failed', environment);
    }

    return [];
  } finally {
    clearTimeout(timer);
  }
}

function skip(
  entry: FormCheckEntry,
  code: 'panel_check_failed' | 'panel_check_over_budget',
  environment: CheckEnvironment,
): void {
  environment.skipped.add(entry.contribution);
  environment.report({ code, addon: entry.addon, contribution: entry.contribution });
}

function summary(found: readonly FoundIssue[]): CheckRun {
  const issues = [...found].sort(
    (a, b) =>
      compare(a.path, b.path) ||
      compare(a.addon, b.addon) ||
      compare(a.contribution, b.contribution),
  );

  return {
    issues,
    blocking: issues.filter((issue) => issue.severity === 'error'),
    acknowledge: issues.filter((issue) => issue.severity === 'acknowledge'),
  };
}

function accepted(
  entry: FormCheckEntry,
  issues: unknown,
  environment: CheckEnvironment,
): FoundIssue[] {
  if (!Array.isArray(issues)) {
    environment.report({
      code: 'panel_check_issue_refused',
      addon: entry.addon,
      contribution: entry.contribution,
    });

    return [];
  }

  const found: FoundIssue[] = [];

  for (const issue of issues as readonly unknown[]) {
    if (!isIssue(issue) || !issue.code.startsWith(`${entry.addon}.`)) {
      environment.report({
        code: 'panel_check_issue_refused',
        addon: entry.addon,
        contribution: entry.contribution,
      });

      continue;
    }

    const severity =
      SEVERITY_RANK[issue.severity] > SEVERITY_RANK[entry.severity]
        ? entry.severity
        : issue.severity;
    found.push({ ...issue, severity, addon: entry.addon, contribution: entry.contribution });
  }

  return found;
}

function isThenable(value: unknown): value is PromiseLike<unknown> {
  return (
    typeof value === 'object' &&
    value !== null &&
    'then' in value &&
    typeof (value as { readonly then: unknown }).then === 'function'
  );
}

function isIssue(value: unknown): value is Issue {
  if (typeof value !== 'object' || value === null) {
    return false;
  }

  const issue = value as Readonly<Record<string, unknown>>;

  return (
    typeof issue.path === 'string' &&
    typeof issue.code === 'string' &&
    typeof issue.message === 'string' &&
    typeof issue.severity === 'string' &&
    Object.hasOwn(SEVERITY_RANK, issue.severity)
  );
}

function compare(a: string, b: string): number {
  if (a === b) {
    return 0;
  }

  return a < b ? -1 : 1;
}
