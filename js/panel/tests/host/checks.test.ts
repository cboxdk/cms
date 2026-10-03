// The check runner (section 3.7 of the panel extension architecture): each check runs on a frozen
// copy of the document within 16 ms; a check that runs over is skipped for the session and its
// overrun is reported with its addon, as is a check that throws; an asynchronous check gets 300 ms
// and is cancelled by the next edit. Issues are concatenated and sorted by path, then addon, are in
// their addon's namespace, weigh at most what the check declares, and an acknowledge issue holds
// the submit until it is ticked.

import { describe, expect, test, vi } from 'vitest';

import {
  CHECK_BUDGET_MILLISECONDS,
  issueKey,
  maySubmit,
  runChecks,
  type CheckEnvironment,
  type FormCheckEntry,
} from '../../src/host/checks';
import type { HostReport } from '../../src/host/reports';

/** An environment whose clock moves only when a check advances it. */
function environment() {
  let clock = 0;
  const reports: Omit<HostReport, 'point'>[] = [];
  const env: CheckEnvironment = {
    context: { locale: 'en' },
    skipped: new Set<string>(),
    report: (report) => reports.push(report),
    now: () => clock,
  };

  return {
    env,
    reports,
    advance: (milliseconds: number) => {
      clock += milliseconds;
    },
  };
}

function check(
  addon: string,
  id: string,
  run: FormCheckEntry['check'],
  severity: FormCheckEntry['severity'] = 'warning',
): FormCheckEntry {
  return { addon, contribution: id, severity, check: run };
}

