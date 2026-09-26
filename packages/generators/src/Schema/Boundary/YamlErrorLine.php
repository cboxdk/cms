<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use Symfony\Component\Yaml\Exception\ParseException;
use TypeError;

/**
 * Where in a YAML file symfony/yaml found a parse error.
 */
#[Internal]
final readonly class YamlErrorLine
{
    /**
     * The line of the file that a parse error is on.
     *
     * symfony/yaml counts the lines of a mapping inside a sequence item from the end of the item
     * instead of its start, so an error there names a later line than its own. The error keeps the
     * line it was found on as its snippet, so the line is the last one at or before the reported
     * line that reads the same, ignoring indentation and sequence dashes. Without a snippet, or
     * without such a line, the reported line stands.
     */
    public static function of(ParseException $invalid, string $contents): int
    {
        $reported = $invalid->getParsedLine();

        try {
            $snippet = self::bare($invalid->getSnippet());
        } catch (TypeError) {
            // symfony/yaml declares the snippet a string but leaves it null for some errors.
            return $reported;
        }

        $lines = preg_split('/\R/', $contents) ?: [];

        if ($snippet === '' || $reported < 1) {
            return $reported;
        }

        for ($line = min($reported, count($lines)); $line >= 1; $line--) {
            if (self::bare($lines[$line - 1]) === $snippet) {
                return $line;
            }
        }

        return $reported;
    }

    /**
     * A line without its indentation, its sequence dashes and trailing space.
     */
    private static function bare(string $line): string
    {
        return (string) preg_replace('/\A(?:-(?:\s+|\z))+/', '', trim($line));
    }
}
