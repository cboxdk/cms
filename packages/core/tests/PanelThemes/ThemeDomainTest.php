<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Tests\PanelThemes;

use Cbox\Cms\Core\PanelThemes\Domain\Contrast;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\Theme;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\TokenOverlap;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\TokenValue;
use Cbox\Cms\Core\PanelThemes\Domain\Mode;
use Cbox\Cms\Core\PanelThemes\Domain\ResolvedTokens;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeComposer;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeName;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeStylesheet;
use Cbox\Cms\Core\PanelThemes\Domain\ValueType;
use InvalidArgumentException;

/*
 * The pure parts of the panel's themes (PRD 13.4): theme names, the contrast ratio as the kit's
 * scripts measure it, references resolved after a theme sets a token, and the stylesheet of the
 * cms.theme layer.
 */

it('names the application\'s theme app and an addon\'s <namespace>:<name>', function (): void {
    $brand = new ThemeName('fixtureaddon:brand');

    expect(ThemeName::app()->isApp())->toBeTrue()
        ->and([$brand->addon(), $brand->local(), $brand->isApp()])->toBe(['fixtureaddon', 'brand', false])
        ->and([ThemeName::app()->addon(), ThemeName::app()->local()])->toBe([null, null]);
});

it('refuses a theme name that is not app or <namespace>:<name>', function (string $name): void {
    expect(static fn (): ThemeName => new ThemeName($name))->toThrow(InvalidArgumentException::class, 'is not the name of a panel theme');
})->with(['', 'App', 'brand', 'app:brand', 'ext:brand', 'fixtureaddon:', 'fixtureaddon:Brand', 'a:b:c', 'fixtureaddon:'.str_repeat('a', 41)]);

it('measures contrast as js/ui-kit/scripts/tokens.js does, for hex and oklch colours', function (string $first, string $second, string $ratio): void {
    expect(Contrast::format(Contrast::ratio($first, $second)))->toBe($ratio);
})->with([
    ['#2f5bd3', '#ffffff', '5.90:1'],
    ['oklch(55% 0.16 150)', '#ffffff', '4.48:1'],
    ['oklch(70% 0.14 150)', '#121418', '7.30:1'],
    ['#eeeeee', '#ffffff', '1.16:1'],
    ['oklch(97% 0.4 30)', '#000000', '5.25:1'],
]);

it('refuses a colour of another form', function (): void {
    expect(static fn (): float => Contrast::ratio('red', '#ffffff'))->toThrow(InvalidArgumentException::class, '"red" is not a colour');
});

it('knows the form of each type\'s literal value', function (ValueType $type, string $accepted, string $refused): void {
    expect($type->accepts($accepted))->toBeTrue()
        ->and($type->accepts($refused))->toBeFalse();
})->with([
    [ValueType::Color, 'oklch(55% 0.16 150)', '#FFF'],
    [ValueType::Length, '1.5rem', '1.5vw'],
    [ValueType::Duration, '120ms', '0.1s'],
    [ValueType::Number, '600', 'bold'],
    [ValueType::FontFamily, "Inter, 'Segoe UI', sans-serif", 'Inter;'],
    [ValueType::Shadow, '0 0 0 2px #ffffff, 0 0 0 4px #2f5bd3', '0 0 0 2px var(--x)'],
]);

it('resolves the references of the catalogue to the values a theme sets', function (): void {
    $catalogue = ThemeWorld::catalogue();
    $accent = ['color-accent' => new TokenValue('#14532d', '#86efac')];

    expect(ResolvedTokens::of($catalogue, [], Mode::Light)['color-focus'])->toBe('oklch(45% 0.16 258)')
        ->and(ResolvedTokens::of($catalogue, $accent, Mode::Light)['color-focus'])->toBe('#14532d')
        ->and(ResolvedTokens::of($catalogue, $accent, Mode::Dark)['focus-ring'])->toBe('0 0 0 2px oklch(15.5% 0.01 250), 0 0 0 4px #86efac');
});

it('composes later themes over earlier ones and lists every overlap', function (): void {
    $first = new Theme(new ThemeName('a:one'), ['radius-md' => TokenValue::both('2px')], ['task-screen' => ['radius-lg' => TokenValue::both('0')]]);
    $second = new Theme(new ThemeName('b:two'), ['radius-md' => TokenValue::both('8px')], ['task-screen' => ['radius-lg' => TokenValue::both('4px')]]);
    $composed = ThemeComposer::compose(ThemeWorld::catalogue(), [$first, $second]);

    expect($composed->tokens)->toEqual(['radius-md' => TokenValue::both('8px')])
        ->and($composed->parts)->toEqual(['task-screen' => ['radius-lg' => TokenValue::both('4px')]])
        ->and(array_map(static fn (TokenOverlap $overlap): array => [$overlap->token, $overlap->part, array_map(static fn (ThemeName $name): string => $name->value, $overlap->themes)], $composed->overlaps))->toBe([
            ['radius-md', null, ['a:one', 'b:two']],
            ['radius-lg', 'task-screen', ['a:one', 'b:two']],
        ]);
});

it('writes every token a part changes, resolved, so a reference on the root does not leak into the part', function (): void {
    $theme = new Theme(ThemeName::app(), ['duration-fast' => TokenValue::both('200ms')], ['task-screen' => ['color-surface' => new TokenValue('#f4f8f6', '#14201a')]]);

    expect(ThemeStylesheet::render(ThemeComposer::compose(ThemeWorld::catalogue(), [$theme])))->toBe(<<<'CSS'
        /* The panel's theme, written by cms:build from the themes cbox-cms.panel.themes selects, in their order: app. */

        @layer cms.theme {
        :root {
        --cms-duration-fast: 200ms;
        }
        [data-cms-part='task-screen'] {
        --cms-color-surface: #f4f8f6;
        --cms-focus-ring: 0 0 0 2px #f4f8f6, 0 0 0 4px oklch(45% 0.16 258);
        }
        @media (prefers-color-scheme: dark) {
        :root:not([data-theme='light']) [data-cms-part='task-screen'] {
        --cms-color-surface: #14201a;
        --cms-focus-ring: 0 0 0 2px #14201a, 0 0 0 4px oklch(65% 0.18 258);
        }
        }
        :root[data-theme='dark'] [data-cms-part='task-screen'] {
        --cms-color-surface: #14201a;
        --cms-focus-ring: 0 0 0 2px #14201a, 0 0 0 4px oklch(65% 0.18 258);
        }
        @media (prefers-reduced-motion: reduce) {
        :root {
        --cms-duration-fast: 0ms;
        }
        }
        }

        CSS);
});

it('writes nothing for no theme', function (): void {
    expect(ThemeStylesheet::render(ThemeComposer::compose(ThemeWorld::catalogue(), [])))->toBe('')
        ->and(ThemeStylesheet::render(ThemeComposer::compose(ThemeWorld::catalogue(), [new Theme(ThemeName::app())])))->toBe('');
});
