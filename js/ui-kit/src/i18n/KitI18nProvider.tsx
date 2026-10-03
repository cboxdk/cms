// The kit's own texts (GUARDRAILS 8: every text through the translations, in Danish and
// English). The kit has a few texts of its own, such as the mark of a required field, in its own
// catalogues in ./catalogues, one per locale with the same keys (checked by
// `npm run lint:translations`, by a test of gate 5 and by tsc below). A page renders the kit inside
// KitI18nProvider with its locale; the provider also gives React Aria, whose primitives the kit
// builds on, the same locale, so dates, numbers and the primitives' own texts follow it.

import { createContext, useContext, type ReactNode } from 'react';
import { I18nProvider } from 'react-aria-components/I18nProvider';

import da from './catalogues/da.json';
import en from './catalogues/en.json';

/**
 * A locale the kit has texts in.
 *
 * @stable
 */
export type KitLocale = 'da' | 'en';

/** @stable */
export const KIT_LOCALES: readonly KitLocale[] = ['da', 'en'];

/** @stable */
export const DEFAULT_KIT_LOCALE: KitLocale = 'en';

/** A key of the kit's catalogues. */
export type KitTranslationKey = keyof typeof en;

/** Reads a text of the kit's catalogue of one locale. */
export type KitTranslate = (key: KitTranslationKey) => string;

// Typed against the English keys, so a key missing in Danish fails the type check as well.
const CATALOGUES: Readonly<Record<KitLocale, Readonly<Record<KitTranslationKey, string>>>> = {
  da,
  en,
};

/** The language tag React Aria formats with for each locale of the kit. */
const LANGUAGE_TAGS: Readonly<Record<KitLocale, string>> = { da: 'da-DK', en: 'en-GB' };

/** Whether a string is a locale the kit has texts in. */
export function isKitLocale(value: string): value is KitLocale {
  return (KIT_LOCALES as readonly string[]).includes(value);
}

/** The kit's texts in a locale. */
export function kitTranslator(locale: KitLocale): KitTranslate {
  const catalogue = CATALOGUES[locale];

  return (key) => catalogue[key];
}

const KitTranslationContext = createContext<KitTranslate>(kitTranslator(DEFAULT_KIT_LOCALE));

/** @stable */
export interface KitI18nProviderProps {
  /** The locale of the page, which the kit's texts and React Aria's formatting follow. */
  readonly locale: KitLocale;
  readonly children: ReactNode;
}

/**
 * Gives the kit's components below it their texts in the page's locale, and React Aria the same
 * locale. Without it, the kit speaks English.
 *
 * @stable
 */
export function KitI18nProvider({ locale, children }: KitI18nProviderProps) {
  return (
    <I18nProvider locale={LANGUAGE_TAGS[locale]}>
      <KitTranslationContext value={kitTranslator(locale)}>{children}</KitTranslationContext>
    </I18nProvider>
  );
}

/** The kit's texts in the locale of the nearest KitI18nProvider; internal to the kit. */
export function useKitTranslation(): KitTranslate {
  return useContext(KitTranslationContext);
}
