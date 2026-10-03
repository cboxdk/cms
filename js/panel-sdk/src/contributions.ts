// What an addon gives the panel for each kind of contribution that runs code (PRD 13.4, section 3
// of the panel extension architecture). An addon names one of these per contribution its manifest
// declares, by the contribution's id, in the map it hands definePanelAddon; `cms:panel:types`
// writes that map, Contributions, from the manifest and the points' schemas, so the props a
// contribution receives are the props the panel's codec writes.
//
// A component is handed over lazily, as a function that imports its module, so the panel loads an
// addon's code only for the contributions a page shows. A pure function, a decorator, a form check
// or an observer, is handed over as it is.

import type { ComponentType, ReactNode } from 'react';

import type { JsonValue } from './json';
import type { CommandAnswer, TranslationKey, TranslationParameters } from './host';

/**
 * A module of an addon's bundle, imported when the panel first needs it: `() =>
 * import('./ApprovalsBadge')`, whose default export is the component.
 *
 * @stable
 */
export type Lazy<T> = () => Promise<{ readonly default: T }>;

/**
 * The result of a contribution's data query, which the panel runs as the viewer on the server and
 * sends as a deferred prop: loading until it arrives, ready with the value the query's result codec
 * wrote, or failed with the error code of the catalog that rejected it (the query ran over its
 * budget, or the viewer may not run it). A page never fails because a contribution's data did.
 *
 * @stable
 */
export type DataState<D> =
  | { readonly status: 'loading' }
  | { readonly status: 'ready'; readonly value: D }
  | { readonly status: 'failed'; readonly code: string };

/**
 * What a slot's contribution receives: the point's props, frozen, and, when the contribution names
 * a data query, its result. D is never for a contribution without a data query, whose component
 * gets no data.
 *
 * @stable
 */
export type SlotProps<P, D = never> = [D] extends [never]
  ? { readonly props: Readonly<P> }
  : { readonly props: Readonly<P>; readonly data: DataState<D> };

/**
 * The component of a contribution to a slot.
 *
 * @stable
 */
export type SlotComponent<P, D = never> = ComponentType<SlotProps<P, D>>;

/**
 * An item a contribution gives a slot in a toolbar region, which the host renders with the kit:
 * a badge, or a button that calls onPress. The label is a key of the addon's catalogue. A toolbar
 * takes descriptors, not markup, so its items stay accessible and consistent; past the point's
 * maximum, buttons overflow into a menu.
 *
 * @stable
 */
export type ToolbarItemDescriptor =
  | {
      readonly kind: 'badge';
      readonly label: TranslationKey;
      readonly tone?: BadgeDescriptor['tone'];
      readonly parameters?: TranslationParameters;
    }
  | {
      readonly kind: 'button';
      readonly label: TranslationKey;
      readonly parameters?: TranslationParameters;
      readonly onPress: () => void;
    };

/**
 * A contribution to a slot in a toolbar region: the default export of its module, a function of
 * the point's props to its item, or null for none.
 *
 * @stable
 */
export type ToolbarItem<P> = (props: Readonly<P>) => ToolbarItemDescriptor | null;

/**
 * A column a contribution gives a slot in a columns region: its header, a key of the addon's
 * catalogue, how wide it is, and the component of its cell, which gets the point's props of the
 * row. The default export of its module.
 *
 * @stable
 */
export interface ColumnDescriptor<P> {
  readonly header: TranslationKey;
  readonly width?: 'narrow' | 'medium' | 'wide';
  readonly cell: ComponentType<{ readonly props: Readonly<P> }>;
}

/**
 * A tab a contribution gives a slot in a tabs region: its label, a key of the addon's catalogue,
 * and the component of its panel, which gets what a slot's contribution gets. The default export
 * of its module.
 *
 * @stable
 */
export interface TabDescriptor<P, D = never> {
  readonly label: TranslationKey;
  readonly component: SlotComponent<P, D>;
}

/**
 * What an addon's page receives: its data query's result, the only props a page has, so the same
 * data can be read over REST. D is never for a page without a data query.
 *
 * @stable
 */
export type PageProps<D = never> = [D] extends [never]
  ? { readonly data?: never }
  : { readonly data: DataState<D> };

/**
 * The component of an addon's page.
 *
 * @stable
 */
export type PageComponent<D = never> = ComponentType<PageProps<D>>;

/**
 * A tone a decorator may move a target towards: only ever towards warning or danger.
 *
 * @stable
 */
export type TighterTone = 'warning' | 'danger';

/**
 * The props of a decorator's target that a decorator may tighten, by the names the point declares
 * (Tighten in PHP): a reason that disables it, a description appended to its own, and a tone that
 * moves towards danger. Tightened props combine most restrictively across decorators.
 *
 * @stable
 */
export interface Tightening {
  readonly disabled_reason: TranslationKey;
  readonly description: TranslationKey;
  readonly tone_towards_danger: TighterTone;
}

/**
 * A badge a decorator puts on its target.
 *
 * @stable
 */
export interface BadgeDescriptor {
  readonly tone: 'neutral' | 'info' | 'warning' | 'danger';
  readonly label: TranslationKey;
}

