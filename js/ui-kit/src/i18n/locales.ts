// The locales the kit has texts in, and the language tag each formats with.

/**
 * A locale the kit has texts in.
 *
 * @stable
 */
export type KitLocale = 'da' | 'en';

/**
 * The locales the kit has texts in.
 *
 * @stable
 */
export const KIT_LOCALES: readonly KitLocale[] = ['da', 'en'];

/**
 * The locale the kit speaks without a KitI18nProvider.
 *
 * @stable
 */
export const DEFAULT_KIT_LOCALE: KitLocale = 'en';

/** The language tag React Aria and Intl format with for each locale of the kit. */
const LANGUAGE_TAGS: Readonly<Record<KitLocale, string>> = { da: 'da-DK', en: 'en-GB' };

/**
 * Whether a string is a locale the kit has texts in.
 *
 * @stable
 */
export function isKitLocale(value: string): value is KitLocale {
  return (KIT_LOCALES as readonly string[]).includes(value);
}

/** The language tag of a locale of the kit; internal to the kit. */
export function kitLanguageTag(locale: KitLocale): string {
  return LANGUAGE_TAGS[locale];
}
