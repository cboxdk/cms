// The flow runner of a command form (section 3.8 of the panel extension architecture): the steps
// contributed to a form's command run in their render order as numbered steps, before the submit or
// after the receipt. A step may patch only the paths its manifest declares, which cms:build held to
// `ext.<namespace>` of its addon or a command of its own; the runner refuses any other patch and
// reports it. The first cancel stops the flow in its addon's name; a step that throws, or takes
// longer than its timeout, cancels in its addon's name too. Before the submit the core's own
// confirmation always runs last: the flow reaches it after every step and ends only through it, and
// no step can skip it. Steps are not enforcement; the addon's hooks on the server are.

import type { JsonObject, JsonValue } from '@cboxdk/cms-panel/extend';

import { isItemKey, pathSegments } from '../forms/field-path';
import { frozenCopy } from './checks';
import type { HostReport } from './reports';

/** Where a flow's steps run. */
export type FlowPosition = 'before_submit' | 'after_receipt';

/** A step of a flow, with whose it is and what it may do. */
export interface FlowStepEntry {
  readonly addon: string;
  readonly contribution: string;
  /** The paths of the document it may patch, as FieldPath::toString() writes them. */
  readonly patches: readonly string[];
  readonly timeoutSeconds: number;
}

/** Why a flow was cancelled. */
export type CancelCause = 'cancelled' | 'failed' | 'timed_out';

/** Where a flow is. */
export type FlowState =
  | {
      readonly phase: 'step';
      readonly index: number;
      readonly step: FlowStepEntry;
      readonly draft: JsonObject;
    }
  | { readonly phase: 'confirm'; readonly draft: JsonObject }
  | {
      readonly phase: 'cancelled';
      readonly addon: string;
      readonly contribution: string;
      readonly cause: CancelCause;
      /** The translation key the step gave, in its addon's catalogue, for a step that cancelled. */
      readonly reason: string | undefined;
      readonly draft: JsonObject;
    }
  | { readonly phase: 'done'; readonly draft: JsonObject };

/** What a step can do to the flow, bound to its turn: outside its turn, nothing happens. */
export interface StepControls {
  readonly patch: (path: string, value: JsonValue) => void;
  readonly next: () => void;
  readonly cancel: (reason: string) => void;
  /** The step threw. */
  readonly fail: () => void;
}

/** The timers a flow uses: the platform's unless a test gives others. */
export interface FlowTimers {
  readonly set: (callback: () => void, milliseconds: number) => unknown;
  readonly clear: (timer: unknown) => void;
}

const PLATFORM_TIMERS: FlowTimers = {
  set: (callback, milliseconds) => setTimeout(callback, milliseconds),
  clear: (timer) => {
    clearTimeout(timer as ReturnType<typeof setTimeout>);
  },
};

function isJson(value: unknown): value is JsonValue {
  if (value === null || typeof value === 'string' || typeof value === 'boolean') {
    return true;
  }

  if (typeof value === 'number') {
    return Number.isFinite(value);
  }

  if (Array.isArray(value)) {
    return value.every(isJson);
  }

  if (typeof value === 'object' && Object.getPrototypeOf(value) === Object.prototype) {
    return Object.values(value).every(isJson);
  }

  return false;
}

/**
 * The document with the value at the path, made where the path's objects do not exist yet. The
 * path is one a step's manifest declared, so it names places by name and by index; a segment that
 * names an item of a list by its key is no place a declared path can know, and such a path leaves
 * the document as it was, as a path the parser does not read does.
 */
export function patched(document: JsonObject, path: string, value: JsonValue): JsonObject {
  const read = pathSegments(path);
  const segments = read.filter((segment): segment is string | number => !isItemKey(segment));

  if (segments.length !== read.length) {
    return structuredClone(document);
  }

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

  return copy as JsonObject;
}

