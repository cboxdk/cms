// What every story of an addon renders in, through the preset @cboxdk/cms-panel/storybook: the
// panel's cascade layers and design tokens, the locale and the theme the toolbar sets
// (KitI18nProvider, and data-theme on the document as the panel sets it), and axe through the
// accessibility addon, whose violations fail a story's test (a11y.test 'error').

import '@cboxdk/cms-ui-kit/layers.css';
import '@cboxdk/cms-ui-kit/tokens.css';
import '@cboxdk/cms-ui-kit/base.css';

import { DEFAULT_KIT_LOCALE, isKitLocale, KIT_LOCALES, KitI18nProvider } from '@cboxdk/cms-ui-kit';
import type { ReactNode } from 'react';

/** The part of a story's context the preview reads: the toolbar's globals. */
export interface PreviewContext {
  readonly globals: Readonly<Record<string, unknown>>;
}

/** The theme a story renders in. */
export type PreviewTheme = 'light' | 'dark';

export function previewLocale(globals: Readonly<Record<string, unknown>>): string {
  const locale = globals['locale'];

  return typeof locale === 'string' && isKitLocale(locale) ? locale : DEFAULT_KIT_LOCALE;
}

export function previewTheme(globals: Readonly<Record<string, unknown>>): PreviewTheme {
  return globals['theme'] === 'dark' ? 'dark' : 'light';
}

export function withPanel(Story: () => ReactNode, context: PreviewContext): ReactNode {
  const locale = previewLocale(context.globals);

  document.documentElement.dataset['theme'] = previewTheme(context.globals);
  document.documentElement.lang = locale;

  return (
    <KitI18nProvider locale={isKitLocale(locale) ? locale : DEFAULT_KIT_LOCALE}>
      <Story />
    </KitI18nProvider>
  );
}

const preview = {
  decorators: [withPanel],
  globalTypes: {
    locale: {
      description: 'The locale of the panel',
      toolbar: { icon: 'globe', items: [...KIT_LOCALES], dynamicTitle: true },
    },
    theme: {
      description: 'The theme of the tokens',
      toolbar: { icon: 'contrast', items: ['light', 'dark'], dynamicTitle: true },
    },
  },
  initialGlobals: { locale: DEFAULT_KIT_LOCALE, theme: 'light' },
  parameters: {
    a11y: { test: 'error' },
  },
};

export default preview;
