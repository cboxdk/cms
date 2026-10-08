// The flow runner (section 3.8 of the panel extension architecture): steps run in order; a step may
// patch only the paths its manifest declares, below ext.<namespace> of its addon, and the runner
// refuses and reports any other patch; the core's own confirmation runs after every step and is
// the only way the flow ends with its draft; the first cancel, a throw or a timeout stops the flow
// in the step's addon's name.

import { describe, expect, test } from 'vitest';

import { pathSegments } from '../../src/forms/field-path';
import { FlowRun, patched, type FlowStepEntry, type FlowTimers } from '../../src/host/flow';
import type { HostReport } from '../../src/host/reports';

function step(
  addon: string,
  id: string,
  patches: readonly string[] = [],
  timeoutSeconds = 30,
): FlowStepEntry {
  return { addon, contribution: id, patches, timeoutSeconds };
}

/** Timers that run only when the test fires them. */
function manualTimers() {
  const pending = new Map<number, () => void>();
  let next = 0;
  const timers: FlowTimers = {
    set: (callback) => {
      next += 1;
      pending.set(next, callback);

      return next;
    },
    clear: (timer) => {
      pending.delete(timer as number);
    },
  };

  return {
    timers,
    fire: () => {
      for (const [key, callback] of [...pending]) {
        pending.delete(key);
        callback();
      }
    },
  };
}

function flow(
  steps: readonly FlowStepEntry[],
  position: 'before_submit' | 'after_receipt' = 'before_submit',
) {
  const reports: Omit<HostReport, 'point'>[] = [];
  const { timers, fire } = manualTimers();
  const run = new FlowRun({
    steps,
    draft: { command: 'grant.assign', fields: { ext: { approvals: {} }, title: 'Draft' } },
    position,
    report: (report) => reports.push(report),
    timers,
  });

  return { run, reports, fire };
}

describe('the flow runner', () => {
  test('refuses a patch outside the step s own paths below ext.<namespace>, and keeps its own', () => {
    const { run, reports } = flow([
      step('approvals', 'approvals.four-eyes', ['fields.ext.approvals.reason']),
    ]);
    const controls = run.controls(0);

    controls.patch('fields.title', 'Hijacked');
    controls.patch('fields.ext.other.reason', 'Not mine');
    controls.patch('fields.ext.approvals.reason', 'Asked Ada');

    expect(run.state.draft).toEqual({
      command: 'grant.assign',
      fields: { ext: { approvals: { reason: 'Asked Ada' } }, title: 'Draft' },
    });
    expect(reports).toEqual([
      { code: 'panel_step_patch_refused', addon: 'approvals', contribution: 'approvals.four-eyes' },
      { code: 'panel_step_patch_refused', addon: 'approvals', contribution: 'approvals.four-eyes' },
    ]);
  });

  test('runs the core s confirmation after every step, and ends only through it', () => {
    const { run } = flow([
      step('alpha', 'alpha.one'),
      step('beta', 'beta.two'),
      step('gamma', 'gamma.three'),
    ]);
    const phases: string[] = [run.state.phase];

    for (const index of [0, 1, 2]) {
      expect(run.confirm()).toBeUndefined();
      run.controls(index).next();
      phases.push(run.state.phase);
    }

    expect(phases).toEqual(['step', 'step', 'step', 'confirm']);
    // A step whose turn has passed cannot skip the confirmation.
    run.controls(2).next();
    expect(run.state.phase).toBe('confirm');
    expect(run.confirm()).toEqual({
      command: 'grant.assign',
      fields: { ext: { approvals: {} }, title: 'Draft' },
    });
    expect(run.state.phase).toBe('done');
  });

  test('starts at the core s confirmation when there is no step, and ends after the receipt without one', () => {
    expect(flow([]).run.state.phase).toBe('confirm');
    expect(flow([], 'after_receipt').run.state.phase).toBe('done');

    const after = flow([step('alpha', 'alpha.follow-up')], 'after_receipt').run;
    after.controls(0).next();
    expect(after.state.phase).toBe('done');
  });

  test('stops at the first cancel in its addon s name, and a step out of turn changes nothing', () => {
    const { run } = flow([step('alpha', 'alpha.one'), step('beta', 'beta.two')]);

    run.controls(1).cancel('beta.not-yet');
    expect(run.state.phase).toBe('step');
    run.controls(0).cancel('alpha.stop');

    expect(run.state).toMatchObject({
      phase: 'cancelled',
      addon: 'alpha',
      contribution: 'alpha.one',
      cause: 'cancelled',
      reason: 'alpha.stop',
    });
    run.controls(0).next();
    expect(run.state.phase).toBe('cancelled');
  });

  test('a step that throws or runs past its timeout cancels in its addon s name, and is reported', () => {
    const failing = flow([step('alpha', 'alpha.one')]);
    failing.run.controls(0).fail();
    expect(failing.run.state).toMatchObject({
      phase: 'cancelled',
      addon: 'alpha',
      cause: 'failed',
    });
    expect(failing.reports).toEqual([
      { code: 'panel_step_failed', addon: 'alpha', contribution: 'alpha.one' },
    ]);

    const slow = flow([step('beta', 'beta.slow', [], 5)]);
    slow.fire();
    expect(slow.run.state).toMatchObject({ phase: 'cancelled', addon: 'beta', cause: 'timed_out' });
    expect(slow.reports).toEqual([
      { code: 'panel_step_timed_out', addon: 'beta', contribution: 'beta.slow' },
    ]);
  });

  test('reads and writes paths as FieldPath::toString() writes them', () => {
    expect(pathSegments('fields.items[2].ext.a')).toEqual(['fields', 'items', 2, 'ext', 'a']);
    expect(patched({ fields: {} }, 'fields.items[0].name', 'One')).toEqual({
      fields: { items: [{ name: 'One' }] },
    });
  });

  test('places nothing for a path that names an item of a list by its key', () => {
    expect(
      patched({ fields: { items: [{ name: 'Two' }] } }, 'fields.items[#k3f9].name', 'One'),
    ).toEqual({ fields: { items: [{ name: 'Two' }] } });
  });
});
