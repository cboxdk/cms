<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use JsonException;
use UnexpectedValueException;

/**
 * Reads a column the placement reader aggregates a list of strings into, a JSON array of strings,
 * such as the locales of an entry's placements. A value of another form is a broken query, and
 * throws.
 */
#[Internal]
final readonly class TextListColumn
{
    /**
     * @return list<string>
     */
    public static function of(object $row, string $column): array
    {
        try {
            $decoded = json_decode(PlacementRows::text($row, $column), false, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(sprintf('The column %s is a JSON array.', $column), 0, $exception);
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new UnexpectedValueException(sprintf('The column %s is a JSON array.', $column));
        }

        return array_map(static fn (mixed $item): string => is_string($item) ? $item : throw new UnexpectedValueException(sprintf('The column %s holds strings.', $column)), $decoded);
    }
}
