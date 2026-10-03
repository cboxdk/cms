// The panel's point host (PRD 13.4, section 3 of the panel extension architecture): a page renders
// each of its panel points with `<PointHost point="<name>@<version>" ...>`, and the host renders the
// contributions the server resolved for it, in their render order, each in its own error boundary
// and scope wrapper, by the point's kind:
//
// - a slot renders each contribution's component with the point's props and its data; in a
//   toolbar, tabs or columns region a contribution gives descriptors the host renders with the
//   kit, not markup; past the point's maximum, toolbar buttons overflow into a menu;
// - an action renders a kit button per contribution, which hands the page the prefilled command;
// - a decorator renders the page's default once, with what the decorators add and tighten;
// - a replacement renders the winning contribution for the page's target in place of the
//   default, and the default when there is none, while it loads, and when it fails.
//
// The points a page asks about rather than renders, the checks, flow steps, observers and columns
// of a point, come from usePointHost('<name>@<version>'). A point the server sent no contribution
// for renders the page's default, or nothing.

import {
  ActionBar,
  Badge,
  Inline,
  Skeleton,
  Tabs,
  type IconName,
  type TabSpec,
} from '@cboxdk/cms-ui-kit';
import type { JsonObject, JsonValue } from '@cboxdk/cms-panel/extend';
import { Fragment, useMemo, type ComponentType, type ReactNode } from 'react';

import { useTranslation } from '../i18n/translations';
import {
  ContributionBoundary,
  FailedContribution,
  UnavailableAddon,
  useAddonName,
} from './boundary';
import { frozenCopy, runChecks, type CheckRuns, type FormCheckEntry } from './checks';
import { compose, type AppliedDecoration, type DefaultTone, type Tightened } from './decorators';
import { FlowRun, type FlowPosition, type FlowStepEntry } from './flow';
import { useLoadedAddons, useLoadedModules, type FillModule } from './loading';
import { pointOf, renderOrder, type ActivePoint, type Fill, type PointKind } from './model';
import { notifyObservers, type ObserverEntry } from './observers';
import { asError, type HostReport } from './reports';
import { useHostRuntime, type HostRuntime } from './runtime';
import { ContributionScope } from './scope';

/** An action a page runs: the command an action contribution runs, prefilled from the point's props. */
export interface HostAction {
  readonly addon: string;
  readonly contribution: string;
  /** The command and version, `<name>@<version>`. */
  readonly command: string;
  /** The action's text, in the panel's locale. */
  readonly label: string;
  readonly confirm: 'none' | 'confirm' | 'dry_run' | 'form';
  readonly tone: 'neutral' | 'info' | 'warning' | 'danger';
  /** The command document's properties the action fills from the point's props. */
  readonly document: JsonObject;
}

/** A slot's host: the contributions render in its region. */
export interface SlotHostProps {
  readonly point: string;
}

/** A tabs slot's host: the page's own tabs, then the contributed ones. */
export interface TabsHostProps {
  readonly point: string;
  /** What the tabs are of, from the page's translations. */
  readonly label: string;
  readonly tabs: readonly TabSpec[];
  readonly selected?: string | undefined;
  readonly onChange?: ((id: string) => void) | undefined;
}

/** An action point's host: a button per action, which hands the page the action taken. */
export interface ActionHostProps {
  readonly point: string;
  /** What the actions act on, from the page's translations. */
  readonly label: string;
  readonly onAction: (action: HostAction) => void;
}

/** A decorator point's host: the page's default, rendered once with the decorations. */
export interface DecoratorHostProps {
  readonly point: string;
  /** The default's own tone, which the decorators may only move towards danger. */
  readonly tone?: DefaultTone;
  /** Renders the default with the props the decorators tightened. */
  readonly render: (tightened: Tightened) => ReactNode;
}

/** A replacement point's host: the winning replacement of the target, or the page's default. */
export interface ReplacementHostProps {
  readonly point: string;
  /** The key the replacement replaces, such as a field type. */
  readonly target: string;
  /** The props the replacement gets, the point's props as the page holds them. */
  readonly props: object;
  /** The default, rendered when no replacement wins, while it loads and when it fails. */
  readonly fallback: ReactNode;
}

/** The props of PointHost: one shape per kind of point a page renders. */
export type PointHostProps =
  SlotHostProps | TabsHostProps | ActionHostProps | DecoratorHostProps | ReplacementHostProps;

