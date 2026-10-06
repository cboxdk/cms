// The fake host of @cboxdk/cms-panel/testing is held to the panel's real host (section 7 of the
// panel extension architecture), as this repository holds the fake of a port to its
// implementation: one set of behaviours runs against the host the panel builds for a
// contribution and against the fake an addon's test builds, and each must do the same with the
// same texts, pages and commands.

import {
  createFakeHost,
  PanelCommandRefused as FakeCommandRefused,
  type FakeHost,
} from '@cboxdk/cms-panel/testing';
import type { PanelHost } from '@cboxdk/cms-panel/extend';
import { describe, expect, test } from 'vitest';

import { createPanelHost, PanelCommandRefused, type AnyCommands } from '../../src/host/panel-host';
import { contributions, services } from './harness';

/** What a host under test was asked, in the form both hosts can give. */
interface Observed {
  readonly visits: readonly string[];
  readonly notices: readonly string[];
  readonly commands: readonly {
    readonly command: string;
    readonly document: object;
    readonly options: object;
  }[];
  readonly dialogs: readonly {
    readonly title: string;
    readonly body: string;
    readonly confirm: string;
    readonly tone: string;
  }[];
  readonly refusals: readonly string[];
}

/** A host of the addon alpha, which may issue alpha.request@1 and reach the page home at /cms. */
interface Subject {
  readonly host: PanelHost<AnyCommands>;
  readonly observed: () => Observed;
}

const TEXTS = {
  'alpha.count': '{count} pending',
  'alpha.saved': 'Saved {what}',
  'alpha.sure': 'Sure about {what}?',
  'beta.secret': 'Not yours',
} as const;

const SUBJECTS: Readonly<Record<string, () => Subject>> = {
  'the panel s host': () => {
    const { services: built, recorded } = services(TEXTS);
    const dialogs: Observed['dialogs'][number][] = [];
    const page = contributions([], { alpha: [] });
    const host = createPanelHost(
      {
        ...built,
        confirm: (dialog) => {
          dialogs.push(dialog);

          return Promise.resolve(true);
        },
      },
      page,
      page.addons.find((addon) => addon.addon === 'alpha'),
      'alpha',
      'desk.cards@1',
      'alpha.card',
    );

    return {
      host,
      observed: () => ({
        visits: recorded.visits,
        notices: recorded.notices,
        commands: recorded.commands,
        dialogs,
        refusals: recorded.reports.map((report) => report.code),
      }),
    };
  },
  'the fake host': () => {
    const host: FakeHost = createFakeHost({
      namespace: 'alpha',
      contribution: 'alpha.card',
      texts: TEXTS,
      issues: ['alpha.request@1'],
      pages: { home: '/cms' },
      answer: () => Promise.reject(new Error('No command runs in this test.')),
    });

    return {
      host,
      observed: () => ({
        visits: host.record.visits,
        notices: host.record.notices.map((notice) => notice.message),
        commands: host.record.commands,
        dialogs: host.record.dialogs,
        refusals: host.record.refusals.map((refusal) => refusal.code),
      }),
    };
  },
};

describe.each(Object.entries(SUBJECTS))('%s', (_name, subject) => {
  test('reads texts of its addon s catalogue only, and fills in their parameters', () => {
    const { host } = subject();

    expect(host.locale).toBe('en');
    expect(host.t('alpha.count', { count: 3 })).toBe('3 pending');
    expect(host.t('alpha.count')).toBe('{count} pending');
    expect(host.t('beta.secret')).toBe('beta.secret');
    expect(host.t('alpha.missing')).toBe('alpha.missing');
  });

  test('formats in the panel s locale', () => {
    const { host } = subject();

    expect(host.formatNumber(1234.5)).toBe('1,234.5');
    expect(host.formatList(['a', 'b', 'c'])).toBe('a, b, and c');
    expect(host.formatDate(new Date(Date.UTC(2026, 9, 4)), { timeZone: 'UTC' })).toBe('10/4/2026');
  });

  test('navigates only to the panel s pages, with the parameters as the query, and reports the rest', () => {
    const { host, observed } = subject();

    host.navigate('home', { tab: 'grants' });
    host.navigate('home');
    host.navigate('elsewhere');

    expect(observed().visits).toEqual(['/cms?tab=grants', '/cms']);
    expect(observed().refusals).toEqual(['panel_navigation_refused']);
  });

  test('shows a notice with its text in the locale', () => {
    const { host, observed } = subject();

    host.notify({ tone: 'success', message: 'alpha.saved', parameters: { what: 'the note' } });

    expect(observed().notices).toEqual(['Saved the note']);
  });

  test('issues only the commands its addon may issue, each with its document and options', async () => {
    const { host, observed } = subject();

    await expect(host.runCommand('grant.assign@1', {})).rejects.toSatisfy(
      (error: unknown) =>
        error instanceof PanelCommandRefused || error instanceof FakeCommandRefused,
    );
    await expect(host.runCommand('alpha.request@1', { note: 'n' })).rejects.toThrow(
      'No command runs in this test.',
    );
    await expect(
      host.runCommand('alpha.request@1', { note: 'n' }, { dryRun: true, waitLevel: 'origin' }),
    ).rejects.toThrow('No command runs in this test.');

    expect(observed().commands).toEqual([
      {
        command: 'alpha.request@1',
        document: { note: 'n' },
        options: {},
        provenance: 'addon:alpha:alpha.card',
      },
      {
        command: 'alpha.request@1',
        document: { note: 'n' },
        options: { dryRun: true, waitLevel: 'origin' },
        provenance: 'addon:alpha:alpha.card',
      },
    ]);
    expect(observed().refusals).toEqual(['panel_command_refused']);
  });

  test('opens a dialog with its texts in the locale, neutral unless the contribution says danger', async () => {
    const { host, observed } = subject();

    await expect(
      host.openDialog({
        title: 'alpha.sure',
        body: 'alpha.body',
        confirm: 'alpha.yes',
        parameters: { what: 'this' },
      }),
    ).resolves.toBe(true);
    await expect(
      host.openDialog({
        title: 'alpha.sure',
        body: 'alpha.body',
        confirm: 'alpha.yes',
        tone: 'danger',
      }),
    ).resolves.toBe(true);

    expect(observed().dialogs).toEqual([
      { title: 'Sure about this?', body: 'alpha.body', confirm: 'alpha.yes', tone: 'neutral' },
      { title: 'Sure about {what}?', body: 'alpha.body', confirm: 'alpha.yes', tone: 'danger' },
    ]);
  });
});
