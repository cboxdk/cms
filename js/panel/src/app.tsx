// The entry of the panel: Inertia resolves each page the server names from ./pages, and every
// page renders inside the translations of the locale the server set on <html lang>.

import '@cboxdk/cms-ui-kit/tokens.css';

import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { createRoot } from 'react-dom/client';

import { localeOf, TranslationProvider } from './i18n/translations';
import { MissingPage } from './MissingPage';

type PageModule = { readonly default: ComponentType };

const pages = import.meta.glob<PageModule>('./pages/**/*.tsx', { eager: true });

function resolvePage(name: string): ComponentType {
  const page = pages[`./pages/${name}.tsx`];

  if (page !== undefined) {
    return page.default;
  }

  return function Missing() {
    return <MissingPage page={name} />;
  };
}

void createInertiaApp({
  resolve: resolvePage,
  setup({ el, App, props }) {
    createRoot(el).render(
      <TranslationProvider locale={localeOf(document.documentElement.lang)}>
        <App {...props} />
      </TranslationProvider>,
    );
  },
});