/** Renders a panel point of a page with the contributions active on it. */
export function PointHost(props: PointHostProps) {
  if ('onAction' in props) {
    return <ActionHost {...props} />;
  }

  if ('render' in props) {
    return <DecoratorHost {...props} />;
  }

  if ('target' in props) {
    return <ReplacementHost {...props} />;
  }

  if ('tabs' in props) {
    return <TabsHost {...props} />;
  }

  return <SlotHost {...props} />;
}

/** The point's active contributions in render order, cut at its maximum. */
function shownFills(point: ActivePoint): readonly Fill[] {
  const fills = renderOrder(point.fills);
  const most = point.multiplicity === 'exclusive' ? 1 : point.max;

  return most === null ? fills : fills.slice(0, most);
}

/**
 * The active point, or undefined when it has none or is not of the kind the page renders it as,
 * which the host reports for each of its contributions.
 */
function useActivePoint(point: string, expected: Expected): ActivePoint | undefined {
  const runtime = useHostRuntime();
  const active = pointOf(runtime.contributions, point);

  if (active === undefined) {
    return undefined;
  }

  if (
    active.kind !== expected.kind ||
    (expected.regions !== undefined && !expected.regions.includes(active.region ?? ''))
  ) {
    for (const fill of active.fills) {
      runtime.report({
        code: 'panel_point_kind_mismatch',
        addon: fill.addon,
        point,
        contribution: fill.id,
      });
    }

    return undefined;
  }

  return active;
}

/** The kind, and the regions of a slot, a host component renders a point as. */
interface Expected {
  readonly kind: PointKind;
  readonly regions?: readonly string[];
}

const FREE_OR_TOOLBAR_SLOT: Expected = { kind: 'slot', regions: ['sections', 'aside', 'toolbar'] };
const TABS_SLOT: Expected = { kind: 'slot', regions: ['tabs'] };
const ACTIONS: Expected = { kind: 'action' };
const DECORATORS: Expected = { kind: 'decorator' };
const REPLACEMENTS: Expected = { kind: 'replacement' };

/** The report of a fill's failure. */
function fillReport(code: HostReport['code'], point: string, fill: Fill): HostReport {
  return { code, addon: fill.addon, point, contribution: fill.id };
}

/** The value at a JSON pointer (RFC 6901) into a document, or undefined when it has none. */
export function atPointer(document: unknown, pointer: string): JsonValue | undefined {
  let value: unknown = document;

  for (const raw of pointer.split('/').slice(1)) {
    const token = raw.replaceAll('~1', '/').replaceAll('~0', '~');

    if (Array.isArray(value) && /^(0|[1-9][0-9]*)$/.test(token)) {
      value = (value as readonly unknown[])[Number(token)];
    } else if (
      typeof value === 'object' &&
      value !== null &&
      !Array.isArray(value) &&
      Object.hasOwn(value, token)
    ) {
      value = (value as Readonly<Record<string, unknown>>)[token];
    } else {
      return undefined;
    }
  }

  // A pointer into a JSON document reaches a JSON value.
  return value as JsonValue;
}

function SlotHost({ point }: SlotHostProps) {
  const active = useActivePoint(point, FREE_OR_TOOLBAR_SLOT);

  if (active === undefined) {
    return null;
  }

  return active.region === 'toolbar' ? (
    <ToolbarSlot point={point} active={active} />
  ) : (
    <FreeSlot point={point} active={active} />
  );
}

/** A slot in a region that takes free markup: sections or the aside. */
function FreeSlot({ point, active }: { readonly point: string; readonly active: ActivePoint }) {
  const fills = shownFills(active);
  const modules = useLoadedModules(fills);

  return (
    <>
      <RefusedAddons fills={fills} modules={modules} />
      {fills.map((fill) => (
        <SlotFillView key={fill.id} point={point} fill={fill} module={modules.get(fill.id)} />
      ))}
    </>
  );
}

/** One notice per addon on the point whose code could not be loaded; a mismatch shows nothing. */
function RefusedAddons({
  fills,
  modules,
}: {
  readonly fills: readonly Fill[];
  readonly modules: ReadonlyMap<string, FillModule>;
}) {
  const unavailable = new Set<string>();

  for (const fill of fills) {
    const module = modules.get(fill.id);

    if (module?.status === 'refused' && module.addon.status === 'unavailable') {
      unavailable.add(fill.addon);
    }
  }

  return (
    <>
      {[...unavailable].map((addon) => (
        <UnavailableAddon key={addon} addon={addon} />
      ))}
    </>
  );
}

