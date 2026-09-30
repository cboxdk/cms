<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Placements\Adapter;

use Cbox\Cms\Contracts\Attributes\Internal;
use Cbox\Cms\Contracts\Content\TimeWindow;
use Cbox\Cms\Core\Placements\Domain\Visibility;
use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use UnexpectedValueException;

/**
 * Reads the columns of the rows the placement reader and writers get from Postgres, and gives an
 * instant the form they write it in: UTC with its microseconds. A value of another form than its
 * column has is a broken schema, and throws.
 */
#[Internal]
final readonly class PlacementRows
{
    public static function object(mixed $row): object
    {
        return is_object($row) ? $row : throw new UnexpectedValueException(sprintf('A row is an object, got %s.', get_debug_type($row)));
    }

    public static function text(object $row, string $column): string
    {
        return self::textOrNull($row, $column) ?? throw new UnexpectedValueException(sprintf('The column %s is not null.', $column));
    }

    public static function textOrNull(object $row, string $column): ?string
    {
        $value = property_exists($row, $column) ? $row->{$column} : null;

        if ($value !== null && ! is_string($value)) {
            throw new UnexpectedValueException(sprintf('The column %s is text, got %s.', $column, get_debug_type($value)));
        }

        return $value;
    }

    public static function integer(object $row, string $column): int
    {
        return self::integerValue(property_exists($row, $column) ? $row->{$column} : null, 'the column '.$column);
    }

    public static function integerValue(mixed $value, string $what): int
    {
        return is_int($value) ? $value : throw new UnexpectedValueException(sprintf('%s is an integer, got %s.', ucfirst($what), get_debug_type($value)));
    }

    public static function boolean(object $row, string $column): bool
    {
        $value = property_exists($row, $column) ? $row->{$column} : null;

        return match ($value) {
            true, 't', 'true' => true,
            false, 'f', 'false' => false,
            default => throw new UnexpectedValueException(sprintf('The column %s is a boolean, got %s.', $column, get_debug_type($value))),
        };
    }

    public static function visibility(object $row): Visibility
    {
        $value = self::text($row, 'visibility');

        return Visibility::tryFrom($value) ?? throw new UnexpectedValueException(sprintf('The visibility "%s" is not a state of PRD 6.4.', $value));
    }

    /**
     * The window of live_from and live_until, or null for a placement without one: a hidden one.
     */
    public static function window(object $row): ?TimeWindow
    {
        $from = self::instant(self::textOrNull($row, 'live_from'));
        $until = self::instant(self::textOrNull($row, 'live_until'));

        if (self::visibility($row) === Visibility::Hidden && ! $from instanceof DateTimeImmutable && ! $until instanceof DateTimeImmutable) {
            return null;
        }

        return new TimeWindow($from, $until);
    }

    /**
     * A JSON array of strings, as the reader aggregates a list.
     *
     * @return list<string>
     */
    public static function textList(object $row, string $column): array
    {
        try {
            $decoded = json_decode(self::text($row, $column), false, 2, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(sprintf('The column %s is a JSON array.', $column), 0, $exception);
        }

        if (! is_array($decoded) || ! array_is_list($decoded)) {
            throw new UnexpectedValueException(sprintf('The column %s is a JSON array.', $column));
        }

        return array_map(static fn (mixed $item): string => is_string($item) ? $item : throw new UnexpectedValueException(sprintf('The column %s holds strings.', $column)), $decoded);
    }

    public static function timestamp(?DateTimeImmutable $at): ?string
    {
        return $at?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.uP');
    }

    private static function instant(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
