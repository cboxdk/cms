// The provider of the kit's locale (GUARDRAILS 8: every text through the translations, in Danish
// and English). A page renders the kit inside KitI18nProvider with its locale: the kit's own texts
// (./translations.ts) follow it, and so does React Aria, whose primitives the kit builds on, so
// dates, numbers and the primitives' own texts are in the same language.

import { useMemo, type ReactNode } from 'react';
import { I18nProvider } from 'react-aria-components/I18nProvider';

import { kitLanguageTag, type KitLocale } from './locales';
import { KitTranslationContext, kitTranslator } from './translations';

export { DEFAULT_KIT_LOCALE, isKitLocale, KIT_LOCALES } from './locales';
export type { KitLocale } from './locales';

/**
 * The props of KitI18nProvider.
 *
 * @stable
 */
export interface KitI18nProviderProps {
  /** The locale of the page, which the kit's texts and React Aria's formatting follow. */
  readonly locale: KitLocale;
  /** The page, or the part of it, in the locale. */
  readonly children: ReactNode;
}

/**
 * Gives the kit's components below it their texts in the page's locale, and React Aria the same
 * locale. Without it, the kit speaks English.
 *
 * @stable
 */
export function KitI18nProvider({ locale, children }: KitI18nProviderProps) {
  const translate = useMemo(() => kitTranslator(locale), [locale]);

  return (
    <I18nProvider locale={kitLanguageTag(locale)}>
      <KitTranslationContext value={translate}>{children}</KitTranslationContext>
    </I18nProvider>
  );
}