/**
 * What a decorator gives its target: content before and after it, a badge, and the props it
 * tightens, only those its manifest declares (T). The decorator never receives the target itself,
 * so it cannot drop it: the host always renders it once.
 *
 * @stable
 */
export interface Decoration<T extends keyof Tightening = never> {
  readonly before?: ReactNode;
  readonly after?: ReactNode;
  readonly badge?: BadgeDescriptor;
  readonly tighten?: Partial<Pick<Tightening, T>>;
}

/**
 * A contribution to a decorator point: a function of the target's props.
 *
 * @stable
 */
export type Decorator<P, T extends keyof Tightening = never> = (
  props: Readonly<P>,
) => Decoration<T>;

/**
 * A contribution to a replacement point: a component with exactly the point's props, which renders
 * in place of the default for a key the addon owns. When it throws, the default renders.
 *
 * @stable
 */
export type Replacement<P> = ComponentType<Readonly<P>>;

/**
 * How serious a form check's issue is: info and warning only inform, acknowledge asks for an
 * explicit tick before submit, and error blocks the client's submit, which only a check that
 * mirrors a hook of the addon on the same command may do.
 *
 * @stable
 */
export type IssueSeverity = 'info' | 'warning' | 'acknowledge' | 'error';

/**
 * An issue a form check finds in a command document.
 *
 * @stable
 */
export interface Issue {
  /** The path of the value, as FieldPath::toString() writes it, such as `fields.title`. */
  readonly path: string;
  /** The issue's code in the addon's namespace, `<namespace>.<name>`. */
  readonly code: string;
  readonly severity: IssueSeverity;
  /** The translation key of the message, in the addon's namespace. */
  readonly message: TranslationKey;
  readonly parameters?: TranslationParameters;
}

/**
 * What a form check knows besides the document: the panel's locale.
 *
 * @stable
 */
export interface CheckContext {
  readonly locale: string;
}

/**
 * A contribution to a form check point: a pure function of the command document D to the issues it
 * finds, synchronous and within 16 ms; a check that throws or overruns is skipped for the session.
 * Checks only add issues; after submit, the server's errors replace them.
 *
 * @stable
 */
export type FormCheck<D> = (document: Readonly<D>, context: CheckContext) => readonly Issue[];

/**
 * What a flow step receives: the draft of the command document D, a dry run of it, the receipt
 * after submit for a step after the receipt, patch() for the paths its manifest declares (Path),
 * issue() for the commands the addon may issue (I), and next() and cancel() to end the step.
 *
 * @stable
 */
export interface StepProps<
  D,
  Path extends string = never,
  I extends IssuedCommands<I> = NoCommands,
> {
  readonly draft: Readonly<D>;
  readonly dryRun: () => Promise<CommandAnswer>;
  readonly receipt?: CommandAnswer;
  readonly patch: (path: Path, value: JsonValue) => void;
  readonly issue: <K extends keyof I & string>(
    command: K,
    document: I[K],
  ) => Promise<CommandAnswer>;
  readonly next: () => void;
  readonly cancel: (reason: TranslationKey) => void;
}

/**
 * A contribution to a flow step point: a component the form runs as a numbered step before submit
 * or after the receipt.
 *
 * @stable
 */
export type FlowStep<
  D,
  Path extends string = never,
  I extends IssuedCommands<I> = NoCommands,
> = ComponentType<StepProps<D, Path, I>>;

/**
 * A contribution to an observer point: called with the point's event after it happened; it cannot
 * affect the flow, and a throw is caught and attributed to the addon.
 *
 * @stable
 */
export type Observer<P> = (event: Readonly<P>) => void;

/**
 * What a provider's component receives: the point's props and the children it wraps.
 *
 * @stable
 */
export interface ProviderProps<P> {
  readonly props: Readonly<P>;
  readonly children: ReactNode;
}

/**
 * A contribution to a provider point: a component that wraps the point's subtree.
 *
 * @stable
 */
export type Provider<P> = ComponentType<ProviderProps<P>>;

/**
 * What the command documents an addon may issue are, I by the command's name and version, such as
 * `{ 'approvals.request@1': ApprovalsRequestV1 }`: each a JSON object. `cms:panel:types` writes the
 * addon's, the commands of its manifest's `issues`, as the interface Issues.
 *
 * @stable
 */
export type IssuedCommands<I> = { readonly [K in keyof I]: object };

/**
 * The commands of an addon that may issue none, the default of every type that takes them: no
 * document is a value of never, so nothing can be issued.
 *
 * @stable
 */
export type NoCommands = { readonly [command: string]: never };

/**
 * The contributions of an addon whose manifest declares none that runs code: the Contributions
 * `cms:panel:types` writes for it, which definePanelAddon() takes only as an empty registration.
 *
 * @stable
 */
export type NoContributions = { readonly [id: string]: never };

/**
 * What an addon registers for one contribution: a function, either one that imports a component's
 * module (Lazy) or a pure function such as a form check.
 *
 * @stable
 */
export type ContributionImplementation = (...inputs: never[]) => unknown;