/** A contribution's component, with the point's props and its data, in its boundary and scope. */
function SlotFillView({
  point,
  fill,
  module,
}: {
  readonly point: string;
  readonly fill: Fill;
  readonly module: FillModule | undefined;
}) {
  const runtime = useHostRuntime();
  const { t } = useTranslation();
  const name = useAddonName(fill.addon);
  const props = useMemo(() => frozenCopy(fill.props), [fill.props]);

  if (module === undefined || module.status === 'refused') {
    return null;
  }

  if (module.status === 'loading') {
    return <Skeleton label={t('panel.host.loading', { addon: name })} />;
  }

  return (
    <ContributionBoundary
      report={fillReport('panel_contribution_failed', point, fill)}
      onFailure={runtime.report}
      fallback={(failure) => <FailedContribution addon={fill.addon} failure={failure} />}
    >
      {module.status === 'failed' ? (
        <Thrown failure={module.failure} />
      ) : (
        <ContributionScope addon={fill.addon} point={point} contribution={fill.id}>
          <Rendered
            module={module.value}
            props={fill.data ? { props, data: runtime.data(fill.addon, fill.id) } : { props }}
          />
        </ContributionScope>
      )}
    </ContributionBoundary>
  );
}

/** Throws what a module's import failed with, so the boundary around it shows the notice. */
function Thrown({ failure }: { readonly failure: unknown }): never {
  throw asError(failure);
}

/** Renders a module's default export as a component with the props, or throws when it is none. */
function Rendered({ module, props }: { readonly module: unknown; readonly props: object }) {
  if (typeof module !== 'function') {
    throw new TypeError("The module's default export is not a component.");
  }

  const Contributed = module as ComponentType<object>;

  return <Contributed {...props} />;
}

/** A slot in a toolbar region: each contribution gives a badge or a button. */
function ToolbarSlot({ point, active }: { readonly point: string; readonly active: ActivePoint }) {
  const runtime = useHostRuntime();
  const { t } = useTranslation();
  const fills = renderOrder(active.fills);
  const modules = useLoadedModules(fills);
  const badges: {
    readonly fill: Fill;
    readonly label: string;
    readonly tone: 'neutral' | 'info' | 'warning' | 'danger';
  }[] = [];
  const buttons: { readonly fill: Fill; readonly label: string; readonly onPress: () => void }[] =
    [];

  for (const fill of fills) {
    const module = modules.get(fill.id);

    if (module?.status === 'failed') {
      runtime.report(fillReport('panel_contribution_failed', point, fill));
    }

    if (module?.status !== 'loaded') {
      continue;
    }

    let item: unknown;

    try {
      if (typeof module.value !== 'function') {
        throw new TypeError("A toolbar item is a function of the point's props.");
      }

      item = (module.value as (props: object) => unknown)(frozenCopy(fill.props));
    } catch {
      runtime.report(fillReport('panel_contribution_failed', point, fill));

      continue;
    }

    if (item === null) {
      continue;
    }

    const label = toolbarLabel(runtime, fill, item);

    if (label === undefined) {
      runtime.report(fillReport('panel_contribution_failed', point, fill));

      continue;
    }

    const descriptor = item as {
      readonly kind: string;
      readonly tone?: unknown;
      readonly onPress?: unknown;
    };

    if (descriptor.kind === 'badge') {
      const tone =
        descriptor.tone === 'info' || descriptor.tone === 'warning' || descriptor.tone === 'danger'
          ? descriptor.tone
          : 'neutral';
      badges.push({ fill, label, tone });
    } else if (descriptor.kind === 'button' && typeof descriptor.onPress === 'function') {
      const press = descriptor.onPress as () => void;
      buttons.push({
        fill,
        label,
        onPress: () => {
          try {
            press();
          } catch {
            runtime.report(fillReport('panel_contribution_failed', point, fill));
          }
        },
      });
    } else {
      runtime.report(fillReport('panel_contribution_failed', point, fill));
    }
  }

  if (badges.length === 0 && buttons.length === 0) {
    return null;
  }

  return (
    <Inline gap="sm" align="center">
      {badges.map((badge) => (
        <span
          key={badge.fill.id}
          className="cms-contribution"
          data-cms-addon={badge.fill.addon}
          data-cms-point={point}
          data-cms-contribution={badge.fill.id}
        >
          <Badge tone={badge.tone}>{badge.label}</Badge>
        </span>
      ))}
      {buttons.length === 0 ? null : (
        <ActionBar
          label={t('panel.host.toolbar')}
          actions={buttons.map((button) => ({ id: button.fill.id, label: button.label }))}
          visible={active.max ?? buttons.length}
          onAction={(id) => {
            buttons.find((button) => button.fill.id === id)?.onPress();
          }}
        />
      )}
    </Inline>
  );
}

