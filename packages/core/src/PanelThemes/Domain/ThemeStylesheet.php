<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\ComposedTheme;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\TokenValue;

/**
 * The stylesheet of a composed theme (PRD 13.4): every rule in the cascade layer cms.theme, the
 * last layer js/ui-kit/src/layers.css declares, so it wins over the kit's tokens, the kit, the
 * panel and every addon's CSS. It sets custom properties of the catalogue and nothing else, with
 * the same selectors as the kit's tokens.css:
 *
 * - on the root, each token a theme sets, its dark value where the dark mode applies;
 * - on each part hook a theme names, `[data-cms-part='<part>']`, every token whose resolved value
 *   there differs from the whole panel's, resolved, because a custom property that refers to
 *   another is computed on the root and inherited as a value;
 * - every duration it sets is 0 when the reader prefers reduced motion, as the kit's are.
 *
 * Every value it writes was checked against its token's type (ValueType), and every part name is
 * a catalogue part, so no value can end a rule. The text is the same for the same composition, so
 * the panel serves it under a hash of its bytes. No theme gives the empty string.
 */
#[Experimental]
final readonly class ThemeStylesheet
{
    private const string PREFIX = '--cms-';

    private const string DARK_MEDIA = '@media (prefers-color-scheme: dark) {';

    public static function render(ComposedTheme $theme): string
    {
        if ($theme->isEmpty()) {
            return '';
        }

        $lines = [
            sprintf('/* The panel\'s theme, written by cms:build from the themes cbox-cms.panel.themes selects, in their order: %s. */', implode(', ', array_map(static fn (ThemeName $name): string => $name->value, $theme->themes))),
            '',
            '@layer cms.theme {',
            ...self::block(':root', $theme->tokens),
        ];
        $durations = [':root' => self::durations($theme, $theme->tokens)];

        foreach (array_keys($theme->parts) as $part) {
            $selector = sprintf("[data-cms-part='%s']", $part);
            $values = self::partValues($theme, $part);
            $lines = [...$lines, ...self::block($selector, $values)];
            $durations[$selector] = self::durations($theme, $values);
        }

        $motion = [];

        foreach ($durations as $selector => $names) {
            if ($names === []) {
                continue;
            }

            $motion[] = $selector.' {';

            foreach ($names as $name) {
                $motion[] = self::PREFIX.$name.': 0ms;';
            }

            $motion[] = '}';
        }

        if ($motion !== []) {
            $lines = [...$lines, '@media (prefers-reduced-motion: reduce) {', ...$motion, '}'];
        }

        return implode("\n", [...$lines, '}', '']);
    }

    /**
     * The rules that set the values on the selector: the light value of each, and the dark value
     * of those that differ in the dark mode, with the kit's selectors for it.
     *
     * @param  array<string, TokenValue>  $values
     * @return list<string>
     */
    private static function block(string $selector, array $values): array
    {
        if ($values === []) {
            return [];
        }

        $lines = [$selector.' {'];

        foreach ($values as $name => $value) {
            $lines[] = sprintf('%s%s: %s;', self::PREFIX, $name, $value->light);
        }

        $lines[] = '}';
        $dark = array_filter($values, static fn (TokenValue $value): bool => $value->modal());

        if ($dark === []) {
            return $lines;
        }

        $declarations = array_map(
            static fn (string $name, TokenValue $value): string => sprintf('%s%s: %s;', self::PREFIX, $name, $value->dark),
            array_keys($dark),
            $dark,
        );
        $scoped = static fn (string $root): string => $selector === ':root' ? $root : $root.' '.$selector;

        return [
            ...$lines,
            self::DARK_MEDIA,
            $scoped(":root:not([data-theme='light'])").' {',
            ...$declarations,
            '}',
            '}',
            $scoped(":root[data-theme='dark']").' {',
            ...$declarations,
            '}',
        ];
    }

    /**
     * The tokens whose resolved value on the part differs, in either mode, from the whole panel's,
     * with their resolved values on the part.
     *
     * @return array<string, TokenValue>
     */
    private static function partValues(ComposedTheme $theme, string $part): array
    {
        $panel = [];
        $here = [];

        foreach (Mode::cases() as $mode) {
            $panel[$mode->value] = ResolvedTokens::of($theme->catalogue, $theme->tokens, $mode);
            $here[$mode->value] = ResolvedTokens::of($theme->catalogue, $theme->valuesAt($part), $mode);
        }

        $values = [];

        foreach ($theme->catalogue->tokens as $token) {
            $light = $here[Mode::Light->value][$token->name] ?? '';
            $dark = $here[Mode::Dark->value][$token->name] ?? '';

            if ($light !== ($panel[Mode::Light->value][$token->name] ?? '') || $dark !== ($panel[Mode::Dark->value][$token->name] ?? '')) {
                $values[$token->name] = new TokenValue($light, $dark);
            }
        }

        ksort($values, SORT_STRING);

        return $values;
    }

    /**
     * The duration tokens among the values.
     *
     * @param  array<string, TokenValue>  $values
     * @return list<string>
     */
    private static function durations(ComposedTheme $theme, array $values): array
    {
        return array_values(array_filter(
            array_keys($values),
            static fn (string $name): bool => $theme->catalogue->token($name)?->type === ValueType::Duration,
        ));
    }
}
