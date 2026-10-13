<?php

declare(strict_types=1);

namespace Cbox\Cms\Generators\Schema\Domain\Dto;

use Cbox\Cms\Contracts\Attributes\Internal;

/**
 * A text of a blueprint that is a string or a map of locale to string (gap G2): a label, a
 * description, the instructions of a section or a field, or an input hint. `text` is the one
 * text the generated code uses wherever it needs a single text, such as the type descriptor, the
 * catalog, the records' documentation and the tools agents read: the string, or the first text of
 * the map in file order, so a blueprint lists its primary language first. `byLocale` holds every
 * text of the map by its locale in canonical case, and is empty for a plain string.
 */
#[Internal]
final readonly class LocalizedText
{
    /**
     * @param  array<string, string>  $byLocale  the texts by locale, in file order; empty for a plain string
     */
    private function __construct(
        public string $text,
        public array $byLocale,
    ) {}

    public static function plain(string $text): self
    {
        return new self($text, []);
    }

    /**
     * @param  array<string, string>  $byLocale  at least one text, by locale in canonical case
     */
    public static function byLocale(array $byLocale): self
    {
        $first = array_key_first($byLocale);

        return new self($first === null ? '' : $byLocale[$first], $byLocale);
    }
}
