<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\ComposedTheme;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\Theme;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\TokenCatalogue;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\TokenOverlap;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\TokenValue;

/**
 * Composes themes in the order given, a later theme's value over an earlier one's, on the whole
 * panel and per part hook (PRD 13.4). There is no implicit merge: only the themes given compose,
 * and each token more than one of them sets in one place is listed as an overlap. Pure.
 */
#[Experimental]
final readonly class ThemeComposer
{
    /**
     * @param  list<Theme>  $themes
     */
    public static function compose(TokenCatalogue $catalogue, array $themes): ComposedTheme
    {
        /** @var array<string, TokenValue> $tokens */
        $tokens = [];
        /** @var array<string, array<string, TokenValue>> $parts */
        $parts = [];
        /** @var array<string, list<ThemeName>> $setters by token on the whole panel */
        $setters = [];
        /** @var array<string, array<string, list<ThemeName>>> $partSetters by part, then token */
        $partSetters = [];

        foreach ($themes as $theme) {
            foreach ($theme->tokens as $token => $value) {
                $tokens[$token] = $value;
                $setters[$token][] = $theme->name;
            }

            foreach ($theme->parts as $part => $values) {
                foreach ($values as $token => $value) {
                    $parts[$part][$token] = $value;
                    $partSetters[$part][$token][] = $theme->name;
                }
            }
        }

        ksort($tokens, SORT_STRING);
        ksort($parts, SORT_STRING);

        foreach ($parts as $part => $values) {
            ksort($values, SORT_STRING);
            $parts[$part] = $values;
        }

        ksort($setters, SORT_STRING);
        ksort($partSetters, SORT_STRING);
        $overlaps = [];

        foreach ($setters as $token => $names) {
            if (count($names) > 1) {
                $overlaps[] = new TokenOverlap((string) $token, null, $names);
            }
        }

        foreach ($partSetters as $part => $tokenSetters) {
            ksort($tokenSetters, SORT_STRING);

            foreach ($tokenSetters as $token => $names) {
                if (count($names) > 1) {
                    $overlaps[] = new TokenOverlap((string) $token, (string) $part, $names);
                }
            }
        }

        return new ComposedTheme(
            $catalogue,
            array_map(static fn (Theme $theme): ThemeName => $theme->name, $themes),
            $tokens,
            $parts,
            $overlaps,
        );
    }
}
