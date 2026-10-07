// The conformance suite of an addon's contributions (section 7 of the panel extension
// architecture), one helper per kind: each renders or runs a contribution the way the host does
// and throws ContributionContractBroken on what the host would refuse or isolate, so an addon's
// own tests catch it before cms:build and the panel do.

import type { ContributionMap } from '../addon';
import type { CheckContext, DataState, Issue, IssueSeverity, Tightening } from '../contributions';
import {
  ContributionContractBroken,
  type ContributionOptions,
  DEFAULT_MARKER,
  detail,
  frozenCopy,
  renderDecorator,
  renderPage,
  renderProvider,
  renderReplacement,
  renderSlot,
  renderStep,
  type Rendered,
  type RenderedDecorator,
  type RenderedStep,
  type SlotRegion,
} from './render';

/**
 * The budget of one run of a synchronous form check, in milliseconds, as the host keeps it.
 *
 * @stable
 */
export const CHECK_BUDGET_MILLISECONDS = 16;

const SEVERITY_RANK: Readonly<Record<IssueSeverity, number>> = {
  info: 0,
  warning: 1,
  acknowledge: 2,
  error: 3,
};

/**
 * What expectSlotContract() takes: the point's props, the region, and for a contribution with a
 * data query the value its result is, which the helper renders as loading, ready and failed.
 *
 * @stable
 */
export interface SlotContractOptions<
  C extends ContributionMap<C>,
  D = never,
> extends ContributionOptions<C> {
  readonly props?: object;
  readonly region?: SlotRegion;
  readonly data?: D;
}

/**
 * A slot contribution does what the host needs: its module's default export is of the region's
 * form, it renders with the point's props without throwing, and with a data query in each state of
 * its data, loading, ready and failed, because a page never fails when a contribution's data does.
 * Gives the last render, with the data ready, for further assertions.
 *
 * @stable
 */
export async function expectSlotContract<C extends ContributionMap<C>, D = never>(
  options: SlotContractOptions<C, D>,
): Promise<Rendered> {
  const states: (DataState<D> | undefined)[] =
    options.data === undefined
      ? [undefined]
      : [
          { status: 'loading' },
          { status: 'failed', code: 'query_over_budget' },
          { status: 'ready', value: options.data },
        ];
  let last: Rendered | undefined;

  for (const data of states) {
    if (last !== undefined) {
      await last.unmount();
    }

    last = await renderSlot<C, D>({
      addon: options.addon,
      id: options.id,
      ...(options.host === undefined ? {} : { host: options.host }),
      ...(options.props === undefined ? {} : { props: options.props }),
      ...(options.region === undefined ? {} : { region: options.region }),
      ...(data === undefined ? {} : { data }),
    });
  }

  if (last === undefined) {
    throw new ContributionContractBroken(options.id, 'it rendered in no state.');
  }

  return last;
}

/**
 * What expectPageContract() takes: for a page with a data query the value its result is.
 *
 * @stable
 */
export interface PageContractOptions<
  C extends ContributionMap<C>,
  D = never,
> extends ContributionOptions<C> {
  readonly data?: D;
}

/**
 * A page contribution renders without throwing, and with a data query in each state of its data.
 *
 * @stable
 */
export async function expectPageContract<C extends ContributionMap<C>, D = never>(
  options: PageContractOptions<C, D>,
): Promise<Rendered> {
  const states: (DataState<D> | undefined)[] =
    options.data === undefined
      ? [undefined]
      : [
          { status: 'loading' },
          { status: 'failed', code: 'query_over_budget' },
          { status: 'ready', value: options.data },
        ];
  let last: Rendered | undefined;

  for (const data of states) {
    if (last !== undefined) {
      await last.unmount();
    }

    last = await renderPage<C, D>({
      addon: options.addon,
      id: options.id,
      ...(options.host === undefined ? {} : { host: options.host }),
      ...(data === undefined ? {} : { data }),
    });
  }

  if (last === undefined) {
    throw new ContributionContractBroken(options.id, 'it rendered in no state.');
  }

  return last;
}

/**
 * What expectReplacementContract() takes: the props of the target.
 *
 * @stable
 */
export interface ReplacementContractOptions<
  C extends ContributionMap<C>,
> extends ContributionOptions<C> {
  readonly props?: object;
}

/**
 * A replacement renders in place of the default with exactly the target's props, without
 * throwing, and renders something: a replacement that renders nothing would hide the default's
 * purpose, where the host would show the default instead.
 *
 * @stable
 */
