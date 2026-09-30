<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Seeding\Domain;

use Cbox\Cms\Contracts\Attributes\Internal;
use Random\Randomizer;

/**
 * The text of seeded values: words drawn from a fixed list of neutral words, so a data set has the
 * shape of text without meaning, and every byte is visible ASCII.
 */
#[Internal]
final readonly class SeedText
{
    /** @var non-empty-list<string> */
    public const array WORDS = [
        'amber', 'anchor', 'arch', 'basin', 'beacon', 'birch', 'bridge', 'canal', 'cedar', 'chalk',
        'cliff', 'cloud', 'coast', 'copper', 'delta', 'dune', 'ember', 'field', 'fjord', 'flint',
        'forest', 'glade', 'granite', 'harbour', 'hazel', 'heath', 'island', 'ivory', 'juniper', 'lagoon',
        'lantern', 'maple', 'marsh', 'meadow', 'mesa', 'mill', 'moss', 'north', 'oak', 'orchard',
        'pebble', 'pine', 'plain', 'quarry', 'reef', 'ridge', 'river', 'rowan', 'sand', 'shore',
        'slate', 'spruce', 'stone', 'summit', 'tide', 'timber', 'valley', 'willow', 'windmill', 'yard',
    ];

    /**
     * Words separated by spaces, between the given lengths in bytes.
     */
    public static function words(Randomizer $random, int $minWords, int $maxWords, int $minLength = 0, ?int $maxLength = null): string
    {
        $count = $random->getInt(max(1, $minWords), max(1, $minWords, $maxWords));
        $words = [];

        for ($index = 0; $index < $count; $index++) {
            $words[] = self::word($random);
        }

        $text = implode(' ', $words);

        while (strlen($text) < $minLength) {
            $text .= ' '.self::word($random);
        }

        if ($maxLength !== null && strlen($text) > $maxLength) {
            $text = rtrim(substr($text, 0, $maxLength));
        }

        return str_pad($text, $minLength, 'a');
    }

    public static function word(Randomizer $random): string
    {
        return self::WORDS[$random->getInt(0, count(self::WORDS) - 1)];
    }
}
