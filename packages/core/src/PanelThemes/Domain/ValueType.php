<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\PanelThemes\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The type of a design token's value (js/ui-kit/tokens.json), and the form a literal value of it
 * has in the catalogue and in a theme. The patterns are those of js/ui-kit/scripts/tokens.js, from
 * which the theme's JSON Schema, theme.v1.json, is generated; ThemeDocumentSchemaTest holds the two
 * to the same verdicts.
 */
#[Experimental]
enum ValueType: string
{
    case Color = 'color';
    case Length = 'length';
    case Duration = 'duration';
    case Number = 'number';
    case FontFamily = 'font-family';
    case Shadow = 'shadow';

    private const string FAMILY = "(?:-?[A-Za-z][A-Za-z0-9-]*|'[A-Za-z0-9 -]+')";

    private const string SHADOW_OFFSETS = '(?:inset )?(?:-?\d+(?:\.\d+)?(?:px)? ){2,4}';

    /**
     * The regular expression a literal value of the type matches, as PCRE with delimiters.
     */
    public function pattern(): string
    {
        return match ($this) {
            self::Color => '/\A(?:#[0-9a-f]{6}|oklch\(\d+(?:\.\d+)?% \d+(?:\.\d+)? \d+(?:\.\d+)?\))\z/',
            self::Length => '/\A(?:0|\d+(?:\.\d+)?(?:px|rem|em|%))\z/',
            self::Duration => '/\A\d+ms\z/',
            self::Number => '/\A\d+(?:\.\d+)?\z/',
            self::FontFamily => '/\A'.self::FAMILY.'(?:, '.self::FAMILY.')*\z/',
            self::Shadow => '/\A'.self::SHADOW_OFFSETS.'#[0-9a-f]{6}(?:, '.self::SHADOW_OFFSETS.'#[0-9a-f]{6})*\z/',
        };
    }

    /**
     * Whether the literal value has the form of the type.
     */
    public function accepts(string $value): bool
    {
        return preg_match($this->pattern(), $value) === 1;
    }
}