export async function expectReplacementContract<C extends ContributionMap<C>>(
  options: ReplacementContractOptions<C>,
): Promise<Rendered> {
  const result = await renderReplacement(options);

  if (result.container.childNodes.length === 0) {
    throw new ContributionContractBroken(
      options.id,
      'a replacement renders something in place of the default; this one rendered nothing.',
    );
  }

  return result;
}

/**
 * What expectProviderContract() takes: the point's props.
 *
 * @stable
 */
export interface ProviderContractOptions<
  C extends ContributionMap<C>,
> extends ContributionOptions<C> {
  readonly props?: object;
}

/**
 * A provider wraps the point's subtree: it renders without throwing and renders its children
 * exactly once.
 *
 * @stable
 */
export async function expectProviderContract<C extends ContributionMap<C>>(
  options: ProviderContractOptions<C>,
): Promise<Rendered> {
  const result = await renderProvider(options);
  const children = result.container.querySelectorAll(`[${DEFAULT_MARKER}="children"]`).length;

  if (children !== 1) {
    throw new ContributionContractBroken(
      options.id,
      `a provider renders its children once; this one rendered them ${String(children)} times.`,
    );
  }

  return result;
}

/**
 * What expectDecoratorKeepsDefault() takes: the target's props and the props the manifest lets
 * the decorator tighten.
 *
 * @stable
 */
export interface DecoratorContractOptions<
  C extends ContributionMap<C>,
> extends ContributionOptions<C> {
  readonly props?: object;
  readonly tightens?: readonly (keyof Tightening)[];
}

/**
 * A decorator keeps the default: it answers with a decoration, the default renders exactly once
 * with what it adds around it, and it tightens only the props its manifest declares, which the
 * host would otherwise pass over and report.
 *
 * @stable
 */
export async function expectDecoratorKeepsDefault<C extends ContributionMap<C>>(
  options: DecoratorContractOptions<C>,
): Promise<RenderedDecorator> {
  const result = await renderDecorator(options);
  const defaults = result.container.querySelectorAll(`[${DEFAULT_MARKER}="default"]`).length;

  if (defaults !== 1) {
    throw new ContributionContractBroken(
      options.id,
      `the default renders once with a decoration; it rendered ${String(defaults)} times.`,
    );
  }

  if (result.refusedTightenings.length > 0) {
    throw new ContributionContractBroken(
      options.id,
      `it tightens ${result.refusedTightenings.join(', ')}, which its manifest does not declare; declare each in DecoratorContribution::$tightens.`,
    );
  }

  return result;
}

/**
 * What expectFlowStepContract() takes: the draft and the paths the manifest lets the step patch.
 *
 * @stable
 */
export interface FlowStepContractOptions<
  C extends ContributionMap<C>,
> extends ContributionOptions<C> {
  readonly draft: object;
  readonly patches?: readonly string[];
}

/**
 * A flow step waits for the viewer: it renders with the draft without throwing, patches only the
 * paths its manifest declares, and neither ends nor cancels the flow while it renders. Gives the
 * render, whose step record shows what the step did after the test interacted with it.
 *
 * @stable
 */
export async function expectFlowStepContract<C extends ContributionMap<C>>(
  options: FlowStepContractOptions<C>,
): Promise<RenderedStep> {
  const result = await renderStep({ ...options, position: 'before_submit' });

  if (result.step.refusedPatches.length > 0) {
    throw new ContributionContractBroken(
      options.id,
      `it patched ${result.step.refusedPatches.join(', ')}, which its manifest does not declare; declare each in FlowStep::$patches.`,
    );
  }

  if (result.step.next > 0 || result.step.cancelled !== null) {
    throw new ContributionContractBroken(
      options.id,
      'it ended the flow while rendering; a step calls next() or cancel() only when the viewer acts.',
    );
  }

  return result;
}

/**
 * What expectFormCheckContract() takes: the documents to run the check on, the severity its
 * manifest declares, and the addon's namespace its issue codes are in.
 *
 * @stable
 */
export interface FormCheckContractOptions<
  C extends ContributionMap<C>,
  D,
> extends ContributionOptions<C> {
  readonly documents: readonly D[];
  readonly severity: IssueSeverity;
  readonly namespace: string;
  readonly locale?: string;
  /** The viewer the check's context names; none unless given. */
  readonly viewer?: string | null;
}

/**
 * A form check is a pure, synchronous function of the document to issues: on each document it
 * answers within the host's budget with a list of issues, each with a path, a code in the addon's
 * namespace, a message key and a severity no heavier than its manifest declares, and answers the
 * same twice. Gives the issues per document.
 *
 * @stable
 */
