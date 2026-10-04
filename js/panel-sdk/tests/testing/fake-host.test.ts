// The fake host of @cboxdk/cms-panel/testing: texts of the addon's own catalogue only, formatting
// in the locale, notices, dialogs, navigation to the pages given and the commands the addon may
// issue, each recorded; a command the addon may not issue is refused as the panel refuses it. Its
// answers are receipts and problem details the generated validators accept. The behaviour test in
// js/panel/tests/host holds it to the panel's real host.

import { describe, expect, test } from 'vitest';

import { validateProblemV1 } from '../../src/generated/protocol/ProblemV1';
import { validateReceiptV1 } from '../../src/generated/protocol/ReceiptV1';
import {
  committedReceipt,
  createFakeHost,
  dryRunReceipt,
  fillText,
  PanelCommandRefused,
  rejectedProblem,
} from '../../src/testing';

describe('createFakeHost()', () => {
  test('reads texts of its addon s catalogue only, and fills in their parameters', () => {
    const host = createFakeHost({
      namespace: 'alpha',
      texts: { 'alpha.count': '{count} pending', 'beta.secret': 'Not yours' },
    });

    expect(host.t('alpha.count', { count: 3 })).toBe('3 pending');
    expect(host.t('beta.secret')).toBe('beta.secret');
    expect(host.t('alpha.missing')).toBe('alpha.missing');
    expect(host.locale).toBe('en');
    expect(host.formatNumber(1234.5)).toBe('1,234.5');
    expect(host.formatList(['a', 'b', 'c'])).toBe('a, b, and c');
    expect(host.formatDate(new Date(Date.UTC(2026, 9, 4)), { timeZone: 'UTC' })).toBe('10/4/2026');
    expect(fillText('{a} and {b}', { a: 1 })).toBe('1 and {b}');
  });

  test('records notices and dialogs in the locale, and answers a dialog as the test says', async () => {
    const host = createFakeHost({
      namespace: 'alpha',
      texts: { 'alpha.saved': 'Saved {what}', 'alpha.sure': 'Sure?' },
      confirm: (dialog) => dialog.tone === 'danger',
    });

    host.notify({ tone: 'success', message: 'alpha.saved', parameters: { what: 'it' } });

    await expect(
      host.openDialog({ title: 'alpha.sure', body: 'alpha.body', confirm: 'alpha.yes' }),
    ).resolves.toBe(false);
    await expect(
      host.openDialog({
        title: 'alpha.sure',
        body: 'alpha.body',
        confirm: 'alpha.yes',
        tone: 'danger',
      }),
    ).resolves.toBe(true);

    expect(host.record.notices).toEqual([{ tone: 'success', message: 'Saved it' }]);
    expect(host.record.dialogs).toEqual([
      { title: 'Sure?', body: 'alpha.body', confirm: 'alpha.yes', tone: 'neutral' },
      { title: 'Sure?', body: 'alpha.body', confirm: 'alpha.yes', tone: 'danger' },
    ]);
  });

  test('navigates only to the pages given, and refuses the rest', () => {
    const host = createFakeHost({ pages: { home: '/cms' } });

    host.navigate('home', { tab: 'grants' });
    host.navigate('home');
    host.navigate('elsewhere');

    expect(host.record.visits).toEqual(['/cms?tab=grants', '/cms']);
    expect(host.record.refusals).toEqual([
      { code: 'panel_navigation_refused', subject: 'elsewhere' },
    ]);
  });

  test('runs only the commands the addon may issue, with the answer the test gives', async () => {
    const host = createFakeHost({
      namespace: 'alpha',
      issues: ['alpha.request@1'],
      answer: (command) =>
        command.options.dryRun === true
          ? dryRunReceipt()
          : rejectedProblem('validation_failed', [{ code: 'validation_required', field: 'note' }]),
    });

    await expect(host.runCommand('grant.assign@1', {})).rejects.toBeInstanceOf(PanelCommandRefused);

    const rejected = await host.runCommand('alpha.request@1', { note: 'n' });
    const dry = await host.runCommand('alpha.request@1', { note: 'n' }, { dryRun: true });

    expect(rejected.receipt.outcome).toBe('rejected');
    expect(rejected.problem?.errors).toEqual([
      {
        code: 'validation_required',
        detail: 'The input was refused with validation_required.',
        field: 'note',
      },
    ]);
    expect(dry.receipt.outcome).toBe('dry_run');
    expect(host.record.commands).toEqual([
      { command: 'alpha.request@1', document: { note: 'n' }, options: {} },
      { command: 'alpha.request@1', document: { note: 'n' }, options: { dryRun: true } },
    ]);
    expect(host.record.refusals).toEqual([
      { code: 'panel_command_refused', subject: 'grant.assign@1' },
    ]);

    const core = createFakeHost({ namespace: 'cms' });
    await expect(core.runCommand('grant.assign@1', {})).resolves.toEqual(committedReceipt());
  });
});

describe('the fixtures', () => {
  test('are receipts and problem details the generated validators accept', () => {
    for (const answer of [
      committedReceipt(),
      committedReceipt({ wait_level: 'origin' }),
      dryRunReceipt(),
      rejectedProblem('unauthorized'),
      rejectedProblem('validation_failed', [
        { code: 'validation_required', field: 'fields.title' },
      ]),
    ]) {
      expect(validateReceiptV1(answer.receipt)).toMatchObject({ valid: true });

      if (answer.problem !== null) {
        expect(validateProblemV1(answer.problem)).toMatchObject({ valid: true });
      }
    }

    const problem = rejectedProblem('version_conflict').problem;

    expect(problem?.status).toBe(409);
    expect(problem?.retryable).toBe(false);
    expect(rejectedProblem('idempotency_in_flight').problem?.retryable).toBe(true);
  });
});
