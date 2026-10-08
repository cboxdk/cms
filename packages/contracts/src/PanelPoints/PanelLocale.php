<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\PanelPoints;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The locales the panel ships its texts in (GUARDRAILS 8: every text in Danish and English from
 * day one). The panel's own catalogues are js/panel/src/i18n/catalogues/<locale>.json, and an
 * addon ships one per locale in its PanelContributions::$lang directory, so cms:build refuses a
 * catalogue missing for any of them (registry_panel_translations_incomplete).
 *
 * The active locale of a request is the application's locale, read with of(), which takes the
 * language of a tag such as "da-DK" and answers English for a language the panel has no catalogue
 * for, as the panel's own localeOf() does in the browser.
 */
#[Experimental]
enum PanelLocale: string
{
    case Danish = 'da';

    case English = 'en';

    /** The locale a page gets when the application's locale is none of these. */
    public const self FALLBACK = self::English;

    /**
     * The locale of a language tag, such as "da", "da-DK" or "da_DK"; FALLBACK for any other.
     */
    public static function of(string $tag): self
    {
        $language = strtolower(strtok($tag, '-_') ?: '');

        return self::tryFrom($language) ?? self::FALLBACK;
    }

    /**
     * The name of the locale's catalogue file in an addon's language directory, `<locale>.json`.
     */
    public function file(): string
    {
        return $this->value.'.json';
    }
}