export function expectFormCheckContract<C extends ContributionMap<C>, D>(
  options: FormCheckContractOptions<C, D>,
): readonly (readonly Issue[])[] {
  const implementations = options.addon.contributions as unknown as Readonly<
    Record<string, unknown>
  >;
  const check = implementations[options.id];

  if (typeof check !== 'function') {
    throw new ContributionContractBroken(options.id, 'a form check is a function of the document.');
  }

  const context: CheckContext = { locale: options.locale ?? 'en', viewer: options.viewer ?? null };
  const found: (readonly Issue[])[] = [];

  for (const document of options.documents) {
    const frozen = frozenCopy(document);
    const started = performance.now();
    let first: unknown;

    try {
      first = (check as (document: unknown, context: CheckContext) => unknown)(frozen, context);
    } catch (failure) {
      throw new ContributionContractBroken(options.id, `it threw: ${detail(failure)}`);
    }

    const took = performance.now() - started;

    if (took > CHECK_BUDGET_MILLISECONDS) {
      throw new ContributionContractBroken(
        options.id,
        `it took ${took.toFixed(1)} ms, over the budget of ${String(CHECK_BUDGET_MILLISECONDS)} ms, so the host would skip it.`,
      );
    }

    const issues = acceptedIssues(options.id, first, options.namespace, options.severity);
    const second = (check as (document: unknown, context: CheckContext) => unknown)(
      frozen,
      context,
    );

    if (JSON.stringify(second) !== JSON.stringify(first)) {
      throw new ContributionContractBroken(
        options.id,
        'it answered differently on the same document twice; a check is a pure function.',
      );
    }

    found.push(issues);
  }

  return found;
}

/** The issues a check answered, as the host accepts them, or a contract failure. */
function acceptedIssues(
  id: string,
  answer: unknown,
  namespace: string,
  severity: IssueSeverity,
): readonly Issue[] {
  if (isThenable(answer)) {
    throw new ContributionContractBroken(
      id,
      'it answered with a promise; a synchronous check answers with issues, and an asynchronous one is an AsyncFormCheck of the experimental API.',
    );
  }

  if (!Array.isArray(answer)) {
    throw new ContributionContractBroken(id, 'it answered with something that is not a list.');
  }

  const issues: Issue[] = [];

  for (const issue of answer as readonly unknown[]) {
    if (!isIssue(issue)) {
      throw new ContributionContractBroken(
        id,
        'an issue has a path, a code, a message key and a severity of info, warning, acknowledge or error.',
      );
    }

    if (!issue.code.startsWith(`${namespace}.`)) {
      throw new ContributionContractBroken(
        id,
        `the issue code ${issue.code} is outside the addon's namespace ${namespace}.`,
      );
    }

    if (SEVERITY_RANK[issue.severity] > SEVERITY_RANK[severity]) {
      throw new ContributionContractBroken(
        id,
        `the issue ${issue.code} is ${issue.severity}, heavier than the ${severity} its manifest declares, so the host would weigh it down.`,
      );
    }

    issues.push(issue);
  }

  return issues;
}

function isThenable(value: unknown): boolean {
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

/**
 * What expectObserverContract() takes: the events to call the observer with.
 *
 * @stable
 */
export interface ObserverContractOptions<
  C extends ContributionMap<C>,
  E,
> extends ContributionOptions<C> {
  readonly events: readonly E[];
}

/**
 * An observer is a function of the event that does not throw, because the host would catch and
 * report it, and gives nothing back, because it cannot affect the flow.
 *
 * @stable
 */
export function expectObserverContract<C extends ContributionMap<C>, E>(
  options: ObserverContractOptions<C, E>,
): void {
  const implementations = options.addon.contributions as unknown as Readonly<
    Record<string, unknown>
  >;
  const observer = implementations[options.id];

  if (typeof observer !== 'function') {
    throw new ContributionContractBroken(options.id, 'an observer is a function of the event.');
  }

  for (const event of options.events) {
    let answer: unknown;

    try {
      answer = (observer as (event: unknown) => unknown)(frozenCopy(event));
    } catch (failure) {
      throw new ContributionContractBroken(options.id, `it threw: ${detail(failure)}`);
    }

    if (answer !== undefined) {
      throw new ContributionContractBroken(
        options.id,
        'it answered with a value; an observer gives nothing back, because it cannot affect the flow.',
      );
    }
  }
}
