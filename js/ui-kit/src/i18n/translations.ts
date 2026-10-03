// The kit's own texts (GUARDRAILS 8: every text through the translations, in Danish and
// English). The kit has a few texts of its own, such as the mark of a required field, in its own
// catalogues in ./catalogues, one per locale with the same keys (checked by
// `npm run lint:translations`, by a test of gate 5 and by tsc below). Internal to the kit: its
// components read their texts with useKitTranslation in the locale of the nearest
// KitI18nProvider.

import { createContext, useContext } from 'react';

import da from './catalogues/da.json';
import en from './catalogues/en.json';
import { DEFAULT_KIT_LOCALE, kitLanguageTag, type KitLocale } from './locales';

/** A key of the kit's catalogues. */
export type KitTranslationKey = keyof typeof en;

/** The values a text of the kit names in braces, such as {count}. */
export type KitTranslationValues = Readonly<Record<string, string | number>>;

/**
 * Reads a text of the kit's catalogue of one locale, with each name in braces replaced by its
 * value; a number is written in the locale's own way.
 */
export type KitTranslate = (key: KitTranslationKey, values?: KitTranslationValues) => string;

// Typed against the English keys, so a key missing in Danish fails the type check as well.
const CATALOGUES: Readonly<Record<KitLocale, Readonly<Record<KitTranslationKey, string>>>> = {
  da,
  en,
};

/** The kit's texts in a locale. */
export function kitTranslator(locale: KitLocale): KitTranslate {
  const catalogue = CATALOGUES[locale];
  const numbers = new Intl.NumberFormat(kitLanguageTag(locale));

  return (key, values) =>
    values === undefined
      ? catalogue[key]
      : catalogue[key].replace(/\{([a-z_]+)\}/g, (match, name: string) => {
          const value = values[name];

          if (value === undefined) {
            return match;
          }

          return typeof value === 'number' ? numbers.format(value) : value;
        });
}

export const KitTranslationContext = createContext<KitTranslate>(kitTranslator(DEFAULT_KIT_LOCALE));

/** The kit's texts in the locale of the nearest KitI18nProvider. */
export function useKitTranslation(): KitTranslate {
  return useContext(KitTranslationContext);
}
