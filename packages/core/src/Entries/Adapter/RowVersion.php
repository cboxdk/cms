<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Entries\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Pipeline\AggregateRef;
use Cbox\Cms\Contracts\Pipeline\AggregateVersion;
use Cbox\Cms\Core\Pipeline\Domain\LockStrength;
use UnexpectedValueException;

/**
 * What the version locks of rows share: the row lock clause of a strength, and the version column
 * a locked row gave, or null for a row that does not exist.
 */
#[Internal]
final readonly class RowVersion
{
    /**
     * FOR SHARE, or FOR NO KEY UPDATE, which does not block a row that only references the locked
     * one through a foreign key.
     */
    public static function clause(LockStrength $strength): string
    {
        return match ($strength) {
            LockStrength::Share => 'for share',
            LockStrength::Update => 'for no key update',
        };
    }

    public static function of(mixed $version, AggregateRef $aggregate): ?AggregateVersion
    {
        if ($version === null) {
            return null;
        }

        if (! is_int($version)) {
            throw new UnexpectedValueException(sprintf('The version of "%s" is an integer, got %s.', $aggregate->aggregateKey(), get_debug_type($version)));
        }

        return new AggregateVersion($version);
    }

    /**
     * A text column of a row a lock read.
     */
    public static function text(object $row, string $column): string
    {
        $value = property_exists($row, $column) ? $row->{$column} : null;

        return is_string($value) ? $value : throw new UnexpectedValueException(sprintf('The column %s of a locked row is text, got %s.', $column, get_debug_type($value)));
    }
}
