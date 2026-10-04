// renderPoint() (section 7 of the panel extension architecture): renders one contribution of an
// addon's registration the way the panel's host renders it on its point, with the point's props
// frozen, its data, a fake host, and for each kind what the host makes of what the contribution
// gives: a toolbar item, a column, a tab, a decoration composed onto the default, a flow step with
// its controls. It needs a DOM, so a test file asks Vitest for jsdom.

import { act, Fragment, type ComponentType, type ReactNode } from 'react';
import { createRoot, type Root } from 'react-dom/client';

import type { ContributionMap, PanelAddon } from '../addon';
import type {
  BadgeDescriptor,
  DataState,
  IssuedCommands,
  StepProps,
  Tightening,
  ToolbarItemDescriptor,
} from '../contributions';
import type { CommandAnswer, TranslationKey } from '../host';
import type { JsonValue } from '../json';
import { dryRunReceipt } from './fixtures';
import { createFakeHost, PanelHostProvider, type FakeHost, type FakeHostOptions } from './host';

/**
 * Thrown when a contribution does not do what its kind's contract says, with what it did
 * instead; a test framework shows the message.
 *
 * @stable
 */
export class ContributionContractBroken extends Error {
  public constructor(id: string, message: string) {
    super(`The contribution ${id} breaks its contract: ${message}`);
    this.name = 'ContributionContractBroken';
  }
}

/**
 * The region a slot is in: free markup, or a structured region that takes a descriptor.
 *
 * @stable
 */
export type SlotRegion = 'sections' | 'aside' | 'toolbar' | 'columns' | 'tabs';

/**
 * A rendered contribution: its markup, the host it reached, and how to take it down.
 *
 * @stable
 */
export interface Rendered {
  /** The element the contribution rendered into, attached to the document until unmount(). */
  readonly container: HTMLElement;
  readonly host: FakeHost;
  /** Renders the same contribution again, after a change the test made. */
  readonly rerender: () => Promise<void>;
  readonly unmount: () => Promise<void>;
}

/**
 * A rendered slot contribution: in a toolbar region the item the contribution gave, in a columns
 * region the column's header, in a tabs region the tab's label, each before the host translates it.
 *
 * @stable
 */
export interface RenderedSlot extends Rendered {
  readonly item: ToolbarItemDescriptor | null;
  readonly header: TranslationKey | null;
  readonly label: TranslationKey | null;
}

/**
 * The props of a default after a decorator tightened them, as the host hands them to the page.
 *
 * @stable
 */
export interface TightenedProps {
  readonly disabled: boolean;
  /** The disabled reasons, as texts of the addon's catalogue. */
  readonly disabledReasons: readonly string[];
  readonly descriptions: readonly string[];
  readonly tone: 'neutral' | 'info' | 'warning' | 'danger';
}

/**
 * A rendered decorator: what it composed onto the default, which renders once.
 *
 * @stable
 */
export interface RenderedDecorator extends Rendered {
  readonly tightened: TightenedProps;
  readonly badges: readonly BadgeDescriptor[];
  /** The tightenings the decorator gave that its manifest does not declare, which the host passes over. */
  readonly refusedTightenings: readonly string[];
}

/**
 * What a rendered flow step did with its controls.
 *
 * @stable
 */
export interface StepRecord {
  /** The draft as the step's patches left it. */
  readonly draft: object;
  readonly patches: readonly { readonly path: string; readonly value: JsonValue }[];
  /** The paths the step patched that its manifest does not declare, which the host refuses. */
  readonly refusedPatches: readonly string[];
  readonly next: number;
  readonly cancelled: TranslationKey | null;
  readonly dryRuns: number;
}

/**
 * A rendered flow step.
 *
 * @stable
 */
export interface RenderedStep extends Rendered {
  readonly step: StepRecord;
}

/**
 * What every renderer and conformance helper takes: the registration, the contribution's id and
 * the host, or what to build one from.
 *
 * @stable
 */
export interface ContributionOptions<C extends ContributionMap<C>> {
  readonly addon: PanelAddon<C>;
  readonly id: keyof C & string;
  /** The host, or what to build one from. */
  readonly host?: FakeHost | FakeHostOptions;
}

