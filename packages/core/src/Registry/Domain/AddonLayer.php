<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Registry\Domain;

use Cbox\Cms\Contracts\Attributes\Experimental;

/**
 * The cascade layer an addon's stylesheets keep their rules in (PRD 13.4), `cms.addon` or a layer
 * below it such as `cms.addon.approvals`, so the panel's own layers, declared after it, always
 * win, and an addon cannot restyle the core with a selector it does not own.
 *
 * firstUnlayered() finds the first rule of a stylesheet outside it: after comments, a stylesheet
 * is at most one `@charset` and then only `@layer` statements and blocks of the addon's layer.
 */
#[Experimental]
final readonly class AddonLayer
{
    public const string LAYER = 'cms.addon';

    /**
     * The start of the first text of the stylesheet outside the addon's layer, at most 60
     * characters, or null when every rule is inside it.
     */
    public static function firstUnlayered(string $css): ?string
    {
        $css = (string) preg_replace('~/\*.*?(?:\*/|\z)~s', ' ', $css);
        $length = strlen($css);
        $at = 0;

        if (preg_match('/\A\s*@charset\s+"[^"]*"\s*;/', $css, $charset) === 1) {
            $at = strlen($charset[0]);
        }

        while (true) {
            while ($at < $length && ctype_space($css[$at])) {
                $at++;
            }

            if ($at >= $length) {
                return null;
            }

            if (preg_match('/\G@layer\s+([^{;]+?)\s*([{;])/', $css, $layer, 0, $at) !== 1 || ! self::addonLayers($layer[1])) {
                return self::snippet($css, $at);
            }

            $at += strlen($layer[0]);

            if ($layer[2] === ';') {
                continue;
            }

            $end = self::blockEnd($css, $at);

            if ($end === null) {
                return self::snippet($css, $at);
            }

            $at = $end + 1;
        }
    }

    /**
     * Whether every name of a layer list is the addon's layer or below it.
     */
    private static function addonLayers(string $names): bool
    {
        foreach (explode(',', $names) as $name) {
            $name = trim($name);

            if ($name !== self::LAYER && ! str_starts_with($name, self::LAYER.'.')) {
                return false;
            }
        }

        return true;
    }

    /**
     * The offset of the brace that closes the block opened just before the offset, past strings
     * and nested blocks, or null when it is never closed.
     */
    private static function blockEnd(string $css, int $at): ?int
    {
        $depth = 1;
        $length = strlen($css);
        $quote = null;

        for ($index = $at; $index < $length; $index++) {
            $char = $css[$index];

            if ($quote !== null) {
                if ($char === '\\') {
                    $index++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                return $index;
            }
        }

        return null;
    }

    private static function snippet(string $css, int $at): string
    {
        return trim((string) preg_replace('/\s+/', ' ', substr($css, $at, 60)));
    }
}
