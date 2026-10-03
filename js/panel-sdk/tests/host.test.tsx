// The host API: usePanelHost() gives a contribution the host the panel, or a test through
// PanelHostProvider, provides, typed for the commands the addon may issue, and fails with
// PanelHostMissing outside one. The Storybook preview renders a story in the kit's locale and theme.

import assert from 'node:assert/strict';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, test } from 'vitest';

import { PanelHostMissing, usePanelHost, type PanelHost } from '@cboxdk/cms-panel/extend';
import { PanelHostProvider } from '@cboxdk/cms-panel/testing';

import { previewLocale, previewTheme } from '../storybook/preview';

interface Issues {
  readonly 'reviews.request@1': { readonly note: string };
}

function host(): PanelHost<Issues> {
  return {
    locale: 'da',
    t: (key, parameters) => `${key}:${JSON.stringify(parameters ?? {})}`,
    formatDate: (value) => value.toISOString(),
    formatNumber: (value) => String(value),
    formatList: (values) => values.join(', '),
    notify: () => undefined,
    navigate: () => undefined,
    runCommand: () => Promise.reject(new Error('not issued in this test')),
    openDialog: () => Promise.resolve(false),
  };
}

function Greeting() {
  const panel = usePanelHost<Issues>();

  return <p lang={panel.locale}>{panel.t('reviews.hello', { name: 'Ada' })}</p>;
}

describe('usePanelHost()', () => {
  test('gives the host the provider holds', () => {
    assert.equal(
      renderToStaticMarkup(
        <PanelHostProvider host={host()}>
          <Greeting />
        </PanelHostProvider>,
      ),
      '<p lang="da">reviews.hello:{&quot;name&quot;:&quot;Ada&quot;}</p>',
    );
  });

  test('fails outside the panel with PanelHostMissing', () => {
    assert.throws(() => renderToStaticMarkup(<Greeting />), PanelHostMissing);
  });
});

describe('the Storybook preview', () => {
  test('reads the locale and the theme of the toolbar, with the kit defaults', () => {
    assert.equal(previewLocale({ locale: 'da' }), 'da');
    assert.equal(previewLocale({ locale: 'de' }), 'en');
    assert.equal(previewTheme({ theme: 'dark' }), 'dark');
    assert.equal(previewTheme({}), 'light');
  });
});
