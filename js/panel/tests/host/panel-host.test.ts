// The host API a contribution gets (section 3.12 of the panel extension architecture): texts of
// its own addon's catalogue, scoped to its namespace; formatting in the panel's locale; notices;
// navigation only to the panel's pages the server named; and only the commands its addon may issue,
// each with the provenance addon:<namespace>:<contribution>.

import { describe, expect, test } from 'vitest';

import { createPanelHost, PanelCommandRefused } from '../../src/host/panel-host';
import { contributions, services } from './harness';

describe('a contribution s host', () => {
  test('reads texts of its addon s catalogue only, and fills in their parameters', () => {
    const { services: built } = services({
      'alpha.count': '{count} pending',
      'beta.secret': 'Not yours',
    });
    const host = createPanelHost(
      built,
      contributions([], {}),
      undefined,
      'alpha',
      'desk.cards@1',
      'alpha.card',
    );

    expect(host.t('alpha.count', { count: 3 })).toBe('3 pending');
    expect(host.t('beta.secret')).toBe('beta.secret');
    expect(host.t('alpha.missing')).toBe('alpha.missing');
    expect(host.formatNumber(1234.5)).toBe('1,234.5');
    expect(host.formatList(['a', 'b', 'c'])).toBe('a, b, and c');
  });

  test('issues only the commands its addon may issue, and any for the core s own', async () => {
    const { services: built, recorded } = services();
    const page = contributions([], { alpha: [], cms: [] });
    const alpha = createPanelHost(
      built,
      page,
      page.addons.find((addon) => addon.addon === 'alpha'),
      'alpha',
      'desk.cards@1',
      'alpha.card',
    );
    const core = createPanelHost(
      built,
      page,
      page.addons.find((addon) => addon.addon === 'cms'),
      'cms',
      'desk.cards@1',
      'cms.card',
    );

    await expect(alpha.runCommand('grant.assign@1', {})).rejects.toBeInstanceOf(
      PanelCommandRefused,
    );
    await expect(alpha.runCommand('alpha.request@1', { note: 'n' })).rejects.toThrow(
      'No command runs in this test.',
    );
    await expect(core.runCommand('grant.assign@1', {}, { dryRun: true })).rejects.toThrow(
      'No command runs in this test.',
    );

    // An addon's call carries the provenance of the contribution that issued it; the core's none.
    expect(recorded.commands).toEqual([
      {
        command: 'alpha.request@1',
        document: { note: 'n' },
        options: {},
        provenance: 'addon:alpha:alpha.card',
      },
      { command: 'grant.assign@1', document: {}, options: { dryRun: true } },
    ]);
    expect(recorded.reports).toEqual([
      {
        code: 'panel_command_refused',
        addon: 'alpha',
        point: 'desk.cards@1',
        contribution: 'alpha.card',
      },
    ]);
  });

  test('navigates only to the panel s pages the server named, and notifies in the panel s locale', () => {
    const { services: built, recorded } = services({ 'alpha.saved': 'Saved {what}' });
    const host = createPanelHost(
      built,
      contributions([], {}),
      undefined,
      'alpha',
      'desk.cards@1',
      'alpha.card',
    );

    host.navigate('home', { tab: 'grants' });
    host.navigate('elsewhere');
    host.notify({ tone: 'success', message: 'alpha.saved', parameters: { what: 'the note' } });

    expect(recorded.visits).toEqual(['/cms?tab=grants']);
    expect(recorded.notices).toEqual(['Saved the note']);
    expect(recorded.reports).toEqual([
      {
        code: 'panel_navigation_refused',
        addon: 'alpha',
        point: 'desk.cards@1',
        contribution: 'alpha.card',
      },
    ]);
  });
});