/** The text of a toolbar item in its addon's catalogue, or undefined for an item that is none. */
function toolbarLabel(runtime: HostRuntime, fill: Fill, item: unknown): string | undefined {
  if (
    typeof item !== 'object' ||
    item === null ||
    !('label' in item) ||
    typeof item.label !== 'string'
  ) {
    return undefined;
  }

  const parameters =
    'parameters' in item && typeof item.parameters === 'object' && item.parameters !== null
      ? (item.parameters as Readonly<Record<string, string | number>>)
      : undefined;

  return runtime.text(fill.addon, item.label, parameters);
}

/** A slot in a tabs region: the page's tabs, then a tab per contribution. */
function TabsHost({ point, label, tabs, selected, onChange }: TabsHostProps) {
  const runtime = useHostRuntime();
  const active = useActivePoint(point, TABS_SLOT);
  const fills = active === undefined ? [] : shownFills(active);
  const modules = useLoadedModules(fills);
  const contributed: TabSpec[] = [];

  for (const fill of fills) {
    const module = modules.get(fill.id);

    if (module?.status !== 'loaded') {
      continue;
    }

    const tab = module.value;

    if (
      typeof tab !== 'object' ||
      tab === null ||
      !('label' in tab) ||
      typeof tab.label !== 'string' ||
      !('component' in tab) ||
      typeof tab.component !== 'function'
    ) {
      runtime.report(fillReport('panel_contribution_failed', point, fill));

      continue;
    }

    contributed.push({
      id: fill.id,
      label: runtime.text(fill.addon, tab.label),
      content: (
        <SlotFillView
          point={point}
          fill={fill}
          module={{ status: 'loaded', value: tab.component }}
        />
      ),
    });
  }

  return (
    <Tabs label={label} tabs={[...tabs, ...contributed]} selected={selected} onChange={onChange} />
  );
}

/** An action point: a kit button per action, in render order, overflowing into a menu past its maximum. */
function ActionHost({ point, label, onAction }: ActionHostProps) {
  const runtime = useHostRuntime();
  const active = useActivePoint(point, ACTIONS);
  const actions: HostAction[] = [];

  for (const fill of active === undefined ? [] : renderOrder(active.fills)) {
    if (fill.action === null) {
      continue;
    }

    const document: Record<string, JsonValue> = {};

    for (const prefill of fill.action.prefill) {
      const value = atPointer(fill.props, prefill.pointer);

      if (value !== undefined) {
        document[prefill.property] = value;
      }
    }

    actions.push({
      addon: fill.addon,
      contribution: fill.id,
      command: fill.action.command,
      label: runtime.text(fill.addon, fill.action.label),
      confirm: fill.action.confirm,
      tone: fill.action.tone,
      document,
    });
  }

  if (active === undefined || actions.length === 0) {
    return null;
  }

  return (
    <ActionBar
      label={label}
      actions={actions.map((action, index) => ({
        id: action.contribution,
        label: action.label,
        variant: action.tone === 'danger' ? 'danger' : index === 0 ? 'secondary' : 'quiet',
        icon: kitIcon(
          active.fills.find((fill) => fill.id === action.contribution)?.action?.icon ?? null,
        ),
      }))}
      visible={active.max ?? actions.length}
      onAction={(id) => {
        const action = actions.find((candidate) => candidate.contribution === id);

        if (action !== undefined) {
          onAction(action);
        }
      }}
    />
  );
}

/** The kit's icons an action may name. */
const KIT_ICONS: readonly IconName[] = [
  'arrow-right',
  'check',
  'close',
  'copy',
  'eye',
  'info',
  'minus',
  'plus',
  'search',
  'warning',
];

function kitIcon(icon: string | null): IconName | undefined {
  return KIT_ICONS.find((name) => name === icon);
}

