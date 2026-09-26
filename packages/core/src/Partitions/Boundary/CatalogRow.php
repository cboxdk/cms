<?php

declare(strict_types=1);

namespace Cbox\Cms\Core\Partitions\Boundary;

use Cbox\Cms\Contracts\Attributes\Internal;
use LogicException;

/**
 * One row from a query on the Postgres catalog, read column by column with the type the query
 * promises. A column of another type is a bug in the query, so it throws.
 */
#[Internal]
final readonly class CatalogRow
{
    private function __construct(private object $row) {}

    /**
     * @param  array<array-key, mixed>  $rows  what Connection::select() returned
     * @return list<self>
     */
    public static function all(array $rows): array
    {
        $read = [];

        foreach ($rows as $row) {
            if (! is_object($row)) {
                throw new LogicException(sprintf('Expected a catalog row as an object, got %s.', get_debug_type($row)));
            }

            $read[] = new self($row);
        }

        return $read;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     */
    public static function one(array $rows): self
    {
        $read = self::all($rows);

        if (count($read) !== 1) {
            throw new LogicException(sprintf('Expected one catalog row, got %d.', count($read)));
        }

        return $read[0];
    }

    public function string(string $column): string
    {
        $value = $this->value($column);

        return is_string($value) ? $value : throw $this->wrongType($column, 'a string', $value);
    }

    public function nullableString(string $column): ?string
    {
        $value = $this->value($column);

        return $value === null || is_string($value) ? $value : throw $this->wrongType($column, 'a string or null', $value);
    }

    public function int(string $column): int
    {
        $value = $this->value($column);

        return match (true) {
            is_int($value) => $value,
            is_string($value) && preg_match('/\A-?\d+\z/', $value) === 1 => (int) $value,
            default => throw $this->wrongType($column, 'an integer', $value),
        };
    }

    public function bool(string $column): bool
    {
        $value = $this->value($column);

        return is_bool($value) ? $value : throw $this->wrongType($column, 'a boolean', $value);
    }

    private function value(string $column): mixed
    {
        if (! property_exists($this->row, $column)) {
            throw new LogicException(sprintf('The catalog row has no column "%s".', $column));
        }

        return $this->row->{$column};
    }

    private function wrongType(string $column, string $expected, mixed $value): LogicException
    {
        return new LogicException(sprintf('Expected column "%s" to be %s, got %s.', $column, $expected, get_debug_type($value)));
    }
}