describe('the check runner', () => {
  test('skips a check over 16 ms for the session and reports it with its addon', () => {
    const { env, reports, advance } = environment();
    let runs = 0;
    const slow = check('slow', 'slow.check', () => {
      runs += 1;
      advance(CHECK_BUDGET_MILLISECONDS + 1);

      return [
        { path: 'fields.title', code: 'slow.found', severity: 'warning', message: 'slow.found' },
      ];
    });
    const quick = check('quick', 'quick.check', () => {
      advance(CHECK_BUDGET_MILLISECONDS);

      return [
        { path: 'fields.title', code: 'quick.found', severity: 'warning', message: 'quick.found' },
      ];
    });

    const first = runChecks([slow, quick], { fields: { title: '' } }, env).now;
    const second = runChecks([slow, quick], { fields: { title: '' } }, env).now;

    expect(first.issues.map((issue) => issue.code)).toEqual(['quick.found']);
    expect(second.issues.map((issue) => issue.code)).toEqual(['quick.found']);
    expect(runs).toBe(1);
    expect(reports).toEqual([
      { code: 'panel_check_over_budget', addon: 'slow', contribution: 'slow.check' },
    ]);
  });

  test('skips a check that throws for the session, and runs the others', () => {
    const { env, reports } = environment();
    const failing = check('bad', 'bad.check', () => {
      throw new Error('Broken.');
    });
    const good = check('good', 'good.check', () => [
      { path: 'note', code: 'good.note', severity: 'info', message: 'good.note' },
    ]);

    expect(runChecks([failing, good], {}, env).now.issues.map((issue) => issue.code)).toEqual([
      'good.note',
    ]);
    expect([...env.skipped]).toEqual(['bad.check']);
    expect(reports).toEqual([
      { code: 'panel_check_failed', addon: 'bad', contribution: 'bad.check' },
    ]);
  });

  test('hands each check a frozen copy, so it cannot change the document', () => {
    const { env } = environment();
    const document = { fields: { title: 'Draft' } };
    const mutating = check('alpha', 'alpha.mutate', (frozen) => {
      (frozen as { fields: { title: string } }).fields.title = 'Changed';

      return [];
    });

    runChecks([mutating], document, env);

    expect(document.fields.title).toBe('Draft');
    expect([...env.skipped]).toEqual(['alpha.mutate']);
  });

  test('sorts issues by path, then addon, caps them at the check s severity and refuses codes of other namespaces', () => {
    const { env, reports } = environment();
    const run = runChecks(
      [
        check('zeta', 'zeta.check', () => [
          { path: 'fields.a', code: 'zeta.a', severity: 'error', message: 'zeta.a' },
          { path: 'fields.b', code: 'alpha.not-mine', severity: 'warning', message: 'zeta.b' },
        ]),
        check(
          'alpha',
          'alpha.check',
          () => [{ path: 'fields.a', code: 'alpha.a', severity: 'error', message: 'alpha.a' }],
          'error',
        ),
        check(
          'beta',
          'beta.check',
          () => [
            {
              path: 'fields.0',
              code: 'beta.first',
              severity: 'acknowledge',
              message: 'beta.first',
            },
          ],
          'acknowledge',
        ),
      ],
      {},
      env,
    ).now;

    expect(run.issues.map((issue) => [issue.path, issue.code, issue.severity])).toEqual([
      ['fields.0', 'beta.first', 'acknowledge'],
      ['fields.a', 'alpha.a', 'error'],
      ['fields.a', 'zeta.a', 'warning'],
    ]);
    expect(run.blocking.map((issue) => issue.code)).toEqual(['alpha.a']);
    expect(reports).toEqual([
      { code: 'panel_check_issue_refused', addon: 'zeta', contribution: 'zeta.check' },
    ]);
  });

  test('holds the submit until every acknowledge issue is ticked, and for an error that only a mirrored check gives', () => {
    const { env } = environment();
    const acknowledge = runChecks(
      [
        check(
          'alpha',
          'alpha.ack',
          () => [
            { path: 'reason', code: 'alpha.ack', severity: 'acknowledge', message: 'alpha.ack' },
          ],
          'acknowledge',
        ),
      ],
      {},
      env,
    ).now;
    const blocking = runChecks(
      [
        check(
          'beta',
          'beta.mirror',
          () => [{ path: 'reason', code: 'beta.no', severity: 'error', message: 'beta.no' }],
          'error',
        ),
      ],
      {},
      env,
    ).now;
    const ticked = new Set(acknowledge.acknowledge.map(issueKey));

    expect(maySubmit(acknowledge, new Set())).toBe(false);
    expect(maySubmit(acknowledge, ticked)).toBe(true);
    expect(maySubmit(blocking, ticked)).toBe(false);
  });

  test('gives an asynchronous check 300 ms, and cancels it on the next edit', async () => {
    vi.useFakeTimers();

    try {
      const { env, reports } = environment();
      const answering = check('quick', 'quick.async', () =>
        Promise.resolve([{ path: 'a', code: 'quick.a', severity: 'info', message: 'quick.a' }]),
      );
      const hanging = check('slow', 'slow.async', () => new Promise(() => undefined));
      const runs = runChecks([answering, hanging], {}, env);
      await vi.advanceTimersByTimeAsync(301);

      expect((await runs.settled).issues.map((issue) => issue.code)).toEqual(['quick.a']);
      expect(reports).toEqual([
        { code: 'panel_check_over_budget', addon: 'slow', contribution: 'slow.async' },
      ]);

      const edits = new AbortController();
      let signal: AbortSignal | undefined;
      const cancelled = runChecks(
        [
          check('quick', 'quick.watch', (_document, context) => {
            signal = context.signal;

            return new Promise((resolve) =>
              setTimeout(() => {
                resolve([{ path: 'b', code: 'quick.b', severity: 'info', message: 'quick.b' }]);
              }, 100),
            );
          }),
        ],
        {},
        env,
        edits.signal,
      );
      edits.abort();
      await vi.advanceTimersByTimeAsync(100);

      expect(signal?.aborted).toBe(true);
      expect((await cancelled.settled).issues).toEqual([]);
      expect(env.skipped.has('quick.watch')).toBe(false);
    } finally {
      vi.useRealTimers();
    }
  });
});
