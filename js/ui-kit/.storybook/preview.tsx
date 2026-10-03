// What every story of the kit renders in: the kit's stylesheets in the order of its cascade layers,
// the locale and the theme the toolbar sets (KitI18nProvider, and data-theme on the document as a
// page sets it), and axe through the accessibility addon, whose violations fail a story's test
// (a11y.test 'error') in gate 7.

import '../src/layers.css';
import '../src/tokens.css';
import '../src/base.css';

import { KitI18nProvider, KIT_LOCALES } from '@cboxdk/cms-ui-kit';
import type { ReactNode } from 'react';

import { storyLocale, storyTheme, type StoryContext } from '../stories/csf';

function withKit(Story: () => ReactNode, context: StoryContext): ReactNode {
  document.documentElement.dataset['theme'] = storyTheme(context.globals);
  document.documentElement.lang = storyLocale(context.globals);

  return (
    <KitI18nProvider locale={storyLocale(context.globals)}>
      <div style={{ padding: 'var(--cms-space-4)' }}>
        <Story />
      </div>
    </KitI18nProvider>
  );
}

const preview = {
  decorators: [withKit],
  globalTypes: {
    locale: {
      description: 'The locale of the kit',
      toolbar: { icon: 'globe', items: [...KIT_LOCALES], dynamicTitle: true },
    },
    theme: {
      description: 'The theme of the tokens',
      toolbar: { icon: 'contrast', items: ['light', 'dark'], dynamicTitle: true },
    },
  },
  initialGlobals: { locale: 'en', theme: 'light' },
  parameters: {
    a11y: { test: 'error' },
    layout: 'fullscreen',
  },
};

export default preview;