/**
 * What renderSlot() takes: the point's props, the region, and the data query's result when the
 * contribution has one.
 *
 * @stable
 */
export interface RenderSlotOptions<
  C extends ContributionMap<C>,
  D = never,
> extends ContributionOptions<C> {
  readonly props?: object;
  readonly region?: SlotRegion;
  readonly data?: DataState<D>;
}

/**
 * What renderPage() takes: the data query's result when the page has one.
 *
 * @stable
 */
export interface RenderPageOptions<
  C extends ContributionMap<C>,
  D = never,
> extends ContributionOptions<C> {
  readonly data?: DataState<D>;
}

/**
 * What renderReplacement() takes: the props of the target the contribution replaces.
 *
 * @stable
 */
export interface RenderReplacementOptions<
  C extends ContributionMap<C>,
> extends ContributionOptions<C> {
  readonly props?: object;
}

/**
 * What renderProvider() takes: the point's props and what the provider wraps.
 *
 * @stable
 */
export interface RenderProviderOptions<
  C extends ContributionMap<C>,
> extends ContributionOptions<C> {
  readonly props?: object;
  readonly children?: ReactNode;
}

/**
 * What renderDecorator() takes: the target's props, the default it decorates and its tone, and
 * the props the manifest lets it tighten.
 *
 * @stable
 */
export interface RenderDecoratorOptions<
  C extends ContributionMap<C>,
> extends ContributionOptions<C> {
  readonly props?: object;
  readonly defaultContent?: ReactNode;
  readonly tone?: TightenedProps['tone'];
  readonly tightens?: readonly (keyof Tightening)[];
}

/**
 * What renderStep() takes: the draft, the paths the manifest lets the step patch, where the step
 * runs, the receipt for a step after it, and what a dry run answers.
 *
 * @stable
 */
export interface RenderStepOptions<C extends ContributionMap<C>> extends ContributionOptions<C> {
  readonly draft: object;
  readonly patches?: readonly string[];
  readonly position?: 'before_submit' | 'after_receipt';
  readonly receipt?: CommandAnswer;
  readonly dryRun?: () => CommandAnswer | Promise<CommandAnswer>;
}

/** The marker the rendered default of a decorator and the children of a provider carry. */
export const DEFAULT_MARKER = 'data-cms-testing-default';

