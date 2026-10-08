// @vitest-environment jsdom

// The texts of an addon's contributions (section 2.6 of the panel extension architecture): the
// page carries the catalogue of the active locale for every addon with an active contribution, as
// cms:build compiled it, and the host serves those texts through HostServices.text, so
// usePanelHost().t(key) of a contribution gives the text and not the key. A key outside the
// addon's namespace, or one its catalogue has not, still shows as the key.

import { KitI18nProvider } from '@cboxdk/cms-ui-kit';
import { render, screen } from '@testing-library/react';
import { expect, test } from 'vitest';

import { PanelRuntime } from '../../src/host/PanelRuntime';
import { useHostRuntime } from '../../src/host/runtime';
import { contributions, fill, point } from './harness';

/** The catalogue of the active locale the server sends for the addon. */
const TEXTS = [
  {
    addon: 'fixtureaddon',
    entries: [
      { key: 'fixtureaddon.my_articles.title', text: 'Mine artikler' },
      { key: 'fixtureaddon.recent_activity.title', text: 'Seneste aktivitet' },
    ],
  },
];

/** Reads texts through the runtime, as a contribution's host does. */
function Probe({ keys }: { readonly keys: readonly (readonly [string, string])[] }) {
  const runtime = useHostRuntime();

  return (
    <ul>
      {keys.map(([addon, key]) => (
        <li key={`${addon} ${key}`} data-testid={`${addon} ${key}`}>
          {runtime.text(addon, key)}
        </li>
      ))}
    </ul>
  );
}

function renderRuntime(keys: readonly (readonly [string, string])[]) {
  const document = contributions(
    [point('account.me.sections@1', [fill('fixtureaddon', 'fixtureaddon.recent-activity', 10)])],
    { fixtureaddon: ['fixtureaddon.recent-activity'] },
    { texts: TEXTS },
  );

  return render(
    <KitI18nProvider locale="en">
      <PanelRuntime props={{ cms: { contributions: document } }}>
        <Probe keys={keys} />
      </PanelRuntime>
    </KitI18nProvider>,
  );
}

test('the host gives an addon the text of its catalogue in the active locale', () => {
  renderRuntime([
    ['fixtureaddon', 'fixtureaddon.recent_activity.title'],
    ['fixtureaddon', 'fixtureaddon.my_articles.title'],
  ]);

  expect(screen.getByTestId('fixtureaddon fixtureaddon.recent_activity.title').textContent).toBe(
    'Seneste aktivitet',
  );
  expect(screen.getByTestId('fixtureaddon fixtureaddon.my_articles.title').textContent).toBe(
    'Mine artikler',
  );
});

test('a key the addon has no text for, and one outside its namespace, show as the key', () => {
  renderRuntime([
    ['fixtureaddon', 'fixtureaddon.unknown.title'],
    ['fixtureaddon', 'panel.host.cancel'],
  ]);

  expect(screen.getByTestId('fixtureaddon fixtureaddon.unknown.title').textContent).toBe(
    'fixtureaddon.unknown.title',
  );
  expect(screen.getByTestId('fixtureaddon panel.host.cancel').textContent).toBe(
    'panel.host.cancel',
  );
});

test("the core's own contributions read the panel's own catalogue", () => {
  renderRuntime([['cms', 'panel.host.cancel']]);

  expect(screen.getByTestId('cms panel.host.cancel').textContent).toBe('Cancel');
});
