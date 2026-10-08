<?php

declare(strict_types=1);

namespace Cbox\Cms\Contracts\Tests\Panel;

use Cbox\Cms\Contracts\PanelPoints\PanelLocale;

/*
 * The locales the panel ships its texts in (GUARDRAILS 8): da and en, read from a language tag as
 * the panel's own localeOf() reads it in the browser, with English for any other language, and the
 * catalogue file of each, which an addon ships in its language directory.
 */

it('reads the locale of a language tag, and falls back to English', function (string $tag, PanelLocale $locale): void {
    expect(PanelLocale::of($tag))->toBe($locale);
})->with([
    ['da', PanelLocale::Danish],
    ['da-DK', PanelLocale::Danish],
    ['da_DK', PanelLocale::Danish],
    ['DA', PanelLocale::Danish],
    ['en', PanelLocale::English],
    ['en-GB', PanelLocale::English],
    ['de', PanelLocale::English],
    ['', PanelLocale::English],
]);

it('ships da and en, and names the catalogue file of each', function (): void {
    expect(array_map(static fn (PanelLocale $locale): string => $locale->value, PanelLocale::cases()))->toBe(['da', 'en'])
        ->and(array_map(static fn (PanelLocale $locale): string => $locale->file(), PanelLocale::cases()))->toBe(['da.json', 'en.json'])
        ->and(PanelLocale::FALLBACK)->toBe(PanelLocale::English);
});