/** A deep copy of a value, frozen at every level, as the host hands a contribution its props. */
export function frozenCopy<T>(value: T): T {
  return deepFreeze(structuredClone(value));
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

/** The segments of a path as FieldPath::toString() writes it, such as `fields.ext.a.b[0]`. */
function pathSegments(path: string): readonly (string | number)[] {
  const segments: (string | number)[] = [];

  for (const match of path.matchAll(/([^.[\]]+)|\[(\d+)\]/g)) {
    segments.push(match[2] === undefined ? (match[1] ?? '') : Number(match[2]));
  }

  return segments;
}

/** The document with the value at the path, made where the path's objects do not exist yet. */
export function patched(document: object, path: string, value: JsonValue): object {
  const segments = pathSegments(path);
  const copy = structuredClone(document) as Record<string, unknown>;
  let parent: Record<string | number, unknown> = copy;

  segments.forEach((segment, index) => {
    if (index === segments.length - 1) {
      parent[segment] = structuredClone(value);

      return;
    }

    const child = parent[segment];
    const next =
      typeof child === 'object' && child !== null
        ? child
        : typeof segments[index + 1] === 'number'
          ? []
          : {};
    parent[segment] = next;
    parent = next as Record<string | number, unknown>;
  });

  return copy;
}

/** The host given, or one built from the options. */
function hostOf(host: FakeHost | FakeHostOptions | undefined): FakeHost {
  return host !== undefined && 'record' in host ? host : createFakeHost(host);
}

/** The implementation the registration holds for the id, which definePanelAddon() checked is a function. */
function implementationOf<C extends ContributionMap<C>>(
  addon: PanelAddon<C>,
  id: keyof C & string,
): unknown {
  const implementations = addon.contributions as unknown as Readonly<Record<string, unknown>>;
  const implementation = implementations[id];

  if (implementation === undefined) {
    throw new ContributionContractBroken(
      id,
      `the registration has no such contribution; it registers ${addon.ids.join(', ') || 'none'}.`,
    );
  }

  return implementation;
}

/** The default export of the module a lazy contribution imports. */
async function moduleOf<C extends ContributionMap<C>>(
  addon: PanelAddon<C>,
  id: keyof C & string,
): Promise<unknown> {
  const implementation = implementationOf(addon, id);

  if (typeof implementation !== 'function') {
    throw new ContributionContractBroken(id, 'it is not a function that imports its module.');
  }

  let module: unknown;

  try {
    module = await (implementation as () => unknown)();
  } catch (failure) {
    throw new ContributionContractBroken(id, `its module failed to import: ${detail(failure)}`);
  }

  if (typeof module !== 'object' || module === null || !('default' in module)) {
    throw new ContributionContractBroken(
      id,
      "its import gives no module with a default export; register it as () => import('./Module').",
    );
  }

  return module.default;
}

/** A module's default export as a component, or a contract failure. */
function componentOf<P extends object = object>(
  id: string,
  value: unknown,
  what: string,
): ComponentType<P> {
  if (typeof value !== 'function') {
    throw new ContributionContractBroken(id, `${what} is not a component.`);
  }

  return value as ComponentType<P>;
}

/** The name and message of what was thrown. */
export function detail(failure: unknown): string {
  if (failure instanceof Error) {
    return failure.message === '' ? failure.name : `${failure.name}: ${failure.message}`;
  }

  return typeof failure === 'string' ? failure : 'a value that is not an Error';
}

/** A root in a container attached to the document, with the host provided. */
async function mount(
  id: string,
  host: FakeHost,
  render: () => ReactNode,
): Promise<{ readonly container: HTMLElement; readonly root: Root }> {
  if (typeof document === 'undefined') {
    throw new TypeError(
      'renderPoint needs a DOM: put `// @vitest-environment jsdom` at the top of the test file.',
    );
  }

  Reflect.set(globalThis, 'IS_REACT_ACT_ENVIRONMENT', true);
  const container = document.createElement('div');
  document.body.append(container);
  const failures: unknown[] = [];
  const root = createRoot(container, {
    onUncaughtError: (failure) => {
      failures.push(failure);
    },
  });

  await paint(id, root, host, render, failures);

  return { container, root };
}

/**
 * Renders the node in the root, inside the host's provider, waits for React to settle, and
 * throws ContributionContractBroken when the contribution threw while rendering.
 */
async function paint(
  id: string,
  root: Root,
  host: FakeHost,
  render: () => ReactNode,
  failures: unknown[],
): Promise<void> {
  try {
    await act(async () => {
      root.render(<PanelHostProvider host={host}>{render()}</PanelHostProvider>);
      await Promise.resolve();
    });
  } catch (failure) {
    failures.push(failure);
  }

  const failure = failures.shift();

  if (failure !== undefined) {
    failures.length = 0;

    throw new ContributionContractBroken(id, `it threw while rendering: ${detail(failure)}`);
  }
}

/** What every rendered kind shares. */
async function rendered(id: string, host: FakeHost, render: () => ReactNode): Promise<Rendered> {
  const { container, root } = await mount(id, host, render);

  return {
    container,
    host,
    rerender: () => paint(id, root, host, render, []),
    unmount: async () => {
      await act(async () => {
        root.unmount();
        await Promise.resolve();
      });
      container.remove();
    },
  };
}

/**
 * Renders a slot contribution with the point's props and its data, as the host renders it in its
 * region: free markup in sections and the aside, and in a toolbar, columns or tabs region what the
 * host makes of the descriptor it gave, with the item, header or label beside the markup.
 *
 * @stable
 */
export async function renderSlot<C extends ContributionMap<C>, D = never>(
  options: RenderSlotOptions<C, D>,
): Promise<RenderedSlot> {
  const host = hostOf(options.host);
  const props = frozenCopy(options.props ?? {});
  const region = options.region ?? 'sections';
  const slotProps =
    options.data === undefined ? { props } : { props, data: frozenCopy(options.data) };
  const exported = await moduleOf(options.addon, options.id);

  if (region === 'toolbar') {
    if (typeof exported !== 'function') {
      throw new ContributionContractBroken(
        options.id,
        "in a toolbar region its module's default export is a function of the point's props to an item.",
      );
    }

    const item = (exported as (props: object) => unknown)(props);
    const descriptor = toolbarItem(options.id, item);
    const base = await rendered(options.id, host, () => null);

    return { ...base, item: descriptor, header: null, label: null };
  }

  if (region === 'columns') {
    const column = exported as { readonly header?: unknown; readonly cell?: unknown } | null;

    if (
      typeof column !== 'object' ||
      column === null ||
      typeof column.header !== 'string' ||
      typeof column.cell !== 'function'
    ) {
      throw new ContributionContractBroken(
        options.id,
        "in a columns region its module's default export is a column: a header, a translation key, and a cell component.",
      );
    }

    const Cell = componentOf<{ readonly props: object }>(
      options.id,
      column.cell,
      "the column's cell",
    );
    const base = await rendered(options.id, host, () => <Cell props={props} />);

    return { ...base, item: null, header: column.header, label: null };
  }

  if (region === 'tabs') {
    const tab = exported as { readonly label?: unknown; readonly component?: unknown } | null;

    if (
      typeof tab !== 'object' ||
      tab === null ||
      typeof tab.label !== 'string' ||
      typeof tab.component !== 'function'
    ) {
      throw new ContributionContractBroken(
        options.id,
        "in a tabs region its module's default export is a tab: a label, a translation key, and a panel component.",
      );
    }

    const Panel = componentOf(options.id, tab.component, "the tab's component");
    const base = await rendered(options.id, host, () => <Panel {...slotProps} />);

    return { ...base, item: null, header: null, label: tab.label };
  }

  const Component = componentOf(options.id, exported, "its module's default export");
  const base = await rendered(options.id, host, () => <Component {...slotProps} />);

  return { ...base, item: null, header: null, label: null };
}

/** A toolbar item as the host takes it, or a contract failure. */
function toolbarItem(id: string, item: unknown): ToolbarItemDescriptor | null {
  if (item === null) {
    return null;
  }

  const descriptor = item as {
    readonly kind?: unknown;
    readonly label?: unknown;
    readonly onPress?: unknown;
  } | null;

  if (
    typeof descriptor !== 'object' ||
    descriptor === null ||
    typeof descriptor.label !== 'string'
  ) {
    throw new ContributionContractBroken(
      id,
      'a toolbar item is null, a badge or a button, each with a label that is a translation key.',
    );
  }

  if (descriptor.kind === 'badge') {
    return item as ToolbarItemDescriptor;
  }

  if (descriptor.kind === 'button' && typeof descriptor.onPress === 'function') {
    return item as ToolbarItemDescriptor;
  }

  throw new ContributionContractBroken(
    id,
    'a toolbar item is a badge, or a button with an onPress function.',
  );
}

/**
 * Renders a page contribution with its data query's result, the only props a page has.
 *
 * @stable
 */
export async function renderPage<C extends ContributionMap<C>, D = never>(
  options: RenderPageOptions<C, D>,
): Promise<Rendered> {
  const host = hostOf(options.host);
  const Page = componentOf(
    options.id,
    await moduleOf(options.addon, options.id),
    "its module's default export",
  );
  const props = options.data === undefined ? {} : { data: frozenCopy(options.data) };

  return rendered(options.id, host, () => <Page {...props} />);
}

/**
 * Renders a replacement with exactly the props of the target it replaces.
 *
 * @stable
 */
export async function renderReplacement<C extends ContributionMap<C>>(
  options: RenderReplacementOptions<C>,
): Promise<Rendered> {
  const host = hostOf(options.host);
  const Replacement = componentOf(
    options.id,
    await moduleOf(options.addon, options.id),
    "its module's default export",
  );
  const props = frozenCopy(options.props ?? {});

  return rendered(options.id, host, () => <Replacement {...props} />);
}

/**
 * Renders a provider around the children given, or around a marker element when none are.
 *
 * @stable
 */
export async function renderProvider<C extends ContributionMap<C>>(
  options: RenderProviderOptions<C>,
): Promise<Rendered> {
  const host = hostOf(options.host);
  const Provider = componentOf<{ readonly props: object; readonly children: ReactNode }>(
    options.id,
    await moduleOf(options.addon, options.id),
    "its module's default export",
  );
  const props = frozenCopy(options.props ?? {});
  const children = options.children ?? <span {...{ [DEFAULT_MARKER]: 'children' }} />;

  return rendered(options.id, host, () => <Provider props={props}>{children}</Provider>);
}

function isBadge(value: unknown): value is BadgeDescriptor {
  if (typeof value !== 'object' || value === null) {
    return false;
  }

  const badge = value as Readonly<Record<string, unknown>>;

  return (
    typeof badge.label === 'string' &&
    typeof badge.tone === 'string' &&
    ['neutral', 'info', 'warning', 'danger'].includes(badge.tone)
  );
}

const TONE_RANK: Readonly<Record<TightenedProps['tone'], number>> = {
  neutral: 0,
  info: 0,
  warning: 1,
  danger: 2,
};

/**
 * Renders a decorator onto a default, as the host composes it: the decoration's content before
 * and after the default, which renders exactly once, its badge, and the props it tightens, only
 * those its manifest declares.
 *
 * @stable
 */
export async function renderDecorator<C extends ContributionMap<C>>(
  options: RenderDecoratorOptions<C>,
): Promise<RenderedDecorator> {
  const host = hostOf(options.host);
  const decorator = implementationOf(options.addon, options.id);

  if (typeof decorator !== 'function') {
    throw new ContributionContractBroken(
      options.id,
      "a decorator is a function of the target's props.",
    );
  }

  const props = frozenCopy(options.props ?? {});
  let decoration: unknown;

  try {
    decoration = (decorator as (props: object) => unknown)(props);
  } catch (failure) {
    throw new ContributionContractBroken(options.id, `it threw: ${detail(failure)}`);
  }

  if (typeof decoration !== 'object' || decoration === null) {
    throw new ContributionContractBroken(options.id, 'a decorator answers with an object.');
  }

  const given = decoration as {
    readonly before?: ReactNode;
    readonly after?: ReactNode;
    readonly badge?: unknown;
    readonly tighten?: unknown;
  };
  const declared: readonly string[] = options.tightens ?? [];
  const disabledReasons: string[] = [];
  const descriptions: string[] = [];
  const refusedTightenings: string[] = [];
  let tone = options.tone ?? 'neutral';
  const tighten: Readonly<Record<string, unknown>> =
    typeof given.tighten === 'object' && given.tighten !== null
      ? (given.tighten as Readonly<Record<string, unknown>>)
      : {};

  for (const [key, value] of Object.entries(tighten)) {
    if (value === undefined) {
      continue;
    }

    if (!declared.includes(key)) {
      refusedTightenings.push(key);

      continue;
    }

    if (key === 'disabled_reason' && typeof value === 'string') {
      disabledReasons.push(host.t(value));
    } else if (key === 'description' && typeof value === 'string') {
      descriptions.push(host.t(value));
    } else if (key === 'tone_towards_danger' && (value === 'warning' || value === 'danger')) {
      tone = TONE_RANK[value] > TONE_RANK[tone] ? value : tone;
    } else {
      refusedTightenings.push(key);
    }
  }

  const badges: BadgeDescriptor[] = [];

  if (given.badge !== undefined) {
    if (!isBadge(given.badge)) {
      throw new ContributionContractBroken(
        options.id,
        'a badge has a tone of neutral, info, warning or danger and a label that is a translation key.',
      );
    }

    badges.push(given.badge);
  }

  const defaultContent = options.defaultContent ?? <span {...{ [DEFAULT_MARKER]: 'default' }} />;
  const base = await rendered(options.id, host, () => (
    <>
      {given.before ?? null}
      {badges.map((badge) => (
        <Fragment key={badge.label}>{host.t(badge.label)}</Fragment>
      ))}
      {defaultContent}
      {given.after ?? null}
    </>
  ));

  return {
    ...base,
    tightened: { disabled: disabledReasons.length > 0, disabledReasons, descriptions, tone },
    badges,
    refusedTightenings,
  };
}

/**
 * Renders a flow step with the controls the host gives it, each recorded: patch() for the paths
 * its manifest declares, refused and recorded for any other, issue() through the host's commands,
 * dryRun(), next() and cancel().
 *
 * @stable
 */
export async function renderStep<C extends ContributionMap<C>>(
  options: RenderStepOptions<C>,
): Promise<RenderedStep> {
  const host = hostOf(options.host);
  const Step = componentOf(
    options.id,
    await moduleOf(options.addon, options.id),
    "its module's default export",
  );
  const patches: { readonly path: string; readonly value: JsonValue }[] = [];
  const refusedPatches: string[] = [];
  const allowed = options.patches ?? [];
  const dryRun = options.dryRun ?? (() => dryRunReceipt());
  let draft = frozenCopy(options.draft);
  let next = 0;
  let cancelled: TranslationKey | null = null;
  let dryRuns = 0;
  const record: StepRecord = {
    get draft() {
      return draft;
    },
    patches,
    refusedPatches,
    get next() {
      return next;
    },
    get cancelled() {
      return cancelled;
    },
    get dryRuns() {
      return dryRuns;
    },
  };

  const stepProps = (): StepProps<object, string, IssuedCommands<Record<string, object>>> => ({
    draft,
    dryRun: () => {
      dryRuns += 1;

      return Promise.resolve(dryRun());
    },
    ...(options.position === 'after_receipt'
      ? { receipt: options.receipt ?? dryRunReceipt() }
      : {}),
    patch: (path, value) => {
      if (!allowed.includes(path)) {
        refusedPatches.push(path);

        return;
      }

      patches.push({ path, value });
      draft = frozenCopy(patched(draft, path, value));
    },
    issue: (command, document) => host.runCommand(command, document),
    next: () => {
      next += 1;
    },
    cancel: (reason) => {
      cancelled ??= reason;
    },
  });

  const base = await rendered(options.id, host, () => <Step {...stepProps()} />);

  return { ...base, step: record };
}

/**
 * What renderPoint() takes: the kind of the point the contribution is on, and what that kind's
 * renderer takes.
 *
 * @stable
 */
export type RenderPointOptions<C extends ContributionMap<C>> =
  | ({ readonly kind: 'slot' } & RenderSlotOptions<C, unknown>)
  | ({ readonly kind: 'page' } & RenderPageOptions<C, unknown>)
  | ({ readonly kind: 'replacement' } & RenderReplacementOptions<C>)
  | ({ readonly kind: 'provider' } & RenderProviderOptions<C>)
  | ({ readonly kind: 'decorator' } & RenderDecoratorOptions<C>)
  | ({ readonly kind: 'flow_step' } & RenderStepOptions<C>);

/**
 * Renders a contribution on a point of the kind, as the host renders it: renderSlot(),
 * renderPage(), renderReplacement(), renderProvider(), renderDecorator() or renderStep() by the
 * kind. A form check and an observer are functions, not rendered: see expectFormCheckContract()
 * and expectObserverContract().
 *
 * @stable
 */
export function renderPoint<C extends ContributionMap<C>>(
  options: RenderPointOptions<C>,
): Promise<Rendered | RenderedSlot | RenderedDecorator | RenderedStep> {
  switch (options.kind) {
    case 'slot':
      return renderSlot(options);
    case 'page':
      return renderPage(options);
    case 'replacement':
      return renderReplacement(options);
    case 'provider':
      return renderProvider(options);
    case 'decorator':
      return renderDecorator(options);
    case 'flow_step':
      return renderStep(options);
  }
}
