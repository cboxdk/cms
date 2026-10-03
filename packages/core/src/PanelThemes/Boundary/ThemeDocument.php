<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Core\Codecs\Boundary\JsonText;
use Cbox\Cms\Core\Codecs\Domain\DecodingFailed;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\CatalogueToken;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\Theme;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\TokenCatalogue;
use Cbox\Cms\Core\PanelThemes\Domain\Dto\TokenValue;
use Cbox\Cms\Core\PanelThemes\Domain\InvalidTheme;
use Cbox\Cms\Core\PanelThemes\Domain\ThemeName;
use stdClass;

/**
 * Reads a theme file (PRD 13.4) as the theme's JSON Schema, js/ui-kit/src/generated/theme.v1.json,
 * describes it, from the same catalogue the schema is generated from:
 *
 *     {"tokens": {"color-accent": {"light": "#1d6b47", "dark": "#7fd0a6"}, "radius-md": "4px"},
 *      "parts": {"task-screen": {"color-surface-raised": {"light": "#f4f8f6", "dark": "#14201a"}}}}
 *
 * An object with only `tokens` and `parts`, both optional; tokens by the name of a semantic or
 * component token of the catalogue, never a primitive; parts by the name of a curated part hook,
 * each with tokens of the same form; and each value a literal of its token's type (ValueType),
 * either one string for both modes or an object with exactly `light` and `dark`, so a theme that
 * sets one mode sets the other. A key given twice is refused, as the kernel's JSON is. Every
 * reason is collected, with its place in the file.
 */
#[Internal]
final readonly class ThemeDocument
{
    /**
     * @throws InvalidTheme
     */
    public static function read(ThemeName $name, string $json, TokenCatalogue $catalogue): Theme
    {
        try {
            $document = JsonText::decode($json);
        } catch (DecodingFailed $failed) {
            throw new InvalidTheme([sprintf('It is not JSON: %s', $failed->getMessage())]);
        }

        $reasons = [];

        foreach (array_keys(get_object_vars($document)) as $key) {
            if ($key !== 'tokens' && $key !== 'parts') {
                $reasons[] = sprintf('/%s: a theme has only tokens and parts, and sets nothing but token values.', $key);
            }
        }

        $tokens = self::tokens($document->tokens ?? new stdClass, '/tokens', $catalogue, $reasons);
        $parts = [];
        $declared = $document->parts ?? new stdClass;

        if (! $declared instanceof stdClass) {
            $reasons[] = '/parts: not an object of part hooks by name.';
        } else {
            foreach (get_object_vars($declared) as $part => $values) {
                $part = (string) $part;

                if (! $catalogue->hasPart($part)) {
                    $reasons[] = sprintf('/parts/%s: not a curated part hook; a theme may name only %s.', $part, $catalogue->parts === [] ? 'none' : implode(', ', $catalogue->parts));

                    continue;
                }

                $parts[$part] = self::tokens($values, '/parts/'.$part, $catalogue, $reasons);
            }

            ksort($parts, SORT_STRING);
        }

        if ($reasons !== []) {
            throw new InvalidTheme($reasons);
        }

        return new Theme($name, $tokens, $parts);
    }

    /**
     * @param  list<string>  $reasons
     * @return array<string, TokenValue> sorted by name
     */
    private static function tokens(mixed $declared, string $at, TokenCatalogue $catalogue, array &$reasons): array
    {
        if (! $declared instanceof stdClass) {
            $reasons[] = sprintf('%s: not an object of token values by token name.', $at);

            return [];
        }

        $tokens = [];

        foreach (get_object_vars($declared) as $name => $value) {
            $name = (string) $name;
            $place = $at.'/'.$name;
            $token = $catalogue->token($name);

            if (! $token instanceof CatalogueToken || ! $token->themeable()) {
                $reasons[] = sprintf('%s: %s; a theme sets only the semantic and component tokens of docs/ui/tokens.md.', $place, $token instanceof CatalogueToken ? 'a primitive token, which only the kit sets' : 'not a token of the catalogue');

                continue;
            }

            if (is_string($value)) {
                $literal = TokenValue::both($value);
                $written = ['value' => $value];
            } elseif ($value instanceof stdClass && self::isModePair($value) && is_string($value->light) && is_string($value->dark)) {
                $literal = new TokenValue($value->light, $value->dark);
                $written = ['light value' => $literal->light, 'dark value' => $literal->dark];
            } else {
                $reasons[] = sprintf('%s: give one %s for both modes, or an object with exactly light and dark, both of them.', $place, $token->type->value);

                continue;
            }

            foreach ($written as $what => $text) {
                if (! $token->type->accepts($text)) {
                    $reasons[] = sprintf('%s: the %s "%s" is not a %s of the form docs/ui/tokens.md shows.', $place, $what, $text, $token->type->value);
                }
            }

            $tokens[$name] = $literal;
        }

        ksort($tokens, SORT_STRING);

        return $tokens;
    }

    private static function isModePair(stdClass $value): bool
    {
        $keys = array_keys(get_object_vars($value));
        sort($keys, SORT_STRING);

        return $keys === ['dark', 'light'];
    }
}
