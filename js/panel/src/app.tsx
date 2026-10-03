// The entry of the panel: Inertia resolves each page the server names from ./pages, and every
// page renders inside the translations of the locale the server set on <html lang>, the panel's
// and the kit's. Every title ends with the installation's product name, which the server writes
// into the application-name meta element when cbox-cms.panel.branding sets one. The kit's stylesheets come first, layers.css before any other, so the order of
// the cascade layers is declared before a rule of any layer.
//
// The panel runs under a strict Content-Security-Policy with a nonce per response (GUARDRAILS 6).
// The server puts the nonce on a meta element, and Inertia gets it here for the style elements it
// adds, such as the progress bar's; Vite's preload helper reads the same element.

import '@cboxdk/cms-ui-kit/layers.css';
import '@cboxdk/cms-ui-kit/tokens.css';
import '@cboxdk/cms-ui-kit/base.css';

import { KitI18nProvider } from '@cboxdk/cms-ui-kit';
import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType } from 'react';
import { createRoot } from 'react-dom/client';

import { applicationName } from './brand';
import { localeOf, TranslationProvider, translator } from './i18n/translations';
import { MissingPage } from './MissingPage';

type PageModule = { readonly default: ComponentType };

const pages = import.meta.glob<PageModule>('./pages/**/*.tsx', { eager: true });

const locale = localeOf(document.documentElement.lang);

const t = translator(locale);

/** The product name every title ends with: the installation's, or the panel's own. */
const name = applicationName() ?? t('panel.name');

function resolvePage(name: string): ComponentType {
  const page = pages[`./pages/${name}.tsx`];

  if (page !== undefined) {
    return page.default;
  }

  return function Missing() {
    return <MissingPage page={name} />;
  };
}

/** The nonce of this response's Content-Security-Policy, from the meta element the server wrote. */
function cspNonce(): string | undefined {
  const nonce = document.querySelector<HTMLMetaElement>('meta[property="csp-nonce"]')?.nonce;

  return nonce === undefined || nonce === '' ? undefined : nonce;
}

/** Inertia's progress bar in the accent colour of the design tokens, or its own when unset. */
function progress(): { color?: string } {
  const colour = getComputedStyle(document.documentElement)
    .getPropertyValue('--cms-color-accent')
    .trim();

  return colour === '' ? {} : { color: colour };
}

const nonce = cspNonce();

void createInertiaApp({
  resolve: resolvePage,
  title: (title) => (title === '' ? name : t('panel.title', { page: title, name })),
  ...(nonce === undefined ? {} : { nonce }),
  progress: progress(),
  setup({ el, App, props }) {
    createRoot(el).render(
      <KitI18nProvider locale={locale}>
        <TranslationProvider locale={locale}>
          <App {...props} />
        </TranslationProvider>
      </KitI18nProvider>,
    );
  },
});