/** A decorator point: the page's default once, with the decorators' content, badges and tightening. */
function DecoratorHost({ point, tone = 'neutral', render }: DecoratorHostProps) {
  const runtime = useHostRuntime();
  const active = useActivePoint(point, DECORATORS);
  const fills = active === undefined ? [] : renderOrder(active.fills);
  const addons = useLoadedAddons(fills);
  const applied: AppliedDecoration[] = [];

  for (const fill of fills) {
    const loaded = addons.get(fill.addon);
    const decorator =
      loaded?.status === 'registered'
        ? runtime.source.implementation(loaded.registration, fill.id)
        : undefined;

    if (decorator === undefined) {
      continue;
    }

    try {
      const decoration = (decorator as (props: object) => unknown)(frozenCopy(fill.props));

      if (typeof decoration !== 'object' || decoration === null) {
        throw new TypeError('A decorator answers with an object.');
      }

      applied.push({
        addon: fill.addon,
        contribution: fill.id,
        tightens: fill.decorator?.tightens ?? [],
        decoration,
      });
    } catch {
      runtime.report(fillReport('panel_decorator_failed', point, fill));
    }
  }

  const composition = compose(
    tone,
    applied,
    (addon, key) => runtime.text(addon, key),
    (report) => {
      runtime.report({ ...report, point });
    },
  );

  return (
    <>
      {composition.before.map((content) => (
        <DecorationView key={`before ${content.contribution}`} point={point} content={content} />
      ))}
      {composition.badges.length === 0 ? null : (
        <Inline gap="sm">
          {composition.badges.map((badge) => (
            <Badge key={badge.contribution} tone={badge.tone}>
              {badge.label}
            </Badge>
          ))}
        </Inline>
      )}
      <Fragment key="default">{render(composition.tightened)}</Fragment>
      {composition.after.map((content) => (
        <DecorationView key={`after ${content.contribution}`} point={point} content={content} />
      ))}
    </>
  );
}

/** What a decorator renders before or after the default, in its boundary and scope. */
function DecorationView({
  point,
  content,
}: {
  readonly point: string;
  readonly content: {
    readonly addon: string;
    readonly contribution: string;
    readonly node: ReactNode;
  };
}) {
  const runtime = useHostRuntime();

  return (
    <ContributionBoundary
      report={{
        code: 'panel_decorator_failed',
        addon: content.addon,
        point,
        contribution: content.contribution,
      }}
      onFailure={runtime.report}
      fallback={(failure) => <FailedContribution addon={content.addon} failure={failure} />}
    >
      <ContributionScope addon={content.addon} point={point} contribution={content.contribution}>
        {content.node}
      </ContributionScope>
    </ContributionBoundary>
  );
}

/** A replacement point: the winning replacement of the target in place of the page's default. */
function ReplacementHost({ point, target, props, fallback }: ReplacementHostProps) {
  const runtime = useHostRuntime();
  const active = useActivePoint(point, REPLACEMENTS);
  const winner =
    active === undefined
      ? undefined
      : renderOrder(active.fills).find((fill) => fill.replacement?.key === target);
  const modules = useLoadedModules(winner === undefined ? [] : [winner]);
  const module = winner === undefined ? undefined : modules.get(winner.id);

  if (winner === undefined || module === undefined || module.status !== 'loaded') {
    if (winner !== undefined && module?.status === 'failed') {
      return (
        <ReplacementFailed
          point={point}
          fill={winner}
          failure={module.failure}
          fallback={fallback}
        />
      );
    }

    return <>{fallback}</>;
  }

  return (
    <ContributionBoundary
      report={fillReport('panel_replacement_failed', point, winner)}
      onFailure={runtime.report}
      fallback={(failure) => (
        <ReplacementFailed point={point} fill={winner} failure={failure} fallback={fallback} />
      )}
    >
      <ContributionScope addon={winner.addon} point={point} contribution={winner.id}>
        <Rendered module={module.value} props={props} />
      </ContributionScope>
    </ContributionBoundary>
  );
}

/** The default in place of a replacement that failed, with the notice that names its addon. */
function ReplacementFailed({
  point,
  fill,
  failure,
  fallback,
}: {
  readonly point: string;
  readonly fill: Fill;
  readonly failure: unknown;
  readonly fallback: ReactNode;
}) {
  const runtime = useHostRuntime();
  runtime.report(fillReport('panel_replacement_failed', point, fill));

  return (
    <>
      <FailedContribution addon={fill.addon} failure={failure} />
      {fallback}
    </>
  );
}

/** A column a contribution gives a columns region. */
export interface HostColumn {
  readonly id: string;
  readonly addon: string;
  /** The column's header, in the panel's locale. */
  readonly header: string;
  readonly width: 'narrow' | 'medium' | 'wide';
  /** The column's cell of a row, given the point's props of the row. */
  readonly cell: (row: JsonObject) => ReactNode;
}

