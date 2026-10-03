// Observers (section 3.9 of the panel extension architecture): read-only functions of an event,
// called in their render order after it happened, on a frozen copy, so they cannot affect the flow
// or each other. A throw is caught and reported with the observer's addon.

import type { Observer } from '@cboxdk/cms-panel/extend';

import { frozenCopy } from './checks';
import type { HostReport } from './reports';

/** An observer, with whose it is. */
export interface ObserverEntry {
  readonly addon: string;
  readonly contribution: string;
  readonly observer: Observer<object>;
}

/** Calls each observer with the event, in order, and reports each that throws. */
export function notifyObservers(
  observers: readonly ObserverEntry[],
  event: object,
  report: (report: Omit<HostReport, 'point'>) => void,
): void {
  const frozen = frozenCopy(event);

  for (const entry of observers) {
    try {
      entry.observer(frozen);
    } catch {
      report({
        code: 'panel_observer_failed',
        addon: entry.addon,
        contribution: entry.contribution,
      });
    }
  }
}