/** One run of a form's flow. */
export class FlowRun {
  readonly #steps: readonly FlowStepEntry[];
  readonly #position: FlowPosition;
  readonly #report: (report: Omit<HostReport, 'point'>) => void;
  readonly #timers: FlowTimers;
  readonly #listeners = new Set<(state: FlowState) => void>();
  #state: FlowState;
  #timer: unknown;

  public constructor(options: {
    readonly steps: readonly FlowStepEntry[];
    readonly draft: JsonObject;
    readonly position: FlowPosition;
    readonly report: (report: Omit<HostReport, 'point'>) => void;
    readonly timers?: FlowTimers;
  }) {
    this.#steps = options.steps;
    this.#position = options.position;
    this.#report = options.report;
    this.#timers = options.timers ?? PLATFORM_TIMERS;
    this.#state = { phase: 'done', draft: frozenCopy(options.draft) };
    this.#enter(0, frozenCopy(options.draft));
  }

  /** Where the flow is. */
  public get state(): FlowState {
    return this.#state;
  }

  /** Called with each new state; gives back the function that stops the calls. */
  public subscribe(listener: (state: FlowState) => void): () => void {
    this.#listeners.add(listener);

    return () => {
      this.#listeners.delete(listener);
    };
  }

  /** The controls of the step at the index, which act only while it is that step's turn. */
  public controls(index: number): StepControls {
    const turn = (): FlowStepEntry | undefined =>
      this.#state.phase === 'step' && this.#state.index === index ? this.#state.step : undefined;

    return {
      patch: (path, value) => {
        const step = turn();

        if (step === undefined || this.#state.phase !== 'step') {
          return;
        }

        if (!step.patches.includes(path) || !isJson(value)) {
          this.#report({
            code: 'panel_step_patch_refused',
            addon: step.addon,
            contribution: step.contribution,
          });

          return;
        }

        this.#set({ ...this.#state, draft: frozenCopy(patched(this.#state.draft, path, value)) });
      },
      next: () => {
        if (turn() !== undefined) {
          this.#enter(index + 1, this.#state.draft);
        }
      },
      cancel: (reason) => {
        const step = turn();

        if (step !== undefined) {
          this.#cancel(step, 'cancelled', reason);
        }
      },
      fail: () => {
        const step = turn();

        if (step !== undefined) {
          this.#report({
            code: 'panel_step_failed',
            addon: step.addon,
            contribution: step.contribution,
          });
          this.#cancel(step, 'failed', undefined);
        }
      },
    };
  }

  /**
   * The core's confirmation: the only way a flow before the submit ends with its draft, and only
   * once every step has gone; gives the draft to submit, or undefined when the flow is not there.
   */
  public confirm(): JsonObject | undefined {
    if (this.#state.phase !== 'confirm') {
      return undefined;
    }

    const draft = this.#state.draft;
    this.#set({ phase: 'done', draft });

    return draft;
  }

  #enter(index: number, draft: JsonObject): void {
    this.#timers.clear(this.#timer);
    const step = this.#steps[index];

    if (step === undefined) {
      this.#set(
        this.#position === 'before_submit' ? { phase: 'confirm', draft } : { phase: 'done', draft },
      );

      return;
    }

    this.#timer = this.#timers.set(() => {
      if (this.#state.phase === 'step' && this.#state.index === index) {
        this.#report({
          code: 'panel_step_timed_out',
          addon: step.addon,
          contribution: step.contribution,
        });
        this.#cancel(step, 'timed_out', undefined);
      }
    }, step.timeoutSeconds * 1000);
    this.#set({ phase: 'step', index, step, draft });
  }

  #cancel(step: FlowStepEntry, cause: CancelCause, reason: string | undefined): void {
    this.#timers.clear(this.#timer);
    this.#set({
      phase: 'cancelled',
      addon: step.addon,
      contribution: step.contribution,
      cause,
      reason,
      draft: this.#state.draft,
    });
  }

  #set(state: FlowState): void {
    this.#state = state;

    for (const listener of this.#listeners) {
      listener(state);
    }
  }
}
