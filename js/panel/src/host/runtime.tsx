// The panel's host runtime (PRD 13.4): what every point of a page renders its contributions from.
// The page's prop cms.contributions says which contributions are active and what each needs; the
// addons' code comes from the AddonSource, which holds each registration to cms:build's; the data
// of a contribution that reads data comes from the deferred prop ext.<addon>; and the services are
// what a contribution's host reaches. A point renders nothing without a runtime around it.

import type { DataState, JsonValue, PanelHost } from '@cboxdk/cms-panel/extend';
import { createContext, useContext, useMemo, useState, type ReactNode } from 'react';

import type { AddonSource } from './addons';
import { NO_CONTRIBUTIONS, addonOf, type Contributions } from './model';
import { addonTexts, createPanelHost, type AnyCommands, type HostServices } from './panel-host';
import type { HostReport } from './reports';

/** The code a contribution's data has when its query gave none: refused, rejected or failed. */
export const DATA_UNAVAILABLE = 'panel_data_unavailable';

/** What the points of a page render from. */
export interface HostRuntime {
  readonly contributions: Contributions;
  readonly source: AddonSource;
  readonly services: HostServices;
  /** The data of a contribution that reads data. */
  readonly data: (addon: string, contribution: string) => DataState<JsonValue>;
  /** A text of an addon's catalogue, scoped to its namespace as its host's t() is. */
  readonly text: (
    addon: string,
    key: string,
    parameters?: Readonly<Record<string, string | number>>,
  ) => string;
  /** Reports a failure once per session: the same report again is passed over. */
  readonly report: (report: HostReport) => void;
  /** The host of a contribution, the same object for as long as the page's contributions are. */
  readonly hostFor: (addon: string, point: string, contribution: string) => PanelHost<AnyCommands>;
  /** The checks skipped for the rest of the session, by contribution id, after a throw or an overrun. */
  readonly skippedChecks: Set<string>;
}

const HostRuntimeContext = createContext<HostRuntime | null>(null);

/** Thrown by a point rendered outside a HostRuntimeProvider. */
export class HostRuntimeMissing extends Error {
  public constructor() {
    super("A panel point was rendered outside the panel's HostRuntimeProvider.");
    this.name = 'HostRuntimeMissing';
  }
}

/** The runtime of the page around the caller. */
export function useHostRuntime(): HostRuntime {
  const runtime = useContext(HostRuntimeContext);

  if (runtime === null) {
    throw new HostRuntimeMissing();
  }

  return runtime;
}

function isRecord(value: unknown): value is Readonly<Record<string, unknown>> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/**
 * The data of a contribution in the page's prop ext: loading until the addon's deferred prop has
 * arrived, ready with the value under the contribution's id, and failed when the addon's prop has
 * arrived without it, because its query was refused, rejected or failed.
 */
export function dataOf(ext: unknown, addon: string, contribution: string): DataState<JsonValue> {
  const answers = isRecord(ext) ? ext[addon] : undefined;

  if (!isRecord(answers)) {
    return { status: 'loading' };
  }

  return Object.hasOwn(answers, contribution)
    ? { status: 'ready', value: answers[contribution] as JsonValue }
    : { status: 'failed', code: DATA_UNAVAILABLE };
}

/** The props of HostRuntimeProvider. */
export interface HostRuntimeProviderProps {
  /** The page's cms.contributions, or undefined on a page that sends none. */
  readonly contributions: Contributions | undefined;
  /** The page's prop ext, the deferred data of the addons, or undefined before it arrives. */
  readonly ext: unknown;
  readonly source: AddonSource;
  readonly services: HostServices;
  readonly children: ReactNode;
}

/**
 * Gives the points below it the runtime of the page: its contributions and data, the addons'
 * code and the services. The source, the skipped checks and the reports made live as long as the
 * provider, so an addon is checked, a check skipped and a failure reported once per session, not
 * once per page or render.
 */
export function HostRuntimeProvider({
  contributions = NO_CONTRIBUTIONS,
  ext,
  source,
  services,
  children,
}: HostRuntimeProviderProps) {
  const [skippedChecks] = useState(() => new Set<string>());
  const [reported] = useState(() => new Set<string>());
  const runtime = useMemo<HostRuntime>(() => {
    const hosts = new Map<string, PanelHost<AnyCommands>>();
    const report = (entry: HostReport): void => {
      const key = [entry.code, entry.addon, entry.point ?? '', entry.contribution ?? ''].join('\n');

      if (!reported.has(key)) {
        reported.add(key);
        services.report(entry);
      }
    };
    const once: HostServices = { ...services, report };

    return {
      contributions,
      source,
      services: once,
      text: (addon, key, parameters) => addonTexts(services, addon)(key, parameters),
      report,
      data: (addon, contribution) => dataOf(ext, addon, contribution),
      hostFor: (addon, point, contribution) => {
        const key = `${point}\n${contribution}`;
        const known = hosts.get(key);

        if (known !== undefined) {
          return known;
        }

        const host = createPanelHost(
          once,
          contributions,
          addonOf(contributions, addon),
          addon,
          point,
          contribution,
        );
        hosts.set(key, host);

        return host;
      },
      skippedChecks,
    };
  }, [contributions, ext, source, services, skippedChecks, reported]);

  return <HostRuntimeContext value={runtime}>{children}</HostRuntimeContext>;
}
