// Observers (section 3.9 of the panel extension architecture): each is called in order with a
// frozen copy of the event after it happened; one that throws is reported and the others still
// run, and none can change the event.

import { describe, expect, test } from 'vitest';

import { notifyObservers } from '../../src/host/observers';
import type { HostReport } from '../../src/host/reports';

describe('the observers', () => {
  test('are called in order with a frozen event, past one that throws', () => {
    const seen: string[] = [];
    const reports: Omit<HostReport, 'point'>[] = [];
    const event = { name: 'grant.assign', outcome: 'committed' };

    notifyObservers(
      [
        {
          addon: 'alpha',
          contribution: 'alpha.log',
          observer: (received) =>
            seen.push(`alpha ${JSON.stringify(received)} ${String(Object.isFrozen(received))}`),
        },
        {
          addon: 'beta',
          contribution: 'beta.bad',
          observer: (received) => {
            (received as { outcome: string }).outcome = 'rejected';
          },
        },
        { addon: 'gamma', contribution: 'gamma.log', observer: () => seen.push('gamma') },
      ],
      event,
      (report) => reports.push(report),
    );

    expect(seen).toEqual(['alpha {"name":"grant.assign","outcome":"committed"} true', 'gamma']);
    expect(event.outcome).toBe('committed');
    expect(reports).toEqual([
      { code: 'panel_observer_failed', addon: 'beta', contribution: 'beta.bad' },
    ]);
  });
});