/** What a page asks a point for rather than renders it as. */
export interface PointHandle {
  readonly point: string;
  /** The point's kind, or undefined when the server sent no contribution for it. */
  readonly kind: PointKind | undefined;
  /** The columns of a slot in a columns region, in render order, once their modules loaded. */
  readonly columns: readonly HostColumn[];
  /** Runs the form checks of the command, `<name>@<version>`, on its document. */
  readonly checks: (command: string, document: object, edits?: AbortSignal) => CheckRuns;
  /** Starts the flow of the command's steps at the position on the draft. */
  readonly flow: (command: string, position: FlowPosition, draft: JsonObject) => FlowRun;
  /** Calls the observers with an event that happened. */
  readonly observe: (event: object) => void;
}

/**
 * The point as a page asks about it: its columns, checks, flow and observers. Their code is the
 * addons' registered code once checked; until then a point has none of them.
 */
export function usePointHost(point: string): PointHandle {
  const runtime = useHostRuntime();
  const { locale } = useTranslation();
  const active = pointOf(runtime.contributions, point);
  const fills = active === undefined ? [] : renderOrder(active.fills);
  const addons = useLoadedAddons(fills);
  const columnFills = active?.region === 'columns' ? shownFills(active) : [];
  const modules = useLoadedModules(columnFills);
  const report = (entry: Omit<HostReport, 'point'>): void => {
    runtime.report({ ...entry, point });
  };

  const implementation = (fill: Fill): unknown => {
    const loaded = addons.get(fill.addon);

    return loaded?.status === 'registered'
      ? runtime.source.implementation(loaded.registration, fill.id)
      : undefined;
  };

  const columns: HostColumn[] = [];

  for (const fill of columnFills) {
    const module = modules.get(fill.id);

    if (module?.status !== 'loaded') {
      continue;
    }

    const column = module.value;

    if (
      typeof column !== 'object' ||
      column === null ||
      !('header' in column) ||
      typeof column.header !== 'string' ||
      !('cell' in column) ||
      typeof column.cell !== 'function'
    ) {
      report({ code: 'panel_contribution_failed', addon: fill.addon, contribution: fill.id });

      continue;
    }

    const width =
      'width' in column && (column.width === 'narrow' || column.width === 'wide')
        ? column.width
        : 'medium';
    const cell = column.cell as ComponentType<{ readonly props: Readonly<JsonObject> }>;
    columns.push({
      id: fill.id,
      addon: fill.addon,
      header: runtime.text(fill.addon, column.header),
      width,
      cell: (row) => (
        <ContributionBoundary
          report={fillReport('panel_contribution_failed', point, fill)}
          onFailure={runtime.report}
          fallback={(failure) => <FailedContribution addon={fill.addon} failure={failure} />}
        >
          <ContributionScope addon={fill.addon} point={point} contribution={fill.id}>
            <Rendered module={cell} props={{ props: frozenCopy(row) }} />
          </ContributionScope>
        </ContributionBoundary>
      ),
    });
  }

  return {
    point,
    kind: active?.kind,
    columns,
    checks: (command, document, edits) => {
      const entries: FormCheckEntry[] = [];

      for (const fill of fills) {
        const check =
          fill.check === null || fill.check.command !== command ? undefined : implementation(fill);

        if (typeof check === 'function' && fill.check !== null) {
          entries.push({
            addon: fill.addon,
            contribution: fill.id,
            severity: fill.check.severity,
            check: check as FormCheckEntry['check'],
          });
        }
      }

      return runChecks(
        entries,
        document,
        { context: { locale }, skipped: runtime.skippedChecks, report },
        edits,
      );
    },
    flow: (command, position, draft) => {
      const steps: FlowStepEntry[] = fills.flatMap((fill) =>
        fill.step !== null &&
        fill.step.command === command &&
        fill.step.position === position &&
        implementation(fill) !== undefined
          ? [
              {
                addon: fill.addon,
                contribution: fill.id,
                patches: fill.step.patches,
                timeoutSeconds: fill.step.timeout_seconds,
              },
            ]
          : [],
      );

      return new FlowRun({ steps, draft, position, report });
    },
    observe: (event) => {
      const observers: ObserverEntry[] = fills.flatMap((fill) => {
        const observer = fill.kind === 'observer' ? implementation(fill) : undefined;

        return typeof observer === 'function'
          ? [
              {
                addon: fill.addon,
                contribution: fill.id,
                observer: observer as ObserverEntry['observer'],
              },
            ]
          : [];
      });

      notifyObservers(observers, event, report);
    },
  };
}
