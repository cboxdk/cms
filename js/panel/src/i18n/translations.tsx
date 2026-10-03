// Every text of the panel comes from here (GUARDRAILS 8): the catalogues in ./catalogues, one per
// locale with the same keys (checked by `npm run lint:translations` and by tsc below), and t() to
// read them. A text may name parameters as {name}, which t() fills in.

import { createContext, useContext, type ReactNode } from 'react';

import da from './catalogues/da.json';
import en from './catalogues/en.json';

export type Locale = 'da' | 'en';

export const LOCALES: readonly Locale[] = ['da', 'en'];

export const DEFAULT_LOCALE: Locale = 'en';

export type TranslationKey = keyof typeof en;

export type TranslationParameters = Readonly<Record<string, string | number>>;

export type Translate = (key: TranslationKey, parameters?: TranslationParameters) => string;

type Catalogue = Readonly<Record<TranslationKey, string>>;

// Typed against the English keys, so a key missing in Danish fails the type check as well.
const CATALOGUES: Readonly<Record<Locale, Catalogue>> = { da, en };

const PARAMETER = /\{([a-z_]+)\}/g;

export function isLocale(value: string): value is Locale {
  return (LOCALES as readonly string[]).includes(value);
}

/** The locale of a language tag such as "da-DK", or the default when the panel has none for it. */
export function localeOf(tag: string): Locale {
  const language = tag.split('-')[0]?.toLowerCase() ?? '';

  return isLocale(language) ? language : DEFAULT_LOCALE;
}

/** Fills in the parameters a text names as {name}, leaving a name without a value as it is. */
export function fill(text: string, parameters: TranslationParameters = {}): string {
  return text.replace(PARAMETER, (match, name: string) => {
    const value = parameters[name];

    return value === undefined ? match : String(value);
  });
}

/**
 * The panel's own text of a key given as a string, such as one a core contribution reaches through
 * the host, or undefined when the panel's catalogue has no such key.
 */
export function lookup(locale: Locale, key: string): string | undefined {
  const catalogue: Readonly<Record<string, string>> = CATALOGUES[locale];

  return Object.hasOwn(catalogue, key) ? catalogue[key] : undefined;
}

export function translator(locale: Locale): Translate {
  const catalogue = CATALOGUES[locale];

  return (key, parameters = {}) => fill(catalogue[key], parameters);
}

interface TranslationContextValue {
  readonly locale: Locale;
  readonly t: Translate;
}

const TranslationContext = createContext<TranslationContextValue>({
  locale: DEFAULT_LOCALE,
  t: translator(DEFAULT_LOCALE),
});

export function TranslationProvider({
  locale,
  children,
}: {
  readonly locale: Locale;
  readonly children: ReactNode;
}) {
  return (
    <TranslationContext value={{ locale, t: translator(locale) }}>{children}</TranslationContext>
  );
}

export function useTranslation(): TranslationContextValue {
  return useContext(TranslationContext);
}
