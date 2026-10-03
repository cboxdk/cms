// The view of a command form's flow (section 3.8 of the panel extension architecture): the step
// whose turn it is, the addon's component in its boundary and scope with what a step gets, then
// the core's own confirmation, which the page renders and which alone ends the flow with the draft
// to submit; or the notice that names the addon that cancelled it.

import type { CommandAnswer, JsonObject, JsonValue } from '@cboxdk/cms-panel/extend';
import { Callout } from '@cboxdk/cms-ui-kit';
import { useSyncExternalStore, type ComponentType, type ReactNode } from 'react';

import { useTranslation } from '../i18n/translations';
import { ContributionBoundary, useAddonName } from './boundary';
import type { FlowRun, FlowState } from './flow';
import { useLoadedModules } from './loading';
import { pointOf, type Fill } from './model';
import { useHostRuntime } from './runtime';
import { asError } from './reports';
import { ContributionScope } from './scope';

/** The props of FlowHost. */
export interface FlowHostProps {
  /** The point the steps contribute to, `<name>@<version>`. */
  readonly point: string;
  readonly run: FlowRun;
  /** A dry run of the draft, which a step may ask for. */
  readonly dryRun: () => Promise<CommandAnswer>;
  /** The receipt, for a flow after it. */
  readonly receipt?: CommandAnswer | undefined;
  /**
   * The core's own confirmation before the submit, which the page renders: `confirm` ends the flow
   * and hands the page the draft through onConfirmed.
   */
  readonly confirmation: (draft: JsonObject, confirm: () => void) => ReactNode;
  readonly onConfirmed: (draft: JsonObject) => void;
}

/** Renders the flow where it is. */
export function FlowHost({
  point,
  run,
  dryRun,
  receipt,
  confirmation,
  onConfirmed,
}: FlowHostProps) {
  const state = useSyncExternalStore(
    (listener) => run.subscribe(listener),
    () => run.state,
  );

  if (state.phase === 'step') {
    return <StepView point={point} run={run} state={state} dryRun={dryRun} receipt={receipt} />;
  }

  if (state.phase === 'confirm') {
    return (
      <>
        {confirmation(state.draft, () => {
          const draft = run.confirm();

          if (draft !== undefined) {
            onConfirmed(draft);
          }
        })}
      </>
    );
  }

  return state.phase === 'cancelled' ? <Cancelled state={state} /> : null;
}

function Cancelled({ state }: { readonly state: Extract<FlowState, { phase: 'cancelled' }> }) {
  const { t } = useTranslation();
  const runtime = useHostRuntime();
  const name = useAddonName(state.addon);

  return (
    <Callout tone="warning" title={t('panel.host.step_cancelled', { addon: name })}>
      {state.cause === 'failed'
        ? t('panel.host.step_failed', { addon: name })
        : state.cause === 'timed_out'
          ? t('panel.host.step_timed_out', { addon: name })
          : runtime.text(state.addon, state.reason ?? '')}
    </Callout>
  );
}

function StepView({
  point,
  run,
  state,
  dryRun,
  receipt,
}: {
  readonly point: string;
  readonly run: FlowRun;
  readonly state: Extract<FlowState, { phase: 'step' }>;
  readonly dryRun: () => Promise<CommandAnswer>;
  readonly receipt: CommandAnswer | undefined;
}) {
  const runtime = useHostRuntime();
  const fill: Fill | undefined = pointOf(runtime.contributions, point)?.fills.find(
    (candidate) => candidate.id === state.step.contribution,
  );
  const modules = useLoadedModules(fill === undefined ? [] : [fill]);
  const module = fill === undefined ? undefined : modules.get(fill.id);
  const controls = run.controls(state.index);

  if (fill === undefined || module === undefined || module.status === 'refused') {
    return null;
  }

  if (module.status === 'loading') {
    return null;
  }

  const host = runtime.hostFor(fill.addon, point, fill.id);

  return (
    <ContributionBoundary
      key={fill.id}
      report={{ code: 'panel_step_failed', addon: fill.addon, point, contribution: fill.id }}
      onFailure={() => {
        controls.fail();
      }}
      fallback={() => null}
    >
      <ContributionScope addon={fill.addon} point={point} contribution={fill.id}>
        <Step
          module={module.status === 'loaded' ? module.value : undefined}
          failure={module.status === 'failed' ? module.failure : undefined}
          props={{
            draft: state.draft,
            dryRun,
            receipt,
            patch: (path: string, value: JsonValue) => {
              controls.patch(path, value);
            },
            issue: (command: string, document: object) => host.runCommand(command, document),
            next: controls.next,
            cancel: controls.cancel,
          }}
        />
      </ContributionScope>
    </ContributionBoundary>
  );
}

/** A step's component with its props, or a throw for a module that failed or is no component. */
function Step({
  module,
  failure,
  props,
}: {
  readonly module: unknown;
  readonly failure: unknown;
  readonly props: object;
}) {
  if (failure !== undefined) {
    throw asError(failure);
  }

  if (typeof module !== 'function') {
    throw new TypeError("A flow step's module does not export a component.");
  }

  const Contributed = module as ComponentType<object>;

  return <Contributed {...props} />;
}
